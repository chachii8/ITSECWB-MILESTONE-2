<?php
/**
 * Global error handler:
 * - APP_DEBUG = true  => detailed error + stack trace
 * - APP_DEBUG = false => generic safe message
 */

if (!function_exists('app_generate_error_id')) {
    function app_generate_error_id(): string
    {
        try {
            return bin2hex(random_bytes(6));
        } catch (Throwable $e) {
            return substr(sha1(uniqid((string)mt_rand(), true)), 0, 12);
        }
    }
}

if (!function_exists('app_render_error_page')) {
    function app_render_error_page(string $error_id, string $message, string $file = '', int $line = 0, string $trace = ''): void
    {
        $is_debug = defined('APP_DEBUG') && APP_DEBUG === true;

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');
        }

        if ($is_debug) {
            echo "<h1>Application Error</h1>";
            echo "<p><strong>Error ID:</strong> " . htmlspecialchars($error_id, ENT_QUOTES, 'UTF-8') . "</p>";
            echo "<h2>Message</h2>";
            echo "<pre>" . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "</pre>";
            if ($file !== '') {
                echo "<h2>File</h2>";
                echo "<pre>" . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . "</pre>";
            }
            if ($line > 0) {
                echo "<h2>Line</h2>";
                echo "<pre>" . (int)$line . "</pre>";
            }
            if ($trace !== '') {
                echo "<h2>Stack Trace</h2>";
                echo "<pre>" . htmlspecialchars($trace, ENT_QUOTES, 'UTF-8') . "</pre>";
            }
        } else {
            // Production: generic message only. Error ID is still logged server-side (see error_log above).
            echo "<h1>Something went wrong</h1>";
            echo "<p>An unexpected error occurred. Please try again later.</p>";
        }
    }
}

if (!function_exists('init_app_error_handler')) {
    function init_app_error_handler(): void
    {
        if (defined('APP_ERROR_HANDLER_INITIALIZED')) {
            return;
        }
        define('APP_ERROR_HANDLER_INITIALIZED', true);

        set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(function (Throwable $exception): void {
            $error_id = app_generate_error_id();
            error_log(sprintf(
                "[%s] [%s] %s in %s:%d\nStack trace:\n%s\n",
                date('Y-m-d H:i:s'),
                $error_id,
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
                $exception->getTraceAsString()
            ));

            app_render_error_page(
                $error_id,
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
                $exception->getTraceAsString()
            );
            exit;
        });

        register_shutdown_function(function (): void {
            $error = error_get_last();
            if ($error === null) {
                return;
            }

            $fatal_types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
            if (!in_array($error['type'], $fatal_types, true)) {
                return;
            }

            $message = $error['message'] ?? 'Unknown fatal error';
            $file = $error['file'] ?? '';
            $line = (int)($error['line'] ?? 0);
            $error_id = app_generate_error_id();

            error_log(sprintf("[%s] [%s] FATAL: %s in %s:%d", date('Y-m-d H:i:s'), $error_id, $message, $file, $line));
            app_render_error_page($error_id, $message, $file, $line);
        });
    }
}
