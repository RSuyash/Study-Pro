<?php

// Simple script to migrate data from JSON files to the database.
// Intended to be run once manually from the CLI: php migrate_data.php

echo "Starting data migration...\n";

// --- Configuration ---
$dataDir = __DIR__ . '/../data/'; // Assuming 'data' directory is one level up from the app root
$usersFile = $dataDir . 'users.json';
$leaderboardFile = $dataDir . 'leaderboard.json';
$subjectsFile = $dataDir . 'subject.json'; // Renamed variable for clarity

// --- Database Connection ---
require_once 'includes/database.php'; // Get the $pdo connection object

try {
    $pdo = getDbConnection(); // Use the function from database.php
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Database connection successful.\n";
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage() . "\n");
}

// --- Helper Function for Reading JSON ---
function readJsonFile($filePath) {
    if (!file_exists($filePath)) {
        echo "Error: File not found - $filePath\n";
        return null;
    }
    $jsonContent = file_get_contents($filePath);
    if ($jsonContent === false) {
        echo "Error: Could not read file - $filePath\n";
        return null;
    }
    $data = json_decode($jsonContent, true); // Decode as associative array
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "Error: Invalid JSON in file - $filePath. Error: " . json_last_error_msg() . "\n";
        return null;
    }
    echo "Successfully read and decoded: $filePath\n";
    return $data;
}

// --- Migration Steps ---

// 1. Migrate Users
echo "\n--- Migrating Users ---\n";
$usersData = readJsonFile($usersFile);
if ($usersData !== null) {
    try {
        $stmt = $pdo->prepare("INSERT INTO USERS (username, password_hash, email) VALUES (:username, :password_hash, :email)");
        $userCount = 0;
        foreach ($usersData as $user) {
            // Basic validation
            if (isset($user['username'], $user['password_hash'], $user['email'])) {
                $stmt->bindParam(':username', $user['username']);
                $stmt->bindParam(':password_hash', $user['password_hash']);
                $stmt->bindParam(':email', $user['email']);
                $stmt->execute();
                $userCount++;
            } else {
                echo "Warning: Skipping user record due to missing fields: " . print_r($user, true);
            }
        }
        echo "Successfully migrated $userCount users.\n";
    } catch (PDOException $e) {
        echo "Error migrating users: " . $e->getMessage() . "\n";
    }
}

// 2. Migrate Leaderboard
echo "\n--- Migrating Leaderboard ---\n";
$leaderboardData = readJsonFile($leaderboardFile);
if ($leaderboardData !== null) {
    try {
        // Prepare statement to find user_id by username
        $userStmt = $pdo->prepare("SELECT user_id FROM USERS WHERE username = :username");
        // Prepare statement to insert leaderboard entry
        $lbStmt = $pdo->prepare("INSERT INTO LEADERBOARD (user_id, total_score, subject_id) VALUES (:user_id, :total_score, :subject_id)");
        $lbCount = 0;
        foreach ($leaderboardData as $entry) {
             if (isset($entry['username'], $entry['total_score'])) {
                // Find the user_id
                $userStmt->bindParam(':username', $entry['username']);
                $userStmt->execute();
                $userResult = $userStmt->fetch(PDO::FETCH_ASSOC);

                if ($userResult) {
                    $userId = $userResult['user_id'];
                    $subjectId = null; // Assuming overall score for now as per requirement
                    $lbStmt->bindParam(':user_id', $userId);
                    $lbStmt->bindParam(':total_score', $entry['total_score'], PDO::PARAM_INT);
                    $lbStmt->bindParam(':subject_id', $subjectId, PDO::PARAM_NULL); // Explicitly bind NULL
                    $lbStmt->execute();
                    $lbCount++;
                } else {
                    echo "Warning: Skipping leaderboard entry for unknown user '{$entry['username']}'.\n";
                }
            } else {
                 echo "Warning: Skipping leaderboard record due to missing fields: " . print_r($entry, true);
            }
        }
        echo "Successfully migrated $lbCount leaderboard entries.\n";
    } catch (PDOException $e) {
        echo "Error migrating leaderboard: " . $e->getMessage() . "\n";
    }
}


