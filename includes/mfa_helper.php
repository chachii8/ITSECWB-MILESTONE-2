<?php
/**
 * TOTP-based MFA (Google Authenticator compatible)
 * No external dependencies - uses PHP built-ins
 */

require_once __DIR__ . '/../config/security_config.php';

// Base32 for TOTP secret (RFC 3548) - used by authenticator apps
function base32_decode($input) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $input = strtoupper(str_replace(' ', '', $input));
    $output = '';
    $v = 0;
    $vbits = 0;
    for ($i = 0; $i < strlen($input); $i++) {
        $c = $input[$i];
        $pos = strpos($alphabet, $c);
        if ($pos === false) continue;
        $v = ($v << 5) | $pos;
        $vbits += 5;
        if ($vbits >= 8) {
            $vbits -= 8;
            $output .= chr(($v >> $vbits) & 255);
        }
    }
    return $output;
}

function base32_encode($input) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $output = '';
    $v = 0;
    $vbits = 0;
    for ($i = 0; $i < strlen($input); $i++) {
        $v = ($v << 8) | ord($input[$i]);
        $vbits += 8;
        while ($vbits >= 5) {
            $vbits -= 5;
            $output .= $alphabet[($v >> $vbits) & 31];
        }
    }
    if ($vbits > 0) {
        $output .= $alphabet[($v << (5 - $vbits)) & 31];
    }
    return $output;
}

/** Generates cryptographically random TOTP secret for new MFA enrollment */
function generate_mfa_secret() {
    $secret = random_bytes(20); // 160 bits for TOTP
    return base32_encode($secret);
}

/** Computes current 6-digit TOTP code (30-second window, RFC 6238) */
function get_totp_code($secret, $timestamp = null) {
    if ($timestamp === null) {
        $timestamp = time();
    }
    $time_slice = floor($timestamp / 30);
    $secret_binary = base32_decode($secret);
    $time_packed = pack('N*', 0) . pack('N*', $time_slice);
    $hash = hash_hmac('sha1', $time_packed, $secret_binary, true);
    $offset = ord(substr($hash, -1)) & 0x0F;
    $code = (
        ((ord($hash[$offset]) & 0x7F) << 24) |
        ((ord($hash[$offset + 1]) & 0xFF) << 16) |
        ((ord($hash[$offset + 2]) & 0xFF) << 8) |
        (ord($hash[$offset + 3]) & 0xFF)
    ) % 1000000;
    return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
}

/** Verifies user's TOTP code; window=1 allows ±30s clock drift */
function verify_totp_code($secret, $code, $window = 1) {
    $code = preg_replace('/\s/', '', $code);
    if (strlen($code) !== 6 || !ctype_digit($code)) {
        return false;
    }
    $timestamp = time();
    for ($i = -$window; $i <= $window; $i++) {
        $check = get_totp_code($secret, $timestamp + ($i * 30));
        if (hash_equals($check, $code)) {
            return true;
        }
    }
    return false;
}

/** Builds otpauth:// URL for QR code - used by Google Authenticator etc. */
function get_otpauth_url($secret, $email, $issuer = null) {
    if ($issuer === null) {
        $issuer = MFA_ISSUER;
    }
    $label = rawurlencode($issuer . ':' . $email);
    $issuer_enc = rawurlencode($issuer);
    return "otpauth://totp/{$label}?secret={$secret}&issuer={$issuer_enc}&algorithm=SHA1&digits=6&period=30";
}
