<?php
require_once 'includes/session_init.php';
require_once 'includes/session_timeout.php';
require_once 'includes/csrf.php';
enforce_session_timeout(true);

// Require POST and valid CSRF token
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo 'invalid';
    exit;
}
$token = $_POST['csrf_token'] ?? '';
if (empty($token) || !validate_csrf()) {
    echo 'invalid';
    exit;
}

if (isset($_POST['currency']) && in_array($_POST['currency'], ['PHP', 'USD', 'KRW'])) {
    $_SESSION['currency'] = $_POST['currency'];
    echo 'success';
} else {
    echo 'invalid';
}
?>
