<?php
include 'db.php';

header('Content-Type: application/json');

if (isset($_POST['email'])) {
    $email = trim($_POST['email']);

    if (empty($email)) {
        echo json_encode(['exists' => false]);
        exit();
    }

    $sql = "SELECT email FROM users WHERE email = ?";
    $stmt = mysqli_prepare($connect, $sql);
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if (mysqli_num_rows($result) > 0) {
        echo json_encode(['exists' => true]);
    } else {
        echo json_encode(['exists' => false]);
    }

    mysqli_stmt_close($stmt);
} else {
    echo json_encode(['exists' => false]);
}

mysqli_close($connect);
?>
