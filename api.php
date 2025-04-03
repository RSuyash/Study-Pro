<?php
// Start session BEFORE any output
session_start([
    'cookie_lifetime' => 86400, // 1 day session lifetime
    'gc_maxlifetime' => 86400,
]);

header('Content-Type: application/json');

// Define the path to the data directory, one level above the script's directory
$dataDir = __DIR__ . '/../data/';
$leaderboardFile = $dataDir . 'leaderboard.json';
$usersFile = $dataDir . 'users.json';
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
            $decodedData = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                // Log error for debugging server-side
                error_log("Error decoding JSON: " . json_last_error_msg() . " in file: " . $file);
                $data = ['error' => 'Error decoding JSON data.', 'code' => 500];
            } else {
                 $data = is_array($decodedData) ? $decodedData : [];
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

        $users = readJsonFile($usersFile);
        if (isset($users['error'])) sendError($users['error'], $users['code']);

        foreach ($users as $user) {
            if (isset($user['username']) && strcasecmp($user['username'], $username) === 0) sendError('Username already taken.');
            if (isset($user['email']) && strcasecmp($user['email'], $email) === 0) sendError('Email already registered.');
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if ($passwordHash === false) sendError('Failed to hash password.', 500);

        $newUser = [
            'username' => $username, 'email' => $email,
            'password_hash' => $passwordHash, 'registered_at' => date('c')
        ];
        $users[] = $newUser;

        $writeResult = writeJsonFile($usersFile, $users);
        if (isset($writeResult['error'])) sendError($writeResult['error'], $writeResult['code']);

        sendSuccess(['message' => 'Registration successful. Please login.']);
        break; // End register case

    case 'login':
        if ($method !== 'POST') sendError('Invalid request method for login.', 405);
        $loginIdentifier = trim($input['loginIdentifier'] ?? '');
        $password = $input['password'] ?? '';

        if (empty($loginIdentifier) || empty($password)) sendError('Username/Email and password are required.');

        $users = readJsonFile($usersFile);
        if (isset($users['error'])) sendError($users['error'], $users['code']);

        $foundUser = null;
        foreach ($users as $user) {
            if ((isset($user['username']) && strcasecmp($user['username'], $loginIdentifier) === 0) || (isset($user['email']) && strcasecmp($user['email'], $loginIdentifier) === 0)) {
                $foundUser = $user; break;
            }
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
        $leaderboard = readJsonFile($leaderboardFile);
        if (isset($leaderboard['error'])) sendError($leaderboard['error'], $leaderboard['code']);
        usort($leaderboard, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
        sendSuccess(['leaderboard' => $leaderboard]);
        break; // End get_leaderboard case

    case 'update_score':
        if ($method !== 'POST') sendError('Invalid request method for update_score.', 405);
        if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !isset($_SESSION['username'])) sendError('User not logged in.', 401);

        $username = $_SESSION['username'];
        $score = $input['score'] ?? null;

        if (!is_numeric($score) || $score < 0) sendError('Invalid score provided.');
        $score = (int)$score;

        $leaderboard = readJsonFile($leaderboardFile);
        if (isset($leaderboard['error'])) sendError($leaderboard['error'], $leaderboard['code']);

        $userFound = false; $updated = false;
        foreach ($leaderboard as $key => $entry) {
            if (isset($entry['username']) && strcasecmp($entry['username'], $username) === 0) {
                if (!isset($entry['score']) || $score > $entry['score']) {
                     $leaderboard[$key]['score'] = $score; $updated = true;
                }
                $userFound = true; break;
            }
        }

        if (!$userFound) {
            $leaderboard[] = ['username' => $username, 'score' => $score]; $updated = true;
        }

        // Only write if data actually changed
        if ($updated) {
            usort($leaderboard, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
            $writeResult = writeJsonFile($leaderboardFile, $leaderboard);
            if (isset($writeResult['error'])) sendError($writeResult['error'], $writeResult['code']);
        }

        sendSuccess(['message' => 'Score processed successfully.']); // Changed message slightly
        break; // End update_score case

    case 'get_syllabus':
         if ($method !== 'GET') sendError('Invalid request method for get_syllabus.', 405);
         $syllabusData = readJsonFile($subjectFile);
         if (isset($syllabusData['not_found'])) { // Check specific key for missing file
              sendSuccess(['syllabus' => []]); // Send empty syllabus if file not found
         } elseif (isset($syllabusData['error'])) {
             sendError($syllabusData['error'], $syllabusData['code']);
         } else {
             sendSuccess(['syllabus' => $syllabusData]);
         }
         break; // End get_syllabus case

    default:
        sendError('Invalid action specified.', 404);
        break; // End default case
}

?>