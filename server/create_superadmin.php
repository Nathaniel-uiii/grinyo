<?php
include __DIR__ . '/db.php';

function add_column_if_missing($connect, $column_name, $definition)
{
    $result = mysqli_query($connect, "SHOW COLUMNS FROM users LIKE '" . mysqli_real_escape_string($connect, $column_name) . "';");
    if ($result && mysqli_num_rows($result) === 0) {
        $sql = "ALTER TABLE users ADD COLUMN " . $column_name . " " . $definition . ";";
        mysqli_query($connect, $sql);
    }
    if ($result) {
        mysqli_free_result($result);
    }
}

function next_user_id($connect)
{
    $year = (string)date('Y');
    $base = $year . '-0001';
    $max = 0;
    $sql = "SELECT MAX(CAST(SUBSTRING_INDEX(user_id, '-', -1) AS UNSIGNED)) AS maxseq FROM users WHERE LEFT(user_id, 4) = ?";
    $stmt = mysqli_prepare($connect, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 's', $year);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($res) {
            $row = mysqli_fetch_assoc($res);
            if ($row && isset($row['maxseq'])) {
                $max = (int)$row['maxseq'];
            }
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);
    }

    $next = $max + 1;
    if ($next > 9999) {
        $year = (string)((int)$year + 1);
        $next = 1;
    }

    return $year . '-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

if (!$connect) {
    die('Database connection failed.');
}

add_column_if_missing($connect, 'role', "ENUM('user','admin','superadmin') NOT NULL DEFAULT 'user'");
add_column_if_missing($connect, 'employee_id', "VARCHAR(30) NULL UNIQUE");
add_column_if_missing($connect, 'approval_status', "ENUM('pending','active','blocked') NOT NULL DEFAULT 'pending'");
add_column_if_missing($connect, 'privileges', "VARCHAR(255) NOT NULL DEFAULT 'read_users'");
add_column_if_missing($connect, 'created_by', "VARCHAR(9) NULL");
add_column_if_missing($connect, 'updated_by', "VARCHAR(9) NULL");

function upsert_default_account($connect, $username, $password, $role, $employeeId, $email, $privileges)
{
    $userCheck = mysqli_prepare($connect, "SELECT user_id FROM users WHERE username = ? LIMIT 1");
    if (!$userCheck) {
        return false;
    }
    mysqli_stmt_bind_param($userCheck, 's', $username);
    mysqli_stmt_execute($userCheck);
    $userResult = mysqli_stmt_get_result($userCheck);
    $existingUser = mysqli_fetch_assoc($userResult);
    mysqli_stmt_close($userCheck);

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $q1 = 'What is your favorite color?';
    $a1 = password_hash('blue', PASSWORD_DEFAULT);
    $q2 = 'What is your mother\'s maiden name?';
    $a2 = password_hash('santos', PASSWORD_DEFAULT);
    $q3 = 'What city were you born in?';
    $a3 = password_hash('manila', PASSWORD_DEFAULT);
    $lastName = ($role === 'superadmin') ? 'Super Admin' : 'Admin';

    if ($existingUser) {
        $updateSql = "UPDATE users SET password_hash = ?, role = ?, employee_id = ?, approval_status = 'active', privileges = ?, email = ?, is_active = 1 WHERE username = ?";
        $updateStmt = mysqli_prepare($connect, $updateSql);
        if (!$updateStmt) {
            return false;
        }
        mysqli_stmt_bind_param($updateStmt, 'ssssss', $passwordHash, $role, $employeeId, $privileges, $email, $username);
        mysqli_stmt_execute($updateStmt);
        mysqli_stmt_close($updateStmt);
        return true;
    }

    $userId = next_user_id($connect);
    $sql = "INSERT INTO users (
        user_id,
        first_name,
        last_name,
        date_of_birth,
        age,
        sex,
        street,
        barangay,
        city_municipality,
        province,
        country,
        zip_code,
        username,
        email,
        password_hash,
        q1,
        a1,
        q2,
        a2,
        q3,
        a3,
        role,
        employee_id,
        approval_status,
        privileges,
        is_active
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = mysqli_prepare($connect, $sql);
    if (!$stmt) {
        return false;
    }

    $firstName = 'Default';
    $dob = '1990-01-01';
    $age = 35;
    $sex = 'Male';
    $street = 'Main Street';
    $barangay = 'Barangay 1';
    $city = 'City';
    $province = 'Province';
    $country = 'Philippines';
    $zipCode = '0000';
    $approval = 'active';
    $isActive = 1;

    mysqli_stmt_bind_param(
        $stmt,
        'ssssssssssssssssssssssssss',
        $userId,
        $firstName,
        $lastName,
        $dob,
        $age,
        $sex,
        $street,
        $barangay,
        $city,
        $province,
        $country,
        $zipCode,
        $username,
        $email,
        $passwordHash,
        $q1,
        $a1,
        $q2,
        $a2,
        $q3,
        $a3,
        $role,
        $employeeId,
        $approval,
        $privileges,
        $isActive
    );
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return true;
}

$adminCreated = upsert_default_account($connect, 'Admin', 'Admin123', 'admin', 'ADM-0001', 'admin@ube.com', 'manage_users,approve_users,update_accounts');
$superCreated = upsert_default_account($connect, 'superadmin', 'SuperAdmin123!', 'superadmin', 'EMP-0001', 'superadmin@ube.com', 'manage_users,approve_users,update_accounts,delete_accounts');

if ($adminCreated) {
    echo "Admin account ready.<br>Username: Admin<br>Password: Admin123<br>Employee ID: ADM-0001";
}
if ($superCreated) {
    echo "<br>Super admin account ready.<br>Username: superadmin<br>Password: SuperAdmin123!<br>Employee ID: EMP-0001";
}

mysqli_close($connect);
