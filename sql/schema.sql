-- Database Schema for Study Track Pro
-- Target DBMS: MySQL (Further Simplified Syntax for Linter Compatibility)

-- Users Table: Stores user account information
CREATE TABLE USERS (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL, -- Store hashed passwords
    email VARCHAR(100) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Subjects Table: Stores the main subjects or courses
CREATE TABLE SUBJECTS (
    subject_id INT AUTO_INCREMENT PRIMARY KEY,
    subject_name VARCHAR(100) NOT NULL,
    description TEXT NULL
);

-- Units Table: Stores units or modules within a subject
CREATE TABLE UNITS (
    unit_id INT AUTO_INCREMENT PRIMARY KEY,
    subject_id INT NOT NULL,
    unit_name VARCHAR(150) NOT NULL,
    order_index INT DEFAULT 0, -- For ordering units within a subject
    FOREIGN KEY (subject_id) REFERENCES SUBJECTS(subject_id) ON DELETE CASCADE
);

-- Topics Table: Stores individual topics or lessons within a unit
CREATE TABLE TOPICS (
    topic_id INT AUTO_INCREMENT PRIMARY KEY,
    unit_id INT NOT NULL,
    topic_name VARCHAR(200) NOT NULL,
    content_url VARCHAR(255) NULL, -- Link to topic content (optional)
    content_type VARCHAR(10) NULL CHECK (content_type IN ('video', 'article', 'quiz', 'exercise')), -- Replaced ENUM with VARCHAR + CHECK
    estimated_time_minutes INT NULL, -- Estimated time to complete (optional)
    order_index INT DEFAULT 0, -- For ordering topics within a unit
    FOREIGN KEY (unit_id) REFERENCES UNITS(unit_id) ON DELETE CASCADE
);

-- User Progress Table: Tracks user progress on specific topics
CREATE TABLE USER_PROGRESS (
    progress_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    topic_id INT NOT NULL,
    status VARCHAR(15) NOT NULL DEFAULT 'not_started' CHECK (status IN ('not_started', 'in_progress', 'completed')), -- Replaced ENUM
    score DECIMAL(5, 2) NULL, -- Score if the topic is a quiz/exercise (e.g., 95.50)
    completed_at TIMESTAMP NULL,
    last_accessed TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES USERS(user_id) ON DELETE CASCADE,
    FOREIGN KEY (topic_id) REFERENCES TOPICS(topic_id) ON DELETE CASCADE,
    CONSTRAINT user_topic_unique UNIQUE (user_id, topic_id) -- Ensure only one progress entry per user per topic
);

-- Leaderboard Table: Stores aggregated scores or points for users, potentially per subject
CREATE TABLE LEADERBOARD (
    leaderboard_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    subject_id INT NULL, -- Can be NULL for an overall leaderboard, or link to a subject
    total_score INT NOT NULL DEFAULT 0,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES USERS(user_id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES SUBJECTS(subject_id) ON DELETE SET NULL, -- Or CASCADE if leaderboard entry should be removed when subject is deleted
    CONSTRAINT user_subject_leaderboard_unique UNIQUE (user_id, subject_id) -- Ensure one entry per user per subject (if subject_id is NOT NULL)
);