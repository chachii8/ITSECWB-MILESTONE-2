<?php
/**
 * Database connection - uses environment variables on Render, localhost defaults for local dev.
 */
function get_db_config(): array {
    return [
        'host' => getenv('DB_HOST') ?: 'localhost',
        'user' => getenv('DB_USER') ?: 'root',
        'password' => getenv('DB_PASSWORD') ?: '',
        'database' => getenv('DB_NAME') ?: 'sole_source',
        'port' => (int)(getenv('DB_PORT') ?: 3306),
    ];
}

function get_db_connection() {
    $c = get_db_config();
    $conn = @mysqli_connect($c['host'], $c['user'], $c['password'], $c['database'], $c['port']);
    if (!$conn) {
        return null;
    }
    return $conn;
}

// Main connection for the request
$conn = get_db_connection();
if (!$conn) {
    die('Unable to connect to database. ' . (defined('APP_DEBUG') && APP_DEBUG ? mysqli_connect_error() : ''));
}
