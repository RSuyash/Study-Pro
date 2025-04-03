<?php
// submit_score.php - Handles score submission and updates the leaderboard

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate'); // Prevent caching

// Include the database connection script which defines $pdo
require_once 'includes/database.php'; // $pdo is now available
// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['error' => 'Invalid request method. Only POST is allowed.']);
    exit;
}

// Get the raw POST data
$rawData = file_get_contents('php://input');
$data = json_decode($rawData, true);

// Validate input data
if (!$data || !isset($data['username']) || !isset($data['score'])) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'Invalid or missing username/score in request body.']);
    exit;
}

$username = trim($data['username']);
$score = filter_var($data['score'], FILTER_VALIDATE_INT);

// Further validation
if (empty($username) || $score === false || $score < 0) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'Invalid username or score value.']);
    exit;
}

// --- Database Interaction ---

try {
    // Use INSERT ... ON DUPLICATE KEY UPDATE to handle both cases atomically
    // Assumes 'username' is a UNIQUE key in the leaderboard table
    // This query inserts a new record or updates the score if the new score is higher
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

    // Check if a row was affected (inserted or updated score was higher)
    // Note: rowCount might return 1 for insert, 1 or 2 for update depending on server/driver,
    // or 0 if the score wasn't higher and no update occurred.
    // We'll just report success if no exception.
    // $rowsAffected = $stmt->rowCount();

    echo json_encode(['success' => true, 'message' => 'Score processed successfully.']);

} catch (PDOException $e) {
    error_log("Database Error (Submit Score): " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error processing score. Please try again later.']);
    exit;
}

?>