<?php
/**
 * Admin/Staff Login - Unique/Hidden URL
 * Not linked from the public site. Use this URL for Admin and Staff access.
 * Optional: Add ?t=YOUR_SECRET if ADMIN_ACCESS_TOKEN is configured.
 */

require_once 'includes/session_init.php';
require_once 'includes/csrf.php';
require_once 'includes/db.php';

require_once 'config/security_config.php';
require_once 'audit_log.php';
require_once 'includes/captcha.php';
require_once 'includes/mfa_helper.php';

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

// Unique URL: optional token in ?t= - blocks access without secret
if (!empty(ADMIN_ACCESS_TOKEN)) {
    $token = $_GET['t'] ?? '';
    if (!hash_equals(ADMIN_ACCESS_TOKEN, $token)) {
        header("Location: login.php");
        exit;
    }
}

$login_error = '';
$max_failures = 3;
$token_ttl_days = 14;
$is_admin_login = true;
$timeout_logout = !empty($_SESSION['session_expired']);
if ($timeout_logout) {
    unset($_SESSION['session_expired']);
}

function format_lockout_remaining_admin(DateTime $now, DateTime $lockout_until): string {
    $diff = $now->diff($lockout_until);
    $total_minutes = ($diff->days * 24 * 60) + ($diff->h * 60) + $diff->i;
    if ($total_minutes < 60) return $total_minutes . " minutes";
    $total_hours = intdiv($total_minutes, 60);
    if ($total_hours < 24) return $total_hours . " hours";
    return intdiv($total_hours, 24) . " days";
}

// --- MFA: Verify 6-digit code before completing Admin/Staff login ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['verify_mfa'])) {
    if (!validate_csrf()) {
        $login_error = "Security check failed. Please try again.";
    } else {
    $code = $_POST['mfa_code'] ?? '';
    $pending_user_id = $_SESSION['pending_mfa_user_id'] ?? null;
    if (!$pending_user_id) {
        unset($_SESSION['pending_mfa_user_id'], $_SESSION['pending_mfa_data']);
        header("Location: login-admin.php" . (!empty(ADMIN_ACCESS_TOKEN) ? '?t=' . urlencode(ADMIN_ACCESS_TOKEN) : ''));
        exit;
    }
    $stmt = mysqli_prepare($conn, "SELECT um.mfa_secret FROM user_mfa um WHERE um.user_id = ? AND um.mfa_enabled = 1");
    if (!$stmt) {
        $login_error = "Security check failed. Please try again.";
    } else {
        mysqli_stmt_bind_param($stmt, "i", $pending_user_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $mfa_secret);
        if (mysqli_stmt_fetch($stmt) && verify_totp_code($mfa_secret, $code)) {
            $data = $_SESSION['pending_mfa_data'] ?? [];
            mysqli_stmt_close($stmt);
            $_SESSION["user_id"] = $data['user_id'];
            $_SESSION["fullname"] = $data['name'];
            $_SESSION["email"] = $data['email'];
            $_SESSION["role"] = $data['role'];
            $_SESSION["date_joined"] = $data['date_joined'];
            $_SESSION["address"] = $data['address'];
            $_SESSION["login_token"] = $data['login_token'];
            unset($_SESSION['pending_mfa_user_id'], $_SESSION['pending_mfa_data']);
            log_audit($conn, $data['user_id'], $data['role'], "LOGIN_SUCCESS_MFA", "user", $data['user_id'], null);
            if ($data['role'] == "Admin") {
                header("Location: adminhomepage.php");
            } else {
                header("Location: staffhomepage.php");
            }
            exit;
        }
        mysqli_stmt_close($stmt);
        $login_error = "Invalid verification code.";
    }
    }
}

