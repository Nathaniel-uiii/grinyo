<?php
/* A single, database-backed duty slot for super administrator sessions. */

function superadmin_duty_acquire($connect, $userId, $sessionId)
{
    if (!mysqli_begin_transaction($connect)) {
        return false;
    }

    $result = mysqli_query($connect, 'SELECT user_id, session_id, last_seen FROM superadmin_duty WHERE lock_id = 1 FOR UPDATE');
    $duty = $result ? mysqli_fetch_assoc($result) : null;
    if ($result) {
        mysqli_free_result($result);
    }
    if (!$duty) {
        mysqli_rollback($connect);
        return false;
    }

    // An abandoned browser cannot reserve the duty slot forever.
    $isCurrent = !empty($duty['user_id']) && !empty($duty['last_seen'])
        && strtotime($duty['last_seen']) >= (time() - (30 * 60));
    if ($isCurrent && $duty['user_id'] !== $userId) {
        mysqli_rollback($connect);
        return false;
    }

    $stmt = mysqli_prepare($connect, 'UPDATE superadmin_duty SET user_id = ?, session_id = ?, last_seen = NOW() WHERE lock_id = 1');
    if (!$stmt) {
        mysqli_rollback($connect);
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'ss', $userId, $sessionId);
    $saved = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    if (!$saved) {
        mysqli_rollback($connect);
        return false;
    }
    return mysqli_commit($connect);
}

function superadmin_duty_refresh($connect, $userId, $sessionId)
{
    // Always advance the timestamp. A plain NOW() can equal the value written
    // during login, making MySQL report zero changed rows and falsely ending
    // a valid session on its first page load.
    $stmt = mysqli_prepare($connect, 'UPDATE superadmin_duty SET last_seen = IF(last_seen >= NOW(), DATE_ADD(last_seen, INTERVAL 1 SECOND), NOW()) WHERE lock_id = 1 AND user_id = ? AND session_id = ?');
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'ss', $userId, $sessionId);
    $ok = mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) === 1;
    mysqli_stmt_close($stmt);
    return $ok;
}

function superadmin_duty_release($connect, $userId, $sessionId)
{
    // Logout is authoritative for the on-duty account.  Do not leave the
    // shared slot occupied if PHP has regenerated or rotated its session ID.
    $stmt = mysqli_prepare($connect, 'UPDATE superadmin_duty SET user_id = NULL, session_id = NULL, last_seen = NULL WHERE lock_id = 1 AND user_id = ?');
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 's', $userId);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}