// 3. Migrate Subjects, Units, and Topics
echo "\n--- Migrating Subjects, Units, Topics ---\n";
$subjectsData = readJsonFile($subjectsFile);
if ($subjectsData !== null && isset($subjectsData['subjects'])) { // Check if 'subjects' key exists
    try {
        // Prepare statements
        $subjectStmt = $pdo->prepare("INSERT INTO SUBJECTS (subject_name, description) VALUES (:name, :desc)");
        $unitStmt = $pdo->prepare("INSERT INTO UNITS (subject_id, unit_name, order_index) VALUES (:subject_id, :name, :order)");
        $topicStmt = $pdo->prepare("INSERT INTO TOPICS (unit_id, topic_name, content_url, content_type, estimated_time_minutes, order_index) VALUES (:unit_id, :name, :url, :type, :time, :order)");

        $subjectCount = 0;
        $unitCount = 0;
        $topicCount = 0;

        foreach ($subjectsData['subjects'] as $subjectIndex => $subject) {
            if (!isset($subject['subject_name'])) {
                 echo "Warning: Skipping subject due to missing name.\n";
                 continue;
            }
            $subjectDesc = $subject['description'] ?? null;
            $subjectStmt->bindParam(':name', $subject['subject_name']);
            $subjectStmt->bindParam(':desc', $subjectDesc);
            $subjectStmt->execute();
            $subjectId = $pdo->lastInsertId();
            $subjectCount++;
            echo "  Migrated Subject: {$subject['subject_name']} (ID: $subjectId)\n";

            if (isset($subject['units']) && is_array($subject['units'])) {
                foreach ($subject['units'] as $unitIndex => $unit) {
                     if (!isset($unit['unit_name'])) {
                         echo "Warning: Skipping unit in subject '{$subject['subject_name']}' due to missing name.\n";
                         continue;
                     }
                    $unitOrder = $unit['order_index'] ?? $unitIndex; // Use index as fallback order
                    $unitStmt->bindParam(':subject_id', $subjectId, PDO::PARAM_INT);
                    $unitStmt->bindParam(':name', $unit['unit_name']);
                    $unitStmt->bindParam(':order', $unitOrder, PDO::PARAM_INT);
                    $unitStmt->execute();
                    $unitId = $pdo->lastInsertId();
                    $unitCount++;
                    echo "    Migrated Unit: {$unit['unit_name']} (ID: $unitId)\n";

                    if (isset($unit['topics']) && is_array($unit['topics'])) {
                        foreach ($unit['topics'] as $topicIndex => $topic) {
                            if (!isset($topic['topic_name'])) {
                                echo "Warning: Skipping topic in unit '{$unit['unit_name']}' due to missing name.\n";
                                continue;
                            }
                            $topicUrl = $topic['content_url'] ?? null;
                            $topicType = $topic['content_type'] ?? null;
                            $topicTime = isset($topic['estimated_time_minutes']) ? (int)$topic['estimated_time_minutes'] : null;
                            $topicOrder = $topic['order_index'] ?? $topicIndex; // Use index as fallback order

                            $topicStmt->bindParam(':unit_id', $unitId, PDO::PARAM_INT);
                            $topicStmt->bindParam(':name', $topic['topic_name']);
                            $topicStmt->bindParam(':url', $topicUrl);
                            $topicStmt->bindParam(':type', $topicType);
                            $topicStmt->bindParam(':time', $topicTime, $topicTime === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                            $topicStmt->bindParam(':order', $topicOrder, PDO::PARAM_INT);
                            $topicStmt->execute();
                            $topicCount++;
                            // No need to echo every topic unless debugging
                        }
                         echo "      Migrated " . count($unit['topics']) . " topics for unit '{$unit['unit_name']}'.\n";
                    }
                     // Handle potential sub-topics if they exist in JSON, although schema doesn't directly support nesting deeper than topic
                     if (isset($unit['sub_topics']) && is_array($unit['sub_topics'])) {
                         echo "Warning: 'sub_topics' found in JSON for unit '{$unit['unit_name']}' but schema does not support nested topics. These were ignored.\n";
                     }
                }
            }
        }
        echo "Successfully migrated $subjectCount subjects, $unitCount units, and $topicCount topics.\n";

    } catch (PDOException $e) {
        echo "Error migrating subjects/units/topics: " . $e->getMessage() . "\n";
    }
} else {
    if ($subjectsData === null) {
         echo "Could not read or decode subjects file.\n";
    } else {
         echo "Error: 'subjects' key not found or is not an array in subjects JSON data.\n";
    }
}


echo "\n--- Data Migration Complete ---\n";

?>