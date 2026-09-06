<?php
include 'db.php';

header('Content-Type: application/json');

if (isset($_POST['id'])) {
    $user_id = $_POST['id'];
    
    // Validate ID format first
    if (!preg_match('/^\d{4}-\d{4}$/', $user_id)) {
        echo json_encode(['exists' => false]);
        exit();
    }
    
    // Check if ID exists in database
    $sql = "SELECT user_id FROM users WHERE user_id = ?";
    $stmt = mysqli_prepare($connect, $sql);
    mysqli_stmt_bind_param($stmt, "s", $user_id);
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
