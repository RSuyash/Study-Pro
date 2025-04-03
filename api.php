<?php
// Start session BEFORE any output
session_start([
    'cookie_lifetime' => 86400, // 1 day session lifetime
    'gc_maxlifetime' => 86400,
]);

header('Content-Type: application/json');

// Include the database connection script which defines $pdo
require_once 'includes/database.php'; // $pdo is now available

// Define the path to the data directory, one level above the script's directory
// Construct path from document root - adjust 'Study-Pro-App' if needed
// Keep subject file logic for now
$dataDir = $_SERVER['DOCUMENT_ROOT'] . '/Study-Pro-App/data/';
$subjectFile = $dataDir . 'subject.json'; // Syllabus file path

// --- Helper Functions ---

// Function to read JSON file with locking
function readJsonFile($file) {
    if (!file_exists($file)) {
        // Return specific structure indicating file not found, especially for syllabus
        if (basename($file) === 'subject.json') {
             return ['not_found' => true]; // Indicate syllabus file is missing specifically
        }
        return []; // Return empty array if other data files don't exist
    }
    $fp = fopen($file, 'c+');
    if (!$fp) {
        // Log error for debugging server-side
        error_log("Could not open file for reading: " . $file);
        return ['error' => 'Could not open file for reading.', 'code' => 500];
    }

    $data = []; // Initialize data
    if (flock($fp, LOCK_SH)) { // Shared lock for reading
        $filesize = filesize($file);
        $content = $filesize > 0 ? fread($fp, $filesize) : '';
        flock($fp, LOCK_UN);

        if (!empty($content)) {
            // Remove potential UTF-8 BOM before decoding
            $bom = pack('H*','EFBBBF');
            $content_cleaned = preg_replace("/^$bom/", '', $content);

            // --- Enhanced Debug Logging ---
            // Log first 100 chars of cleaned content to check if it looks right
            error_log("Attempting to decode JSON from file: " . $file . ". Cleaned content start: " . substr($content_cleaned, 0, 100));

            $decodedData = json_decode($content_cleaned, true);
            $jsonErrorCode = json_last_error();

            if ($jsonErrorCode !== JSON_ERROR_NONE) {
                // Return error details including the raw content start for debugging
                $errorMessage = json_last_error_msg();
                error_log("JSON Decode Error Code: " . $jsonErrorCode . " - Message: " . $errorMessage . " in file: " . $file);
                // Return a specific structure indicating decode failure
                return [
                    'decode_error' => true,
                    'error_code' => $jsonErrorCode,
                    'error_message' => $errorMessage,
                    'raw_content_start' => substr($content_cleaned, 0, 200) // Send back start of content
                ];
            } else {
                 // Explicitly check if decode resulted in null, even if no error code set
                 if ($decodedData === null && $content_cleaned !== 'null') {
                      error_log("json_decode returned null despite no error code for file: " . $file);
                      return [
                          'decode_error' => true, 'error_code' => $jsonErrorCode, // Might be 0 (JSON_ERROR_NONE)
                          'error_message' => 'json_decode returned null, possibly due to invalid structure or depth limit.',
                          'raw_content_start' => substr($content_cleaned, 0, 200)
                      ];
                 } elseif (!is_array($decodedData)) {
                     // Decoded successfully but is not an array
                     error_log("Decoded JSON was not an array for file: " . $file . ". Type: " . gettype($decodedData));
                     return [
                         'type_error' => true, 'actual_type' => gettype($decodedData),
                         'raw_content_start' => substr($content_cleaned, 0, 200)
                     ];
                 } else {
                    // Success, it's an array
                    error_log("JSON decoded successfully as array for file: " . $file);
                    $data = $decodedData;
                 }
            }
        }
        // If content was empty or JSON was invalid (and reset to error), $data remains empty array or error structure
    } else {
        // Log error for debugging server-side
        error_log("Could not lock file for reading: " . $file);
        $data = ['error' => 'Could not lock file for reading.', 'code' => 500];
    }
    fclose($fp);
    return $data;
}

