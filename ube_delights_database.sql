-- Ube Delights Database (refactored to 2 tables)

-- Create database
CREATE DATABASE IF NOT EXISTS ube_delights_db;
USE ube_delights_db;

-- Drop legacy tables if present
DROP TABLE IF EXISTS user_sessions;
DROP TABLE IF EXISTS sessions;
DROP TABLE IF EXISTS user_addresses;
DROP TABLE IF EXISTS user_security_questions;
DROP TABLE IF EXISTS users;

-- Users table: PK = user_id (xxxx-xxxx). Contains personal info, address, account, and security Q&A
CREATE TABLE IF NOT EXISTS users (
    user_id VARCHAR(9) NOT NULL PRIMARY KEY COMMENT 'Format: xxxx-xxxx',
    -- Personal info
    first_name VARCHAR(50) NOT NULL,
    middle_name VARCHAR(50) NULL,
    last_name VARCHAR(50) NOT NULL,
    extension_name VARCHAR(10) NULL,
    date_of_birth DATE NOT NULL,
    age INT NOT NULL,
    sex ENUM('Male','Female') NOT NULL,
    -- Address
    street VARCHAR(100) NOT NULL COMMENT 'Purok/Street',
    barangay VARCHAR(50) NOT NULL,
    city_municipality VARCHAR(50) NOT NULL,
    province VARCHAR(50) NOT NULL,
    country VARCHAR(50) NOT NULL,
    zip_code VARCHAR(10) NOT NULL,
    -- Account
    username VARCHAR(30) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    -- Security questions (answers stored hashed)
    q1 VARCHAR(255) NOT NULL,
    a1 VARCHAR(255) NOT NULL,
    q2 VARCHAR(255) NOT NULL,
    a2 VARCHAR(255) NOT NULL,
    q3 VARCHAR(255) NOT NULL,
    a3 VARCHAR(255) NOT NULL,
    -- Meta
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_active BOOLEAN DEFAULT TRUE,
    -- Indexes
    INDEX idx_username (username),
    INDEX idx_email (email),
    INDEX idx_city (city_municipality),
    INDEX idx_province (province)
);


-- Triggers for basic validation
DELIMITER //
CREATE TRIGGER trg_validate_user_id_format
BEFORE INSERT ON users
FOR EACH ROW
BEGIN
    IF NEW.user_id NOT REGEXP '^[0-9]{4}-[0-9]{4}$' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'User ID must be in format xxxx-xxxx';
    END IF;
END //
DELIMITER ;

DELIMITER //
CREATE TRIGGER trg_validate_email_format
BEFORE INSERT ON users
FOR EACH ROW
BEGIN
    IF NEW.email NOT REGEXP '^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid email format';
    END IF;
END //
DELIMITER ;

-- Sample insert (answers should be stored hashed in real usage)
INSERT INTO users (
    user_id, first_name, middle_name, last_name, extension_name,
    date_of_birth, age, sex,
    street, barangay, city_municipality, province, country, zip_code,
    username, email, password_hash,
    q1, a1, q2, a2, q3, a3
) VALUES (
    '2024-0001', 'Juan', 'Santos', 'Dela Cruz', 'Jr.',
    '1995-06-15', 29, 'Male',
    '123 Main Street', 'Barangay 1', 'Manila', 'Metro Manila', 'Philippines', '1000',
    'juan.delacruz', 'juan.delacruz@email.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'What is your favorite color?', '$2y$10$hashA',
    'What is your mother\'s maiden name?', '$2y$10$hashB',
    'What city were you born in?', '$2y$10$hashC'
);

-- Show tables
SHOW TABLES;
DESCRIBE users;

