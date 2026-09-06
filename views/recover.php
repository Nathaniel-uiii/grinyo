<?php
include '../server/db.php';
session_start();

// If user is already logged in, redirect to index
if (isset($_SESSION['auth_user_id'])) {
    header('Location: ./index.php');
    exit();
}

function fetch_user_by_id($connect, $user_id) {
    $sql = "SELECT email, username FROM users WHERE user_id = ? LIMIT 1";
    $stmt = mysqli_prepare($connect, $sql);
    mysqli_stmt_bind_param($stmt, 's', $user_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return $row;
}

// Helpers
function fetch_user_by_idnumber($connect, $user_id_str) {
    $sql = "SELECT email, user_id, username FROM users WHERE user_id = ? LIMIT 1";
    $stmt = mysqli_prepare($connect, $sql);
    mysqli_stmt_bind_param($stmt, 's', $user_id_str);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $user = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return $user;
}

function fetch_questions($connect, $user_id) {
    $sql = "SELECT q1, a1, q2, a2, q3, a3 FROM users WHERE user_id = ? LIMIT 1";
    $stmt = mysqli_prepare($connect, $sql);
    mysqli_stmt_bind_param($stmt, 's', $user_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return $row;
}

// Handle POST for ID submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['stage']) && $_POST['stage'] === 'email') {
    // Keep stage value for simplicity, but use ID number now
    $idnum = isset($_POST['idnum']) ? trim($_POST['idnum']) : '';
    if ($idnum === '') {
        header('Location: ./recover.php?error=empty_id');
        exit();
    }
    // Basic format guard: ####-#### (align with registration)
    if (!preg_match('/^\d{4}-\d{4}$/', $idnum)) {
        header('Location: ./recover.php?error=invalid_id');
        exit();
    }
    $user = fetch_user_by_idnumber($connect, $idnum);
    if (!$user) {
        header('Location: ./recover.php?error=unknown_id');
        exit();
    }
    $qs = fetch_questions($connect, $user['user_id']);
    if (!$qs) {
        header('Location: ./recover.php?error=no_questions');
        exit();
    }
    $_SESSION['recover_user_id'] = (string)$user['user_id'];
    $_SESSION['recover_email'] = $user['email'];
    $_SESSION['recover_username'] = isset($user['username']) ? $user['username'] : null;
    header('Location: ./recover.php?step=questions');
    exit();
}

// Handle POST for answering questions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['stage']) && $_POST['stage'] === 'answers') {
    if (!isset($_SESSION['recover_user_id'])) {
        // AJAX safe fallback
        $wants_json = (isset($_POST['ajax']) && $_POST['ajax'] === '1') || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
        if ($wants_json) {
            header('Content-Type: application/json');
            echo json_encode([ 'ok' => false, 'reason' => 'no_session' ]);
            exit();
        }
        header('Location: ./recover.php');
        exit();
    }
    $user_id = (string)$_SESSION['recover_user_id'];
    $qs = fetch_questions($connect, $user_id);
    if (!$qs) {
        $wants_json = (isset($_POST['ajax']) && $_POST['ajax'] === '1') || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
        if ($wants_json) {
            header('Content-Type: application/json');
            echo json_encode([ 'ok' => false, 'reason' => 'no_questions' ]);
            exit();
        }
        header('Location: ./recover.php?error=no_questions');
        exit();
    }
    $a1 = isset($_POST['a1']) ? trim($_POST['a1']) : '';
    $a2 = isset($_POST['a2']) ? trim($_POST['a2']) : '';
    $a3 = isset($_POST['a3']) ? trim($_POST['a3']) : '';
    $wants_json = (isset($_POST['ajax']) && $_POST['ajax'] === '1') || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
    if ($a1 === '' || $a2 === '' || $a3 === '') {
        if ($wants_json) {
            header('Content-Type: application/json');
            echo json_encode([ 'ok' => false, 'reason' => 'empty', 'empty' => [ $a1 === '', $a2 === '', $a3 === '' ] ]);
            exit();
        }
        header('Location: ./recover.php?step=questions&error=empty');
        exit();
    }
    // Compare against hashed answers: require all 3 correct
    $correct = 0;
    if (password_verify($a1, $qs['a1'])) { $correct++; }
    if (password_verify($a2, $qs['a2'])) { $correct++; }
    if (password_verify($a3, $qs['a3'])) { $correct++; }
    if ($wants_json) {
        header('Content-Type: application/json');
        $per = [ password_verify($a1, $qs['a1']), password_verify($a2, $qs['a2']), password_verify($a3, $qs['a3']) ];
        if ($correct === 3) {
            // mark session and instruct client to navigate
            $_SESSION['pw_reset_user_id'] = $user_id;
            unset($_SESSION['recover_user_id'], $_SESSION['recover_email']);
            echo json_encode([
                'ok' => true,
                'requiredMet' => true,
                'correct' => $per,
                'redirect' => './change_password.php'
            ]);
            exit();
        } else {
            echo json_encode([
                'ok' => true,
                'requiredMet' => false,
                'correct' => $per
            ]);
            exit();
        }
    } else {
        if ($correct !== 3) {
            $s1 = password_verify($a1, $qs['a1']) ? '1' : '0';
            $s2 = password_verify($a2, $qs['a2']) ? '1' : '0';
            $s3 = password_verify($a3, $qs['a3']) ? '1' : '0';
            $status = $s1 . $s2 . $s3; // e.g., 101
            header('Location: ./recover.php?step=questions&status=' . $status);
            exit();
        }
        // Success: set reset session and proceed
        $_SESSION['pw_reset_user_id'] = $user_id;
        unset($_SESSION['recover_user_id'], $_SESSION['recover_email']);
        header('Location: ./change_password.php');
        exit();
    }
}