// Function to write JSON file with locking
function writeJsonFile($file, $data) {
    // Ensure the directory exists before trying to open the file
    $dir = dirname($file);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0775, true)) { // Create directory recursively with appropriate permissions
             error_log("Failed to create directory: " . $dir);
             return ['error' => 'Could not create data directory.', 'code' => 500];
        }
    }

    $fp = fopen($file, 'c+');
    if (!$fp) {
        error_log("Could not open file for writing: " . $file);
        return ['error' => 'Could not open file for writing.', 'code' => 500];
    }

    $result = ['error' => 'Could not lock file for writing.', 'code' => 500]; // Default error
    if (flock($fp, LOCK_EX)) { // Exclusive lock for writing
        ftruncate($fp, 0); // Truncate the file
        rewind($fp); // Move pointer to the beginning

        $jsonContent = json_encode($data, JSON_PRETTY_PRINT);
        if (json_last_error() !== JSON_ERROR_NONE) {
             error_log("Error encoding JSON: " . json_last_error_msg());
             $result = ['error' => 'Error encoding JSON data.', 'code' => 500];
        } elseif (fwrite($fp, $jsonContent) === false) {
             error_log("Failed to write to file: " . $file);
             $result = ['error' => 'Failed to write data to file.', 'code' => 500];
        } else {
             fflush($fp); // Ensure data is written
             $result = ['success' => true]; // Success
        }
        flock($fp, LOCK_UN);
    } else {
         error_log("Could not lock file for writing: " . $file);
    }
    fclose($fp);
    return $result;
}

// Function to handle errors and send JSON response
function sendError($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
}

// Function to send success JSON response
function sendSuccess($data = []) {
    echo json_encode(array_merge(['status' => 'success'], $data));
    exit;
}


// --- API Actions ---

$method = $_SERVER['REQUEST_METHOD'];
// Get action primarily from query string, fallback to POST body if needed
$action = $_GET['action'] ?? null;
$input = []; // Initialize input

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    // If action wasn't in query string for POST, check JSON body
    if ($action === null && isset($input['action'])) {
        $action = $input['action'];
    }
}

// --- Action Routing ---

