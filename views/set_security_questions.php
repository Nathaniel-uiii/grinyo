<?php
include '../server/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
// If already logged in, send to dashboard
if (isset($_SESSION['auth_user_id'])) {
    header('Location: ./index.php');
    exit();
}

// Require pending registration data from previous step
$pending = isset($_SESSION['pending_registration']) ? $_SESSION['pending_registration'] : null;
if (!$pending) {
    header('Location: ./register.php');
    exit();
}

// If GET, show the form below
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // fall through to HTML
} else {
    // Collect values from form (selected questions and answers)
    $q1 = isset($_POST['q1']) ? trim($_POST['q1']) : '';
    $q2 = isset($_POST['q2']) ? trim($_POST['q2']) : '';
    $q3 = isset($_POST['q3']) ? trim($_POST['q3']) : '';
    $a1 = isset($_POST['a1']) ? trim($_POST['a1']) : '';
    $a2 = isset($_POST['a2']) ? trim($_POST['a2']) : '';
    $a3 = isset($_POST['a3']) ? trim($_POST['a3']) : '';

    // Required checks
    if ($q1 === '' || $q2 === '' || $q3 === '' || $a1 === '' || $a2 === '' || $a3 === '') {
        header('Location: ./set_security_questions.php?error=required');
        exit();
    }
    // Ensure chosen questions are unique
    if (count(array_unique([$q1, $q2, $q3])) < 3) {
        header('Location: ./set_security_questions.php?error=duplicate');
        exit();
    }

    // Truncate answers (defense-in-depth)
    $a1 = mb_substr($a1, 0, 255);
    $a2 = mb_substr($a2, 0, 255);
    $a3 = mb_substr($a3, 0, 255);
    
    // Hash answers for privacy
    $h1 = password_hash($a1, PASSWORD_DEFAULT);
    $h2 = password_hash($a2, PASSWORD_DEFAULT);
    $h3 = password_hash($a3, PASSWORD_DEFAULT);

    // Begin simple transaction
    mysqli_begin_transaction($connect);

    // Unified insert into users (includes personal, address, account, and security Q&A)
    $sql_user = "INSERT INTO `users` (
        `user_id`, `first_name`, `middle_name`, `last_name`, `extension_name`, `date_of_birth`, `age`, `sex`,
        `username`, `email`, `password_hash`,
        `street`, `barangay`, `city_municipality`, `province`, `country`, `zip_code`,
        `q1`, `a1`, `q2`, `a2`, `q3`, `a3`
    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
    $stmt_user = mysqli_prepare($connect, $sql_user);
    if ($stmt_user === false) { mysqli_rollback($connect); header('Location: ./set_security_questions.php?error=stmt'); exit(); }
    // Create local variables (bind_param requires variables by reference)
    $uid   = $pending['user_id'];
    $fn    = $pending['first_name'];
    $mn    = $pending['middle_name'];
    $ln    = $pending['last_name'];
    $en    = $pending['extension_name'];
    $dob   = $pending['date_of_birth'];
    $age   = (int)$pending['age'];
    $sex   = $pending['sex'];
    $un    = $pending['username'];
    $em    = $pending['email'];
    $ph    = $pending['password_hash'];
    $st    = $pending['street'];
    $brgy  = $pending['barangay'];
    $citym = $pending['city'];
    $prov  = $pending['province'];
    $ctry  = $pending['country'];
    $zip   = $pending['zip_code'];
    $qq1 = $q1; $aa1 = $h1; $qq2 = $q2; $aa2 = $h2; $qq3 = $q3; $aa3 = $h3;

    // Build dynamic types string: 6 strings + 1 int + remaining strings
    $bindParams = [
        &$uid, &$fn, &$mn, &$ln, &$en, &$dob,
        &$age, // int
        &$sex, &$un, &$em, &$ph,
        &$st, &$brgy, &$citym, &$prov, &$ctry, &$zip,
        &$qq1, &$aa1, &$qq2, &$aa2, &$qq3, &$aa3
    ];
    $types = 'ssssss' . 'i' . str_repeat('s', count($bindParams) - 7);
    $bindArgs = array_merge([$types], $bindParams);
    call_user_func_array([$stmt_user, 'bind_param'], $bindArgs);
    $ok_user = mysqli_stmt_execute($stmt_user);
    mysqli_stmt_close($stmt_user);

    if ($ok_user) {
        mysqli_commit($connect);
        unset($_SESSION['pending_registration']);
        header('Location: ./login.php?registered=success');
        exit();
    } else {
        mysqli_rollback($connect);
        header('Location: ./set_security_questions.php?error=save');
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Set Security Questions</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="../css/security_questions.css?v=1.0" />
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
        <a onclick="getHome()" class="nav-link">Home</a>
        <a onclick="getLogin()" class="nav-link">Login</a>
      </div>
    </div>
  </nav>

  <main class="main-content">
    <div class="content-wrapper">
      <div class="form-section">
        <div class="sq-container">
          <form action="" method="POST" autocomplete="off" novalidate>
            <div class="form-header">
              <h1>Set Your Authentication Questions</h1>
              <p class="subtitle">These will help you recover your account if you forget your password.</p>
            </div>

            <div class="form-group">
              <div class="form-box">
                <label for="q1">Question 1 <span class="required">*</span></label>
                <select id="q1" name="q1" required>
                  <option value="" selected disabled>-Select a question-</option>
                </select>
              </div>
              <div class="form-box">
                <label for="a1">Answer <span class="required">*</span></label>
                <div class="password-wrapper">
                  <input type="password" id="a1" name="a1" required />
                  <i class="fa-solid fa-eye-slash" id="eyeicon-a1"></i>
                </div>
              </div>

              <div class="form-box">
                <label for="q2">Question 2 <span class="required">*</span></label>
                <select id="q2" name="q2" required>
                  <option value="" selected disabled>-Select a question-</option>
                </select>
              </div>
              <div class="form-box">
                <label for="a2">Answer <span class="required">*</span></label>
                <div class="password-wrapper">
                  <input type="password" id="a2" name="a2" required />
                  <i class="fa-solid fa-eye-slash" id="eyeicon-a2"></i>
                </div>
              </div>

              <div class="form-box">
                <label for="q3">Question 3 <span class="required">*</span></label>
                <select id="q3" name="q3" required>
                  <option value="" selected disabled>-Select a question-</option>
                </select>
              </div>
              <div class="form-box">
                <label for="a3">Answer <span class="required">*</span></label>
                <div class="password-wrapper">
                  <input type="password" id="a3" name="a3" required />
                  <i class="fa-solid fa-eye-slash" id="eyeicon-a3"></i>
                </div>
              </div>
            </div>

            <div class="form-actions">
              <button type="button" onclick="history.back()">Previous</button>
              <button type="submit" name="submit">Register</button>
            </div>
          </form>
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
  <script src="../javascript/routing.js"></script>
  <script src="../javascript/inspect.js"></script>
</body>
</html>