<?php
/**
 * Shared session timeout enforcement.
 * Applies inactivity timeout only for authenticated sessions.
 */

require_once __DIR__ . '/../config/security_config.php';

if (!function_exists('enforce_session_timeout')) {
    function enforce_session_timeout(bool $api_mode = false): void {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            require_once __DIR__ . '/session_init.php';
        }

        if (!isset($_SESSION['user_id'])) {
            return;
        }

        $current_time = time();
        $last_activity = isset($_SESSION['last_activity']) ? (int)$_SESSION['last_activity'] : 0;

        if ($last_activity > 0 && ($current_time - $last_activity) > SESSION_TIMEOUT_SECONDS) {
            $role = $_SESSION['role'] ?? '';
            session_unset();
            session_destroy();

            if ($api_mode) {
                http_response_code(401);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Session expired. Please log in again.',
                    'code' => 'SESSION_EXPIRED'
                ]);
                exit();
            }

            if (session_status() !== PHP_SESSION_ACTIVE) {
                require_once __DIR__ . '/session_init.php';
            }
            $_SESSION['session_expired'] = 1;

            $is_staff_portal = ($role === 'Admin' || $role === 'Staff');
            header('Location: ' . ($is_staff_portal ? 'login-admin.php' : 'login.php'));
            exit();
        }

        $_SESSION['last_activity'] = $current_time;
    }
}
