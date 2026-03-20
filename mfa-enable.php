<?php
/**
 * Customer MFA Enable - Optional 2FA enrollment from profile
 * Reuses same QR/verification flow as Admin/Staff mfa-setup
 */

require_once 'includes/session_init.php';
require_once 'includes/csrf.php';
require_once 'includes/no_cache_headers.php';
$conn = mysqli_connect("localhost", "root", "") or die("Unable to connect!" . mysqli_connect_error());
mysqli_select_db($conn, "sole_source");

require_once 'config/security_config.php';
require_once 'audit_log.php';
require_once 'includes/mfa_helper.php';

// Customers only; must be logged in
if (!isset($_SESSION["role"]) || $_SESSION["role"] != "Customer") {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION["user_id"];
$email = $_SESSION["email"] ?? '';

// Build data for otpauth (same structure as Admin/Staff flow for QR)
$data = [
    'user_id' => $user_id,
    'name' => $_SESSION["fullname"],
    'email' => $email,
    'role' => 'Customer',
    'date_joined' => $_SESSION["date_joined"] ?? '',
    'address' => $_SESSION["address"] ?? '',
    'login_token' => $_SESSION["login_token"] ?? ''
];

// Check if already enabled - show status and back link
$stmt_check = mysqli_prepare($conn, "SELECT mfa_enabled FROM user_mfa WHERE user_id = ?");
mysqli_stmt_bind_param($stmt_check, "i", $user_id);
mysqli_stmt_execute($stmt_check);
mysqli_stmt_bind_result($stmt_check, $mfa_enabled);
$has_mfa = mysqli_stmt_fetch($stmt_check);
mysqli_stmt_close($stmt_check);

if ($has_mfa && $mfa_enabled) {
    header("Location: userprofilec.php?mfa=already_enabled");
    exit;
}

$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['confirm_setup'])) {
    if (!validate_csrf()) {
        $error = "Security check failed. Please try again.";
    } else {
    $code = trim($_POST['mfa_code'] ?? '');
    $secret = $_SESSION['mfa_setup_secret'] ?? null;

    if (!$secret) {
        $error = "Session expired. Please try again.";
    } elseif (verify_totp_code($secret, $code)) {
        $stmt = mysqli_prepare($conn, "INSERT INTO user_mfa (user_id, mfa_secret, mfa_enabled) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE mfa_secret = VALUES(mfa_secret), mfa_enabled = 1");
        mysqli_stmt_bind_param($stmt, "is", $user_id, $secret);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        unset($_SESSION['mfa_setup_secret']);
        log_audit($conn, $user_id, 'Customer', "MFA_ENABLED", "user", $user_id, null);
        header("Location: userprofilec.php?mfa=success");
        exit;
    } else {
        $error = "Invalid code. Please try again.";
    }
    }
}

if (!isset($_SESSION['mfa_setup_secret'])) {
    $secret = generate_mfa_secret();
    $_SESSION['mfa_setup_secret'] = $secret;
}
$secret = $_SESSION['mfa_setup_secret'];
$otpauth_url = get_otpauth_url($secret, $email);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Enable Two-Factor Authentication | Sole Source</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="logo">Sole Source</div>
    <div class="signup-container">
        <h1>Enable Two-Factor Authentication</h1>
        <h2>Add an extra layer of security. Scan the QR code with Google Authenticator, Authy, or similar app.</h2>
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
            <a href="userprofilec.php">← Back to Profile</a>
        </div>
    </div>
<script src="js/no-back-cache.js"></script>
</body>
</html>
