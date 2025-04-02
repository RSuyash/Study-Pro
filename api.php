<?php
// Start session BEFORE any output
session_start([
    'cookie_lifetime' => 86400, // 1 day session lifetime
    'gc_maxlifetime' => 86400,
]);

header('Content-Type: application/json');

$leaderboardFile = 'leaderboard.json';
$usersFile = 'users.json'; // New file for user data

// --- Helper Functions ---

// Function to read JSON file with locking (modified to be generic)
function readJsonFile($file) {
    if (!file_exists($file)) {
        return []; // Return empty array if file doesn't exist
    }
    $fp = fopen($file, 'c+'); // Open for read/write, create if not exists
    if (!$fp) {
        return ['error' => 'Could not open file for reading.', 'code' => 500];
    }

    if (flock($fp, LOCK_SH)) { // Shared lock for reading
        $filesize = filesize($file);
        $content = $filesize > 0 ? fread($fp, $filesize) : '';
        flock($fp, LOCK_UN);
        fclose($fp);

        if (empty($content)) {
            return []; // Return empty array for empty file
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['error' => 'Error decoding JSON: ' . json_last_error_msg(), 'code' => 500];
        }
        return is_array($data) ? $data : [];
    } else {
        fclose($fp);
        return ['error' => 'Could not lock file for reading.', 'code' => 500];
    }
}

