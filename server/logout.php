<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Best-effort cleanup of legacy DB session state if the old table exists.
$userId = isset($_SESSION['auth_user_id']) ? (string)$_SESSION['auth_user_id'] : '';
if ($userId !== '') {
    require_once __DIR__ . '/db.php';
    if ($connect) {
        $tableExists = mysqli_query($connect, "SHOW TABLES LIKE 'sessions'");
        if ($tableExists && mysqli_num_rows($tableExists) > 0) {
            $sql = "UPDATE sessions
                    SET session_token = NULL, is_active = 0, expires_at = NOW()
                    WHERE user_id = ?";
            if ($stmt = mysqli_prepare($connect, $sql)) {
                mysqli_stmt_bind_param($stmt, 's', $userId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        }
        mysqli_close($connect);
    }
}

// Clear PHP session data
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();

// Clear custom session token cookie if used
if (isset($_COOKIE['session_token'])) {
    setcookie('session_token', '', time() - 3600, '/');
}

header('Location: ../views/login.php');
exit();
?>
