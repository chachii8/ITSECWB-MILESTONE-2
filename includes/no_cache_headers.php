<?php
/**
 * Prevent caching of authenticated pages.
 * When user logs out and presses back, browser will re-request the page
 * instead of showing cached content; server will redirect to login.
 */
require_once __DIR__ . '/session_timeout.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    require_once __DIR__ . '/session_init.php';
}
enforce_session_timeout(false);

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");
?>
