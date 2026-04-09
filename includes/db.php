<?php
/**
 * Database connection - uses environment variables on Render, localhost defaults for local dev.
 *
 * Cloud MySQL (e.g. Aiven): set DB_SSL=true and DB_SSL_CA_PATH to the CA .pem from the provider.
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

/**
 * Database/schema name for SQL identifiers (matches DB_NAME / Aiven database).
 * Whitelist-only; use when you need `dbname`.`table` explicitly.
 */
function db_database_name(): string {
    $d = getenv('DB_NAME');
    if ($d !== false && $d !== '') {
        $d = trim((string) $d);
        if (preg_match('/^[a-zA-Z0-9_]+$/', $d)) {
            return $d;
        }
    }
    return 'sole_source';
}

function db_env_flag_enabled($name) {
    $v = getenv($name);
    if ($v === false || $v === '') {
        return false;
    }
    return in_array(strtolower(trim((string) $v)), ['1', 'true', 'yes', 'on'], true);
}

function get_db_connection() {
    $c = get_db_config();
    if (!db_env_flag_enabled('DB_SSL')) {
        $conn = @mysqli_connect($c['host'], $c['user'], $c['password'], $c['database'], $c['port']);
        return $conn ?: null;
    }

    $mysqli = mysqli_init();
    if (!$mysqli) {
        return null;
    }

    $ca = getenv('DB_SSL_CA_PATH');
    $ca = ($ca !== false && $ca !== '' && is_readable($ca)) ? $ca : null;
    mysqli_ssl_set($mysqli, null, null, $ca, null, null);

    if (defined('MYSQLI_OPT_SSL_VERIFY_SERVER_CERT')) {
        @mysqli_options($mysqli, MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, $ca !== null);
    }

    $flags = defined('MYSQLI_CLIENT_SSL') ? MYSQLI_CLIENT_SSL : 0;
    if (!@mysqli_real_connect($mysqli, $c['host'], $c['user'], $c['password'], $c['database'], $c['port'], null, $flags)) {
        return null;
    }
    return $mysqli;
}

// Main connection for the request
$conn = get_db_connection();
if (!$conn) {
    die('Unable to connect to database. ' . (defined('APP_DEBUG') && APP_DEBUG ? mysqli_connect_error() : ''));
}
