<?php
require_once 'includes/session_init.php';

// Prevent caching so back button doesn't show logged-in pages
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

session_unset();
session_destroy();

header("Location: login.php");
exit();
?>
