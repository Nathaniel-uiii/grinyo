<?php
include '../server/db.php';
require_once '../server/mail.php';
require_once '../server/mask.php';
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
    $sql = "SELECT email, user_id, username, role, approval_status FROM users WHERE user_id = ? LIMIT 1";
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

function security_question_options() {
    return [
        'Who is your best friend in Elementary?',
        'What is the name of your favorite pet?',
        'Who is your favorite teacher in high school?',
        'What city were you born in?',
        "What is your mother's maiden name?",
        'What was the name of your first school?'
    ];
}

  function mask_recovery_email($email) {
    $parts = explode('@', (string)$email, 2);
    if (count($parts) !== 2) return 'your registered email address';
    $local = $parts[0];
    $visible = strlen($local) > 2 ? substr($local, 0, 2) : substr($local, 0, 1);
    return $visible . str_repeat('*', max(1, strlen($local) - strlen($visible))) . '@' . $parts[1];
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
    $accountRole = strtolower((string)($user['role'] ?? 'user'));
    if (($user['approval_status'] ?? 'pending') === 'blocked') {
        header('Location: ./recover.php?error=blocked');
        exit();
    }
    $_SESSION['recover_user_id'] = (string)$user['user_id'];
    $_SESSION['recover_email'] = $user['email'];
    $_SESSION['recover_username'] = isset($user['username']) ? $user['username'] : null;
    header('Location: ./recover.php?step=questions');
    exit();
}

  // Handle one-time PIN verification for every account role.
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['stage']) && $_POST['stage'] === 'otp') {
    $userId = (string)($_SESSION['recover_user_id'] ?? '');
    $pin = trim((string)($_POST['otp'] ?? ''));
    $expires = (int)($_SESSION['recover_pin_expires'] ?? 0);
    $attempts = (int)($_SESSION['recover_pin_attempts'] ?? 0);
    if ($userId === '' || empty($_SESSION['recover_pin_hash'])) {
      header('Location: ./recover.php?error=otp_session');
      exit();
    }
    if ($expires < time()) {
      unset($_SESSION['recover_pin_hash'], $_SESSION['recover_pin_expires'], $_SESSION['recover_pin_attempts']);
      header('Location: ./recover.php?error=otp_expired');
      exit();
    }
    if ($attempts >= 5) {
      header('Location: ./recover.php?step=otp&error=otp_locked');
      exit();
    }
    $_SESSION['recover_pin_attempts'] = $attempts + 1;
    if (!preg_match('/^\d{6}$/', $pin) || !password_verify($pin, $_SESSION['recover_pin_hash'])) {
      header('Location: ./recover.php?step=otp&error=otp_invalid');
      exit();
    }
    $_SESSION['pw_reset_user_id'] = $userId;
    unset($_SESSION['recover_user_id'], $_SESSION['recover_email'], $_SESSION['recover_username'], $_SESSION['recover_pin_hash'], $_SESSION['recover_pin_expires'], $_SESSION['recover_pin_attempts']);
    header('Location: ./change_password.php');
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
    $submittedQuestions = [
        trim((string)($_POST['q1'] ?? '')),
        trim((string)($_POST['q2'] ?? '')),
        trim((string)($_POST['q3'] ?? ''))
    ];
    $_SESSION['recover_selected_questions'] = $submittedQuestions;
    $wants_json = (isset($_POST['ajax']) && $_POST['ajax'] === '1') || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
    $missingFields = [
        $submittedQuestions[0] === '', $submittedQuestions[1] === '', $submittedQuestions[2] === '',
        $a1 === '', $a2 === '', $a3 === ''
    ];
    if (in_array(true, $missingFields, true)) {
        if ($wants_json) {
            header('Content-Type: application/json');
            echo json_encode([ 'ok' => false, 'reason' => 'empty', 'missing' => $missingFields ]);
            exit();
        }
        header('Location: ./recover.php?step=questions&error=empty&missing=' . implode('', array_map(function ($missing) { return $missing ? '1' : '0'; }, $missingFields)));
        exit();
    }
    $availableQuestions = security_question_options();
    $storedQuestions = [$qs['q1'], $qs['q2'], $qs['q3']];
    $selectionValid = count(array_unique($submittedQuestions)) === 3;
    $selectionStatus = [];
    foreach ($submittedQuestions as $question) {
        $selectionStatus[] = in_array($question, $storedQuestions, true);
        $selectionValid = $selectionValid && in_array($question, $availableQuestions, true) && in_array($question, $storedQuestions, true);
    }
    if (!$selectionValid) {
        if ($wants_json) {
            header('Content-Type: application/json');
            echo json_encode([ 'ok' => false, 'reason' => 'question_selection', 'correct' => $selectionStatus ]);
            exit();
        }
        header('Location: ./recover.php?step=questions&error=question_selection&status=' . implode('', array_map(function ($valid) { return $valid ? '1' : '0'; }, $selectionStatus)));
        exit();
    }
    $selectedHashes = [];
    foreach ($submittedQuestions as $question) {
        $questionIndex = array_search($question, $storedQuestions, true) + 1;
        $selectedHashes[] = $qs['a' . $questionIndex];
    }
    // Compare answers against the selected stored questions: require all 3 correct.
    $correct = 0;
    if (password_verify($a1, $selectedHashes[0])) { $correct++; }
    if (password_verify($a2, $selectedHashes[1])) { $correct++; }
    if (password_verify($a3, $selectedHashes[2])) { $correct++; }
    if ($wants_json) {
        header('Content-Type: application/json');
      $per = [ password_verify($a1, $selectedHashes[0]), password_verify($a2, $selectedHashes[1]), password_verify($a3, $selectedHashes[2]) ];
        if ($correct === 3) {
          $pin = (string)random_int(100000, 999999);
          if (!send_recovery_pin($_SESSION['recover_email'] ?? '', $pin)) {
            echo json_encode([ 'ok' => false, 'reason' => 'mail' ]);
            exit();
          }
          $_SESSION['recover_pin_hash'] = password_hash($pin, PASSWORD_DEFAULT);
          $_SESSION['recover_pin_expires'] = time() + 600;
          $_SESSION['recover_pin_attempts'] = 0;
            echo json_encode([
                'ok' => true,
                'requiredMet' => true,
                'correct' => $per,
            'redirect' => './recover.php?step=otp'
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
            $s1 = password_verify($a1, $selectedHashes[0]) ? '1' : '0';
            $s2 = password_verify($a2, $selectedHashes[1]) ? '1' : '0';
            $s3 = password_verify($a3, $selectedHashes[2]) ? '1' : '0';
            $status = $s1 . $s2 . $s3; // e.g., 101
            header('Location: ./recover.php?step=questions&status=' . $status);
            exit();
        }
        $pin = (string)random_int(100000, 999999);
        if (!send_recovery_pin($_SESSION['recover_email'] ?? '', $pin)) {
          header('Location: ./recover.php?step=questions&error=mail');
          exit();
        }
        $_SESSION['recover_pin_hash'] = password_hash($pin, PASSWORD_DEFAULT);
        $_SESSION['recover_pin_expires'] = time() + 600;
        $_SESSION['recover_pin_attempts'] = 0;
        unset($_SESSION['recover_selected_questions']);
        header('Location: ./recover.php?step=otp');
        exit();
    }
}

// Determine view stage
$step = isset($_GET['step']) ? $_GET['step'] : 'email';
$recoveryEmail = $_SESSION['recover_email'] ?? '';
$securityQuestions = security_question_options();
$selectedRecoveryQuestions = $_SESSION['recover_selected_questions'] ?? ['', '', ''];
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
          <?php if ($step === 'email') { ?>
          <form action="" method="POST" id="recover-email-form">
            <input type="hidden" name="stage" value="email" />
            <div class="form-header">
              <h1>Recover Account</h1>
              <p class="subtitle">To ensure your account security, please enter your registered ID Number below.</p>
            </div>
            <div class="form-group">
              <div class="form-box span-two">
                <label for="idnum">ID Number <span class="required">*</span></label>
                <input type="password" id="idnum" name="idnum" placeholder="xxxx-xxxx" required />
              </div>
            </div>
            <div class="form-actions">
              <button type="submit">Continue</button>
            </div>
          </form>
          <?php } elseif ($step === 'otp' && isset($_SESSION['recover_user_id'])) { ?>
          <form action="" method="POST" id="recover-otp-form" novalidate>
            <input type="hidden" name="stage" value="otp" />
            <div class="form-header">
              <h1>Enter One-Time PIN</h1>
              <p class="subtitle">A six-digit PIN was sent to <?php echo htmlspecialchars(mask_recovery_email($recoveryEmail)); ?>.</p>
              <p class="subtitle">
                ID Number: <?php echo htmlspecialchars(mask_id_number($_SESSION['recover_user_id'] ?? '')); ?>
                <?php if (!empty($_SESSION['recover_username'])) { ?>
                  &nbsp;&nbsp;Username: <?php echo htmlspecialchars(mask_username($_SESSION['recover_username'])); ?>
                <?php } ?>
              </p>
              <p class="subtitle">The PIN expires in 10 minutes.</p>
            </div>
            <div class="form-group">
              <div class="form-box span-two">
                <label for="otp">One-Time PIN <span class="required">*</span></label>
                <input type="text" id="otp" name="otp" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code" required />
              </div>
            </div>
            <div class="form-actions">
              <button type="submit">Verify PIN</button>
            </div>
          </form>
          <?php } elseif ($step === 'questions' && $qs) { ?>
          <form action="" method="POST" id="recover-answers-form" novalidate>
            <input type="hidden" name="stage" value="answers" />
            <div class="form-header">
              <h1>Answer Security Questions</h1>
              <p class="subtitle">Select and answer the three security questions you set for this account.</p>
              <?php if (isset($_SESSION['recover_user_id'])) { ?>
                <p class="subtitle">
                  ID Number: <?php echo htmlspecialchars(mask_id_number($_SESSION['recover_user_id'] ?? '')); ?>
                  <?php if (isset($_SESSION['recover_username']) && $_SESSION['recover_username'] !== null) { ?>
                    &nbsp;&nbsp;Username: <?php echo htmlspecialchars(mask_username($_SESSION['recover_username'])); ?>
                  <?php } ?>
                </p>
              <?php } ?>
            </div>

            <div class="form-group">
              <div class="form-box span-two">
                <label for="q1">Question 1 <span class="required">*</span></label>
                <select id="q1" name="q1" required>
                  <option value="" selected disabled>-Select a question-</option>
                  <?php foreach ($securityQuestions as $question): ?>
                    <option value="<?php echo htmlspecialchars($question, ENT_QUOTES); ?>"<?php echo ($selectedRecoveryQuestions[0] ?? '') === $question ? ' selected' : ''; ?>><?php echo htmlspecialchars($question); ?></option>
                  <?php endforeach; ?>
                </select>
                <div class="password-wrapper">
                  <input type="password" id="a1" name="a1" required />
                  <i class="fa-solid fa-eye-slash" id="eyeicon-a1"></i>
                </div>
              </div>

              <div class="form-box span-two">
                <label for="q2">Question 2 <span class="required">*</span></label>
                <select id="q2" name="q2" required>
                  <option value="" selected disabled>-Select a question-</option>
                  <?php foreach ($securityQuestions as $question): ?>
                    <option value="<?php echo htmlspecialchars($question, ENT_QUOTES); ?>"<?php echo ($selectedRecoveryQuestions[1] ?? '') === $question ? ' selected' : ''; ?>><?php echo htmlspecialchars($question); ?></option>
                  <?php endforeach; ?>
                </select>
                <div class="password-wrapper">
                  <input type="password" id="a2" name="a2" required />
                  <i class="fa-solid fa-eye-slash" id="eyeicon-a2"></i>
                </div>
              </div>

              <div class="form-box span-two">
                <label for="q3">Question 3 <span class="required">*</span></label>
                <select id="q3" name="q3" required>
                  <option value="" selected disabled>-Select a question-</option>
                  <?php foreach ($securityQuestions as $question): ?>
                    <option value="<?php echo htmlspecialchars($question, ENT_QUOTES); ?>"<?php echo ($selectedRecoveryQuestions[2] ?? '') === $question ? ' selected' : ''; ?>><?php echo htmlspecialchars($question); ?></option>
                  <?php endforeach; ?>
                </select>
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
  <script src="../javascript/recover.js?v=1.2"></script>
  <script src="../javascript/routing.js"></script>
  <script src="../javascript/inspect.js"></script>

</body>
</html>