// Main login form
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['login'])) {
    if (!validate_csrf()) {
        $login_error = "Security check failed. Please try again.";
    } else {
    $login_email = trim($_POST['loginEmail']);
    $login_password = $_POST['loginPassword'];
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $now = new DateTime();
    $login_failure_action = null;
    $failed_count = 0;
    $lockout_level = 0;
    $lockout_until = null;
    $db_schema = db_database_name();

    $stmt_now = mysqli_prepare($conn, "SELECT NOW()");
    if ($stmt_now) {
        mysqli_stmt_execute($stmt_now);
        mysqli_stmt_bind_result($stmt_now, $db_now_str);
        mysqli_stmt_fetch($stmt_now);
        mysqli_stmt_close($stmt_now);
        if (!empty($db_now_str)) $now = new DateTime($db_now_str);
    }

    // CAPTCHA: always on admin login (high-value target for bots)
    if (!validate_captcha_response()) {
        $login_error = "Security check failed. Please try again.";
    } else {
        // Rate limiting: track attempts per email+IP
        $stmt = mysqli_prepare($conn, "INSERT INTO login_security (email, ip_address, last_attempt) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE last_attempt = NOW()");
        if (!$stmt) {
            $login_error = "Login system error. Please try again.";
        } else {
            mysqli_stmt_bind_param($stmt, "ss", $login_email, $ip_address);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            $stmt = mysqli_prepare($conn, "SELECT failed_count, lockout_level, lockout_until FROM login_security WHERE email = ? AND ip_address = ?");
            if (!$stmt) {
                $login_error = "Login system error. Please try again.";
            } else {
                mysqli_stmt_bind_param($stmt, "ss", $login_email, $ip_address);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_bind_result($stmt, $failed_count, $lockout_level, $lockout_until);
                if (!mysqli_stmt_fetch($stmt)) {
                    $failed_count = 0;
                    $lockout_level = 0;
                    $lockout_until = null;
                }
                mysqli_stmt_close($stmt);
            }
        }

        // Rate limiting: escalating lockout (1h -> 3h -> 24h -> 1 week)
        $lockout_active = false;
        if (empty($login_error) && !empty($lockout_until)) {
            $lockout_until_dt = new DateTime($lockout_until);
            if ($lockout_until_dt > $now) {
                $lockout_active = true;
                $login_error = "Too many failed attempts. Try again in " . format_lockout_remaining_admin($now, $lockout_until_dt) . ".";
                log_audit($conn, null, null, "LOGIN_BLOCKED_ADMIN", "user", null, "email={$login_email}");
            }
        }

        if (empty($login_error) && !$lockout_active) {
            $stmt = mysqli_prepare($conn, "SELECT user_id, name, email, password, role, date_joined, address, login_token, token_expires_at FROM `{$db_schema}`.`user` WHERE LOWER(TRIM(email)) = LOWER(?) LIMIT 1");
            if (!$stmt) {
                $login_error = "Login system error. Please try again.";
            } else {
            mysqli_stmt_bind_param($stmt, "s", $login_email);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_store_result($stmt);

            if ($stmt && mysqli_stmt_num_rows($stmt) >= 1) {
                mysqli_stmt_bind_result($stmt, $user_id, $name, $email, $hashed_password, $role, $date_joined, $address, $login_token, $token_expires_at);
                mysqli_stmt_fetch($stmt);
                mysqli_stmt_close($stmt);
                $stmt = null;

                // Unique URL: reject customers here (they use login.php)
                if ($role !== 'Admin' && $role !== 'Staff') {
                    $login_error = "Access denied. Use the standard login for customer accounts.";
                    log_audit($conn, null, null, "LOGIN_DENIED_CUSTOMER_ON_ADMIN", "user", null, "email={$login_email}");
                } elseif (password_verify($login_password, $hashed_password)) {
                    // Rehash if algorithm/cost changed (keeps hashes up to date)
                    if (password_needs_rehash($hashed_password, PASSWORD_DEFAULT)) {
                        $new_hash = password_hash($login_password, PASSWORD_DEFAULT);
                        $stmt_rehash = mysqli_prepare($conn, "UPDATE `{$db_schema}`.`user` SET password = ? WHERE user_id = ?");
                        if ($stmt_rehash) {
                            mysqli_stmt_bind_param($stmt_rehash, "si", $new_hash, $user_id);
                            mysqli_stmt_execute($stmt_rehash);
                            mysqli_stmt_close($stmt_rehash);
                        }
                    }
                    $should_refresh_token = ($failed_count > 0 || $lockout_level > 0);
                    if (!empty($token_expires_at)) {
                        $token_exp_dt = new DateTime($token_expires_at);
                        if ($token_exp_dt <= $now) $should_refresh_token = true;
                    } else {
                        $should_refresh_token = true;
                    }
                    $new_token = $login_token;
                    if ($should_refresh_token) {
                        $new_token = bin2hex(random_bytes(32));
                        $stmt_up = mysqli_prepare($conn, "UPDATE `{$db_schema}`.`user` SET login_token = ?, token_expires_at = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE user_id = ?");
                        mysqli_stmt_bind_param($stmt_up, "sii", $new_token, $token_ttl_days, $user_id);
                        mysqli_stmt_execute($stmt_up);
                        mysqli_stmt_close($stmt_up);
                    }

                    $stmt_reset = mysqli_prepare($conn, "UPDATE login_security SET failed_count = 0, lockout_level = 0, lockout_until = NULL WHERE email = ? AND ip_address = ?");
                    mysqli_stmt_bind_param($stmt_reset, "ss", $login_email, $ip_address);
                    mysqli_stmt_execute($stmt_reset);
                    mysqli_stmt_close($stmt_reset);

                    // MFA: required for Admin/Staff; redirect to setup if not enrolled
                    $mfa_required = ($role === 'Admin' && MFA_REQUIRED_FOR_ADMIN) || ($role === 'Staff' && MFA_REQUIRED_FOR_STAFF);
                    $mfa_stmt = mysqli_prepare($conn, "SELECT mfa_enabled FROM user_mfa WHERE user_id = ?");
                    $has_mfa = false;
                    $mfa_enabled = 0;
                    if ($mfa_stmt) {
                        mysqli_stmt_bind_param($mfa_stmt, "i", $user_id);
                        mysqli_stmt_execute($mfa_stmt);
                        mysqli_stmt_bind_result($mfa_stmt, $mfa_enabled);
                        $has_mfa = mysqli_stmt_fetch($mfa_stmt);
                        mysqli_stmt_close($mfa_stmt);
                    }

                    if ($has_mfa && $mfa_enabled) {
                        $_SESSION['pending_mfa_user_id'] = $user_id;
                        $_SESSION['pending_mfa_data'] = [
                            'user_id' => $user_id, 'name' => $name, 'email' => $email, 'role' => $role,
                            'date_joined' => $date_joined, 'address' => $address, 'login_token' => $new_token
                        ];
                        $show_mfa_form = true;
                    } elseif ($mfa_required && (!$has_mfa || !$mfa_enabled)) {
                        $_SESSION['pending_mfa_user_id'] = $user_id;
                        $_SESSION['pending_mfa_data'] = [
                            'user_id' => $user_id, 'name' => $name, 'email' => $email, 'role' => $role,
                            'date_joined' => $date_joined, 'address' => $address, 'login_token' => $new_token
                        ];
                        header("Location: mfa-setup.php");
                        exit;
                    } else {
                        $_SESSION["user_id"] = $user_id;
                        $_SESSION["fullname"] = $name;
                        $_SESSION["email"] = $email;
                        $_SESSION["role"] = $role;
                        $_SESSION["date_joined"] = $date_joined;
                        $_SESSION["address"] = $address;
                        $_SESSION["login_token"] = $new_token;
                        log_audit($conn, $user_id, $role, "LOGIN_SUCCESS_ADMIN", "user", $user_id, null);
                        header("Location: " . ($role == "Admin" ? "adminhomepage.php" : "staffhomepage.php"));
                        exit;
                    }
                } else {
                    $login_error = "Invalid credentials.";
                    $login_failure_action = "LOGIN_INVALID";
                }
            } else {
                mysqli_stmt_close($stmt);
                $login_error = "No admin or staff account found.";
                $login_failure_action = "LOGIN_NO_ACCOUNT";
            }

            // Rate limiting: escalate lockout level on repeated failures
            if (!empty($login_error) && isset($login_failure_action)) {
                $new_lockout_level = $lockout_level;
                $new_failed_count = $failed_count + 1;
                $lockout_interval_sql = null;

                // Escalate only when failures within the current level reach the threshold
                // Whitelist lockout intervals to prevent SQL injection (defense-in-depth)
                $lockout_intervals = [1 => '1 hour', 2 => '3 hour', 3 => '24 hour', 4 => '168 hour'];
                if ($lockout_level === 0 && $new_failed_count >= $max_failures) {
                    $new_lockout_level = 1;
                    $new_failed_count = 0;
                } elseif ($lockout_level === 1 && $new_failed_count >= $max_failures) {
                    $new_lockout_level = 2;
                    $new_failed_count = 0;
                } elseif ($lockout_level === 2 && $new_failed_count >= $max_failures) {
                    $new_lockout_level = 3;
                    $new_failed_count = 0;
                } elseif ($lockout_level >= 3 && $new_failed_count >= $max_failures) {
                    $new_lockout_level = 4;
                    $new_failed_count = 0;
                }
                $lockout_interval_sql = $lockout_intervals[$new_lockout_level] ?? null;

                if ($lockout_interval_sql !== null) {
                    // Escalate and set a new lockout window (interval from whitelist only)
                    $lockout_until_dt = (clone $now)->modify("+$lockout_interval_sql");
                    $stmt_lock = mysqli_prepare($conn, "UPDATE login_security SET failed_count = ?, lockout_level = ?, lockout_until = DATE_ADD(NOW(), INTERVAL $lockout_interval_sql) WHERE email = ? AND ip_address = ?");
                    if ($stmt_lock) {
                        mysqli_stmt_bind_param($stmt_lock, "iiss", $new_failed_count, $new_lockout_level, $login_email, $ip_address);
                        mysqli_stmt_execute($stmt_lock);
                        mysqli_stmt_close($stmt_lock);
                    }
                    $login_error = "Too many failed attempts. Try again in " . format_lockout_remaining_admin($now, $lockout_until_dt) . ".";
                } else {
                    // Just record the failed attempt count for this level
                    $stmt_fail = mysqli_prepare($conn, "UPDATE login_security SET failed_count = ? WHERE email = ? AND ip_address = ?");
                    if ($stmt_fail) {
                        mysqli_stmt_bind_param($stmt_fail, "iss", $new_failed_count, $login_email, $ip_address);
                        mysqli_stmt_execute($stmt_fail);
                        mysqli_stmt_close($stmt_fail);
                    }
                }

                log_audit($conn, null, null, $login_failure_action . "_ADMIN", "user", null, "email={$login_email}");
            }
            }
        }
    }
    } // end validate_csrf
}

