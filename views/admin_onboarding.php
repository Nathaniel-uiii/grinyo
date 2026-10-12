<?php
require_once __DIR__ . '/../server/db.php';
require_once __DIR__ . '/../server/superadmin_duty.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$userId = (string)($_SESSION['onboarding_user_id'] ?? '');
if ($userId === '' || !isset($_SESSION['auth_user_id']) || $_SESSION['auth_user_id'] !== $userId) {
    header('Location: ./login.php');
    exit();
}
if (($_SESSION['auth_role'] ?? '') === 'superadmin' && !superadmin_duty_refresh($connect, $userId, session_id())) {
    $_SESSION = [];
    session_destroy();
    header('Location: ./login.php?error=superadmin_session_ended');
    exit();
}

$stmt = mysqli_prepare($connect, "SELECT first_name, middle_name, last_name, extension_name, date_of_birth, age, sex, street, barangay, city_municipality, province, country, zip_code, username, email, employee_id, role FROM users WHERE user_id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 's', $userId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user = $result ? mysqli_fetch_assoc($result) : null;
mysqli_stmt_close($stmt);
if (!$user) { session_destroy(); header('Location: ./login.php'); exit(); }

$questions = [
    'Who is your best friend in Elementary?',
    'What is the name of your favorite pet?',
    'Who is your favorite teacher in high school?',
    'What city were you born in?',
    "What is your mother's maiden name?",
    'What was the name of your first school?'
];
$message = '';
$stage = $_SESSION['onboarding_stage'] ?? 'profile';
if (!in_array($stage, ['profile', 'address', 'questions'], true)) {
  $stage = 'profile';
  $_SESSION['onboarding_stage'] = $stage;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($stage === 'profile') {
        $firstName = trim((string)($_POST['first_name'] ?? ''));
        $middleName = trim((string)($_POST['middle_name'] ?? ''));
        $lastName = trim((string)($_POST['last_name'] ?? ''));
        $extensionName = trim((string)($_POST['extension_name'] ?? ''));
        $dateOfBirth = trim((string)($_POST['date_of_birth'] ?? ''));
        $age = (int)($_POST['age'] ?? 0);
        $sex = trim((string)($_POST['sex'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        if ($firstName === '' || $lastName === '' || $dateOfBirth === '' || $age < 1 || !in_array($sex, ['Male', 'Female'], true) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
          $message = 'Complete all required personal information.';
        } else {
          $update = mysqli_prepare($connect, "UPDATE users SET first_name = ?, middle_name = ?, last_name = ?, extension_name = ?, date_of_birth = ?, age = ?, sex = ?, email = ? WHERE user_id = ?");
          mysqli_stmt_bind_param($update, 'ssssssiss', $firstName, $middleName, $lastName, $extensionName, $dateOfBirth, $age, $sex, $email, $userId);
          $ok = mysqli_stmt_execute($update);
          mysqli_stmt_close($update);
          if ($ok) {
              $_SESSION['onboarding_stage'] = 'address';
              header('Location: ./admin_onboarding.php');
              exit();
          }
          $message = 'Unable to update your account.';
        }
    } elseif ($stage === 'address') {
        $street = trim((string)($_POST['street'] ?? ''));
        $barangay = trim((string)($_POST['barangay'] ?? ''));
        $city = trim((string)($_POST['city_municipality'] ?? ''));
        $province = trim((string)($_POST['province'] ?? ''));
        $country = trim((string)($_POST['country'] ?? ''));
        $zipCode = trim((string)($_POST['zip_code'] ?? ''));
        $newPassword = (string)($_POST['new_password'] ?? '');
        if ($street === '' || $barangay === '' || $city === '' || $province === '' || $country === '' || $zipCode === '') {
          $message = 'Complete all required address information.';
        } elseif ($newPassword === '' || strlen($newPassword) < 8) {
          $message = 'Password must be at least 8 characters long.';
        } elseif (strlen($newPassword) > 50 || preg_match('/\s/', $newPassword) || !preg_match('/[A-Z]/', $newPassword) || !preg_match('/[a-z]/', $newPassword) || !preg_match('/\d/', $newPassword) || !preg_match('/[^A-Za-z0-9]/', $newPassword)) {
          $message = 'Password needs uppercase, lowercase, number, special character, and no spaces.';
        } else {
          $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
          $update = mysqli_prepare($connect, "UPDATE users SET street = ?, barangay = ?, city_municipality = ?, province = ?, country = ?, zip_code = ?, password_hash = ? WHERE user_id = ?");
          mysqli_stmt_bind_param($update, 'ssssssss', $street, $barangay, $city, $province, $country, $zipCode, $passwordHash, $userId);
          $ok = mysqli_stmt_execute($update);
          mysqli_stmt_close($update);
          if ($ok) {
              $_SESSION['onboarding_stage'] = 'questions';
              header('Location: ./admin_onboarding.php');
              exit();
          }
          $message = 'Unable to update your account.';
        }
    } elseif ($stage === 'questions') {
        $q1 = trim((string)($_POST['q1'] ?? '')); $q2 = trim((string)($_POST['q2'] ?? '')); $q3 = trim((string)($_POST['q3'] ?? ''));
        $a1 = trim((string)($_POST['a1'] ?? '')); $a2 = trim((string)($_POST['a2'] ?? '')); $a3 = trim((string)($_POST['a3'] ?? ''));
        if (!in_array($q1, $questions, true) || !in_array($q2, $questions, true) || !in_array($q3, $questions, true) || count(array_unique([$q1, $q2, $q3])) < 3 || $a1 === '' || $a2 === '' || $a3 === '') {
            $message = 'Choose three different questions and answer all of them.';
        } else {
            $update = mysqli_prepare($connect, "UPDATE users SET q1 = ?, a1 = ?, q2 = ?, a2 = ?, q3 = ?, a3 = ?, onboarding_required = 0 WHERE user_id = ?");
            $h1 = password_hash($a1, PASSWORD_DEFAULT); $h2 = password_hash($a2, PASSWORD_DEFAULT); $h3 = password_hash($a3, PASSWORD_DEFAULT);
            mysqli_stmt_bind_param($update, 'sssssss', $q1, $h1, $q2, $h2, $q3, $h3, $userId);
            $ok = mysqli_stmt_execute($update);
            mysqli_stmt_close($update);
            if ($ok) {
                unset($_SESSION['onboarding_user_id'], $_SESSION['onboarding_stage']);
                $destRole = $_SESSION['auth_role'] ?? 'user';
                header('Location: ' . (in_array($destRole, ['admin', 'superadmin'], true) ? './admin.php' : './index.php'));
                exit();
            }
            $message = 'Unable to save your security questions.';
        }
    }
}

function onboarding_value($user, $key) { return htmlspecialchars((string)($user[$key] ?? ''), ENT_QUOTES, 'UTF-8'); }
function onboarding_profile_value($user, $key) {
    // These are temporary values used only to create a database row before the
    // administrator completes onboarding. They must not look like user-provided
    // profile information in Step 2.
    $temporaryDefaults = [
        'first_name' => 'New',
        'last_name' => 'Administrator',
        'date_of_birth' => '1990-01-01',
        'age' => '35',
        'sex' => 'Male',
        'street' => 'Main Street',
        'barangay' => 'Barangay 1',
        'city_municipality' => 'City',
        'province' => 'Province',
        'country' => 'Philippines',
        'zip_code' => '0000'
    ];
    $value = (string)($user[$key] ?? '');
    if (isset($temporaryDefaults[$key]) && $value === $temporaryDefaults[$key]) {
        $value = '';
    }
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Administrator Setup</title><link rel="stylesheet" href="../css/admin.css?v=1.0"></head>
<body>
<div class="onboarding-shell">
  <section class="onboarding-card">
    <h1><?php echo $stage === 'questions' ? 'Set Security Questions' : 'Update Your Account'; ?></h1>
    <p class="onboarding-step">Step <?php echo $stage === 'profile' ? '1 of 3' : ($stage === 'address' ? '2 of 3' : '3 of 3'); ?></p>
    <?php if ($message !== ''): ?><p class="account-result error-result" role="alert"><?php echo htmlspecialchars($message); ?></p><?php endif; ?>
        <?php if ($stage === 'profile'): ?>
      <p>Review and complete your personal information.</p>
      <form method="POST" class="onboarding-form" data-skip-availability-checks="true">
                <h2>Personal Information</h2>
        <label>First Name <span class="required">*</span><input id="fname" name="first_name" value="<?php echo onboarding_profile_value($user, 'first_name'); ?>" required></label>
        <label>Middle Name <span class="optional">(optional)</span><input id="mname" name="middle_name" value="<?php echo onboarding_profile_value($user, 'middle_name'); ?>"></label>
        <label>Last Name <span class="required">*</span><input id="lname" name="last_name" value="<?php echo onboarding_profile_value($user, 'last_name'); ?>" required></label>
        <label>Extension Name <span class="optional">(optional)</span><input id="ename" name="extension_name" value="<?php echo onboarding_profile_value($user, 'extension_name'); ?>"></label>
                <label>Date of Birth <span class="required">*</span><input type="date" name="date_of_birth" id="bday" value="<?php echo onboarding_profile_value($user, 'date_of_birth'); ?>" required></label>
                <label>Age <span class="required">*</span><input type="number" name="age" id="age" value="<?php echo onboarding_profile_value($user, 'age'); ?>" min="1" max="120" readonly required></label>
                <label>Sex <span class="required">*</span><select id="sex" name="sex" required><option value="">Choose sex</option><option value="Male" <?php echo onboarding_profile_value($user, 'sex') === 'Male' ? 'selected' : ''; ?>>Male</option><option value="Female" <?php echo onboarding_profile_value($user, 'sex') === 'Female' ? 'selected' : ''; ?>>Female</option></select></label>
        <label>Email <span class="required">*</span><input id="email" type="email" name="email" value="<?php echo onboarding_value($user, 'email'); ?>" required></label>
        <button type="submit">Next</button>
      </form>
    <?php elseif ($stage === 'address'): ?>
      <p>Complete your address and account information.</p>
      <form method="POST" class="onboarding-form" data-skip-availability-checks="true">
                <h2>Address Information</h2>
                <label>Street <span class="required">*</span><input id="street" name="street" value="<?php echo onboarding_profile_value($user, 'street'); ?>" required></label>
                <label>Barangay <span class="required">*</span><input id="brgy" name="barangay" value="<?php echo onboarding_profile_value($user, 'barangay'); ?>" required></label>
                <label>City / Municipality <span class="required">*</span><input id="city" name="city_municipality" value="<?php echo onboarding_profile_value($user, 'city_municipality'); ?>" required></label>
                <label>Province <span class="required">*</span><input id="province" name="province" value="<?php echo onboarding_profile_value($user, 'province'); ?>" required></label>
                <label>Country <span class="required">*</span><input id="country" name="country" value="<?php echo onboarding_profile_value($user, 'country'); ?>" required></label>
                <label>ZIP Code <span class="required">*</span><input id="zipcode" type="text" name="zip_code" value="<?php echo onboarding_profile_value($user, 'zip_code'); ?>" required></label>
                <h2>Account Information</h2>
                <label>Username <span class="required">*</span><input value="<?php echo onboarding_value($user, 'username'); ?>" readonly required></label>
                <label>User ID <span class="required">*</span><input id="user_id" value="<?php echo htmlspecialchars($userId, ENT_QUOTES, 'UTF-8'); ?>" readonly required></label>
                <label class="onboarding-password-field">Re-enter Password <span class="required">*</span><input id="onboarding-password" type="password" name="new_password" minlength="8" maxlength="50" autocomplete="new-password" required><small id="onboarding-password-strength" class="password-strength" aria-live="polite"></small><small class="password-requirements">Use 8-50 characters with uppercase, lowercase, number, special character, and no spaces.</small></label>
        <button type="submit">Next</button>
      </form>
    <?php else: ?>
      <p>Step 3 of 3 — Choose three questions and provide your answers.</p>
      <form method="POST" class="onboarding-form">
        <?php for ($i = 1; $i <= 3; $i++): ?><label>Question <?php echo $i; ?><select name="q<?php echo $i; ?>" required><option value="">Choose a question</option><?php foreach ($questions as $question): ?><option value="<?php echo htmlspecialchars($question, ENT_QUOTES); ?>"><?php echo htmlspecialchars($question); ?></option><?php endforeach; ?></select><input type="password" name="a<?php echo $i; ?>" aria-label="Answer to question <?php echo $i; ?>" required></label><?php endfor; ?>
        <button type="submit">Finish Setup</button>
      </form>
    <?php endif; ?>
  </section>
</div>
<script src="../javascript/disable_back.js"></script>
<script src="../javascript/admin_onboarding.js?v=1.0"></script>
<script src="../javascript/register.js?v=1.0"></script>
<script>
(function(){
  if (!(window.history && window.history.pushState)) return;
  var SENT = { ob: 1 };
  function lock(){
    try { history.pushState(SENT, document.title, location.href); } catch(e){}
    window.onpopstate = function(){ try { history.go(1); } catch(e){} };
  }
  lock();
  window.addEventListener('pageshow', lock, true);
  document.addEventListener('keydown', function(e){
    var tag = (e.target && e.target.tagName) ? e.target.tagName.toLowerCase() : '';
    if (e.key === 'Backspace' && tag !== 'input' && tag !== 'textarea') e.preventDefault();
  }, true);
})();
</script>
</body></html>
