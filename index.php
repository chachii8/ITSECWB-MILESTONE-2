<?php
require_once __DIR__ . '/includes/security_headers.php';
// Redirect to login - customers use login.php, admin/staff use login-admin.php
header('Location: login.php');
exit;
