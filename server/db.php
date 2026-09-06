<?php
// Database connection
$host = 'localhost';
$user = 'root';
$pass = '';
$db = 'ube_delights_db';

$probe = @fsockopen('127.0.0.1', 3306, $errorNumber, $errorMessage, 2);
if (!$probe) {
    http_response_code(503);
    exit('Database service is unavailable. Start MySQL in XAMPP and try again.');
}
stream_set_timeout($probe, 2);
$greeting = fread($probe, 1);
fclose($probe);
if ($greeting === false || $greeting === '') {
    http_response_code(503);
    exit('Database service is unavailable. Restart MySQL in XAMPP and try again.');
}

$connect = mysqli_init();
mysqli_options($connect, MYSQLI_OPT_CONNECT_TIMEOUT, 3);
mysqli_real_connect($connect, $host, $user, $pass, $db);

if (!$connect || mysqli_connect_errno()) {
    http_response_code(503);
    exit('Database service is unavailable. Start MySQL in XAMPP and try again.');
}
?>
