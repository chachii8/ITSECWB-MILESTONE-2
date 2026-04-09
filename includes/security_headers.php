<?php
/**
 * HTTP security headers (defense in depth).
 * Include early, before any output.
 */
require_once __DIR__ . '/https.php';

if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    if (function_exists('request_is_https') && request_is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}
