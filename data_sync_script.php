<?php
// --- SECURITY CHECK - REMOVE THIS SCRIPT AFTER RUNNING ONCE ---
$secretKey = 'suyash9596@D!'; // <-- CHANGE THIS to something unique!
if (!isset($_GET['key']) || $_GET['key'] !== $secretKey) {
    // If running via CLI, allow it without key
    if (php_sapi_name() !== 'cli') {
        http_response_code(403); // Forbidden
        die('Access Denied. Invalid or missing key.');
    }
}
// --- End Security Check ---

// Simple script to migrate data from JSON files to the database.
// Intended to be run once manually from the CLI: php migrate_data.php
// Or via web with secret key: /migrate_data.php?key=YOUR_SECRET_KEY

// Add line breaks for browser output if run via web
$newLine = php_sapi_name() === 'cli' ? "\n" : "<br>\n";

echo "Migration script started..." . $newLine;

// --- Configuration ---
// Adjust path if migrate_data.php is not in the same dir as api.php/submit_score.php
// Assuming migrate_data.php is in Study-Pro alongside includes/
// The path needs to go up one level from Study-Pro, then into the sibling 'data' directory
$dataDir = __DIR__ . '/../Data/'; // Corrected case for Data directory
$usersFile = $dataDir . 'users.json';
$leaderboardFile = $dataDir . 'leaderboard.json';
$subjectsFile = $dataDir . 'subject.json';

echo "Data directory path: " . realpath($dataDir) . $newLine; // Debug path

// --- Database Connection ---
require_once __DIR__ . '/includes/database.php'; // Use __DIR__ for reliable include path

try {
    $pdo = getDbConnection(); // Use the function from database.php
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Database connection successful." . $newLine;
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage() . $newLine);
} catch (Exception $e) {
    die("Error including database config: " . $e->getMessage() . $newLine);
}

// --- Helper Function for Reading JSON ---
function readJsonFile($filePath, $outputNewLine) {
    echo "Attempting to read: $filePath" . $outputNewLine;
    if (!file_exists($filePath)) {
        echo "Error: File not found - $filePath" . $outputNewLine;
        return null;
    }
    if (!is_readable($filePath)) {
         echo "Error: File not readable (check permissions) - $filePath" . $outputNewLine;
         return null;
    }
    $jsonContent = file_get_contents($filePath);
    if ($jsonContent === false) {
        echo "Error: Could not read file content - $filePath" . $outputNewLine;
        return null;
    }
     // Remove potential UTF-8 BOM before decoding
    $bom = pack('H*','EFBBBF');
    $jsonContent = preg_replace("/^$bom/", '', $jsonContent);

    if (trim($jsonContent) === '') {
         echo "Warning: File is empty - $filePath" . $outputNewLine;
         return []; // Return empty array for empty file
    }

    $data = json_decode($jsonContent, true); // Decode as associative array
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "Error: Invalid JSON in file - $filePath. Error: " . json_last_error_msg() . $outputNewLine;
        // Optionally log or show part of the content causing issues
        // echo "Content Start: " . substr($jsonContent, 0, 100) . $outputNewLine;
        return null;
    }
    echo "Successfully read and decoded: $filePath" . $outputNewLine;
    return $data;
}

// --- Migration Steps ---

// 1. Migrate Users
echo $newLine . "--- Migrating Users ---" . $newLine;
$usersData = readJsonFile($usersFile, $newLine);
if ($usersData !== null) {
    try {
        // Check if users table is empty first to prevent duplicate errors if run twice
        $checkStmt = $pdo->query("SELECT COUNT(*) FROM USERS");
        if ($checkStmt->fetchColumn() > 0) {
             echo "Warning: USERS table is not empty. Skipping user migration to avoid duplicates." . $newLine;
        } elseif (is_array($usersData)) { // Only proceed if data is an array
            $stmt = $pdo->prepare("INSERT INTO USERS (username, password_hash, email, created_at) VALUES (:username, :password_hash, :email, :created_at)");
            $userCount = 0;
            foreach ($usersData as $user) {
                if (isset($user['username'], $user['password_hash'], $user['email'])) {
                    $createdAt = $user['registered_at'] ?? date('Y-m-d H:i:s'); // Use existing timestamp or now
                    $stmt->bindParam(':username', $user['username']);
                    $stmt->bindParam(':password_hash', $user['password_hash']);
                    $stmt->bindParam(':email', $user['email']);
                    $stmt->bindParam(':created_at', $createdAt);
                    $stmt->execute();
                    $userCount++;
                } else {
                    echo "Warning: Skipping user record due to missing fields: " . print_r($user, true) . $newLine;
                }
            }
            echo "Successfully migrated $userCount users." . $newLine;
        } else {
             echo "Error: User data from JSON was not an array." . $newLine;
        }
    } catch (PDOException $e) {
        echo "Error migrating users: " . $e->getMessage() . $newLine;
    }
}

