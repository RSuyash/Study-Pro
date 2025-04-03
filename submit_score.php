<?php
// submit_score.php - Handles score submission and updates the leaderboard

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate'); // Prevent caching

$dataDir = __DIR__ . '/../data/'; // Define data directory relative to this script
$leaderboardFile = $dataDir . 'leaderboard.json';

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

// --- File Locking and Processing ---
$fileHandle = fopen($leaderboardFile, 'c+'); // Open for read/write, create if not exists

if (!$fileHandle) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not open leaderboard file for writing.']);
    exit;
}

// Acquire exclusive lock (blocking)
if (flock($fileHandle, LOCK_EX)) {
    // Read existing data
    fseek($fileHandle, 0); // Go to the beginning
    $jsonContent = stream_get_contents($fileHandle);
    $leaderboard = [];

    if (trim($jsonContent) !== '') {
        $leaderboard = json_decode($jsonContent, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Handle JSON decode error but try to continue
             error_log("JSON decode error in submit_score.php: " . json_last_error_msg() . ". Content: " . $jsonContent);
             $leaderboard = []; // Reset if invalid
        }
        // Ensure it's an array
        if (!is_array($leaderboard)) {
             $leaderboard = [];
        }
    }


    // Find if user exists and update score if the new score is higher
    $userFound = false;
    foreach ($leaderboard as $key => $entry) {
        if (isset($entry['username']) && strtolower($entry['username']) === strtolower($username)) {
            if (!isset($entry['score']) || $score > $entry['score']) {
                $leaderboard[$key]['score'] = $score;
            } else {
                // Optionally return a message if the score isn't higher
                // echo json_encode(['success' => true, 'message' => 'Score not updated, new score is not higher.']);
                // flock($fileHandle, LOCK_UN); // Release lock
                // fclose($fileHandle);
                // exit; // Exit here if no update needed
            }
             $userFound = true;
            break;
        }
    }

    // Add new user if not found
    if (!$userFound) {
        $leaderboard[] = ['username' => $username, 'score' => $score, 'rank' => 0]; // Initial rank 0
    }

    // Sort by score descending, then alphabetically by username for ties
    usort($leaderboard, function ($a, $b) {
        $scoreDiff = $b['score'] - $a['score'];
        if ($scoreDiff == 0) {
            return strcmp(strtolower($a['username']), strtolower($b['username']));
        }
        return $scoreDiff;
    });

    // Recalculate ranks
    $currentRank = 1;
    $lastScore = null;
    $rankIncrement = 1;
    foreach ($leaderboard as $key => $entry) {
         if ($lastScore !== null && $entry['score'] < $lastScore) {
             $currentRank += $rankIncrement;
             $rankIncrement = 1;
         } elseif ($lastScore !== null && $entry['score'] === $lastScore) {
             $rankIncrement++; // Increment counter for tied ranks
         }
         $leaderboard[$key]['rank'] = $currentRank;
         $lastScore = $entry['score'];
    }

    // Truncate file before writing new data
    ftruncate($fileHandle, 0);
    fseek($fileHandle, 0); // Go back to the beginning

    // Write updated data
    if (fwrite($fileHandle, json_encode($leaderboard, JSON_PRETTY_PRINT)) === false) {
         // Handle write error
         flock($fileHandle, LOCK_UN); // Release lock before exiting
         fclose($fileHandle);
         http_response_code(500);
         echo json_encode(['error' => 'Could not write updated leaderboard data.']);
         exit;
    }

    fflush($fileHandle); // Ensure data is written to disk
    flock($fileHandle, LOCK_UN); // Release the lock
    fclose($fileHandle);

    echo json_encode(['success' => true, 'message' => 'Score submitted successfully.']);

} else {
    // Could not get lock
    fclose($fileHandle); // Close the handle even if lock failed
    http_response_code(503); // Service Unavailable
    echo json_encode(['error' => 'Could not acquire lock on leaderboard file. Please try again.']);
    exit;
}

?>