// Function to write JSON file with locking (modified to be generic)
function writeJsonFile($file, $data) {
    $fp = fopen($file, 'c+'); // Open for read/write, create if not exists, pointer at beginning
     if (!$fp) {
        return ['error' => 'Could not open file for writing.', 'code' => 500];
    }

    if (flock($fp, LOCK_EX)) { // Exclusive lock for writing
        ftruncate($fp, 0); // Truncate the file
        rewind($fp); // Move pointer to the beginning

        $jsonContent = json_encode($data, JSON_PRETTY_PRINT);
        if (json_last_error() !== JSON_ERROR_NONE) {
             flock($fp, LOCK_UN);
             fclose($fp);
             return ['error' => 'Error encoding JSON: ' . json_last_error_msg(), 'code' => 500];
        }

        fwrite($fp, $jsonContent);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return ['success' => true];
    } else {
        fclose($fp);
        return ['error' => 'Could not lock file for writing.', 'code' => 500];
    }
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
$action = $_REQUEST['action'] ?? null; // Primarily check GET/POST form data
$input = []; // Initialize input

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    // If action wasn't in URL/form data for POST, check JSON body
    if ($action === null && isset($input['action'])) {
        $action = $input['action'];
    }
}
// Now $action should have the correct action name from either source
// and $input contains the JSON body for POST requests
// --- User Management Actions ---

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'register') {
    // Input Validation
    $username = trim($input['username'] ?? '');
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';
    $confirmPassword = $input['confirmPassword'] ?? '';

    if (empty($username) || strlen($username) < 3) sendError('Username must be at least 3 characters.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) sendError('Invalid email format.');
    if (empty($password) || strlen($password) < 6) sendError('Password must be at least 6 characters.');
    if ($password !== $confirmPassword) sendError('Passwords do not match.');

    // Check if user or email exists
    $users = readJsonFile($usersFile);
    if (isset($users['error'])) sendError($users['error'], $users['code']);

    foreach ($users as $user) {
        if (strcasecmp($user['username'], $username) === 0) sendError('Username already taken.');
        if (strcasecmp($user['email'], $email) === 0) sendError('Email already registered.');
    }

    // Hash password
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    if ($passwordHash === false) sendError('Failed to hash password.', 500);

    // Add new user
    $newUser = [
        'username' => $username,
        'email' => $email,
        'password_hash' => $passwordHash,
        'registered_at' => date('c') // ISO 8601 date
    ];
    $users[] = $newUser;

    // Write updated users file
    $writeResult = writeJsonFile($usersFile, $users);
    if (isset($writeResult['error'])) sendError($writeResult['error'], $writeResult['code']);

    sendSuccess(['message' => 'Registration successful. Please login.']);

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'login') {
    $loginIdentifier = trim($input['loginIdentifier'] ?? ''); // Can be username or email
    $password = $input['password'] ?? '';

    if (empty($loginIdentifier) || empty($password)) sendError('Username/Email and password are required.');

    $users = readJsonFile($usersFile);
    if (isset($users['error'])) sendError($users['error'], $users['code']);

    $foundUser = null;
    foreach ($users as $user) {
        if (strcasecmp($user['username'], $loginIdentifier) === 0 || strcasecmp($user['email'], $loginIdentifier) === 0) {
            $foundUser = $user;
            break;
        }
    }

    if (!$foundUser) sendError('Invalid username/email or password.');

    // Verify password
    if (password_verify($password, $foundUser['password_hash'])) {
        // Regenerate session ID on login for security
        session_regenerate_id(true);
        $_SESSION['loggedin'] = true;
        $_SESSION['username'] = $foundUser['username']; // Store username in session
        sendSuccess(['message' => 'Login successful.', 'username' => $foundUser['username']]);
    } else {
        sendError('Invalid username/email or password.');
    }

} elseif ($action === 'logout') { // Can be GET or POST
    session_unset();     // Unset $_SESSION variables
    session_destroy();   // Destroy the session
    // Clear session cookie (optional but good practice)
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    sendSuccess(['message' => 'Logout successful.']);

} elseif ($action === 'check_session') { // Can be GET or POST
    if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true && isset($_SESSION['username'])) {
        sendSuccess(['loggedin' => true, 'username' => $_SESSION['username']]);
    } else {
        sendSuccess(['loggedin' => false]);
    }

// --- Leaderboard Actions ---

} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get_leaderboard') {
    $leaderboard = readJsonFile($leaderboardFile);
    if (isset($leaderboard['error'])) sendError($leaderboard['error'], $leaderboard['code']);

    // Ensure sorting by score before sending
    usort($leaderboard, function($a, $b) {
        return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
    });
    sendSuccess(['leaderboard' => $leaderboard]);

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_score') {
    // Check if user is logged in
    if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !isset($_SESSION['username'])) {
        sendError('User not logged in.', 401); // Unauthorized
    }

    $username = $_SESSION['username']; // Get username from session
    $score = $input['score'] ?? null; // Get score from POST data

    // Validate score
    if (!is_numeric($score) || $score < 0) {
        sendError('Invalid score provided.');
    }
    $score = (int)$score; // Ensure score is integer

    $leaderboard = readJsonFile($leaderboardFile);
    if (isset($leaderboard['error'])) sendError($leaderboard['error'], $leaderboard['code']);

    $userFound = false;
    foreach ($leaderboard as $key => $entry) {
        // Use case-insensitive comparison for username matching
        if (isset($entry['username']) && strcasecmp($entry['username'], $username) === 0) {
            $leaderboard[$key]['score'] = $score; // Update score
            $userFound = true;
            break;
        }
    }

    if (!$userFound) {
        // Add new user to leaderboard
        $leaderboard[] = ['username' => $username, 'score' => $score];
    }

    // Sort leaderboard by score descending before writing
    usort($leaderboard, function($a, $b) {
        return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
    });

    // Assign ranks (optional, can be done on frontend too)
    // $rank = 1;
    // foreach ($leaderboard as $key => $entry) {
    //     $leaderboard[$key]['rank'] = $rank++;
    // }

    // Write updated leaderboard file
    $writeResult = writeJsonFile($leaderboardFile, $leaderboard);
    if (isset($writeResult['error'])) sendError($writeResult['error'], $writeResult['code']);

    sendSuccess(['message' => 'Score updated successfully.']);

} else {
    sendError('Invalid action or request method.', 404);
}

?>