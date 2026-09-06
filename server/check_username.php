<?php
include 'db.php';

header('Content-Type: application/json');

if (isset($_POST['username'])) {
    $username = trim($_POST['username']);
    
    // Check if username is empty
    if (empty($username)) {
        echo json_encode(['exists' => false]);
        exit();
    }
    
    // Check if username exists in database
    $sql = "SELECT username FROM users WHERE username = ?";
    $stmt = mysqli_prepare($connect, $sql);
    mysqli_stmt_bind_param($stmt, "s", $username);
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
