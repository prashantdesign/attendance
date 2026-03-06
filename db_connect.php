<?php
// db_connect.php - FINAL VERSION for Hostinger

// NOTE: We do NOT set the header('Content-Type: application/json') here.
// The caller script (api.php or admin_api.php) is responsible for setting the content type,
// as a successful include should not set headers or exit early.

// --- DATABASE CREDENTIALS for HOSTINGER ---
// The server name is almost always 'localhost' on shared hosting
$servername = "localhost";

// These values are from your Hostinger screenshot
$username = "u876416965_root";
$password = "Psm@@2022"; 
$dbname = "u876416965_attendance";

// --- ERROR REPORTING & CONNECTION ---
// Temporarily disable standard error reporting to create a custom JSON response
// We move this *after* connection attempt so the error is only caught if it fails.
mysqli_report(MYSQLI_REPORT_OFF);

// Attempt to connect
$db = new mysqli($servername, $username, $password, $dbname);

// Check for connection errors manually
if ($db->connect_error) {
    // If connection fails, send a detailed JSON error and stop everything.
    // This will break the calling script (admin_api.php) but at least it sends JSON.
    // The preferred fix is that admin_api.php's ob_start() should catch this.
    
    // We clear the output buffer if possible before sending error data
    if (ob_get_length() > 0) {
        ob_clean();
    }
    
    header("HTTP/1.1 500 Internal Server Error");
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false, 
        'message' => 'Database Connection Error: ' . $db->connect_error,
        'data' => null
    ]);
    exit;
}

// Set character set to avoid encoding issues
$db->set_charset("utf8mb4");

?>