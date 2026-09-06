<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('This script must be run from the command line.');
}

require_once __DIR__ . '/db.php';

$checks = [
    'role' => "ENUM('user','admin','superadmin') NOT NULL DEFAULT 'user'",
    'employee_id' => "VARCHAR(30) NULL UNIQUE",
    'approval_status' => "ENUM('pending','approved','blocked') NOT NULL DEFAULT 'pending'",
    'privileges' => "VARCHAR(255) NOT NULL DEFAULT 'read_users'",
    'created_by' => "VARCHAR(9) NULL",
    'updated_by' => "VARCHAR(9) NULL"
];

foreach ($checks as $column => $definition) {
    $escapedColumn = mysqli_real_escape_string($connect, $column);
    $result = mysqli_query($connect, "SHOW COLUMNS FROM users LIKE '$escapedColumn'");
    if ($result && mysqli_num_rows($result) === 0) {
        mysqli_query($connect, "ALTER TABLE users ADD COLUMN $column $definition");
    }
    if ($result) {
        mysqli_free_result($result);
    }
}

mysqli_query($connect, "CREATE TABLE IF NOT EXISTS account_delete_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    target_user_id VARCHAR(9) NOT NULL,
    requested_by VARCHAR(9) NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    reviewed_by VARCHAR(9) NULL,
    reviewed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (target_user_id),
    INDEX (status)
)");

echo "Database setup complete.";