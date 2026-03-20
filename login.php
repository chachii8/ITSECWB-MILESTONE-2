<?php
// SQL connection - Customer login only. Admin/Staff use login-admin.php
$conn = mysqli_connect("localhost", "root", "") or die("Unable to connect!" . mysqli_connect_error());
mysqli_select_db($conn, "sole_source");

require_once 'includes/session_init.php';
require_once 'includes/csrf.php';
require_once 'audit_log.php';
require_once 'config/security_config.php';
require_once 'includes/captcha.php';
require_once 'includes/mfa_helper.php';

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

$login_error = '';
$max_failures = 3;      // Rate limiting: lockout after this many wrong attempts
$token_ttl_days = 14;
$show_mfa_form = false;
$timeout_logout = !empty($_SESSION['session_expired']);
if ($timeout_logout) {
    unset($_SESSION['session_expired']);
}

/** Formats lockout duration for user message (e.g. "2 hours") */
function format_lockout_remaining(DateTime $now, DateTime $lockout_until): string {
    $diff = $now->diff($lockout_until);
    $total_minutes = ($diff->days * 24 * 60) + ($diff->h * 60) + $diff->i;
    if ($total_minutes < 60) {
        return $total_minutes . " minutes";
    }
    $total_hours = intdiv($total_minutes, 60);
    if ($total_hours < 24) {
        return $total_hours . " hours";
    }
    $days = intdiv($total_hours, 24);
    return $days . " days";
}

