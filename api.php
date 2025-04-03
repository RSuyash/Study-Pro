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
// Note: readJsonFile and writeJsonFile functions are removed as they are no longer needed for core data.
// They could be kept if needed for other potential JSON config files in the future.

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


    case 'get_user_progress':
        // Fetch progress for the logged-in user
        if ($method !== 'GET') sendError('Invalid request method for get_user_progress.', 405);
        if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !isset($_SESSION['username'])) sendError('User not logged in.', 401);

        $username = $_SESSION['username'];
        $progressData = [];
        try {
            // Get user_id first
            $userStmt = $pdo->prepare("SELECT user_id FROM USERS WHERE username = :username");
            $userStmt->execute([':username' => $username]);
            $user = $userStmt->fetch();

            if (!$user) {
                 sendError('User not found.', 404); // Should not happen if session is valid
            }
            $userId = $user['user_id'];

            // Fetch all progress entries for this user
            $stmt = $pdo->prepare("SELECT topic_id, status FROM USER_PROGRESS WHERE user_id = :user_id");
            $stmt->execute([':user_id' => $userId]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Format as { topic_id: status } for the frontend
            foreach ($results as $row) {
                $progressData[$row['topic_id']] = $row['status'];
            }

        } catch (PDOException $e) {
            error_log("Database Error (Get User Progress): " . $e->getMessage());
            sendError('Database error fetching user progress.', 500);
        }
        sendSuccess(['progress' => $progressData]);
        break; // End get_user_progress case

    case 'update_topic_status':
        // Update a single topic's status and recalculate/update leaderboard score
        if ($method !== 'POST') sendError('Invalid request method for update_topic_status.', 405);
        if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !isset($_SESSION['username'])) sendError('User not logged in.', 401);

        $username = $_SESSION['username'];
        $topicId = $input['topicId'] ?? null;
        $newStatus = $input['status'] ?? null;

        // Basic validation (add more robust status validation if needed)
        $allowedStatuses = ['not_started', 'reviewing', 'practicing', 'confident', 'mastered']; // Use statusOrder from JS if possible
        if ($topicId === null || $newStatus === null || !in_array($newStatus, $allowedStatuses)) {
             sendError('Missing or invalid topicId or status.', 400);
        }

        try {
            // Get user_id
            $userStmt = $pdo->prepare("SELECT user_id FROM USERS WHERE username = :username");
            $userStmt->execute([':username' => $username]);
            $user = $userStmt->fetch();
            if (!$user) sendError('User not found.', 404);
            $userId = $user['user_id'];

            // Check if topic exists (optional but good practice)
            $topicStmt = $pdo->prepare("SELECT COUNT(*) FROM TOPICS WHERE topic_id = :topic_id");
            $topicStmt->execute([':topic_id' => $topicId]);
            if ($topicStmt->fetchColumn() == 0) sendError('Invalid topicId.', 400);

            // Update or Insert the progress status
            $stmt = $pdo->prepare("
                INSERT INTO USER_PROGRESS (user_id, topic_id, status, completed_at, last_accessed)
                VALUES (:user_id, :topic_id, :status, :completed_at, NOW())
                ON DUPLICATE KEY UPDATE
                    status = VALUES(status),
                    completed_at = VALUES(completed_at),
                    last_accessed = NOW()
            ");
            // Use status values from JS statusLevels map
            $completedAt = ($newStatus === 'mastered') ? date('Y-m-d H:i:s') : null; // Set completion time only if status is 'mastered'
            $stmt->execute([
                ':user_id' => $userId,
                ':topic_id' => $topicId,
                ':status' => $newStatus,
                ':completed_at' => $completedAt
            ]);

            // --- Recalculate and Update Leaderboard Score ---
            // Define status scores (should match JS statusLevels values)
            $statusScores = [
                 'not_started' => 0,
                 'reviewing' => 2,
                 'practicing' => 5,
                 'confident' => 8,
                 'mastered' => 10
             ];

            // Fetch all current statuses for the user
            $progressStmt = $pdo->prepare("SELECT status FROM USER_PROGRESS WHERE user_id = :user_id");
            $progressStmt->execute([':user_id' => $userId]);
            $allStatuses = $progressStmt->fetchAll(PDO::FETCH_COLUMN);

            // Calculate total score
            $totalScore = 0;
            foreach ($allStatuses as $status) {
                $totalScore += $statusScores[$status] ?? 0;
            }

            // Update leaderboard
            $lbStmt = $pdo->prepare("
                INSERT INTO LEADERBOARD (user_id, total_score, last_updated)
                VALUES (:user_id, :score, NOW())
                ON DUPLICATE KEY UPDATE
                    total_score = VALUES(total_score),
                    last_updated = NOW()
            ");
             // Note: This leaderboard update assumes overall score. Subject-specific would need subject_id.
             // It overwrites the score. If only higher scores should count, logic needs adjustment.
            $lbStmt->execute([
                ':user_id' => $userId,
                ':score' => $totalScore
            ]);

        } catch (PDOException $e) {
            error_log("Database Error (Update Topic Status): " . $e->getMessage());
            sendError('Database error updating topic status.', 500);
        }

        sendSuccess(['message' => 'Topic status updated successfully.']);
        break; // End update_topic_status case

    case 'get_syllabus':
        if ($method !== 'GET') sendError('Invalid request method for get_syllabus.', 405);

        $syllabusStructure = [];
        try {
            // Fetch all subjects
            $subjectStmt = $pdo->query("SELECT subject_id, subject_name, description FROM SUBJECTS ORDER BY subject_id"); // Add ordering if needed
            $subjects = $subjectStmt->fetchAll(PDO::FETCH_ASSOC);

            // Prepare statements for units and topics
            $unitStmt = $pdo->prepare("SELECT unit_id, unit_name FROM UNITS WHERE subject_id = :subject_id ORDER BY order_index, unit_id");
            $topicStmt = $pdo->prepare("SELECT topic_id, topic_name FROM TOPICS WHERE unit_id = :unit_id ORDER BY order_index, topic_id"); // Simplified for now

            foreach ($subjects as $subject) {
                $subjectData = [
                    'subjectId' => $subject['subject_id'], // Match frontend expected key? Check script.js if needed
                    'subjectName' => $subject['subject_name'],
                    'units' => []
                ];

                // Fetch units for this subject
                $unitStmt->execute([':subject_id' => $subject['subject_id']]);
                $units = $unitStmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($units as $unit) {
                    $unitData = [
                        'unitId' => $unit['unit_id'], // Match frontend expected key?
                        'unitName' => $unit['unit_name'],
                        'topics' => []
                    ];

                    // Fetch topics for this unit
                    $topicStmt->execute([':unit_id' => $unit['unit_id']]);
                    $topics = $topicStmt->fetchAll(PDO::FETCH_ASSOC);

                    // Basic topic structure - doesn't handle subTopics from JSON directly yet
                    // If subTopics need to be represented, the DB schema and this query need adjustment
                    foreach ($topics as $topic) {
                         $unitData['topics'][] = [
                             'topicId' => $topic['topic_id'], // Match frontend expected key?
                             'topicName' => $topic['topic_name']
                             // Add other fields like content_url etc. if needed by frontend
                         ];
                    }
                    $subjectData['units'][] = $unitData;
                }
                $syllabusStructure[] = $subjectData;
            }

            sendSuccess(['syllabus' => $syllabusStructure]);

        } catch (PDOException $e) {
            error_log("Database Error (Get Syllabus): " . $e->getMessage());
            sendError('Database error fetching syllabus.', 500);
        }
        break; // End get_syllabus case

    default:
        sendError('Invalid action specified.', 404);
        break; // End default case
}

?>