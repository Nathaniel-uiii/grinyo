<?php
function activity_log_browser_os()
{
    $userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown');
    $browser = 'Unknown browser';
    $os = 'Unknown OS';

    if (preg_match('/Edg\/([\d.]+)/i', $userAgent)) {
        $browser = 'Edge';
    } elseif (preg_match('/Chrome\/([\d.]+)/i', $userAgent)) {
        $browser = 'Chrome';
    } elseif (preg_match('/Firefox\/([\d.]+)/i', $userAgent)) {
        $browser = 'Firefox';
    } elseif (preg_match('/Safari\/([\d.]+)/i', $userAgent)) {
        $browser = 'Safari';
    } elseif (preg_match('/MSIE\s|Trident\//i', $userAgent)) {
        $browser = 'Internet Explorer';
    }

    if (preg_match('/Windows/i', $userAgent)) {
        $os = 'Windows';
    } elseif (preg_match('/Android/i', $userAgent)) {
        $os = 'Android';
    } elseif (preg_match('/iPhone|iPad|iPod/i', $userAgent)) {
        $os = 'iOS';
    } elseif (preg_match('/Mac OS X|Macintosh/i', $userAgent)) {
        $os = 'macOS';
    } elseif (preg_match('/Linux/i', $userAgent)) {
        $os = 'Linux';
    }

    return $browser . ' / ' . $os;
}

function activity_log_ip_address()
{
    $ipAddress = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($ipAddress === '::1') {
        return '127.0.0.1';
    }
    return filter_var($ipAddress, FILTER_VALIDATE_IP) ? $ipAddress : 'Unknown';
}

function activity_log_start($connect, $userId, $name, $role, $section, $details)
{
    $sessionKey = bin2hex(random_bytes(16));
    $browserOs = activity_log_browser_os();
    $ipAddress = activity_log_ip_address();
    $stmt = mysqli_prepare($connect, "INSERT INTO activity_logs (session_key, user_id, name, role, section, browser_os, time_in, ip_address, details) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?)");
    if (!$stmt) {
        return '';
    }
    mysqli_stmt_bind_param($stmt, 'ssssssss', $sessionKey, $userId, $name, $role, $section, $browserOs, $ipAddress, $details);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok ? $sessionKey : '';
}

function activity_log_event($connect, $userId, $name, $role, $section, $details)
{
    $sessionKey = bin2hex(random_bytes(16));
    $browserOs = activity_log_browser_os();
    $ipAddress = activity_log_ip_address();
    $stmt = mysqli_prepare($connect, "INSERT INTO activity_logs (session_key, user_id, name, role, section, browser_os, time_in, time_out, ip_address, details) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), ?, ?)");
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'ssssssss', $sessionKey, $userId, $name, $role, $section, $browserOs, $ipAddress, $details);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

function activity_log_end($connect, $sessionKey, $details = 'Logged out')
{
    if ($sessionKey === '') {
        return;
    }
    $stmt = mysqli_prepare($connect, "UPDATE activity_logs SET time_out = NOW(), details = ? WHERE session_key = ? AND time_out IS NULL LIMIT 1");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'ss', $details, $sessionKey);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}