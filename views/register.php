<?php
include '../server/db.php';
// Start session to pass newly created user id to the next step (security questions)
if (session_status() === PHP_SESSION_NONE) { session_start(); }
// If already logged in, send to dashboard
if (isset($_SESSION['auth_user_id'])) {
    header('Location: ./index.php');
    exit();
}

// Generate next auto-increment style User ID with year prefix (YYYY-xxxx)
// Start from <currentYear>-0001; roll over to next year after xxxx reaches 9999
$year = date('Y');
$generated_user_id = $year . '-0001';
$attempts = 0;
while ($attempts < 5) { // safety loop for rare cases where multiple years are saturated
    $q = "SELECT MAX(CAST(SUBSTRING_INDEX(user_id, '-', -1) AS UNSIGNED)) AS maxseq FROM users WHERE LEFT(user_id, 4) = '" . mysqli_real_escape_string($connect, $year) . "'";
    $res = mysqli_query($connect, $q);
    if ($res) {
        $row = mysqli_fetch_assoc($res);
        $maxseq = isset($row['maxseq']) ? (int)$row['maxseq'] : 0;
        mysqli_free_result($res);
        if ($maxseq >= 9999) {
            // move to next year and try again
            $year = (string)((int)$year + 1);
            $attempts++;
            continue;
        }
        $nextSeq = $maxseq + 1; // if 0, becomes 1
        $suffix = str_pad((string)$nextSeq, 4, '0', STR_PAD_LEFT);
        $generated_user_id = $year . '-' . $suffix;
        break;
    } else {
        // On query failure, fall back to current year's default
        $generated_user_id = $year . '-0001';
        break;
    }
}

