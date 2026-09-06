<?php
include 'db.php';

header('Content-Type: application/json');

if (!isset($_POST['password'])) {
    echo json_encode(['exists' => false]);
    mysqli_close($connect);
    exit();
}

$password = (string)$_POST['password'];
$password = trim($password);

if ($password === '') {
    echo json_encode(['exists' => false]);
    mysqli_close($connect);
    exit();
}

// Fetch password hashes and verify using password_verify (hashes are salted)
$sql = "SELECT password_hash FROM users";
$result = mysqli_query($connect, $sql);

$exists = false;
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        if (!empty($row['password_hash']) && password_verify($password, $row['password_hash'])) {
            $exists = true;
            break;
        }
    }
}

echo json_encode(['exists' => $exists]);

mysqli_close($connect);
?>