// Determine view stage
$step = isset($_GET['step']) ? $_GET['step'] : 'email';
$qs = null;
if ($step === 'questions' && isset($_SESSION['recover_user_id'])) {
    $uid = (string)$_SESSION['recover_user_id'];
    $qs = fetch_questions($connect, $uid);
    // Backfill username/email in session if missing from earlier flows
    if (!isset($_SESSION['recover_username']) || !isset($_SESSION['recover_email'])) {
        $urow = fetch_user_by_id($connect, $uid);
        if ($urow) {
            if (!isset($_SESSION['recover_email'])) $_SESSION['recover_email'] = $urow['email'];
            if (!isset($_SESSION['recover_username'])) $_SESSION['recover_username'] = $urow['username'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Account Recovery</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="../css/recover.css?v=1.0" />
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
    <div class="content-wrapper recover-layout">
      <div class="form-section">
        <div class="sq-container">
          <?php if ($step !== 'questions' || !$qs) { ?>
          <form action="" method="POST" id="recover-email-form">
            <input type="hidden" name="stage" value="email" />
            <div class="form-header">
              <h1>Recover Account</h1>
              <p class="subtitle">To ensure your account security, please enter your registered ID Number below.</p>
            </div>
            <div class="form-group">
              <div class="form-box span-two">
                <label for="idnum">ID Number <span class="required">*</span></label>
                <input type="text" id="idnum" name="idnum" placeholder="xxxx-xxxx" required />
              </div>
            </div>
            <div class="form-actions">
              <button type="submit">Continue</button>
            </div>
          </form>
          <?php } else { ?>
          <form action="" method="POST" id="recover-answers-form" novalidate>
            <input type="hidden" name="stage" value="answers" />
            <div class="form-header">
              <h1>Answer Security Questions</h1>
              <p class="subtitle">Please answer all 3 questions correctly to reset your password.</p>
              <?php if (isset($_SESSION['recover_user_id'])) { ?>
                <p class="subtitle">
                  ID Number: <?php echo htmlspecialchars($_SESSION['recover_user_id']); ?>
                  <?php if (isset($_SESSION['recover_username']) && $_SESSION['recover_username'] !== null) { ?>
                    &nbsp;&nbsp;Username: <?php echo htmlspecialchars($_SESSION['recover_username']); ?>
                  <?php } ?>
                </p>
              <?php } ?>
            </div>

            <div class="form-group">
              <div class="form-box span-two">
                <label for="a1"><?php echo htmlspecialchars($qs['q1']); ?> <span class="required">*</span></label>
                <div class="password-wrapper">
                  <input type="password" id="a1" name="a1" required />
                  <i class="fa-solid fa-eye-slash" id="eyeicon-a1"></i>
                </div>
              </div>

              <div class="form-box span-two">
                <label for="a2"><?php echo htmlspecialchars($qs['q2']); ?> <span class="required">*</span></label>
                <div class="password-wrapper">
                  <input type="password" id="a2" name="a2" required />
                  <i class="fa-solid fa-eye-slash" id="eyeicon-a2"></i>
                </div>
              </div>

              <div class="form-box span-two">
                <label for="a3"><?php echo htmlspecialchars($qs['q3']); ?> <span class="required">*</span></label>
                <div class="password-wrapper">
                  <input type="password" id="a3" name="a3" required />
                  <i class="fa-solid fa-eye-slash" id="eyeicon-a3"></i>
                </div>
              </div>
            </div>

            <div class="form-actions">
              <button type="submit">Verify</button>
            </div>
          </form>
          <?php } ?>
        </div>
      </div>
      <div class="image-section">
        <img src="../images/cake.png" alt="Delicious Ube Cake" class="hero-image" />
      </div>
    </div>
  </main>

  <footer class="footer">
    <p>&copy; 2025 Ube Delights. All rights reserved.</p>
  </footer>
  <script src="../javascript/disable_back.js"></script>
  <script src="../javascript/sq.js"></script>
  <script src="../javascript/recover.js"></script>
  <script src="../javascript/routing.js"></script>
  <script src="../javascript/inspect.js"></script>
</body>
</html>
