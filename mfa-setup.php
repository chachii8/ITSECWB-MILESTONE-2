<?php
/**
 * MFA Setup - Enroll in Two-Factor Authentication
 * Required for Admin/Staff on first login after MFA requirement
 */

require_once 'includes/session_init.php';
require_once 'includes/csrf.php';
require_once 'includes/no_cache_headers.php';
require_once 'includes/db.php';

require_once 'config/security_config.php';
require_once 'audit_log.php';
require_once 'includes/mfa_helper.php';

// MFA setup: user arrived from login-admin after password verified
$user_id = $_SESSION['pending_mfa_user_id'] ?? null;
$data = $_SESSION['pending_mfa_data'] ?? null;

if (!$user_id || !$data) {
    header("Location: login.php");
    exit;
}

$error = '';
$step = 'scan';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
        if (isset($_POST['confirm_setup'])) {
        if (!validate_csrf()) {
            $error = "Security check failed. Please try again.";
        } else {
        // Verify user can generate correct TOTP before saving to DB
        $code = trim($_POST['mfa_code'] ?? '');
        $secret = $_SESSION['mfa_setup_secret'] ?? null;
        if (!$secret) {
            $error = "Session expired. Please log in again.";
        } elseif (verify_totp_code($secret, $code)) {
            // Store TOTP secret; MFA now required for this user on future logins
            $stmt = mysqli_prepare($conn, "INSERT INTO user_mfa (user_id, mfa_secret, mfa_enabled) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE mfa_secret = VALUES(mfa_secret), mfa_enabled = 1");
            mysqli_stmt_bind_param($stmt, "is", $user_id, $secret);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            unset($_SESSION['mfa_setup_secret']);
            log_audit($conn, $user_id, $data['role'], "MFA_ENABLED", "user", $user_id, null);
            $_SESSION["user_id"] = $data['user_id'];
            $_SESSION["fullname"] = $data['name'];
            $_SESSION["email"] = $data['email'];
            $_SESSION["role"] = $data['role'];
            $_SESSION["date_joined"] = $data['date_joined'];
            $_SESSION["address"] = $data['address'];
            $_SESSION["login_token"] = $data['login_token'];
            unset($_SESSION['pending_mfa_user_id'], $_SESSION['pending_mfa_data']);
            if ($data['role'] == "Admin") {
                header("Location: adminhomepage.php");
            } else {
                header("Location: staffhomepage.php");
            }
            exit;
        } else {
            $error = "Invalid code. Please try again.";
        }
        }
    }
}

if (!isset($_SESSION['mfa_setup_secret'])) {
    // Generate new TOTP secret per enrollment session
    $secret = generate_mfa_secret();
    $_SESSION['mfa_setup_secret'] = $secret;
}
$secret = $_SESSION['mfa_setup_secret'];
$otpauth_url = get_otpauth_url($secret, $data['email']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Setup Two-Factor Authentication | Sole Source</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="logo">Sole Source</div>
    <div class="signup-container">
        <h1>Setup Two-Factor Authentication</h1>
        <h2>Scan the QR code with Google Authenticator, Authy, or similar app</h2>
        <?php if (!empty($error)): ?><p style="color:red; text-align:center;"><?php echo htmlspecialchars($error); ?></p><?php endif; ?>
        <div style="text-align:center; margin:20px 0;">
            <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?php echo urlencode($otpauth_url); ?>" alt="QR Code" width="200" height="200" />
        </div>
        <p style="font-size:12px; text-align:center; margin-bottom:15px;">Or enter this key manually: <code><?php echo chunk_split($secret, 4, ' '); ?></code></p>
        <form class="signup-form" action="" method="post">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <p>Enter the 6-digit code from your app</p>
                <input type="text" name="mfa_code" maxlength="6" pattern="[0-9]{6}" placeholder="000000" required autocomplete="one-time-code">
            </div>
            <button type="submit" name="confirm_setup">Complete Setup</button>
        </form>
        <div class="login-link">
            <a href="logout.php">Cancel and logout</a>
        </div>
    </div>
<script src="js/no-back-cache.js"></script>
</body>
</html>