// --- MFA: Second factor verification (blocks login even if password stolen) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['verify_mfa'])) {
    if (!validate_csrf()) {
        $login_error = "Security check failed. Please try again.";
        $show_mfa_form = true;
    } else {
    $code = $_POST['mfa_code'] ?? '';
    $pending_user_id = $_SESSION['pending_mfa_user_id'] ?? null;
    if ($pending_user_id) {
        $stmt = mysqli_prepare($conn, "SELECT um.mfa_secret FROM user_mfa um WHERE um.user_id = ? AND um.mfa_enabled = 1");
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
            unset($_SESSION['pending_mfa_user_id'], $_SESSION['pending_mfa_data'], $_SESSION['login_requires_captcha']);
            log_audit($conn, $data['user_id'], $data['role'], "LOGIN_SUCCESS_MFA", "user", $data['user_id'], null);
            header("Location: homepage.php");
            exit;
        }
        mysqli_stmt_close($stmt);
    }
    unset($_SESSION['pending_mfa_user_id'], $_SESSION['pending_mfa_data']);
    $login_error = "Invalid verification code.";
    $show_mfa_form = true;
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['login'])) {
    if (!validate_csrf()) {
        $login_error = "Security check failed. Please try again.";
    } else {
    $login_email = trim($_POST['loginEmail']);
    $login_password = $_POST['loginPassword'];
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $now = new DateTime();
    $login_failure_action = null;

    // --- CAPTCHA: Blocks bots after failed attempts ---
    if (isset($_SESSION['login_requires_captcha']) && !validate_captcha_response()) {
        $login_error = "Security check failed. Please try again.";
    } else {
    // Use database time to avoid PHP/MySQL timezone mismatches
    $stmt_now = mysqli_prepare($conn, "SELECT NOW()");
    if ($stmt_now) {
        mysqli_stmt_execute($stmt_now);
        mysqli_stmt_bind_result($stmt_now, $db_now_str);
        mysqli_stmt_fetch($stmt_now);
        mysqli_stmt_close($stmt_now);
        if (!empty($db_now_str)) {
            $now = new DateTime($db_now_str);
        }
    }

    // Rate limiting: track attempts per email+IP in login_security table
    $stmt = mysqli_prepare($conn, "
        INSERT INTO login_security (email, ip_address, last_attempt)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE last_attempt = NOW()
    ");
    mysqli_stmt_bind_param($stmt, "ss", $login_email, $ip_address);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

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

    if (!$lockout_active) {
        // Prepared statement to prevent SQL injection
        $stmt = mysqli_prepare($conn, "
            SELECT user_id, name, email, password, role, date_joined, address, login_token, token_expires_at
            FROM `sole_source`.`user`
            WHERE LOWER(TRIM(email)) = LOWER(?)
            ORDER BY user_id DESC
            LIMIT 1
        ");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "s", $login_email);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_store_result($stmt);
        } else {
            $login_error = "Login system error. Please try again.";
        }

        // Check if user exists
        if ($stmt && mysqli_stmt_num_rows($stmt) >= 1) {
            // Bind the results to variables
            mysqli_stmt_bind_result(
                $stmt,
                $user_id,
                $name,
                $email,
                $hashed_password,
                $role,
                $date_joined,
                $address,
                $login_token,
                $token_expires_at
            );
            mysqli_stmt_fetch($stmt);
            mysqli_stmt_close($stmt);
            $stmt = null;

            // Verify the password using password_verify
            if (password_verify($login_password, $hashed_password)) {
                // Rehash if algorithm/cost changed (keeps hashes up to date)
                if (password_needs_rehash($hashed_password, PASSWORD_DEFAULT)) {
                    $new_hash = password_hash($login_password, PASSWORD_DEFAULT);
                    $stmt_rehash = mysqli_prepare($conn, "UPDATE user SET password = ? WHERE user_id = ?");
                    if ($stmt_rehash) {
                        mysqli_stmt_bind_param($stmt_rehash, "si", $new_hash, $user_id);
                        mysqli_stmt_execute($stmt_rehash);
                        mysqli_stmt_close($stmt_rehash);
                    }
                }
                // Unique URL: Admin/Staff must use login-admin.php; prevents enumeration
                if ($role !== "Customer") {
                    $login_error = "Invalid credentials.";
                    $login_failure_action = "LOGIN_INVALID";
                } else {
                    $should_refresh_token = ($failed_count > 0 || $lockout_level > 0);
                    if (!empty($token_expires_at)) {
                        $token_exp_dt = new DateTime($token_expires_at);
                        if ($token_exp_dt <= $now) $should_refresh_token = true;
                    } else {
                        $should_refresh_token = true;
                    }

                    $actual_token = $login_token;
                    if ($should_refresh_token) {
                        $new_token = bin2hex(random_bytes(32));
                        $actual_token = $new_token;
                        $stmt_update = mysqli_prepare($conn, "
                            UPDATE user SET login_token = ?, token_expires_at = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE user_id = ?
                        ");
                        mysqli_stmt_bind_param($stmt_update, "sii", $new_token, $token_ttl_days, $user_id);
                        mysqli_stmt_execute($stmt_update);
                        mysqli_stmt_close($stmt_update);
                    }
                    // Rate limiting: clear lockout on success (failed_count, lockout_until)
                    $stmt_reset = mysqli_prepare($conn, "
                        UPDATE login_security
                        SET failed_count = 0, lockout_level = 0, lockout_until = NULL
                        WHERE email = ? AND ip_address = ?
                    ");
                    mysqli_stmt_bind_param($stmt_reset, "ss", $login_email, $ip_address);
                    mysqli_stmt_execute($stmt_reset);
                    mysqli_stmt_close($stmt_reset);

                    unset($_SESSION['login_requires_captcha']);
                    log_audit($conn, $user_id, $role, "LOGIN_SUCCESS", "user", $user_id, "email={$email}");

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
                            'user_id' => $user_id, 'name' => $name, 'email' => $email, 'role' => $role,
                            'date_joined' => $date_joined, 'address' => $address, 'login_token' => $actual_token
                        ];
                        $show_mfa_form = true;
                    } else {
                        $_SESSION["user_id"] = $user_id;
                        $_SESSION["fullname"] = $name;
                        $_SESSION["email"] = $email;
                        $_SESSION["role"] = $role;
                        $_SESSION["date_joined"] = $date_joined;
                        $_SESSION["address"] = $address;
                        $_SESSION["login_token"] = $actual_token;
                        header("Location: homepage.php");
                        exit;
                    }
                }
            } else {
                $login_error = "Invalid credentials.";
                $login_failure_action = "LOGIN_INVALID";
            }
        } elseif ($stmt) {
            $login_error = "No user account found.";
            $login_failure_action = "LOGIN_NO_ACCOUNT";
        }

        if ($stmt) {
            mysqli_stmt_close($stmt);
        }

        // Rate limiting: increment failed count, apply escalating lockout
        if (!empty($login_error)) {
            $new_lockout_level = $lockout_level;
            $new_failed_count = $failed_count + 1;
            $lockout_interval_sql = null;
            $lockout_message = null;

            // Escalating lockout: 3 fails -> 1h, then 3h, 24h, 1 week
            // Whitelist lockout intervals to prevent SQL injection (defense-in-depth)
            $lockout_config = [
                1 => ['sql' => '1 hour', 'message' => '1 hour'],
                2 => ['sql' => '3 hour', 'message' => '3 hours'],
                3 => ['sql' => '24 hour', 'message' => '24 hours'],
                4 => ['sql' => '168 hour', 'message' => '1 week'],
            ];
            if ($lockout_level === 0) {
                if ($new_failed_count >= $max_failures) {
                    $new_lockout_level = 1;
                    $new_failed_count = 0;
                }
            } elseif ($lockout_level === 1) {
                $new_lockout_level = 2;
                $new_failed_count = 0;
            } elseif ($lockout_level === 2) {
                $new_lockout_level = 3;
                $new_failed_count = 0;
            } else {
                $new_lockout_level = 4;
                $new_failed_count = 0;
            }
            $lockout_interval_sql = $lockout_config[$new_lockout_level]['sql'] ?? null;
            $lockout_message = $lockout_config[$new_lockout_level]['message'] ?? null;

            if ($lockout_interval_sql !== null) {
                $lockout_until_dt = (clone $now)->modify("+$lockout_interval_sql");
                $stmt_lock = mysqli_prepare($conn, "
                    UPDATE login_security
                    SET failed_count = ?, lockout_level = ?, lockout_until = DATE_ADD(NOW(), INTERVAL $lockout_interval_sql)
                    WHERE email = ? AND ip_address = ?
                ");
                mysqli_stmt_bind_param($stmt_lock, "iiss", $new_failed_count, $new_lockout_level, $login_email, $ip_address);
                mysqli_stmt_execute($stmt_lock);
                mysqli_stmt_close($stmt_lock);

                $login_error = "Too many failed attempts. Try again in " . format_lockout_remaining($now, $lockout_until_dt) . ".";
                log_audit(
                    $conn,
                    null,
                    null,
                    "LOGIN_LOCKOUT_SET",
                    "user",
                    null,
                    "email={$login_email}, level={$new_lockout_level}, duration={$lockout_message}"
                );
            } else {
                $stmt_fail = mysqli_prepare($conn, "
                    UPDATE login_security
                    SET failed_count = ?
                    WHERE email = ? AND ip_address = ?
                ");
                mysqli_stmt_bind_param($stmt_fail, "iss", $new_failed_count, $login_email, $ip_address);
                mysqli_stmt_execute($stmt_fail);
                mysqli_stmt_close($stmt_fail);
                if ($login_failure_action !== null) {
                    log_audit($conn, null, null, $login_failure_action, "user", null, "email={$login_email}");
                }
            }
        }
        $_SESSION['login_requires_captcha'] = true; // CAPTCHA: show on next attempt after failure
    }
    } // end else (CAPTCHA passed)
    } // end validate_csrf
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login | Sole Source</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="logo">Sole Source</div>

    <div class="signup-container">
        <?php if (!empty($show_mfa_form)): ?>
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
            <h1>Welcome back!</h1>
            <h2>Sign in to continue</h2>

            <?php if (!empty($login_error)): ?>
                <p style="color:red; text-align:center;"><?php echo htmlspecialchars($login_error, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>

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
                <?php /* CAPTCHA shown after 1+ failed attempts */ echo isset($_SESSION['login_requires_captcha']) ? render_captcha(1) : ''; ?>
                <button type="submit" name="login">Login</button>
            </form>
        <?php endif; ?>

        <div class="login-link">
            Don't have an account?
            <a href="signup.php">Sign up</a>
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
