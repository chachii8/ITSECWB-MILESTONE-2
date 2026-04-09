<?php
/**
 * Session initialization with secure cookie params.
 * Call this instead of session_start() to ensure SameSite and other security settings.
 * Must be called before any output.
 */
require_once __DIR__ . '/security_headers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => request_is_https(),
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}
