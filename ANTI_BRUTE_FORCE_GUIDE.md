# Anti-Brute-Force Techniques: Rationale & Implementation Guide

This document explains **why** each anti-brute-force technique was added to Sole Source and **how** it is implemented in code.

---

## 1. Unique/Hidden Admin Login URL

### Rationale

- **What it does:** Admin and staff use a **separate login page** (`login-admin.php`) that is not linked from the public site. Optionally, that page can require a secret token in the URL (`?t=TOKEN`).
- **Why it’s necessary:** Brute-force and credential-stuffing attacks usually target the main login. If the admin login is on a different URL (and optionally behind a secret), attackers must first discover it. That reduces the attack surface and makes automated scanning less effective.

### Implementation

**Config** – `config/security_config.php`:

```php
// Unique/Hidden Login URL: Admin portal uses separate file - reduces brute-force surface
define('ADMIN_LOGIN_FILE', 'login-admin.php');

// Optional URL token: when set, admin login requires ?t=TOKEN - adds obscurity
define('ADMIN_ACCESS_TOKEN', '');
```

**Token check at top of admin login** – `login-admin.php`:

```php
// Unique URL: optional token in ?t= - blocks access without secret
if (!empty(ADMIN_ACCESS_TOKEN)) {
    $token = $_GET['t'] ?? '';
    if (!hash_equals(ADMIN_ACCESS_TOKEN, $token)) {
        header("Location: login.php");
        exit;
    }
}
```

- **What this does:** If `ADMIN_ACCESS_TOKEN` is set, the script only allows access when `?t=` matches the configured token. `hash_equals()` avoids timing leaks. Wrong or missing token sends the user to the customer login page.

**Customer login rejects admin/staff** – `login.php` (inside password-verified block):

```php
// Unique URL: Admin/Staff must use login-admin.php; prevents enumeration
if ($role !== "Customer") {
    $login_error = "Invalid credentials.";
    $login_failure_action = "LOGIN_INVALID";
} else {
    // ... proceed with customer login
}
```

- **What this does:** Even if someone uses the correct admin/staff password on `login.php`, they get “Invalid credentials” and are not logged in. Admin/staff must use `login-admin.php`, so the main site login does not reveal whether an account is admin or customer.

---

## 2. Rate Limiting with Escalating Lockout

### Rationale

- **What it does:** Tracks failed login attempts **per email + IP** in a `login_security` table. After a set number of failures (e.g. 3), the account is locked out for a period. Lockout duration **escalates** on repeated abuse (e.g. 1h → 3h → 24h → 1 week).
- **Why it’s necessary:** Pure brute force and credential stuffing rely on many attempts. Capping attempts and temporarily blocking further tries makes such attacks impractical and protects both the server and user accounts.

### Database schema

**Table** – `database/sole_source.sql`:

