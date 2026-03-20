# Security Enhancements - Setup Guide

This document describes the anti-brute-force and authentication improvements added to Sole Source.

## 1. Database Migration

Run the MFA tables migration (if not already done):

```bash
# From project root, with MySQL in PATH:
mysql -u root sole_source < database/add_mfa_tables.sql
```

Or via phpMyAdmin: import `database/add_mfa_tables.sql` into the `sole_source` database.

## 2. Admin/Staff Login (Unique Hidden URL)

**Admin and Staff must use a separate login URL** that is not linked from the public site:

- **URL**: `login-admin.php`  
  Example: `http://localhost/itsecwb/login-admin.php`

- **Optional token protection**: In `config/security_config.php`, set `ADMIN_ACCESS_TOKEN` to a secret string. Then the URL becomes:
  `login-admin.php?t=YOUR_SECRET_TOKEN`

  Generate a token: `bin2hex(random_bytes(16))` in PHP

## 3. MFA (Multi-Factor Authentication)

- **Admin & Staff**: MFA is **required**. On first login after deployment, they will be prompted to set up 2FA using Google Authenticator, Authy, or similar.
- **Customers**: MFA is **optional**. Customers can enable it from their profile: **User Profile → Security section → Enable 2FA** (links to `mfa-enable.php`).

TOTP secrets are stored in the `user_mfa` table. The setup flow uses a QR code (requires internet for the QR image API).

## 4. Password Policies

Centralized in `config/security_config.php`:

- Minimum 12 characters
- At least one uppercase letter
- At least one lowercase letter
- At least one number
- At least one special character
- Blocked common passwords (password, admin123, etc.)

Adjust in `config/security_config.php` if needed.

## 5. CAPTCHA

- **Google reCAPTCHA v2** (recommended for production):
  1. Go to https://www.google.com/recaptcha/admin
  2. Register your site (choose reCAPTCHA v2 "I'm not a robot" Checkbox)
  3. Add your domain(s), e.g. `localhost` for XAMPP
  4. Copy the **Site Key** and **Secret Key**
  5. In `config/security_config.php`, set:
     ```php
     define('RECAPTCHA_SITE_KEY', 'your_site_key_here');
     define('RECAPTCHA_SECRET_KEY', 'your_secret_key_here');
     ```

- **Math CAPTCHA** (fallback, works offline): If both keys are empty, the built-in math CAPTCHA is used.

- CAPTCHA is used on: Admin login (always), Customer login (after 1+ failed attempts), Registration, Admin create-account.

## 6. Summary of Changes

| Feature | Location |
|---------|----------|
| Customer login | `login.php` – CAPTCHA after failed attempts, optional MFA |
| Admin/Staff login | `login-admin.php` – Always CAPTCHA, required MFA |
| Registration | `signup.php` – Password policy + CAPTCHA |
| Create account (Admin) | `create-account.php` – Password policy + CAPTCHA |
| MFA setup | `mfa-setup.php` – Used when Admin/Staff first enable MFA |

Admin and staff protected pages now redirect to `login-admin.php` when not logged in. Customer pages redirect to `login.php`.

## 7. SQL Injection Protection

**Keep using prepared statements for all new queries.**

- **Never** concatenate user input (`$_GET`, `$_POST`, `$_REQUEST`) into SQL strings.
- **Always** use `mysqli_prepare()` + `bind_param()` for any value that comes from the user or external sources.
- For dynamic SQL parts that cannot use placeholders (e.g., `ORDER BY` column, `INTERVAL` values), use whitelists only—map user input to allowed values; never concatenate raw input.
- Validation helpers: `validate_int_range()`, `sanitize_string()`, `validate_size()`, `validate_order_direction()` in `includes/input_validation.php`—use these before binding.
- Static queries (no user input) may use `mysqli_query()`; when in doubt, use prepared statements.

## 8. XSS (Cross-Site Scripting) Protection

**Escape all output that may contain user or database content.**

- **Always** use `htmlspecialchars($var, ENT_QUOTES, 'UTF-8')` when echoing user input, session data, or database content into HTML.
- For user-submitted text with newlines (e.g. reviews), use `nl2br(htmlspecialchars($var, ENT_QUOTES, 'UTF-8'))`.
- For HTML attributes and JavaScript strings, `ENT_QUOTES` is required to escape single and double quotes.
- Helper: `h($s)` in `includes/input_validation.php` wraps `htmlspecialchars` for convenience.

## 9. CSRF (Cross-Site Request Forgery) Protection

**All state-changing POST requests must validate a CSRF token.**

- **Helper**: `includes/csrf.php` provides `csrf_token()`, `csrf_field()`, and `validate_csrf()`.
- **Forms**: Add `<?php echo csrf_field(); ?>` inside every POST form.
- **POST handlers**: Call `validate_csrf()` at the start of any handler that processes POST data. If it returns false, reject the request with an error message.
- **AJAX (fetch)**: For endpoints like `set_currency.php` and `add_to_favorites.php`, include a `<meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">` in the page head, and send `csrf_token` in the POST body from JavaScript.
- **SameSite cookie**: Session cookies use `SameSite=Strict` via `includes/session_init.php`. Use `require_once 'includes/session_init.php'` instead of `session_start()` so cookie params are set before the session starts.