// 2. Migrate Leaderboard
echo $newLine . "--- Migrating Leaderboard ---" . $newLine;
$leaderboardData = readJsonFile($leaderboardFile, $newLine);
if ($leaderboardData !== null) {
    try {
        // Check if leaderboard table is empty first
         $checkStmt = $pdo->query("SELECT COUNT(*) FROM LEADERBOARD");
         if ($checkStmt->fetchColumn() > 0) {
              echo "Warning: LEADERBOARD table is not empty. Skipping leaderboard migration." . $newLine;
         } elseif (is_array($leaderboardData)) { // Only proceed if data is an array
            $userStmt = $pdo->prepare("SELECT user_id FROM USERS WHERE username = :username");
            $lbStmt = $pdo->prepare("INSERT INTO LEADERBOARD (user_id, total_score, subject_id, last_updated) VALUES (:user_id, :total_score, :subject_id, :last_updated)");
            $lbCount = 0;
            foreach ($leaderboardData as $entry) {
                 // Use total_score if present, otherwise score
                 $score = $entry['total_score'] ?? $entry['score'] ?? null;
                 if (isset($entry['username']) && $score !== null) {
                    $userStmt->bindParam(':username', $entry['username']);
                    $userStmt->execute();
                    $userResult = $userStmt->fetch(PDO::FETCH_ASSOC);

                    if ($userResult) {
                        $userId = $userResult['user_id'];
                        $subjectId = $entry['subject_id'] ?? null; // Handle potential subject-specific scores
                        $lastUpdated = $entry['last_updated'] ?? date('Y-m-d H:i:s'); // Use existing or now

                        $lbStmt->bindParam(':user_id', $userId);
                        $lbStmt->bindParam(':total_score', $score, PDO::PARAM_INT);
                        $lbStmt->bindParam(':subject_id', $subjectId, $subjectId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                        $lbStmt->bindParam(':last_updated', $lastUpdated);
                        $lbStmt->execute();
                        $lbCount++;
                    } else {
                        echo "Warning: Skipping leaderboard entry for unknown user '{$entry['username']}'." . $newLine;
                    }
                } else {
                     echo "Warning: Skipping leaderboard record due to missing fields: " . print_r($entry, true) . $newLine;
                }
            }
            echo "Successfully migrated $lbCount leaderboard entries." . $newLine;
        } else {
             echo "Error: Leaderboard data from JSON was not an array." . $newLine;
        }
    } catch (PDOException $e) {
        echo "Error migrating leaderboard: " . $e->getMessage() . $newLine;
    }
}


// 3. Migrate Subjects, Units, and Topics
echo $newLine . "--- Migrating Subjects, Units, Topics ---" . $newLine;
$subjectsStructure = readJsonFile($subjectsFile, $newLine); // Read the whole structure

// Check if the top level is an array
if ($subjectsStructure !== null && is_array($subjectsStructure)) {
    try {
         // Check if subjects table is empty first
         $checkStmt = $pdo->query("SELECT COUNT(*) FROM SUBJECTS");
         if ($checkStmt->fetchColumn() > 0) {
              echo "Warning: SUBJECTS table is not empty. Skipping syllabus migration." . $newLine;
         } else {
            $subjectStmt = $pdo->prepare("INSERT INTO SUBJECTS (subject_name, description) VALUES (:name, :desc)");
            $unitStmt = $pdo->prepare("INSERT INTO UNITS (subject_id, unit_name, order_index) VALUES (:subject_id, :name, :order)");
            $topicStmt = $pdo->prepare("INSERT INTO TOPICS (unit_id, topic_name, content_url, content_type, estimated_time_minutes, order_index) VALUES (:unit_id, :name, :url, :type, :time, :order)");

            $subjectCount = 0; $unitCount = 0; $topicCount = 0;

            // Recursive function to handle topics and sub-topics (if schema supported nesting)
            // For now, assumes subTopics in JSON are just regular topics under the parent
            function migrateTopics($pdo, $topicStmt, $unitId, $topics, &$topicCounter, $outputNewLine) {
                 foreach ($topics as $topicIndex => $topic) {
                     if (!isset($topic['topic_name'])) {
                         echo "Warning: Skipping topic in unit ID '{$unitId}' due to missing name." . $outputNewLine;
                         continue;
                     }
                     // Use topicId from JSON if available, otherwise generate one (less ideal)
                     // $topicDbId = $topic['topicId'] ?? uniqid('topic_'); // Schema uses AUTO_INCREMENT, so we don't insert ID

                     $topicUrl = $topic['content_url'] ?? null;
                     $topicType = $topic['content_type'] ?? null;
                     $topicTime = isset($topic['estimated_time_minutes']) ? (int)$topic['estimated_time_minutes'] : null;
                     $topicOrder = $topic['order_index'] ?? $topicIndex;

                     $topicStmt->bindParam(':unit_id', $unitId, PDO::PARAM_INT);
                     $topicStmt->bindParam(':name', $topic['topic_name']);
                     $topicStmt->bindParam(':url', $topicUrl);
                     $topicStmt->bindParam(':type', $topicType);
                     $topicStmt->bindParam(':time', $topicTime, $topicTime === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                     $topicStmt->bindParam(':order', $topicOrder, PDO::PARAM_INT);
                     $topicStmt->execute();
                     $topicCounter++;
                     $insertedTopicId = $pdo->lastInsertId(); // Get the ID generated by the DB

                     // If subTopics exist in JSON, migrate them as if they were direct children of the unit
                     // NOTE: This flattens the hierarchy as the current DB schema doesn't support topic nesting.
                     // If nesting is needed, the schema and this logic would need adjustment (e.g., parent_topic_id column).
                     if (isset($topic['subTopics']) && is_array($topic['subTopics'])) {
                          echo "Info: Migrating subTopics for '{$topic['topic_name']}' as direct children of Unit ID {$unitId}." . $outputNewLine;
                          migrateTopics($pdo, $topicStmt, $unitId, $topic['subTopics'], $topicCounter, $outputNewLine);
                     }
                 }
            }

            // Iterate through the subjects array
            foreach ($subjectsStructure as $subjectIndex => $subject) {
                if (!isset($subject['subject_name'])) {
                     echo "Warning: Skipping subject due to missing name." . $outputNewLine;
                     continue;
                }
                $subjectDesc = $subject['description'] ?? null;
                $subjectStmt->bindParam(':name', $subject['subject_name']);
                $subjectStmt->bindParam(':desc', $subjectDesc);
                $subjectStmt->execute();
                $subjectId = $pdo->lastInsertId();
                $subjectCount++;
                echo "  Migrated Subject: {$subject['subject_name']} (ID: $subjectId)" . $outputNewLine;

                if (isset($subject['units']) && is_array($subject['units'])) {
                    foreach ($subject['units'] as $unitIndex => $unit) {
                         if (!isset($unit['unit_name'])) {
                             echo "Warning: Skipping unit in subject '{$subject['subject_name']}' due to missing name." . $outputNewLine;
                             continue;
                         }
                        $unitOrder = $unit['order_index'] ?? $unitIndex;
                        $unitStmt->bindParam(':subject_id', $subjectId, PDO::PARAM_INT);
                        $unitStmt->bindParam(':name', $unit['unit_name']);
                        $unitStmt->bindParam(':order', $unitOrder, PDO::PARAM_INT);
                        $unitStmt->execute();
                        $unitId = $pdo->lastInsertId();
                        $unitCount++;
                        echo "    Migrated Unit: {$unit['unit_name']} (ID: $unitId)" . $outputNewLine;

                        if (isset($unit['topics']) && is_array($unit['topics'])) {
                             $topicsMigratedInUnit = $topicCount; // Store count before migrating this unit's topics
                             migrateTopics($pdo, $topicStmt, $unitId, $unit['topics'], $topicCount, $outputNewLine);
                             echo "      Migrated " . ($topicCount - $topicsMigratedInUnit) . " topics/subtopics for unit '{$unit['unit_name']}'." . $outputNewLine;
                        }
                    }
                }
            }
            echo "Successfully migrated $subjectCount subjects, $unitCount units, and $topicCount topics/subtopics." . $outputNewLine;
        } // End of check for empty subjects table
    } catch (PDOException $e) {
        echo "Error migrating subjects/units/topics: " . $e->getMessage() . $outputNewLine;
    }
} else {
    if ($subjectsStructure === null) {
         echo "Could not read or decode subjects file." . $outputNewLine;
    } else {
         echo "Error: Subjects JSON data is not a valid array at the top level." . $outputNewLine;
    }
}


echo $newLine . "--- Data Migration Complete ---" . $newLine;

?>
