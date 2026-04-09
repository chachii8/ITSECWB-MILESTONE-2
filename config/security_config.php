<?php
/**
 * Security Configuration for Sole Source
 * Anti-brute-force and authentication settings
 */

// Optional local overrides (see local_security_config.php.example). Not for production commits.
if (is_file(__DIR__ . '/local_security_config.php')) {
    require __DIR__ . '/local_security_config.php';
}

// Debug mode:
// - true: detailed error messages with stack trace
// - false: generic error message only
// Toggle: env APP_DEBUG=true|false, or define in local_security_config.php, or edit below.
if (!defined('APP_DEBUG')) {
    $ad = getenv('APP_DEBUG');
    if ($ad === false || $ad === '') {
        define('APP_DEBUG', false);
    } else {
        $lv = strtolower(trim((string) $ad));
        define('APP_DEBUG', in_array($lv, ['1', 'true', 'yes', 'on'], true));
    }
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

// Audit log — works on shared hosting: file + DB always; syslog is optional.
// Env overrides (Render/production): AUDIT_USE_SYSLOG=true, AUDIT_SYSLOG_REMOTE=host:514, AUDIT_LOG_DIR=/path
if (!defined('AUDIT_USE_SYSLOG')) {
    $v = getenv('AUDIT_USE_SYSLOG');
    if ($v === false || $v === '') {
        define('AUDIT_USE_SYSLOG', false);
    } else {
        $lv = strtolower(trim((string) $v));
        define('AUDIT_USE_SYSLOG', in_array($lv, ['1', 'true', 'yes', 'on'], true));
    }
}
if (!defined('AUDIT_SYSLOG_REMOTE')) {
    define('AUDIT_SYSLOG_REMOTE', getenv('AUDIT_SYSLOG_REMOTE') !== false ? trim((string) getenv('AUDIT_SYSLOG_REMOTE')) : '');
}
if (!defined('AUDIT_LOG_DIR')) {
    $d = getenv('AUDIT_LOG_DIR');
    define('AUDIT_LOG_DIR', ($d !== false && $d !== '') ? trim((string) $d) : '');
}

require_once __DIR__ . '/../includes/error_handler.php';
init_app_error_handler();
