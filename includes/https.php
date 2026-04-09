<?php
/**
 * Detect whether the client request was made over HTTPS.
 * TLS is terminated by Apache/nginx/Render/etc.; PHP only reads server variables.
 *
 * For reverse proxies (e.g. Render), set env TRUST_PROXY_HEADERS=1 so
 * HTTP_X_FORWARDED_PROTO=https is honored for session cookie flags.
 */
function request_is_https() {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443') {
        return true;
    }
    $trust = getenv('TRUST_PROXY_HEADERS');
    if ($trust !== false && $trust !== '' && strtolower(trim((string) $trust)) !== '0' && strtolower(trim((string) $trust)) !== 'false') {
        $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        if (strtolower((string) $proto) === 'https') {
            return true;
        }
    }
    return false;
}
