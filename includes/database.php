<?php
// Function to establish database connection
function getDbConnection() {
    // Define the path to the db_config.php file, assuming it's two levels above the 'includes' directory
    $configPath = __DIR__ . '/../../db_config.php'; // Go up 2 levels from 'includes' dir
    echo "Attempting to find config at: " . realpath($configPath) . "<br>\n"; // Debug path

    // Check if the config file exists before trying to include it
    if (!file_exists($configPath)) {
        error_log("Database configuration file not found at: " . $configPath);
        throw new Exception('Server configuration error (config file not found). Please contact administrator.');
    }
    if (!is_readable($configPath)) {
         error_log("Database configuration file not readable at: " . $configPath);
         throw new Exception('Server configuration error (config file not readable). Please contact administrator.');
    }

    echo "Config file found and readable. Attempting to require: " . $configPath . "<br>\n"; // Add path here
    $config = require $configPath; // Use require to get the returned array
    echo "Config file required successfully.<br>\n";

    // Check if required keys exist in the returned array
    if (!isset($config['host']) || !isset($config['dbname']) || !isset($config['user']) || !isset($config['password'])) {
        error_log("Database configuration keys missing in array returned by: " . $configPath);
        throw new Exception('Server configuration error (missing config keys). Please contact administrator.');
    }
    echo "Config keys verified.<br>\n";

    // Assign variables from the config array
    $db_host = $config['host'];
    $db_name = $config['dbname'];
    $db_user = $config['user'];
    $db_pass = $config['password'];

    $dsn = "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Throw exceptions on errors
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Fetch associative arrays
        PDO::ATTR_EMULATE_PREPARES   => false,                  // Use native prepared statements
    ];

    echo "Attempting PDO connection...<br>\n";
    try {
        $pdo = new PDO($dsn, $db_user, $db_pass, $options);
        echo "PDO connection successful!<br>\n";
        return $pdo; // Return the connection object
    } catch (\PDOException $e) {
        error_log("Database Connection Error: " . $e->getMessage());
        // Re-throw exception to be caught by the calling script
        throw new \PDOException($e->getMessage(), (int)$e->getCode());
    }
}

// --- Code below this line is effectively removed as logic moved into the function ---
/*

// Define the path to the db_config.php file, assuming it's two levels above the 'includes' directory
*/
// Note: The original code that directly assigned $pdo is now commented out or removed.
// The calling script (migrate_data.php, api.php, etc.) MUST now call getDbConnection()
// to get the PDO object.

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