// Add data
if(isset($_POST['submit'])){
    // Always use the server-generated ID to prevent tampering
    $user_id = $generated_user_id;
    
    // Validate and format User ID (xxxx-xxxx)
    if (!preg_match('/^\d{4}-\d{4}$/', $user_id)) {
        // If not in correct format, show error or redirect back
        header('location: ./register.php?error=invalid_id_format');
        exit();
    }
    
    $first_name = $_POST['fname'];
    $middle_name = $_POST['mname'];
    $last_name = $_POST['lname'];
    $extension_name = $_POST['ename'];
    $date_of_birth = $_POST['bday'];
    $age = $_POST['age'];
    $sex = $_POST['sex'];
    $username = $_POST['uname'];
    $email = $_POST['email'];
    $password = $_POST['pass'];
    $street = $_POST['street'];
    $barangay = $_POST['brgy'];
    $city = $_POST['city'];
    $province = $_POST['province'];
    $country = $_POST['country'];
    $zip_code = $_POST['zipcode'];
    
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // Defer DB writes until security questions are completed
    $_SESSION['pending_registration'] = array(
        'user_id' => $user_id,
        'first_name' => $first_name,
        'middle_name' => $middle_name,
        'last_name' => $last_name,
        'extension_name' => $extension_name,
        'date_of_birth' => $date_of_birth,
        'age' => $age,
        'sex' => $sex,
        'username' => $username,
        'email' => $email,
        'password_hash' => $hashed_password,
        'street' => $street,
        'barangay' => $barangay,
        'city' => $city,
        'province' => $province,
        'country' => $country,
        'zip_code' => $zip_code
    );

    header('Location: ./set_security_questions.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration Page</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../css/register.css?v=1.0">
</head>
<body>
    <nav class="navbar">
        <div class="nav-container">
            <div class="nav-logo">
                <a onclick="getHome()" class="logo-link">
                    <img src="../images/logo.png" alt="Ube Roll Logo" class="logo-image">
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
                <div class="register-container">
                    <form action="" method="POST">

                        <div class="form-sections">
                            <div class="section">
                                <h1>Personal Information</h1>
                                <div class="form-group">
                                    <div class="form-box">
                                        <label for="id">ID Number <span class="required">*</span></label>
                                        <input type="text" id="id" name="id" placeholder="xxxx-xxxx" value="<?php echo htmlspecialchars($generated_user_id, ENT_QUOTES, 'UTF-8'); ?>" readonly required>
                                    </div>
                                    <div class="form-box">
                                        <label for="fname">First Name <span class="required">*</span></label>
                                        <input type="text" id="fname" name="fname" required>
                                    </div>
                                    <div class="form-box">
                                        <label for="mname">Middle Name <span class="optional">(optional)</span></label>
                                        <input type="text" id="mname" name="mname">
                                    </div>
                                    <div class="form-box">
                                        <label for="lname">Last Name <span class="required">*</span></label>
                                        <input type="text" id="lname" name="lname" required>
                                    </div>
                                    <div class="form-box">
                                        <label for="ename">Extension Name <span class="optional">(optional)</span></label>
                                        <input type="text" id="ename" name="ename">
                                    </div>
                                    <div class="form-box">
                                        <label for="bday">Date of Birth <span class="required">*</span></label>
                                        <input type="date" id="bday" name="bday" onchange="calculateAge()">
                                    </div>
                                    <div class="form-box">
                                        <label for="age">Age <span class="required" placeholder="Please set your birthdate">*</span></label>
                                        <input type="text" id="age" name="age" readonly>
                                    </div>
                                    <div class="form-box">
                                        <label for="sex">Sex <span class="required">*</span></label>
                                        <select name="sex" id="sex" required>
                                            <option value="">-Select Sex-</option>
                                            <option value="Male">Male</option>
                                            <option value="Female">Female</option>
                                        </select>
                                    </div>
                                    <div class="form-box">
                                        <label for="email">Email Address <span class="required">*</span></label>
                                        <input type="text" id="email" name="email" required>
                                    </div>
                                </div>
                            </div>

                            <div class="section">
                                <h1>Address Information</h1>
                                <div class="form-group">
                                    <div class="form-box">
                                        <label for="street">Purok/Street <span class="required">*</span></label>
                                        <input type="text" name="street" id="street" required>
                                    </div>
                                    <div class="form-box">
                                        <label for="brgy">Barangay <span class="required">*</span></label>
                                        <input type="text" name="brgy" id="brgy" required>
                                    </div>
                                    <div class="form-box">
                                        <label for="city">City/Municipality <span class="required">*</span></label>
                                        <input type="text" name="city" id="city" required>
                                    </div>
                                    <div class="form-box">
                                        <label for="province">Province <span class="required">*</span></label>
                                        <input type="text" name="province" id="province" required>
                                    </div>
                                    <div class="form-box">
                                        <label for="country">Country <span class="required">*</span></label>
                                        <input type="text" name="country" id="country" required>
                                    </div>
                                    <div class="form-box">
                                        <label for="zipcode">Zip Code <span class="required">*</span></label>
                                        <input type="number" name="zipcode" id="zipcode" required>
                                    </div>
                                </div>
                            </div>

                            <div class="section">
                                <h1>Account Information</h1>
                                <div class="form-group">
                                    <div class="form-box">
                                        <label for="user">Username <span class="required">*</span></label>
                                        <input type="text" id="user" name="uname" required>
                                    </div>
                                    <div class="form-box">
                                        <label for="pass">Password <span class="required">*</span> <span id="pass-strength" class="field-hint"></span></label>
                                        <div class="password-wrapper">
                                            <input type="password" id="pass" name="pass" required>
                                            <i class="fa-solid fa-eye-slash" id="eyeicon-register"></i>
                                        </div>
                                    </div>
                                    <div class="form-box">
                                        <label for="repass">Re-Enter Password <span class="required">*</span> <span id="repass-match" class="field-hint"></span></label>
                                        <input type="password" id="repass" name="repass" required>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" name="submit">Next</button>
                            <div class="login-prompt">
                                Already have an account? <a onclick="getLogin()">Log In</a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>

    <footer class="footer">
        <p>&copy; 2025 Ube Delights. All rights reserved.</p>
    </footer>
    <script src="../javascript/disable_back.js"></script>
    <script src="../javascript/noauto.js"></script>
    <script src="../javascript/register.js"></script>
    <script src="../javascript/routing.js"></script>
    <script src="../javascript/inspect.js"></script>
</body>
</html>