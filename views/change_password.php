<?php
include '../server/db.php';
session_start();

// Require reset session set by recover.php
if (!isset($_SESSION['pw_reset_user_id'])) {
    header('Location: ./recover.php');
    exit();
}

$user_id = (string)$_SESSION['pw_reset_user_id'];

// Fetch username for display
$display_username = '';
if ($connect) {
    $stmtU = mysqli_prepare($connect, "SELECT username FROM users WHERE user_id = ? LIMIT 1");
    if ($stmtU) {
        mysqli_stmt_bind_param($stmtU, 's', $user_id);
        mysqli_stmt_execute($stmtU);
        $resU = mysqli_stmt_get_result($stmtU);
        $rowU = $resU ? mysqli_fetch_assoc($resU) : null;
        mysqli_stmt_close($stmtU);
        if ($rowU && isset($rowU['username'])) { $display_username = (string)$rowU['username']; }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pass = isset($_POST['pass']) ? (string)$_POST['pass'] : '';
    $repass = isset($_POST['repass']) ? (string)$_POST['repass'] : '';

    if ($pass === '' || $repass === '') {
        header('Location: ./change_password.php?error=empty');
        exit();
    }
    if ($pass !== $repass) {
        header('Location: ./change_password.php?error=mismatch');
        exit();
    }

    // Strength check: min 8 chars (simple server-side)
    if (strlen($pass) < 8) {
        header('Location: ./change_password.php?error=weak');
        exit();
    }

    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $sql = "UPDATE users SET password_hash = ? WHERE user_id = ?";
    $stmt = mysqli_prepare($connect, $sql);
    mysqli_stmt_bind_param($stmt, 'ss', $hash, $user_id);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    if ($ok) {
        unset($_SESSION['pw_reset_user_id']);
        header('Location: ./login.php?reset=success');
        exit();
    }

    header('Location: ./change_password.php?error=save');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Change Password</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="../css/change.css?v=1.1" />
</head>
<body>
  <nav class="navbar">
    <div class="nav-container">
      <div class="nav-logo">
        <a onclick="getHome()" class="logo-link">
          <img src="../images/logo.png" alt="Ube Roll Logo" class="logo-image" />
          <h2>Ube Delights</h2>
        </a>
      </div>
      <div class="nav-menu">
        <a onclick="getLogin()" class="nav-link">Login</a>
        <a onclick="getRegister()" class="nav-link">Register</a>
      </div>
    </div>
  </nav>

  <main class="main-content">
    <div class="content-wrapper">
      <div class="form-section">
        <header>
          <h1>Change Password</h1>
          <div class="id-username-row">
            <span class="id-username-info">ID Number: <?php echo htmlspecialchars($user_id); ?></span>
            <?php if ($display_username !== '') { ?>
              <span class="id-username-info">Username: <?php echo htmlspecialchars($display_username); ?></span>
            <?php } ?>
          </div>
        </header>
        <div class="login-container">
          <form action="" method="POST">
            <div class="form-group">
            </div>
            <div class="form-group">
              <label for="newpass">Enter Password</label>
              <div class="password-wrapper">
                <input type="password" id="newpass" name="pass" placeholder="New Password">
                <i class="fa-solid fa-eye-slash" id="eyeicon-change"></i>
              </div>
              <small id="strength">Strength: -</small>
            </div>
            <div class="form-group">
              <label for="repass">Re-enter Password</label>
              <div class="password-wrapper">
                <input type="password" id="repass" name="repass" placeholder="Re-enter New Password">
              </div>
              <small id="matchmsg">Must match.</small>
            </div>
            <button class="btn" type="submit" id="save-password-btn">Save Password</button>
          </form>
        </div>
      </div>
      <div class="image-section">
        <img src="../images/cake.png" alt="Delicious Ube Cake" class="hero-image">
      </div>
    </div>
  </main>

  <footer class="footer">
    <p>&copy; 2025 Ube Delights. All rights reserved.</p>
  </footer>
  <script src="../javascript/disable_back.js"></script>
  <script src="../javascript/change_password.js"></script>
  <script src="../javascript/routing.js"></script>
  <script src="../javascript/inspect.js"></script>
</body>
</html>