switch ($action) {
    case 'register':
        if ($method !== 'POST') sendError('Invalid request method for register.', 405);
        // Input Validation
        $username = trim($input['username'] ?? '');
        $email = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';
        $confirmPassword = $input['confirmPassword'] ?? '';

        if (empty($username) || strlen($username) < 3) sendError('Username must be at least 3 characters.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) sendError('Invalid email format.');
        if (empty($password) || strlen($password) < 6) sendError('Password must be at least 6 characters.');
        if ($password !== $confirmPassword) sendError('Passwords do not match.');

        // Check if username or email already exists in the database
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :username OR email = :email");
            $stmt->execute([':username' => $username, ':email' => $email]);
            if ($stmt->fetchColumn() > 0) {
                sendError('Username or Email already registered.');
            }
        } catch (PDOException $e) {
            error_log("Database Error (Register Check): " . $e->getMessage());
            sendError('Database error during registration check.', 500);
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if ($passwordHash === false) sendError('Failed to hash password.', 500);

        // Insert the new user into the database
        try {
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, registered_at) VALUES (:username, :email, :password_hash, NOW())");
            $stmt->execute([
                ':username' => $username,
                ':email' => $email,
                ':password_hash' => $passwordHash
            ]);
        } catch (PDOException $e) {
            error_log("Database Error (Register Insert): " . $e->getMessage());
            // Check for duplicate entry error code (e.g., 23000 for SQLSTATE)
             if ($e->getCode() == 23000) {
                 sendError('Username or Email already exists.', 409); // Conflict
             } else {
                 sendError('Database error during registration.', 500);
             }
        }

        sendSuccess(['message' => 'Registration successful. Please login.']);
        break; // End register case

    case 'login':
        if ($method !== 'POST') sendError('Invalid request method for login.', 405);
        $loginIdentifier = trim($input['loginIdentifier'] ?? '');
        $password = $input['password'] ?? '';

        if (empty($loginIdentifier) || empty($password)) sendError('Username/Email and password are required.');

        // Fetch user from database by username or email
        $foundUser = null;
        try {
            $stmt = $pdo->prepare("SELECT username, email, password_hash FROM users WHERE username = :identifier OR email = :identifier");
            $stmt->execute([':identifier' => $loginIdentifier]);
            $foundUser = $stmt->fetch(PDO::FETCH_ASSOC); // Fetch associative array
        } catch (PDOException $e) {
            error_log("Database Error (Login Fetch): " . $e->getMessage());
            sendError('Database error during login.', 500);
        }

        if (!$foundUser || !isset($foundUser['password_hash'])) sendError('Invalid username/email or password.');

        if (password_verify($password, $foundUser['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['loggedin'] = true;
            $_SESSION['username'] = $foundUser['username'];
            sendSuccess(['message' => 'Login successful.', 'username' => $foundUser['username']]);
        } else {
            sendError('Invalid username/email or password.');
        }
        break; // End login case

    case 'logout':
        // Allow GET or POST for logout for simplicity, though POST is arguably better
        session_unset(); session_destroy();
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
        }
        sendSuccess(['message' => 'Logout successful.']);
        break; // End logout case

    case 'check_session':
        // Allow GET or POST
        if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true && isset($_SESSION['username'])) {
            sendSuccess(['loggedin' => true, 'username' => $_SESSION['username']]);
        } else {
            sendSuccess(['loggedin' => false]);
        }
        break; // End check_session case

    case 'get_leaderboard':
        if ($method !== 'GET') sendError('Invalid request method for get_leaderboard.', 405);
        // Fetch leaderboard from database
        $leaderboard = [];
        try {
            // Fetch top N scores, e.g., top 100, or all if needed
            $stmt = $pdo->query("SELECT username, score FROM leaderboard ORDER BY score DESC LIMIT 100");
            $leaderboard = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Database Error (Get Leaderboard): " . $e->getMessage());
            sendError('Database error fetching leaderboard.', 500);
        }
        sendSuccess(['leaderboard' => $leaderboard]);
        break; // End get_leaderboard case

    case 'update_score':
        if ($method !== 'POST') sendError('Invalid request method for update_score.', 405);
        if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !isset($_SESSION['username'])) sendError('User not logged in.', 401);

        $username = $_SESSION['username'];
        $score = $input['score'] ?? null;

        if (!is_numeric($score) || $score < 0) sendError('Invalid score provided.');
        $score = (int)$score;

        // Update or insert score in the database
        try {
            // Use INSERT ... ON DUPLICATE KEY UPDATE to handle both cases atomically
            // Assumes 'username' is a UNIQUE key in the leaderboard table
            $stmt = $pdo->prepare("
                INSERT INTO leaderboard (username, score, last_updated)
                VALUES (:username, :score, NOW())
                ON DUPLICATE KEY UPDATE
                    score = IF(VALUES(score) > score, VALUES(score), score),
                    last_updated = NOW()
            ");
            $stmt->execute([
                ':username' => $username,
                ':score' => $score
            ]);
            // Check if any row was actually changed (inserted or updated)
            // $updated = $stmt->rowCount() > 0; // rowCount is unreliable for ON DUPLICATE KEY UPDATE in some drivers/versions
            // We can assume success if no exception occurred for this logic.

        } catch (PDOException $e) {
            error_log("Database Error (Update Score): " . $e->getMessage());
            sendError('Database error updating score.', 500);
        }

        sendSuccess(['message' => 'Score processed successfully.']); // Changed message slightly
        break; // End update_score case

    case 'get_syllabus':
         if ($method !== 'GET') sendError('Invalid request method for get_syllabus.', 405);
         $syllabusData = readJsonFile($subjectFile);
         if (isset($syllabusData['not_found'])) { // Check specific key for missing file
              sendSuccess(['syllabus' => []]); // Send empty syllabus if file not found
         } elseif (isset($syllabusData['decode_error'])) {
             // Send back the specific decode error details
             sendError('Failed to decode syllabus JSON. Error: ' . $syllabusData['error_message'] . ' (Code: ' . $syllabusData['error_code'] . '). Content starts: ' . htmlspecialchars(substr($syllabusData['raw_content_start'], 0, 100)) . '...', 500);
         } elseif (isset($syllabusData['type_error'])) {
              // Send back the specific type error details
             sendError('Decoded syllabus data was not the expected array type. Got: ' . $syllabusData['actual_type'] . '. Content starts: ' . htmlspecialchars(substr($syllabusData['raw_content_start'], 0, 100)) . '...', 500);
         } elseif (isset($syllabusData['error'])) {
            // Handle other file read errors
            sendError($syllabusData['error'], $syllabusData['code']);
        } else {
            // Success - send the syllabus array
            sendSuccess(['syllabus' => $syllabusData]);
        }
         break; // End get_syllabus case

    default:
        sendError('Invalid action specified.', 404);
        break; // End default case
}

?>