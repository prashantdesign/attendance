<?php
// Database configuration
$host = "localhost"; 
$username = "u876416965_root0411"; 
$password = "Pr@mukh@7425"; 
$dbname = "u876416965_root0411"; 
$charset = "utf8mb4";

// --- Create PDO Connection ---
$dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Throw exceptions on errors
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,     // Fetch as associative arrays
    PDO::ATTR_EMULATE_PREPARES   => false,                // Use real prepared statements
];

try {
     $pdo = new PDO($dsn, $username, $password, $options);
} catch (\PDOException $e) {
    // Send a 500 Internal Server Error response
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database connection failed: ' . $e->getMessage()
    ]);
    die();
}
// IMPORTANT: NO CLOSING PHP TAG HERE TO PREVENT WHITESPACE ERRORS