$show_mfa_form = $show_mfa_form ?? false;
$admin_url_suffix = (!empty(ADMIN_ACCESS_TOKEN)) ? '?t=' . urlencode(ADMIN_ACCESS_TOKEN) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Login | Sole Source</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="logo">Sole Source</div>
    <div class="signup-container">
        <?php if ($show_mfa_form): ?>
            <h1>Two-Factor Authentication</h1>
            <h2>Enter the 6-digit code from your authenticator app</h2>
            <?php if (!empty($login_error)): ?><p style="color:red; text-align:center;"><?php echo htmlspecialchars($login_error, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
            <form class="signup-form" action="" method="post">
                <?php echo csrf_field(); ?>
                <div class="form-group">
                    <p>Verification Code</p>
                    <input type="text" name="mfa_code" maxlength="6" pattern="[0-9]{6}" placeholder="000000" required autocomplete="one-time-code">
                </div>
                <button type="submit" name="verify_mfa">Verify</button>
            </form>
        <?php else: ?>
            <h1>Staff Portal</h1>
            <h2>Sign in with your admin or staff account</h2>
            <?php if (!empty($login_error)): ?><p style="color:red; text-align:center;"><?php echo htmlspecialchars($login_error, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
            <form class="signup-form" action="" method="post" autocomplete="off">
                <?php echo csrf_field(); ?>
                <div class="form-group">
                    <p>Email</p>
                    <input type="email" name="loginEmail" required autocomplete="off">
                </div>
                <div class="form-group">
                    <p>Password</p>
                    <input type="password" name="loginPassword" required autocomplete="new-password">
                </div>
                <?php /* CAPTCHA always shown on admin login */ echo render_captcha(1); ?>
                <button type="submit" name="login">Login</button>
            </form>
        <?php endif; ?>
        <div class="login-link">
            <a href="login.php">← Back to main login</a>
        </div>
    </div>
    <?php if ($timeout_logout): ?>
    <script>
        (function () {
            var email = document.querySelector('input[name="loginEmail"]');
            var password = document.querySelector('input[name="loginPassword"]');
            if (email) email.value = '';
            if (password) password.value = '';
        })();
    </script>
    <?php endif; ?>
</body>
</html>
