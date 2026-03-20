<?php
/**
 * CSRF (Cross-Site Request Forgery) protection
 * Use csrf_field() in forms and validate_csrf() in POST handlers.
 * Requires session_start() to be called before including this file.
 */

/** Get or create CSRF token. Returns hex string. */
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Output hidden input for forms: <input type="hidden" name="csrf_token" value="..."> */
function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/** Validate CSRF token from POST. Returns true if valid. Call at start of POST handlers. */
function validate_csrf() {
    $token = $_POST['csrf_token'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';
    return !empty($expected) && hash_equals($expected, $token);
}
