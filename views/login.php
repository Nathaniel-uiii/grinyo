<?php
// Simple login: prepared statements + password_verify + basic lockout
include '../server/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
// If already authenticated, send to dashboard
if (isset($_SESSION['auth_user_id'])) {
    if (($_SESSION['auth_role'] ?? 'user') === 'user') {
        header('Location: ./index.php');
    } else {
        header('Location: ./admin.php');
    }
    exit();
}

// Legacy server-side lockout logic has been removed; JavaScript now handles
// all attempt counting and lockout timing on the client. If any old lock
// markers remain in the session, clear them.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_SESSION['lock_user_id'])) {
    unset($_SESSION['lock_user_id'], $_SESSION['lock_username']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = isset($_POST['login']) ? trim($_POST['login']) : (isset($_POST['username']) ? trim($_POST['username']) : '');
    $password = isset($_POST['password']) ? (string)$_POST['password'] : '';

    if ($login === '' || $password === '') {
        header('Location: ./login.php?error=empty');
        exit();
    }

    // 1) Find the account by username (case-sensitive) or email.
    // The role is always read from the database, never chosen by the user.
    // Use BINARY for username to enforce case sensitivity. Email remains as-is.
    $sqlUser = "SELECT user_id, username, email, password_hash, is_active, role, approval_status, privileges FROM users WHERE (BINARY username = ? OR email = ?) LIMIT 1";
    $stmtUser = mysqli_prepare($connect, $sqlUser);
    mysqli_stmt_bind_param($stmtUser, 'ss', $login, $login);
    mysqli_stmt_execute($stmtUser);
    $resUser = mysqli_stmt_get_result($stmtUser);
    $user = $resUser ? mysqli_fetch_assoc($resUser) : null;
    mysqli_stmt_close($stmtUser);

    if (!$user) {
        header('Location: ./login.php?error=user');
        exit();
    }
    if (!$user['is_active']) {
        header('Location: ./login.php?error=inactive');
        exit();
    }
    $accountRole = strtolower((string)($user['role'] ?? 'user'));
    if ($accountRole === 'user' && (($user['approval_status'] ?? 'pending') !== 'approved')) {
        $statusError = (($user['approval_status'] ?? 'pending') === 'blocked') ? 'blocked' : 'pending';
        header("Location: ./login.php?error=$statusError");
        exit();
    }

    $userId = (string)$user['user_id'];

    // 2) Verify password (lockout and attempt counting are handled in JavaScript)
    if (!password_verify($password, $user['password_hash'])) {
        $u = urlencode($login);
        header("Location: ./login.php?error=pass&u=$u");
        exit();
    }

    // 3) Success: start session
    $_SESSION['auth_user_id'] = $userId;
    $_SESSION['auth_username'] = $user['username'];
    $_SESSION['auth_role'] = isset($user['role']) ? $user['role'] : 'user';
    $_SESSION['auth_privileges'] = isset($user['privileges']) ? $user['privileges'] : 'read_users';

    // Check if user already has security questions set
    // In new schema, questions are stored on users
    $sqlChk = "SELECT q1 FROM users WHERE user_id = ? LIMIT 1";
    $stmtChk = mysqli_prepare($connect, $sqlChk);
    mysqli_stmt_bind_param($stmtChk, 's', $userId);
    mysqli_stmt_execute($stmtChk);
    $resChk = mysqli_stmt_get_result($stmtChk);
    $rowChk = $resChk ? mysqli_fetch_assoc($resChk) : null;
    $hasSQ = ($rowChk && !empty($rowChk['q1']));
    mysqli_stmt_close($stmtChk);

    if (in_array($_SESSION['auth_role'], ['admin', 'superadmin'], true)) {
        header('Location: ./admin.php');
        exit();
    }

    if ($hasSQ) {
        header('Location: ./index.php');
        exit();
    } else {
        header('Location: ./set_security_questions.php');
        exit();
    }
}
?>

<?php
$vs = isset($_GET['viewsource']) ? '?viewsource=1' : '';
$cssUrl = '../css/login.css';
$logoUrl = '../images/logo.png';
$cakeUrl = '../images/cake.png';
$jsBase = '../javascript';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log In</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($cssUrl); ?>?v=1.0">
</head>
<body>
    <nav class="navbar">
        <div class="nav-container">
            <div class="nav-logo">
                <a onclick="getHome()" class="logo-link">
                    <img src="<?php echo htmlspecialchars($logoUrl); ?>" alt="Ube Roll Logo" class="logo-image">
                    <h2>Ube Delights</h2>
                </a>
            </div>
            <div class="nav-menu">
                <a onclick="getHome()" class="nav-link">Home</a>
                <a onclick="getRegister()" class="nav-link">Register</a>
            </div>
        </div>
    </nav>

    <main class="main-content">
        <div class="content-wrapper">
            <div class="form-section">
                <header>
                    <h1>Login</h1>
                </header>
                <div class="login-container">
                    <form action="" method="POST" novalidate>
                        <div class="form-group">
                            <label for="username">Username</label>
                            <input type="text" id="username" name="username" placeholder="Username" required value="<?php echo isset($_GET['u']) ? htmlspecialchars($_GET['u']) : '';?>">
                        </div>
                        <div class="form-group">
                            <label for="password">Password</label>
                            <div class="password-wrapper">
                                <input type="password" id="password" name="password" placeholder="Password" required>
                                <i class="fa-solid fa-eye-slash" id="eyeicon"></i>
                            </div>
                        </div>
                        <div class="auth-alt">
                            <p id="forgot-section" class="forgot" >Forgot Password? <a onclick="getRecover()">Reset Here</a></p>
                        </div>
                        <div id="lockout-timer" class="lockout-timer"></div>
                        <button class="btn" type="submit">Login</button>
                    </form>
                    <p class="below">Don't have an account? <a onclick="getRegister()">Register</a></p>
                </div>
            </div>
            <div class="image-section">
                <img src="<?php echo htmlspecialchars($cakeUrl); ?>" alt="Delicious Ube Cake" class="hero-image">
            </div>
        </div>
    </main>

    <footer class="footer">
        <p>&copy; 2025 Ube Delights. All rights reserved.</p>
    </footer>
    <div id="pending-modal" class="status-modal" role="dialog" aria-modal="true" aria-labelledby="pending-modal-title" aria-describedby="pending-modal-description" hidden>
        <div class="status-modal-card">
            <div class="status-modal-content">
                <div class="status-modal-icon" aria-hidden="true"><i class="fa-solid fa-hourglass-half"></i></div>
                <h2 id="pending-modal-title">Account pending</h2>
                <p id="pending-modal-description">Please wait for the admin for approval</p>
                <button type="button" id="pending-modal-close" class="status-modal-button">OK, GOT IT</button>
            </div>
        </div>
    </div>
    <script src="<?php echo htmlspecialchars($jsBase); ?>/disable_back.js"></script>
    <script src="<?php echo htmlspecialchars($jsBase); ?>/login.js"></script>
    <script src="<?php echo htmlspecialchars($jsBase); ?>/routing.js"></script>
    <script src="<?php echo htmlspecialchars($jsBase); ?>/inspect.js"></script>
</body>
</html>