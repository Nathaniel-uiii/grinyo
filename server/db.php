<?php
// Database connection
$host = 'localhost';
$user = 'root';
$pass = '';
$db = 'ube_delights_db';

$probe = @fsockopen('127.0.0.1', 3306, $errorNumber, $errorMessage, 2);
if (!$probe) {
    http_response_code(503);
    exit('Database service is unavailable. Start MySQL in XAMPP and try again.');
}
stream_set_timeout($probe, 2);
$greeting = fread($probe, 1);
fclose($probe);
if ($greeting === false || $greeting === '') {
    http_response_code(503);
    exit('Database service is unavailable. Restart MySQL in XAMPP and try again.');
}

$connect = mysqli_init();
mysqli_options($connect, MYSQLI_OPT_CONNECT_TIMEOUT, 3);
mysqli_real_connect($connect, $host, $user, $pass, $db);

if (!$connect || mysqli_connect_errno()) {
    http_response_code(503);
    exit('Database service is unavailable. Start MySQL in XAMPP and try again.');
}

// Keep existing installations compatible with the role-based account flow.
$schemaColumns = [
    'role' => "ENUM('user','admin','superadmin') NOT NULL DEFAULT 'user'",
    'employee_id' => "VARCHAR(30) NULL UNIQUE",
    'approval_status' => "ENUM('pending','active','blocked') NOT NULL DEFAULT 'pending'",
    'privileges' => "VARCHAR(255) NOT NULL DEFAULT 'read_users'",
    'created_by' => "VARCHAR(9) NULL",
    'updated_by' => "VARCHAR(9) NULL",
    'onboarding_required' => "TINYINT(1) NOT NULL DEFAULT 0"
];
foreach ($schemaColumns as $column => $definition) {
    $escapedColumn = mysqli_real_escape_string($connect, $column);
    $columnResult = mysqli_query($connect, "SHOW COLUMNS FROM users LIKE '$escapedColumn'");
    if ($columnResult && mysqli_num_rows($columnResult) === 0) {
        mysqli_query($connect, "ALTER TABLE users ADD COLUMN $column $definition");
    }
    if ($columnResult) {
        mysqli_free_result($columnResult);
    }
}

mysqli_query($connect, "CREATE TABLE IF NOT EXISTS activity_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_key CHAR(32) NOT NULL UNIQUE,
    user_id VARCHAR(9) NOT NULL,
    name VARCHAR(160) NOT NULL,
    role VARCHAR(30) NOT NULL,
    section VARCHAR(80) NOT NULL,
    browser_os VARCHAR(120) NOT NULL,
    time_in DATETIME NOT NULL,
    time_out DATETIME NULL,
    ip_address VARCHAR(45) NOT NULL,
    details VARCHAR(255) NOT NULL,
    INDEX idx_activity_user (user_id),
    INDEX idx_activity_time (time_in)
)");

// This singleton row coordinates the currently on-duty super administrator.
// A database lock is used so the rule also works across separate browsers/devices.
mysqli_query($connect, "CREATE TABLE IF NOT EXISTS superadmin_duty (
    lock_id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    user_id VARCHAR(9) NULL,
    session_id VARCHAR(128) NULL,
    last_seen DATETIME NULL,
    CONSTRAINT chk_superadmin_duty_lock CHECK (lock_id = 1)
)");
mysqli_query($connect, "INSERT IGNORE INTO superadmin_duty (lock_id) VALUES (1)");
?>
