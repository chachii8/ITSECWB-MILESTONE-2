<?php
@require_once __DIR__ . '/config/security_config.php';
/**
 * Log an audit event. Uses a dedicated DB connection to avoid "Commands out of sync"
 * when the caller's connection still has unfetched results or open statements.
 *
 * Categories:
 *   AUTH       - Authentication (login, logout, MFA, lockout)
 *   TRANSACTION - Customer orders, payments
 *   ADMIN      - Product CRUD, user management, order status updates
 *
 * Outputs: database, logs/audit.log file, and optionally syslog (local or remote).
 */
function log_audit($conn, $user_id, $role, $action, $entity_type, $entity_id, $details = null, $category = null) {
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;

    // Auto-detect category from action if not provided
    if ($category === null) {
        if (preg_match('/^(LOGIN_|MFA_|LOGOUT)/', $action)) {
            $category = 'AUTH';
        } elseif (preg_match('/^ORDER_PLACE|^ORDER_CREATE/', $action)) {
            $category = 'TRANSACTION';
        } elseif (preg_match('/^ORDER_STATUS_/', $action)) {
            $category = 'ADMIN';
        } elseif (preg_match('/^(PRODUCT_|USER_)/', $action)) {
            $category = 'ADMIN';
        } else {
            $category = 'AUTH'; // default for unknown
        }
    }

    // Database logging - use dedicated connection to avoid "Commands out of sync"
    require_once __DIR__ . '/includes/db.php';
    $audit_conn = get_db_connection();
    if ($audit_conn) {
        static $has_category = null;
        if ($has_category === null) {
            $r = @mysqli_query($audit_conn, "SHOW COLUMNS FROM audit_log LIKE 'log_category'");
            $has_category = $r && mysqli_num_rows($r) > 0;
            if ($r) mysqli_free_result($r);
        }

        $user_id_param = $user_id !== null ? (int)$user_id : null;
        $entity_id_param = $entity_id !== null ? (int)$entity_id : null;
        $role_val = $role ?? '';
        $entity_val = $entity_type ?? '';
        $ip_val = $ip_address ?? '';

        if ($has_category) {
            $stmt = mysqli_prepare(
                $audit_conn,
                "INSERT INTO audit_log (user_id, role, action, log_category, entity_type, entity_id, details, ip_address)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "issssiss", $user_id_param, $role_val, $action, $category, $entity_val, $entity_id_param, $details, $ip_val);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        } else {
            $stmt = mysqli_prepare(
                $audit_conn,
                "INSERT INTO audit_log (user_id, role, action, entity_type, entity_id, details, ip_address)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "isssiss", $user_id_param, $role_val, $action, $entity_val, $entity_id_param, $details, $ip_val);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        }
        mysqli_close($audit_conn);
    }

    // File logging
    $log_dir = __DIR__ . '/logs';
    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0755, true);
    }
    $log_file = $log_dir . '/audit.log';
    $role_display = $role ?? 'Guest';
    $user_display = $user_id !== null ? "user_id={$user_id}" : 'user_id=—';
    $entity_display = $entity_type ? "{$entity_type}#" . ($entity_id ?? '—') : '—';
    $details_display = $details ? " | {$details}" : '';
    $line = sprintf(
        "[%s] [%s] [%s] %s | %s | %s | %s%s | %s\n",
        date('Y-m-d H:i:s'),
        $category,
        $role_display,
        $action,
        $user_display,
        $entity_display,
        $ip_address ?? '—',
        $details_display,
        $_SERVER['REQUEST_URI'] ?? 'cli'
    );
    @file_put_contents($log_file, $line, FILE_APPEND | LOCK_EX);

    // Syslog: send to local syslog daemon and/or remote syslog server
    $ip_and_details = ($ip_address ?? '—') . $details_display;
    $syslog_msg = sprintf(
        "[%s] %s | %s | %s | %s | %s",
        $category,
        $role_display,
        $action,
        $user_display,
        $entity_display,
        $ip_and_details
    );
    if (defined('AUDIT_USE_SYSLOG') && AUDIT_USE_SYSLOG && function_exists('openlog') && function_exists('syslog')) {
        openlog('sole_source_audit', LOG_PID | LOG_NDELAY, LOG_LOCAL0);
        syslog(LOG_INFO, $syslog_msg);
        closelog();
    }
    // Remote syslog via UDP (e.g. central log server, Splunk, ELK)
    if (defined('AUDIT_SYSLOG_REMOTE') && AUDIT_SYSLOG_REMOTE !== '' && function_exists('socket_create')) {
        $remote = AUDIT_SYSLOG_REMOTE;
        $port = 514;
        if (strpos($remote, ':') !== false) {
            list($remote, $port) = explode(':', $remote, 2);
            $port = (int)$port;
        }
        $pri = (LOG_LOCAL0 << 3) | LOG_INFO;
        $timestamp = date('M j H:i:s');
        $hostname = gethostname() ?: 'localhost';
        $syslog_line = "<{$pri}>{$timestamp} {$hostname} sole_source_audit: {$syslog_msg}";
        $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($sock !== false) {
            @socket_sendto($sock, $syslog_line, strlen($syslog_line), 0, $remote, $port);
            socket_close($sock);
        }
    }
}
?>
