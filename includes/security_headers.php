<?php
/**
 * HTTP security headers (defense in depth).
 * Include early, before any output.
 */
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
}
