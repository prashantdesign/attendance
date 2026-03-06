<?php
// db_connect.php - resilient connection setup

// The caller script (api.php/admin_api.php) controls response content type.

$servername = "localhost";
$username = "u876416965_root";
$password = "Psm@@2022";
$dbname = "u876416965_attendance";

mysqli_report(MYSQLI_REPORT_OFF);

// Use explicit init/options so connection attempts do not hang for too long.
$connect_timeout_seconds = 8;
$read_timeout_seconds = 20;

$db_init = mysqli_init();
if ($db_init === false) {
    if (ob_get_length() > 0) {
        ob_clean();
    }
    header("HTTP/1.1 500 Internal Server Error");
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Database initialization failed.',
        'data' => null
    ]);
    exit;
}

// Keep network/database waits bounded to avoid occasional request freezes.
mysqli_options($db_init, MYSQLI_OPT_CONNECT_TIMEOUT, $connect_timeout_seconds);
if (defined('MYSQLI_OPT_READ_TIMEOUT')) {
    mysqli_options($db_init, MYSQLI_OPT_READ_TIMEOUT, $read_timeout_seconds);
}

$connected = @mysqli_real_connect($db_init, $servername, $username, $password, $dbname);
if (!$connected) {
    if (ob_get_length() > 0) {
        ob_clean();
    }

    header("HTTP/1.1 500 Internal Server Error");
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Database Connection Error: ' . mysqli_connect_error(),
        'data' => null
    ]);
    exit;
}

$db = $db_init;
$db->set_charset("utf8mb4");

// Conservative session-level settings to reduce long waits/locks.
$db->query("SET SESSION wait_timeout = 60");
$db->query("SET SESSION interactive_timeout = 60");
$db->query("SET SESSION innodb_lock_wait_timeout = 10");

// Helper can be used by APIs before critical work if needed.
if (!function_exists('ensure_db_connection')) {
    function ensure_db_connection($db) {
        if ($db && @mysqli_ping($db)) {
            return true;
        }
        return false;
    }
}
