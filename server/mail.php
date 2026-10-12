<?php
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

require_once __DIR__ . '/../vendor/PHPMailer/src/Exception.php';
require_once __DIR__ . '/../vendor/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/PHPMailer/src/SMTP.php';

function load_mail_env() {
    $envPath = __DIR__ . '/../.env';
    if (!is_readable($envPath)) return;

    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($value !== '' && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) {
            $value = substr($value, 1, -1);
        }
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
        }
    }
}

function send_recovery_pin($email, $pin) {
    load_mail_env();
    $host = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
    $port = (int)(getenv('SMTP_PORT') ?: 587);
    $username = getenv('SMTP_USERNAME');
    $password = getenv('SMTP_PASSWORD');
    $from = getenv('SMTP_FROM_EMAIL') ?: $username;
    $fromName = getenv('SMTP_FROM_NAME') ?: 'UBE Security';

    if (!$username || !$password || !$from) {
        error_log('OTP email is not configured: set SMTP_USERNAME, SMTP_PASSWORD, and SMTP_FROM_EMAIL.');
        return false;
    }

    $mailer = new PHPMailer(true);
    try {
        $mailer->isSMTP();
        $mailer->Host = $host;
        $mailer->SMTPAuth = true;
        $mailer->Username = $username;
        $mailer->Password = $password;
        $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mailer->Port = $port;
        $mailer->CharSet = 'UTF-8';
        $mailer->setFrom($from, $fromName);
        $mailer->addAddress($email);
        $mailer->Subject = 'Ube Delights password recovery PIN';
        $mailer->Body = "Your Ube Delights password recovery PIN is: {$pin}\n\nThis PIN expires in 10 minutes and can be used only once. If you did not request a password reset, ignore this email.";
        $mailer->send();
        return true;
    } catch (Exception $exception) {
        error_log('OTP email failed: ' . $mailer->ErrorInfo);
        return false;
    }
}

function send_account_credentials($email, $username, $password) {
    load_mail_env();
    $host = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
    $port = (int)(getenv('SMTP_PORT') ?: 587);
    $smtpUsername = getenv('SMTP_USERNAME');
    $smtpPassword = getenv('SMTP_PASSWORD');
    $from = getenv('SMTP_FROM_EMAIL') ?: $smtpUsername;
    $fromName = getenv('SMTP_FROM_NAME') ?: 'UBE Security';

    if (!$smtpUsername || !$smtpPassword || !$from) {
        error_log('Account credentials email is not configured: set SMTP_USERNAME, SMTP_PASSWORD, and SMTP_FROM_EMAIL.');
        return false;
    }

    $mailer = new PHPMailer(true);
    try {
        $mailer->isSMTP();
        $mailer->Host = $host;
        $mailer->SMTPAuth = true;
        $mailer->Username = $smtpUsername;
        $mailer->Password = $smtpPassword;
        $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mailer->Port = $port;
        $mailer->CharSet = 'UTF-8';
        $mailer->setFrom($from, $fromName);
        $mailer->addAddress($email);
        $mailer->Subject = 'Your Ube Delights account credentials';
        $mailer->Body = "Your Ube Delights account has been created.\n\nUsername: {$username}\nPassword: {$password}\n\nUse these credentials to sign in. Please change your password after signing in and keep this email private.";
        $mailer->send();
        return true;
    } catch (Exception $exception) {
        error_log('Account credentials email failed: ' . $mailer->ErrorInfo);
        return false;
    }
}
