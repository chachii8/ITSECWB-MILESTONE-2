<?php
/**
 * Security Configuration for Sole Source
 * Anti-brute-force and authentication settings
 */

// Debug mode:
// - true: detailed error messages with stack trace
// - false: generic error message only
if (!defined('APP_DEBUG')) {
    define('APP_DEBUG', false);
}

// Session inactivity timeout in seconds (15 minutes)
if (!defined('SESSION_TIMEOUT_SECONDS')) {
    define('SESSION_TIMEOUT_SECONDS', 900);
}

// Unique/Hidden Login URL: Admin portal uses separate file - reduces brute-force surface
define('ADMIN_LOGIN_FILE', 'login-admin.php');

// Optional URL token: when set, admin login requires ?t=TOKEN - adds obscurity
define('ADMIN_ACCESS_TOKEN', '');

// Password policy - strong passwords resist guessing/credential stuffing
define('PASSWORD_MIN_LENGTH', 12);
define('PASSWORD_MAX_LENGTH', 128);
define('PASSWORD_REQUIRE_UPPERCASE', true);
define('PASSWORD_REQUIRE_LOWERCASE', true);
define('PASSWORD_REQUIRE_NUMBER', true);
define('PASSWORD_REQUIRE_SPECIAL', true);

// MFA: second factor blocks attackers even if password is compromised
define('MFA_ISSUER', 'Sole Source');
define('MFA_REQUIRED_FOR_ADMIN', true);
define('MFA_REQUIRED_FOR_STAFF', true);
define('MFA_OPTIONAL_FOR_CUSTOMER', false);

// CAPTCHA: thwarts automated bots after failed attempts (1 = after first failure)
define('CAPTCHA_AFTER_FAILED_ATTEMPTS', 1);

// Google reCAPTCHA v2: get keys at https://www.google.com/recaptcha/admin
// Use env vars on Render; leave empty to use built-in math CAPTCHA (works offline)
define('RECAPTCHA_SITE_KEY', getenv('RECAPTCHA_SITE_KEY') ?: '6LcvgWQsAAAAAJDc_OxeOSU_nkNIOkMBJYh-Lk0E');
define('RECAPTCHA_SECRET_KEY', getenv('RECAPTCHA_SECRET_KEY') ?: '6LcvgWQsAAAAAJ0j9HfFiZ8W2b44zGslCMaS_-o0');

// Audit log: syslog integration - send logs to another system
// Set to true to use PHP syslog() (sends to local syslog daemon; daemon can forward to remote)
define('AUDIT_USE_SYSLOG', true);
// Remote syslog: set host (and optional :port) to send UDP syslog to another server
// Example: 'logserver.example.com' or '192.168.1.100:514' (default port 514)
define('AUDIT_SYSLOG_REMOTE', '');

require_once __DIR__ . '/../includes/error_handler.php';
init_app_error_handler();
