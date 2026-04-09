<?php
@require_once __DIR__ . '/config/security_config.php';

/**
 * Writable directory for audit.log. Uses AUDIT_LOG_DIR (env) if set and usable; else project logs/.
 */
function audit_log_resolve_log_dir() {
    $fallback = __DIR__ . DIRECTORY_SEPARATOR . 'logs';
    $configured = (defined('AUDIT_LOG_DIR') && AUDIT_LOG_DIR !== '') ? trim((string) AUDIT_LOG_DIR) : '';
    if ($configured === '') {
        return $fallback;
    }
    if (!is_dir($configured)) {
        @mkdir($configured, 0750, true);
    }
    $real = @realpath($configured);
    if ($real !== false && is_dir($real) && is_writable($real)) {
        return $real;
    }
    return $fallback;
}

/**
 * Send one RFC 3164-style UDP syslog line. Uses fsockopen (always available) then socket_* if needed.
 */
function audit_send_remote_syslog_line($remote_host, $port, $syslog_line) {
    $remote_host = trim((string) $remote_host);
    if ($remote_host === '') {
        return false;
    }
    $port = (int) $port;
    if ($port < 1 || $port > 65535) {
        $port = 514;
    }
    $payload = strlen($syslog_line) > 8192 ? substr($syslog_line, 0, 8192) : $syslog_line;
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen('udp://' . $remote_host, $port, $errno, $errstr, 2);
    if ($fp !== false) {
        @fwrite($fp, $payload);
        @fclose($fp);
        return true;
    }
    if (function_exists('socket_create')) {
        $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($sock !== false) {
            $sent = @socket_sendto($sock, $payload, strlen($payload), 0, $remote_host, $port);
            @socket_close($sock);
            return $sent !== false;
        }
    }
    return false;
}

/**
 * Log an audit event. Uses a dedicated DB connection to avoid "Commands out of sync"
 * when the caller's connection still has unfetched results or open statements.
 *
 * Categories:
 *   AUTH       - Authentication (login, logout, MFA, lockout)
 *   TRANSACTION - Customer orders, payments
 *   ADMIN      - Product CRUD, user management, order status updates
 *
 * Outputs: database, append-only audit.log file, and optionally syslog (local or remote UDP).
 * File is append-only from PHP (FILE_APPEND | LOCK_EX); chmod 0640 on Unix where supported.
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

    // File logging — append only; never truncate (WORM-friendly at app layer; OS may add stricter controls)
    $log_dir = audit_log_resolve_log_dir();
    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0750, true);
    }
    if (PHP_OS_FAMILY !== 'Windows' && is_dir($log_dir)) {
        @chmod($log_dir, 0750);
    }
    $log_file = $log_dir . DIRECTORY_SEPARATOR . 'audit.log';
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
    if (PHP_OS_FAMILY !== 'Windows' && is_file($log_file)) {
        @chmod($log_file, 0640);
    }

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
        if (@openlog('sole_source_audit', LOG_PID | LOG_NDELAY, LOG_LOCAL0)) {
            @syslog(LOG_INFO, $syslog_msg);
            @closelog();
        }
    }
    // Remote syslog via UDP (central collector, Splunk, ELK, rsyslog remote). Works without socket extension.
    if (defined('AUDIT_SYSLOG_REMOTE') && AUDIT_SYSLOG_REMOTE !== '') {
        $remote = AUDIT_SYSLOG_REMOTE;
        $port = 514;
        if (strpos($remote, ':') !== false) {
            list($remote, $port) = explode(':', $remote, 2);
            $port = (int) $port;
        }
        $pri = (LOG_LOCAL0 << 3) | LOG_INFO;
        $timestamp = date('M j H:i:s');
        $hostname = gethostname() ?: 'localhost';
        $syslog_line = "<{$pri}>{$timestamp} {$hostname} sole_source_audit: {$syslog_msg}";
        audit_send_remote_syslog_line($remote, $port, $syslog_line);
    }
}
