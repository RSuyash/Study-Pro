<?php

// Define the path to the db_config.php file, assuming it's two levels above the 'includes' directory
$configPath = __DIR__ . '/../../../../db_config.php'; // Go up 4 levels from 'includes' dir to reach alongside public_html

// Check if the config file exists before trying to include it
if (!file_exists($configPath)) {
    // Log error or handle appropriately - cannot proceed without config
    // For now, we'll just die, but in a real app, more robust error handling is needed
    error_log("Database configuration file not found at: " . $configPath);
    http_response_code(500); // Internal Server Error
    echo json_encode(['error' => 'Server configuration error. Please contact administrator.']);
    exit; // Stop script execution
}

// Include the database configuration
// This file should define $db_host, $db_name, $db_user, $db_pass
require_once $configPath;

// Check if required variables are defined after including the file
if (!isset($db_host) || !isset($db_name) || !isset($db_user) || !isset($db_pass)) {
    error_log("Database configuration variables missing in: " . $configPath);
    http_response_code(500);
    echo json_encode(['error' => 'Server configuration error (missing variables). Please contact administrator.']);
    exit;
}

$dsn = "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Throw exceptions on errors
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Fetch associative arrays
    PDO::ATTR_EMULATE_PREPARES   => false,                  // Use native prepared statements
];

$pdo = null; // Initialize $pdo to null

try {
    $pdo = new PDO($dsn, $db_user, $db_pass, $options);
} catch (\PDOException $e) {
    // Log the detailed error message for the administrator
    error_log("Database Connection Error: " . $e->getMessage());

    // Provide a generic error message to the client
    http_response_code(500); // Internal Server Error
    // Avoid echoing detailed PDOException message to the user for security
    echo json_encode(['error' => 'Database connection failed. Please try again later or contact administrator.']);
    exit; // Stop script execution
}

// The $pdo object is now available for use in scripts that include this file.
// Optionally, you could wrap this in a function:
/*
function getDbConnection() {
    // ... (include config, define DSN, options) ...
    static $pdo = null; // Use static variable to maintain connection across calls within the same request
    if ($pdo === null) {
        try {
            $pdo = new PDO($dsn, $db_user, $db_pass, $options);
        } catch (\PDOException $e) {
            error_log("Database Connection Error: " . $e->getMessage());
            // Handle error appropriately, maybe throw an exception or return false
            throw new \PDOException($e->getMessage(), (int)$e->getCode());
        }
    }
    return $pdo;
}
// Then call $pdo = getDbConnection(); in other scripts.
// For simplicity now, we'll just make $pdo available directly.
*/

?>