```sql
CREATE TABLE `login_security` (
  `id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `failed_count` int(11) NOT NULL DEFAULT 0,
  `lockout_level` int(11) NOT NULL DEFAULT 0,
  `lockout_until` datetime DEFAULT NULL,
  `last_attempt` datetime DEFAULT NULL,
  ...
  UNIQUE KEY `uniq_email_ip` (`email`,`ip_address`)
);
```

- **What this does:** One row per (email, IP). `failed_count` = failed attempts; `lockout_level` = current escalation level; `lockout_until` = when the lockout ends.

### Implementation in login flow

**1) Record every attempt** – `login.php` (and similarly in `login-admin.php`):

```php
// Rate limiting: track attempts per email+IP in login_security table
$stmt = mysqli_prepare($conn, "
    INSERT INTO login_security (email, ip_address, last_attempt)
    VALUES (?, ?, NOW())
    ON DUPLICATE KEY UPDATE last_attempt = NOW()
");
mysqli_stmt_bind_param($stmt, "ss", $login_email, $ip_address);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);
```

- **What this does:** Ensures a row exists for this email+IP and updates `last_attempt` on every login attempt (success or failure).

**2) Check if currently locked out** – `login.php`:

```php
// Rate limiting: check if currently locked out (escalating: 1h -> 3h -> 24h -> 1 week)
$stmt = mysqli_prepare($conn, "
    SELECT failed_count, lockout_level, lockout_until
    FROM login_security
    WHERE email = ? AND ip_address = ?
");
mysqli_stmt_bind_param($stmt, "ss", $login_email, $ip_address);
mysqli_stmt_execute($stmt);
mysqli_stmt_bind_result($stmt, $failed_count, $lockout_level, $lockout_until);
mysqli_stmt_fetch($stmt);
mysqli_stmt_close($stmt);

$lockout_active = false;
if (!empty($lockout_until)) {
    $lockout_until_dt = new DateTime($lockout_until);
    if ($lockout_until_dt > $now) {
        $lockout_active = true;
        $login_error = "Too many failed attempts. Try again in " . format_lockout_remaining($now, $lockout_until_dt) . ".";
        log_audit($conn, null, null, "LOGIN_BLOCKED", "user", null, "email={$login_email}");
    }
}
```

- **What this does:** If `lockout_until` is in the future, login is blocked and the user sees how long they must wait. Uses DB time for consistency.

**3) Clear lockout on success** – `login.php`:

```php
// Rate limiting: clear lockout on success (failed_count, lockout_until)
$stmt_reset = mysqli_prepare($conn, "
    UPDATE login_security
    SET failed_count = 0, lockout_level = 0, lockout_until = NULL
    WHERE email = ? AND ip_address = ?
");
mysqli_stmt_bind_param($stmt_reset, "ss", $login_email, $ip_address);
mysqli_stmt_execute($stmt_reset);
mysqli_stmt_close($stmt_reset);
```

- **What this does:** A successful login resets failure state for that email+IP.

**4) On failure: increment and apply escalating lockout** – `login.php`:

```php
$max_failures = 3;      // Rate limiting: lockout after this many wrong attempts

// ... on failed login:
$new_failed_count = $failed_count + 1;
// Escalating lockout: 3 fails -> 1h, then 3h, 24h, 1 week
if ($lockout_level === 0) {
    if ($new_failed_count >= $max_failures) {
        $new_lockout_level = 1;
        $new_failed_count = 0;
        $lockout_interval_sql = '1 hour';
        $lockout_message = '1 hour';
    }
} elseif ($lockout_level === 1) {
    $new_lockout_level = 2;
    $new_failed_count = 0;
    $lockout_interval_sql = '3 hour';
    $lockout_message = '3 hours';
} elseif ($lockout_level === 2) {
    $new_lockout_level = 3;
    $lockout_interval_sql = '24 hour';
    $lockout_message = '24 hours';
} else {
    $new_lockout_level = 4;
    $lockout_interval_sql = '168 hour';
    $lockout_message = '1 week';
}

if ($lockout_interval_sql !== null) {
    $stmt_lock = mysqli_prepare($conn, "
        UPDATE login_security
        SET failed_count = ?, lockout_level = ?, lockout_until = DATE_ADD(NOW(), INTERVAL $lockout_interval_sql)
        WHERE email = ? AND ip_address = ?
    ");
    mysqli_stmt_bind_param($stmt_lock, "iiss", $new_failed_count, $new_lockout_level, $login_email, $ip_address);
    mysqli_stmt_execute($stmt_lock);
    mysqli_stmt_close($stmt_lock);
    $login_error = "Too many failed attempts. Try again in " . format_lockout_remaining($now, $lockout_until_dt) . ".";
} else {
    $stmt_fail = mysqli_prepare($conn, "
        UPDATE login_security
        SET failed_count = ?
        WHERE email = ? AND ip_address = ?
    ");
    mysqli_stmt_bind_param($stmt_fail, "iss", $new_failed_count, $login_email, $ip_address);
    mysqli_stmt_execute($stmt_fail);
    mysqli_stmt_close($stmt_fail);
}
```

- **What this does:** Each failure increments `failed_count`. When it reaches `$max_failures` at level 0, or when already in lockout and they hit again, the code sets the next `lockout_level` and `lockout_until`. So repeat abusers get longer lockouts. If not applying a new lockout, only `failed_count` is updated.

---

## 3. CAPTCHA (After Failures or Always on Admin)

### Rationale

- **What it does:** Requires a human-style challenge (math CAPTCHA or Google reCAPTCHA v2) before the login is processed. On **customer** login it is shown **after at least one failed attempt**; on **admin** login it is **always** shown.
- **Why it’s necessary:** Bots can automate username/password tries. CAPTCHA makes automated attempts much harder. Using it after the first failure balances security and usability for customers; using it always on admin protects the high-value admin/staff accounts.

### Config

**When to show CAPTCHA** – `config/security_config.php`:

```php
// CAPTCHA: thwarts automated bots after failed attempts (1 = after first failure)
define('CAPTCHA_AFTER_FAILED_ATTEMPTS', 1);

define('RECAPTCHA_SITE_KEY', '...');
define('RECAPTCHA_SECRET_KEY', '...');
```

**Require CAPTCHA when needed** – `includes/captcha.php`:

```php
function captcha_required($failed_attempts = 0) {
    if (!empty(RECAPTCHA_SITE_KEY)) {
        return true; // Always use reCAPTCHA if configured
    }
    return $failed_attempts >= CAPTCHA_AFTER_FAILED_ATTEMPTS;
}
```

- **What this does:** If reCAPTCHA keys are set, CAPTCHA is always required where used. Otherwise, the math CAPTCHA is used when `failed_attempts >= 1` (or whatever you set).

### Validation on submit

**Customer login** – `login.php`:

```php
// --- CAPTCHA: Blocks bots after failed attempts ---
if (isset($_SESSION['login_requires_captcha']) && !validate_captcha_response()) {
    $login_error = "Security check failed. Please try again.";
} else {
    // ... proceed with login logic
}
// After any failed login:
$_SESSION['login_requires_captcha'] = true; // CAPTCHA: show on next attempt after failure
```

- **What this does:** After the first failed login, `login_requires_captcha` is set. On the next attempt, the form is only processed if `validate_captcha_response()` passes. So bots cannot keep trying without solving the CAPTCHA.

**Admin login** – `login-admin.php`:

```php
// CAPTCHA: always on admin login (high-value target for bots)
if (!validate_captcha_response()) {
    $login_error = "Security check failed. Please try again.";
} else {
    // ... login logic
}
```

- **What this does:** Every admin login requires a valid CAPTCHA before any credential check.

**Validate response** – `includes/captcha.php`:

```php
function validate_captcha_response() {
    if (!empty(RECAPTCHA_SITE_KEY) && !empty(RECAPTCHA_SECRET_KEY)) {
        $token = $_POST['g-recaptcha-response'] ?? '';
        if (empty($token)) return false;
        $post = http_build_query([
            'secret'   => RECAPTCHA_SECRET_KEY,
            'response' => $token,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''
        ]);
        // ... POST to Google reCAPTCHA api/siteverify ...
        return $data && !empty($data->success);
    }
    return verify_captcha($_POST['captcha_answer'] ?? '');
}
```

- **What this does:** With reCAPTCHA configured, it verifies the token with Google. Otherwise it checks the math CAPTCHA answer stored in session (single-use).

**Rendering in forms**

- Customer login: `<?php echo isset($_SESSION['login_requires_captcha']) ? render_captcha(1) : ''; ?>` — only after a failure.
- Admin login: `<?php echo render_captcha(1); ?>` — always.

---

## 4. Password Policy (Strong + Block Common)

### Rationale

- **What it does:** Enforces minimum length (e.g. 12), mix of character types (uppercase, lowercase, number, special), and blocks a list of **common passwords** (e.g. `password`, `admin123`).
- **Why it’s necessary:** Weak or common passwords are easy to guess or appear in breach lists used in credential stuffing. A strict policy reduces the chance that brute force or list-based attacks succeed.

### Config

**Policy settings** – `config/security_config.php`:

```php
// Password policy - strong passwords resist guessing/credential stuffing
define('PASSWORD_MIN_LENGTH', 12);
define('PASSWORD_MAX_LENGTH', 128);
define('PASSWORD_REQUIRE_UPPERCASE', true);
define('PASSWORD_REQUIRE_LOWERCASE', true);
define('PASSWORD_REQUIRE_NUMBER', true);
define('PASSWORD_REQUIRE_SPECIAL', true);
```

### Validation logic

**`includes/password_policy.php`**:

```php
function validate_password_policy($password, &$errors = []) {
    $errors = [];
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        $errors[] = "Password must be at least " . PASSWORD_MIN_LENGTH . " characters.";
    }
    if (strlen($password) > PASSWORD_MAX_LENGTH) {
        $errors[] = "Password must not exceed " . PASSWORD_MAX_LENGTH . " characters.";
    }
    if (PASSWORD_REQUIRE_UPPERCASE && !preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter.";
    }
    if (PASSWORD_REQUIRE_LOWERCASE && !preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain at least one lowercase letter.";
    }
    if (PASSWORD_REQUIRE_NUMBER && !preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number.";
    }
    if (PASSWORD_REQUIRE_SPECIAL && !preg_match('/[!@#$%^&*()_+\-=\[\]{};\':"\\|,.<>\/?`~]/', $password)) {
        $errors[] = "Password must contain at least one special character (!@#$%^&* etc.).";
    }
    $common = ['password', 'password1', 'admin', 'admin123', 'letmein', ...];
    if (in_array(strtolower($password), $common)) {
        $errors[] = "This password is too common. Please choose a stronger password.";
    }
    return empty($errors);
}
```

- **What this does:** Checks length, character classes, and disallows a fixed list of common passwords. Used at **registration** (`signup.php`) and **admin create-account** (`create-account.php`), so new passwords cannot be weak or common.

---

## 5. Multi-Factor Authentication (MFA / TOTP)

### Rationale

- **What it does:** After the correct password is entered, users with MFA enabled must enter a **time-based one-time code** (e.g. from Google Authenticator). **Admin/Staff** have MFA **required**: if they have not enrolled yet, they are redirected to a setup page to enroll before login completes. **Customers** can **optionally** enable MFA from their profile; if enabled, they must enter the code at login.
- **Why it’s necessary:** If a password is stolen or guessed, the attacker still cannot log in without the second factor. This directly mitigates the impact of brute force, credential stuffing, or phishing of passwords.

### Config

**`config/security_config.php`**:

```php
define('MFA_ISSUER', 'Sole Source');
define('MFA_REQUIRED_FOR_ADMIN', true);
define('MFA_REQUIRED_FOR_STAFF', true);
define('MFA_OPTIONAL_FOR_CUSTOMER', false);
```

### TOTP helper (`includes/mfa_helper.php`)

MFA uses **RFC 6238 TOTP** with no external libraries:

- **`generate_mfa_secret()`** – Creates a cryptographically random Base32 secret (160 bits) for new enrollment.
- **`get_totp_code($secret, $timestamp)`** – Computes the current 6-digit code for a 30-second window.
- **`verify_totp_code($secret, $code, $window = 1)`** – Verifies the user’s code; `$window = 1` allows ±30 seconds for clock drift.
- **`get_otpauth_url($secret, $email)`** – Builds the `otpauth://totp/...` URL used for QR codes (Google Authenticator, Authy, etc.).

Secrets are stored in the `user_mfa` table (`mfa_secret`, `mfa_enabled` per `user_id`).

### Customer login flow (`login.php`)

**After password is correct:**

```php
// MFA: if enabled, require 6-digit code before completing login
$mfa_stmt = mysqli_prepare($conn, "SELECT mfa_enabled FROM user_mfa WHERE user_id = ?");
mysqli_stmt_bind_param($mfa_stmt, "i", $user_id);
mysqli_stmt_execute($mfa_stmt);
mysqli_stmt_bind_result($mfa_stmt, $mfa_enabled);
$has_mfa = mysqli_stmt_fetch($mfa_stmt);
mysqli_stmt_close($mfa_stmt);

if ($has_mfa && $mfa_enabled) {
    $_SESSION['pending_mfa_user_id'] = $user_id;
    $_SESSION['pending_mfa_data'] = [
        'user_id' => $user_id, 'name' => $name, 'email' => $email, 'role' => $role, ...
    ];
    $show_mfa_form = true;
} else {
    // Set session and redirect to homepage
}
```

- **What this does:** If the customer has MFA enabled, the app does **not** set the full session yet; it shows the MFA form and stores pending login data in session. Login completes only after the TOTP code is verified. If MFA is not enabled, login completes immediately.

**Verifying the MFA code** – `login.php` (and same pattern in `login-admin.php`):

```php
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['verify_mfa'])) {
    $code = $_POST['mfa_code'] ?? '';
    $pending_user_id = $_SESSION['pending_mfa_user_id'] ?? null;
    if ($pending_user_id) {
        $stmt = mysqli_prepare($conn, "SELECT um.mfa_secret FROM user_mfa um WHERE um.user_id = ? AND um.mfa_enabled = 1");
        mysqli_stmt_bind_param($stmt, "i", $pending_user_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $mfa_secret);
        if (mysqli_stmt_fetch($stmt) && verify_totp_code($mfa_secret, $code)) {
            $data = $_SESSION['pending_mfa_data'] ?? [];
            $_SESSION["user_id"] = $data['user_id'];
            // ... set full session ...
            header("Location: homepage.php");
            exit;
        }
        mysqli_stmt_close($stmt);
    }
    $login_error = "Invalid verification code.";
    $show_mfa_form = true;
}
```

- **What this does:** The user submits the 6-digit code. The server loads the stored TOTP secret and checks it with `verify_totp_code()`. If valid, the pending login data is written to the session and the user is redirected. Otherwise they see an error and can try again.

### Admin/Staff login flow (`login-admin.php`) – required MFA and enrollment

After password is correct, Admin/Staff are handled in **three branches**:

```php
// MFA: required for Admin/Staff; redirect to setup if not enrolled
$mfa_required = ($role === 'Admin' && MFA_REQUIRED_FOR_ADMIN) || ($role === 'Staff' && MFA_REQUIRED_FOR_STAFF);
$mfa_stmt = mysqli_prepare($conn, "SELECT mfa_enabled FROM user_mfa WHERE user_id = ?");
mysqli_stmt_bind_param($mfa_stmt, "i", $user_id);
mysqli_stmt_execute($mfa_stmt);
mysqli_stmt_bind_result($mfa_stmt, $mfa_enabled);
$has_mfa = mysqli_stmt_fetch($mfa_stmt);
mysqli_stmt_close($mfa_stmt);

if ($has_mfa && $mfa_enabled) {
    // Already enrolled: show MFA verify form
    $_SESSION['pending_mfa_user_id'] = $user_id;
    $_SESSION['pending_mfa_data'] = [ ... ];
    $show_mfa_form = true;
} elseif ($mfa_required && (!$has_mfa || !$mfa_enabled)) {
    // Required but not yet enrolled: redirect to setup (first-time enrollment)
    $_SESSION['pending_mfa_user_id'] = $user_id;
    $_SESSION['pending_mfa_data'] = [ ... ];
    header("Location: mfa-setup.php");
    exit;
} else {
    // No MFA required for this role, or already completed: complete login
    $_SESSION["user_id"] = $user_id;
    // ... set full session ...
    header("Location: " . ($role == "Admin" ? "adminhomepage.php" : "staffhomepage.php"));
    exit;
}
```

- **What this does:**
  1. **Already enrolled** (`user_mfa` row with `mfa_enabled = 1`): Pending MFA data is stored and the MFA **verify** form is shown; after a valid code, login completes in the same way as customer.
  2. **MFA required but not enrolled** (no row or `mfa_enabled = 0`): User is redirected to **`mfa-setup.php`** to enroll. They cannot finish login until they complete setup.
  3. **Otherwise**: Session is set and user is redirected to the admin or staff homepage.

### MFA enrollment – Admin/Staff (`mfa-setup.php`)

Used when Admin/Staff are required to enroll but have not yet done so (arrive from `login-admin.php` with `pending_mfa_user_id` and `pending_mfa_data` in session).

**Session check:**

```php
$user_id = $_SESSION['pending_mfa_user_id'] ?? null;
$data = $_SESSION['pending_mfa_data'] ?? null;
if (!$user_id || !$data) {
    header("Location: login.php");
    exit;
}
```

**Generate secret and show QR (first load):**

```php
if (!isset($_SESSION['mfa_setup_secret'])) {
    $secret = generate_mfa_secret();
    $_SESSION['mfa_setup_secret'] = $secret;
}
$secret = $_SESSION['mfa_setup_secret'];
$otpauth_url = get_otpauth_url($secret, $data['email']);
// QR image: https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=...$otpauth_url
// Manual key shown as chunk_split($secret, 4, ' ')
```

**On submit – verify code and save:**

```php
if (isset($_POST['confirm_setup'])) {
    $code = trim($_POST['mfa_code'] ?? '');
    $secret = $_SESSION['mfa_setup_secret'] ?? null;
    if ($secret && verify_totp_code($secret, $code)) {
        $stmt = mysqli_prepare($conn, "INSERT INTO user_mfa (user_id, mfa_secret, mfa_enabled) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE mfa_secret = VALUES(mfa_secret), mfa_enabled = 1");
        mysqli_stmt_bind_param($stmt, "is", $user_id, $secret);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        unset($_SESSION['mfa_setup_secret']);
        log_audit($conn, $user_id, $data['role'], "MFA_ENABLED", "user", $user_id, null);
        $_SESSION["user_id"] = $data['user_id'];
        // ... set full session from $data ...
        unset($_SESSION['pending_mfa_user_id'], $_SESSION['pending_mfa_data']);
        header("Location: " . ($data['role'] == "Admin" ? "adminhomepage.php" : "staffhomepage.php"));
        exit;
    }
    $error = "Invalid code. Please try again.";
}
```

- **What this does:** User scans the QR (or enters the key) in an authenticator app, then enters the first 6-digit code. The server verifies it with `verify_totp_code()` and only then saves the secret to `user_mfa` and enables MFA. Then it completes the login session and redirects to the correct homepage. If the code is wrong, they can try again.

### MFA enrollment – Customers (`mfa-enable.php`)

Customers can **optionally** enable MFA from **User Profile → Security → Enable 2FA** (link to `mfa-enable.php`). They must already be logged in as Customer.

- **Access:** Only if `$_SESSION["role"] == "Customer"`; otherwise redirect to `login.php`.
- **Already enabled:** If `user_mfa` has `mfa_enabled = 1` for their `user_id`, redirect to `userprofilec.php?mfa=already_enabled`.
- **Flow:** Same as `mfa-setup.php`: generate secret, store in `$_SESSION['mfa_setup_secret']`, show QR and manual key, form posts `confirm_setup` with `mfa_code`. On valid `verify_totp_code()`, INSERT/UPDATE `user_mfa`, clear session secret, log audit, redirect to `userprofilec.php?mfa=success`.

- **What this does:** Lets customers turn on 2FA at any time from their profile; once enabled, they must enter the TOTP code on every subsequent login (enforced in `login.php` as above).

---

## Summary Table

| Technique | What it does | Why it’s necessary |
|-----------|--------------|-------------------|
| **Unique/hidden admin URL** | Separate admin login page; optional `?t=TOKEN` | Shrinks brute-force surface; attackers must find and optionally know the secret URL. |
| **Rate limiting + escalating lockout** | Track failures per email+IP; lock out with 1h → 3h → 24h → 1 week | Makes mass guessing and credential stuffing impractical. |
| **CAPTCHA** | Human challenge after failures (customer) or always (admin) | Stops automated bots from submitting login forms at scale. |
| **Password policy** | Length, complexity, block common passwords at signup/create-account | Reduces success rate of guessing and list-based attacks. |
| **MFA (TOTP)** | Second factor after password; Admin/Staff must enroll (via mfa-setup.php) if required; customers can optionally enable from profile (mfa-enable.php) | Ensures that stealing or guessing the password is not enough to log in. |

Together, these techniques make brute-force and credential-stuffing attacks much harder and less effective for your website.
