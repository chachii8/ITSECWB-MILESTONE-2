<?php
/**
 * Centralized input validation helpers
 * SQL injection protection: use whitelists for ORDER BY, identifiers, etc.
 */

/** Validate ORDER BY direction - returns 'ASC' or 'DESC' only. Prevents SQL injection. */
function validate_order_direction($value) {
    $v = strtolower(trim((string)$value));
    return ($v === 'asc') ? 'ASC' : 'DESC';
}

/** Validate sort option against whitelist. Returns mapped SQL fragment or default. */
function validate_sort_option($value, array $allowed_map, $default) {
    return $allowed_map[$value] ?? $default;
}

/** Validate price: min, max, 2 decimal places. Returns validated float or false. */
function validate_price($value, $min = 0, $max = 999999.99) {
    if (!is_numeric($value)) return false;
    $v = round((float)$value, 2);
    if ($v < $min || $v > $max) return false;
    return $v;
}

/** Validate positive integer within range. */
function validate_int_range($value, $min, $max) {
    $v = filter_var($value, FILTER_VALIDATE_INT);
    if ($v === false || $v < $min || $v > $max) return false;
    return $v;
}

/** Validate order status. */
function validate_order_status($status) {
    return in_array($status, ['Pending', 'Shipped', 'Delivered', 'Cancelled'], true);
}

/** Check if order can be updated (not Delivered or Cancelled). */
function can_update_order_status($conn, $order_id) {
    $stmt = mysqli_prepare($conn, "SELECT order_status FROM `order` WHERE order_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $order_id);
    mysqli_stmt_execute($stmt);
    $r = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($r);
    mysqli_stmt_close($stmt);
    if (!$row) return false;
    return !in_array($row['order_status'], ['Delivered', 'Cancelled'], true);
}

/** Escape for HTML output - prevents XSS. Use for all user/DB content in HTML. */
function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** Sanitize string with max length. */
function sanitize_string($value, $max_len = 255) {
    $s = trim((string)$value);
    if (strlen($s) > $max_len) $s = substr($s, 0, $max_len);
    return $s;
}

/** Validate size format (e.g. 6, 6.5, 7). */
function validate_size($value) {
    if (!is_numeric($value)) return false;
    $v = (float)$value;
    if ($v < 1 || $v > 20) return false;
    return $v;
}
