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

$stagedTableResult = mysqli_query($connect, "CREATE TABLE IF NOT EXISTS staged_accounts (
    user_id VARCHAR(9) NOT NULL PRIMARY KEY,
    staged_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_staged_accounts_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB");
if (!$stagedTableResult) {
    error_log('Staged accounts table creation failed: ' . mysqli_error($connect));
} else {
    $stagedBackfill = mysqli_query($connect, "INSERT IGNORE INTO staged_accounts (user_id) SELECT user_id FROM users WHERE onboarding_required = 1");
    if (!$stagedBackfill) {
        error_log('Staged accounts backfill failed: ' . mysqli_error($connect));
    }
}

$deleteRequestsTableResult = mysqli_query($connect, "CREATE TABLE IF NOT EXISTS account_delete_requests (
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
) ENGINE=InnoDB");
if (!$deleteRequestsTableResult) {
    error_log('Account delete request table creation failed: ' . mysqli_error($connect));
}
mysqli_query($connect, "CREATE TABLE IF NOT EXISTS activity_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_key CHAR(32) NOT NULL UNIQUE,
    user_id VARCHAR(9) NOT NULL,
    target_user_id VARCHAR(9) NULL,
    name VARCHAR(160) NOT NULL,
    role VARCHAR(30) NOT NULL,
    section VARCHAR(80) NOT NULL,
    browser_os VARCHAR(120) NOT NULL,
    time_in DATETIME NOT NULL,
    time_out DATETIME NULL,
    ip_address VARCHAR(45) NOT NULL,
    details VARCHAR(255) NOT NULL,
    INDEX idx_activity_user (user_id),
    INDEX idx_activity_target_role_time (target_user_id, role, time_in),
    INDEX idx_activity_time (time_in)
)");

$activityTargetColumnResult = mysqli_query($connect, "SHOW COLUMNS FROM activity_logs LIKE 'target_user_id'");
if ($activityTargetColumnResult) {
    if (mysqli_num_rows($activityTargetColumnResult) === 0) {
        $activityTargetMigration = "ALTER TABLE activity_logs ADD COLUMN target_user_id VARCHAR(9) NULL AFTER user_id, ADD INDEX idx_activity_target_role_time (target_user_id, role, time_in)";
        if (!mysqli_query($connect, $activityTargetMigration)) {
            error_log('Activity log target-user migration failed: ' . mysqli_error($connect));
        }
    }
    mysqli_free_result($activityTargetColumnResult);
}

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
