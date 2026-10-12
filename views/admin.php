<?php
include '../server/db.php';
require_once __DIR__ . '/../server/activity_log.php';
require_once __DIR__ . '/../server/mail.php';
require_once __DIR__ . '/../server/superadmin_duty.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['auth_user_id']) || !in_array($_SESSION['auth_role'] ?? 'user', ['admin', 'superadmin'], true)) {
    header('Location: ./login.php');
    exit();
}

$isSuperAdmin = ($_SESSION['auth_role'] ?? 'user') === 'superadmin';

if ($isSuperAdmin && !superadmin_duty_refresh($connect, (string)$_SESSION['auth_user_id'], session_id())) {
    $_SESSION = [];
    session_destroy();
    header('Location: ./login.php?error=superadmin_session_ended');
    exit();
}

$currentUserId = (string)$_SESSION['auth_user_id'];
$currentPrivileges = array_filter(array_map('trim', explode(',', (string)($_SESSION['auth_privileges'] ?? ''))));
$isAdmin = ($_SESSION['auth_role'] ?? 'user') === 'admin';
$canApprove = $isSuperAdmin || $isAdmin || in_array('approve_users', $currentPrivileges, true);
$canUpdate = $isSuperAdmin || in_array('update_accounts', $currentPrivileges, true);
$canManage = $isSuperAdmin || in_array('manage_users', $currentPrivileges, true);
$accountMessage = '';
$onDutySuperadminId = '';

function next_privileged_user_id($connect)
{
    $year = date('Y');
    $stmt = mysqli_prepare($connect, "SELECT MAX(CAST(SUBSTRING_INDEX(user_id, '-', -1) AS UNSIGNED)) AS maxseq FROM users WHERE LEFT(user_id, 4) = ?");
    $maxSequence = 0;
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 's', $year);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;
        $maxSequence = (int)($row['maxseq'] ?? 0);
        mysqli_stmt_close($stmt);
    }

    $nextSequence = $maxSequence + 1;
    if ($nextSequence > 9999) {
        $year = (string)((int)$year + 1);
        $nextSequence = 1;
    }

    return $year . '-' . str_pad((string)$nextSequence, 4, '0', STR_PAD_LEFT);
}

function render_access_badges($privileges)
{
    $labels = [
        'read_users' => 'Read',
        'manage_users' => 'Manage',
        'approve_users' => 'Approve',
        'update_accounts' => 'Edit',
        'delete_accounts' => 'Delete'
    ];
    $items = array_filter(array_map('trim', explode(',', (string)$privileges)));
    if (!$items) {
        return '<span class="access-empty">None</span>';
    }
    $badges = [];
    foreach ($items as $item) {
        if (isset($labels[$item])) {
            $badges[] = '<span class="access-badge">' . htmlspecialchars($labels[$item], ENT_QUOTES, 'UTF-8') . '</span>';
        }
    }
    return $badges ? implode('', $badges) : '<span class="access-empty">Custom</span>';
}

function account_display_status($account)
{
    global $onDutySuperadminId;
    if (!empty($account['onboarding_required'])) {
        return 'incomplete';
    }
    $status = (string)($account['approval_status'] ?? 'pending');
    // Keep the account enabled in the database, but show other superadmins
    // as unavailable while one of them holds the single duty slot.
    if ($status === 'active'
        && ($account['role'] ?? '') === 'superadmin'
        && $onDutySuperadminId !== ''
        && ($account['user_id'] ?? '') !== $onDutySuperadminId) {
        return 'inactive';
    }
    return $status;
}

// Counts super administrators that occupy a slot. Blocked accounts do not
// count, so blocking one frees a slot for another super administrator.
// Deliberately ignores onboarding_required: a freshly created account is
// hidden from the list until onboarding finishes, which would otherwise
// let the cap be bypassed.
function count_superadmin_slots($connect, $excludeUserId = '')
{
    $sql = "SELECT COUNT(*) FROM users WHERE role = 'superadmin' AND approval_status <> 'blocked'";
    $excludeUserId = (string)$excludeUserId;
    if ($excludeUserId !== '') {
        $sql .= " AND user_id <> '" . mysqli_real_escape_string($connect, $excludeUserId) . "'";
    }
    $result = mysqli_query($connect, $sql);
    return $result ? (int)mysqli_fetch_row($result)[0] : 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $targetUserId = trim($_POST['user_id'] ?? '');
    $targetIsSuperAdmin = false;
    $targetName = '';
    $targetRole = '';
    $targetStatus = '';
    if ($targetUserId !== '') {
        $targetRoleStmt = mysqli_prepare($connect, "SELECT first_name, middle_name, last_name, extension_name, role, approval_status FROM users WHERE user_id = ? LIMIT 1");
        if ($targetRoleStmt) {
            mysqli_stmt_bind_param($targetRoleStmt, 's', $targetUserId);
            mysqli_stmt_execute($targetRoleStmt);
            $targetRoleResult = mysqli_stmt_get_result($targetRoleStmt);
            $targetRoleRow = $targetRoleResult ? mysqli_fetch_assoc($targetRoleResult) : null;
            $targetIsSuperAdmin = ($targetRoleRow['role'] ?? '') === 'superadmin';
            $targetRole = (string)($targetRoleRow['role'] ?? 'user');
            $targetStatus = (string)($targetRoleRow['approval_status'] ?? 'pending');
            $targetName = trim(($targetRoleRow['first_name'] ?? '') . ' ' . ($targetRoleRow['middle_name'] ?? '') . ' ' . ($targetRoleRow['last_name'] ?? '') . ' ' . ($targetRoleRow['extension_name'] ?? ''));
            mysqli_stmt_close($targetRoleStmt);
        }
    }
    $actorName = $_SESSION['auth_username'] ?? $currentUserId;
    $actorStmt = mysqli_prepare($connect, "SELECT first_name, middle_name, last_name, extension_name FROM users WHERE user_id = ? LIMIT 1");
    if ($actorStmt) {
        mysqli_stmt_bind_param($actorStmt, 's', $currentUserId);
        mysqli_stmt_execute($actorStmt);
        $actorResult = mysqli_stmt_get_result($actorStmt);
        $actorRow = $actorResult ? mysqli_fetch_assoc($actorResult) : null;
        $fullActorName = trim(($actorRow['first_name'] ?? '') . ' ' . ($actorRow['middle_name'] ?? '') . ' ' . ($actorRow['last_name'] ?? '') . ' ' . ($actorRow['extension_name'] ?? ''));
        if ($fullActorName !== '') {
            $actorName = $fullActorName;
        }
        mysqli_stmt_close($actorStmt);
    }

    $protectedActions = ['approve_account', 'block_account', 'unblock_account', 'delete_account', 'reset_password'];
    if ($isSuperAdmin && in_array($action, $protectedActions, true)) {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $authStmt = mysqli_prepare($connect, "SELECT password_hash FROM users WHERE user_id = ? LIMIT 1");
        $authHash = null;
        if ($authStmt) {
            mysqli_stmt_bind_param($authStmt, 's', $currentUserId);
            mysqli_stmt_execute($authStmt);
            $authResult = mysqli_stmt_get_result($authStmt);
            $authRow = $authResult ? mysqli_fetch_assoc($authResult) : null;
            $authHash = $authRow['password_hash'] ?? null;
            mysqli_stmt_close($authStmt);
        }
        if (!$authHash || $currentPassword === '' || !password_verify($currentPassword, $authHash)) {
            $accountMessage = 'Action denied. Enter your correct super-admin password.';
            $action = '';
        }
    }

    if ($action === 'reset_password' && $isSuperAdmin) {
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');
        if ($targetUserId === '' || $targetUserId === $currentUserId) {
            $accountMessage = 'Choose another user to reset.';
        } elseif ($newPassword !== $confirmPassword) {
            $accountMessage = 'The new passwords do not match.';
        } elseif (strlen($newPassword) < 8 || strlen($newPassword) > 50 || preg_match('/\s/', $newPassword) || !preg_match('/[A-Z]/', $newPassword) || !preg_match('/[a-z]/', $newPassword) || !preg_match('/\d/', $newPassword) || !preg_match('/[^A-Za-z0-9]/', $newPassword)) {
            $accountMessage = 'New password needs uppercase, lowercase, number, special character, and no spaces.';
        } else {
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $resetStmt = mysqli_prepare($connect, "UPDATE users SET password_hash = ?, updated_by = ? WHERE user_id = ?");
            if ($resetStmt) {
                mysqli_stmt_bind_param($resetStmt, 'sss', $newHash, $currentUserId, $targetUserId);
                $resetOk = mysqli_stmt_execute($resetStmt);
                mysqli_stmt_close($resetStmt);
                $accountMessage = $resetOk ? 'User password reset successfully.' : 'Unable to reset the user password.';
                if ($resetOk) {
                    activity_log_event($connect, $currentUserId, $actorName, $_SESSION['auth_role'] ?? 'superadmin', 'Account Management', 'Reset password for ' . $targetUserId . ($targetName !== '' ? ' (' . $targetName . ')' : ''));
                }
            } else {
                $accountMessage = 'Unable to prepare the password reset request.';
            }
        }
        $action = '';
    }

    $normalizePrivileges = function ($payload) {
        $allowed = ['read_users', 'manage_users', 'approve_users', 'update_accounts', 'delete_accounts'];
        $selected = [];
        if (is_array($payload)) {
            foreach ($payload as $value) {
                $value = trim((string)$value);
                if ($value !== '' && in_array($value, $allowed, true)) {
                    $selected[] = $value;
                }
            }
        } else {
            $items = preg_split('/\s*,\s*/', trim((string)$payload), -1, PREG_SPLIT_NO_EMPTY);
            foreach ($items as $value) {
                if (in_array($value, $allowed, true)) {
                    $selected[] = $value;
                }
            }
        }
        return implode(',', array_values(array_unique($selected)));
    };

    if ($action === 'approve_account') {
        if ($canApprove && $targetUserId !== '' && $targetUserId !== $currentUserId) {
            $stmt = mysqli_prepare($connect, "UPDATE users SET approval_status = 'active', updated_by = ? WHERE user_id = ?");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'ss', $currentUserId, $targetUserId);
                $approved = mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                if ($approved) {
                    activity_log_event($connect, $currentUserId, $actorName, $_SESSION['auth_role'] ?? 'admin', 'Account Management', 'Approved account ' . $targetUserId . ($targetName !== '' ? ' (' . $targetName . ')' : ''));
                }
            }
        }
    }

    if ($action === 'block_account') {
        if ($canApprove && $targetUserId !== '' && $targetUserId !== $currentUserId) {
            $stmt = mysqli_prepare($connect, "UPDATE users SET approval_status = 'blocked', updated_by = ? WHERE user_id = ?");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'ss', $currentUserId, $targetUserId);
                $blocked = mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                if ($blocked) {
                    activity_log_event($connect, $currentUserId, $actorName, $_SESSION['auth_role'] ?? 'admin', 'Account Management', 'Blocked account ' . $targetUserId . ($targetName !== '' ? ' (' . $targetName . ')' : ''));
                }
            }
        }
    }

    if ($action === 'unblock_account') {
        if ($canApprove && $targetUserId !== '' && $targetUserId !== $currentUserId) {
            // Only a superadmin may demote a blocked superadmin here. A missing or
            // invalid choice falls back to retain, so a crafted request can never
            // demote an account silently.
            $resolution = (string)($_POST['unblock_resolution'] ?? 'retain');
            $demoteSuperadmin = $isSuperAdmin && $targetRole === 'superadmin' && $resolution === 'demote';

            // Two non-blocked super administrators already occupy both slots:
            // the blocked one can only come back by demoting it, otherwise the
            // active super administrator must first block another one and then
            // choose who to unblock.
            if ($targetRole === 'superadmin' && $resolution !== 'demote' && count_superadmin_slots($connect, $targetUserId) >= 2) {
                $accountMessage = 'Two super administrators are already active. Block another super administrator first, then choose who to unblock - or choose Demote.';
                $stmt = null;
            } elseif ($demoteSuperadmin) {
                $demotedRole = 'admin';
                $demotedPrivileges = 'read_users,manage_users,approve_users,update_accounts';
                $stmt = mysqli_prepare($connect, "UPDATE users SET approval_status = 'active', role = ?, privileges = ?, updated_by = ? WHERE user_id = ? AND approval_status = 'blocked'");
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, 'ssss', $demotedRole, $demotedPrivileges, $currentUserId, $targetUserId);
                }
            } else {
                $stmt = mysqli_prepare($connect, "UPDATE users SET approval_status = 'active', updated_by = ? WHERE user_id = ? AND approval_status = 'blocked'");
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, 'ss', $currentUserId, $targetUserId);
                }
            }

            if ($stmt) {
                $unblocked = mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                if ($unblocked) {
                    $resolutionNote = $demoteSuperadmin
                        ? ' and demoted to administrator'
                        : ($targetRole === 'superadmin' ? ' and retained the super administrator role' : '');
                    activity_log_event($connect, $currentUserId, $actorName, $_SESSION['auth_role'] ?? 'admin', 'Account Management', 'Unblocked account ' . $targetUserId . ($targetName !== '' ? ' (' . $targetName . ')' : '') . $resolutionNote);
                }
            }
        }
    }

    if ($action === 'update_account') {
        if (!$canUpdate) {
            $action = '';
        }
    }

    if ($action === 'update_account') {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        if (!preg_match('/^\d{4}-\d{4}$/', $targetUserId)) {
            $accountMessage = 'User ID must contain numbers in the format xxxx-xxxx.';
        } else {
            $allowedUpdateRoles = $isSuperAdmin ? ['user', 'admin', 'superadmin'] : ['user', 'admin'];
            $role = in_array($_POST['role'] ?? 'user', $allowedUpdateRoles, true) ? $_POST['role'] : 'user';
            if ($targetUserId === $currentUserId && $role !== $targetRole) {
                $accountMessage = 'You cannot change your own role.';
            } elseif ($targetUserId === $currentUserId && ($_POST['approval_status'] ?? '') !== $targetStatus) {
                $accountMessage = 'You cannot change your own status.';
            } elseif ($targetRole === 'superadmin' && $role === 'user') {
                $accountMessage = 'A super administrator cannot be demoted to a user.';
            } elseif ($targetRole === 'admin' && $role === 'user') {
                $accountMessage = 'An administrator cannot be demoted to a user.';
            } elseif ($role === 'superadmin' && $targetRole !== 'superadmin' && count_superadmin_slots($connect, $targetUserId) >= 2) {
                $accountMessage = 'Two super administrators are already active. Block one before promoting this account.';
            } else {
                $status = in_array($_POST['approval_status'] ?? 'pending', ['pending','active','blocked']) ? $_POST['approval_status'] : 'pending';
                $privileges = $normalizePrivileges($_POST['privileges'] ?? ['read_users']);
                if (!$isSuperAdmin) {
                    $privileges = implode(',', array_diff(explode(',', $privileges), ['delete_accounts']));
                }

                $stmt = mysqli_prepare($connect, "UPDATE users SET first_name = ?, last_name = ?, username = ?, email = ?, role = ?, approval_status = ?, privileges = ?, updated_by = ? WHERE user_id = ?");
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, 'sssssssss', $firstName, $lastName, $username, $email, $role, $status, $privileges, $currentUserId, $targetUserId);
                    $updated = mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                    if ($updated) {
                        $updatedName = trim($firstName . ' ' . $lastName);
                        activity_log_event($connect, $currentUserId, $actorName, $_SESSION['auth_role'] ?? 'admin', 'Account Management', 'Edited account ' . $targetUserId . ($updatedName !== '' ? ' (' . $updatedName . ')' : '') . ' [' . $role . ']');
                    }
                }
            }
        }
    }

    if ($action === 'create_privileged_account' && $isSuperAdmin) {
        $firstName = 'New';
        $lastName = 'Administrator';
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $role = $_POST['role'] ?? '';

        if ($username === '' || $email === '' || $password === '' || !in_array($role, ['user', 'admin', 'superadmin'], true)) {
            $accountMessage = 'Complete all required fields and choose a valid account role.';
        } elseif (!preg_match('/^[a-z]{5,10}_[0-9]{1,6}$/', $username)) {
            $accountMessage = 'Username format must be like xxxxx_12345.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $accountMessage = 'Enter a valid email address.';
        } elseif (strlen($password) < 8) {
            $accountMessage = 'The password must be at least 8 characters.';
        } elseif (strlen($password) > 50 || preg_match('/\s/', $password) || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/\d/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
            $accountMessage = 'Password needs uppercase, lowercase, number, special character, and no spaces.';
        } elseif ($role === 'superadmin' && count_superadmin_slots($connect) >= 2) {
            $accountMessage = 'Two super administrators are already active. Block one before creating another.';
        } else {
            $duplicateStmt = mysqli_prepare($connect, "SELECT user_id FROM users WHERE username = ? OR email = ? LIMIT 1");
            if ($duplicateStmt) {
                mysqli_stmt_bind_param($duplicateStmt, 'ss', $username, $email);
                mysqli_stmt_execute($duplicateStmt);
                $duplicateResult = mysqli_stmt_get_result($duplicateStmt);
                $duplicate = $duplicateResult ? mysqli_fetch_assoc($duplicateResult) : null;
                mysqli_stmt_close($duplicateStmt);
            }

            if (!empty($duplicate)) {
                $accountMessage = 'The username or email is already in use.';
            } else {
                $userId = next_privileged_user_id($connect);
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $middleName = '';
                $extensionName = '';
                $dateOfBirth = '1990-01-01';
                $age = 35;
                $sex = 'Male';
                $street = 'Main Street';
                $barangay = 'Barangay 1';
                $city = 'City';
                $province = 'Province';
                $country = 'Philippines';
                $zipCode = '0000';
                $q1 = 'What is your favorite color?';
                $a1 = password_hash('blue', PASSWORD_DEFAULT);
                $q2 = 'What is your mother\'s maiden name?';
                $a2 = password_hash('santos', PASSWORD_DEFAULT);
                $q3 = 'What city were you born in?';
                $a3 = password_hash('manila', PASSWORD_DEFAULT);
                $approvalStatus = 'active';
                $privileges = $role === 'superadmin'
                    ? 'read_users,manage_users,approve_users,update_accounts,delete_accounts'
                    : ($role === 'admin' ? 'read_users,manage_users,approve_users,update_accounts' : 'read_users');
                $isActive = 1;

                $createStmt = mysqli_prepare($connect, "INSERT INTO users (user_id, first_name, middle_name, last_name, extension_name, date_of_birth, age, sex, street, barangay, city_municipality, province, country, zip_code, username, email, password_hash, q1, a1, q2, a2, q3, a3, role, approval_status, privileges, created_by, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if ($createStmt) {
                    mysqli_stmt_bind_param($createStmt, 'ssssssissssssssssssssssssssi', $userId, $firstName, $middleName, $lastName, $extensionName, $dateOfBirth, $age, $sex, $street, $barangay, $city, $province, $country, $zipCode, $username, $email, $passwordHash, $q1, $a1, $q2, $a2, $q3, $a3, $role, $approvalStatus, $privileges, $currentUserId, $isActive);
                    $created = mysqli_stmt_execute($createStmt);
                    if (!$created) {
                        error_log('Administrator creation failed: ' . mysqli_stmt_error($createStmt));
                    }
                    mysqli_stmt_close($createStmt);
                    if ($created) {
                        mysqli_query($connect, "UPDATE users SET onboarding_required = 1 WHERE user_id = '" . mysqli_real_escape_string($connect, $userId) . "'");
                        $credentialsSent = send_account_credentials($email, $username, $password);
                        $verifyStmt = mysqli_prepare($connect, "SELECT role FROM users WHERE user_id = ? LIMIT 1");
                        $verifiedRole = null;
                        if ($verifyStmt) {
                            mysqli_stmt_bind_param($verifyStmt, 's', $userId);
                            mysqli_stmt_execute($verifyStmt);
                            $verifyResult = mysqli_stmt_get_result($verifyStmt);
                            $verified = $verifyResult ? mysqli_fetch_assoc($verifyResult) : null;
                            $verifiedRole = $verified['role'] ?? null;
                            mysqli_stmt_close($verifyStmt);
                        }
                        $accountMessage = $verifiedRole === $role
                            ? 'Account created successfully. User ID: ' . $userId . ($credentialsSent ? ' Login credentials were sent to the email address.' : ' The account was created, but the credentials email could not be sent.')
                            : 'The account was created, but its role was not saved correctly.';
                    } else {
                        $accountMessage = 'Unable to create the account.';
                    }
                } else {
                    error_log('Administrator creation prepare failed: ' . mysqli_error($connect));
                    $accountMessage = 'Unable to prepare the account creation request.';
                }
            }
        }
    }

    if ($action === 'delete_account') {
        if ($targetUserId !== '' && $targetUserId !== $currentUserId) {
            if ($isSuperAdmin) {
                $stmt = mysqli_prepare($connect, "DELETE FROM users WHERE user_id = ?");
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, 's', $targetUserId);
                    $deleted = mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) === 1;
                    mysqli_stmt_close($stmt);
                    if ($deleted) {
                        activity_log_event($connect, $currentUserId, $actorName, $_SESSION['auth_role'] ?? 'superadmin', 'Account Management', 'Deleted account ' . $targetUserId . ($targetName !== '' ? ' (' . $targetName . ')' : '') . ($targetRole !== '' ? ' [' . $targetRole . ']' : ''));
                    }
                }
            }
        }
    }

    if ($action === 'request_delete' && !$isSuperAdmin && $canManage) {
        $reason = trim($_POST['reason'] ?? '');
        if (!$targetIsSuperAdmin && $targetUserId !== '' && $reason !== '') {
            $stmt = mysqli_prepare($connect, "INSERT INTO account_delete_requests (target_user_id, requested_by, reason) VALUES (?, ?, ?)");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'sss', $targetUserId, $currentUserId, $reason);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        }
    }

    if ($action === 'approve_delete_request' && $isSuperAdmin) {
        $requestId = (int)($_POST['request_id'] ?? 0);
        if ($requestId > 0) {
            $stmt = mysqli_prepare($connect, "SELECT target_user_id FROM account_delete_requests WHERE id = ? AND status = 'pending' LIMIT 1");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'i', $requestId);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                $request = $result ? mysqli_fetch_assoc($result) : null;
                mysqli_stmt_close($stmt);
                if ($request) {
                    $deleteStmt = mysqli_prepare($connect, "DELETE FROM users WHERE user_id = ? AND user_id <> ?");
                    if ($deleteStmt) {
                        mysqli_stmt_bind_param($deleteStmt, 'ss', $request['target_user_id'], $currentUserId);
                        mysqli_stmt_execute($deleteStmt);
                        mysqli_stmt_close($deleteStmt);
                    }
                    $reviewStmt = mysqli_prepare($connect, "UPDATE account_delete_requests SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
                    if ($reviewStmt) {
                        mysqli_stmt_bind_param($reviewStmt, 'si', $currentUserId, $requestId);
                        mysqli_stmt_execute($reviewStmt);
                        mysqli_stmt_close($reviewStmt);
                    }
                }
            }
        }
    }

    if ($action === 'reject_delete_request' && $isSuperAdmin) {
        $requestId = (int)($_POST['request_id'] ?? 0);
        if ($requestId > 0) {
            $stmt = mysqli_prepare($connect, "UPDATE account_delete_requests SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = 'pending'");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'si', $currentUserId, $requestId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        }
    }
}

$filterUserId = trim($_GET['user_id'] ?? '');
$filterName = trim($_GET['name'] ?? '');
$filterRole = trim($_GET['role'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$isMyAccountView = ($_GET['view'] ?? '') === 'my-account';
$isDeleteRequestsView = ($_GET['view'] ?? '') === 'delete-requests';
$isActivityLogsView = ($_GET['view'] ?? '') === 'activity-logs';
if (!in_array($filterRole, ['user', 'admin', 'superadmin'], true)) {
    $filterRole = '';
}
if (!in_array($filterStatus, ['all', 'pending', 'active', 'blocked'], true)) {
    $filterStatus = '';
}
$accounts = [];
$pendingAccounts = 0;
$blockedAccounts = 0;
$totalUsers = 0;
$accountVisibility = $isSuperAdmin ? '' : " WHERE role <> 'superadmin'";
$summaryResult = mysqli_query($connect, "SELECT COUNT(*) AS total_users, SUM(CASE WHEN COALESCE(approval_status, 'pending') = 'pending' THEN 1 ELSE 0 END) AS pending_accounts, SUM(CASE WHEN approval_status = 'blocked' THEN 1 ELSE 0 END) AS blocked_accounts FROM users WHERE onboarding_required = 0" . ($isSuperAdmin ? '' : " AND role <> 'superadmin'"));
if ($summaryResult && ($summary = mysqli_fetch_assoc($summaryResult))) {
    $totalUsers = (int)($summary['total_users'] ?? 0);
    $pendingAccounts = (int)($summary['pending_accounts'] ?? 0);
    $blockedAccounts = (int)($summary['blocked_accounts'] ?? 0);
    mysqli_free_result($summaryResult);
}
$sql = "SELECT * FROM users WHERE onboarding_required = 0" . ($isSuperAdmin ? '' : " AND role <> 'superadmin'");
$bindTypes = '';
$params = [];
if ($filterUserId !== '') {
    $sql .= " AND user_id LIKE ?";
    $params[] = '%' . $filterUserId . '%';
    $bindTypes .= 's';
}
if ($filterName !== '') {
    $sql .= " AND CONCAT_WS(' ', first_name, middle_name, last_name, extension_name) LIKE ?";
    $params[] = '%' . $filterName . '%';
    $bindTypes .= 's';
}
if ($filterRole !== '') {
    $sql .= " AND role = ?";
    $params[] = $filterRole;
    $bindTypes .= 's';
}
if (in_array($filterStatus, ['pending', 'active', 'blocked'], true)) {
    $sql .= " AND COALESCE(approval_status, 'pending') = ?";
    $params[] = $filterStatus;
    $bindTypes .= 's';
}
$sql .= " ORDER BY created_at DESC";

$stmt = mysqli_prepare($connect, $sql);
if ($stmt) {
    if ($bindTypes !== '') {
        mysqli_stmt_bind_param($stmt, $bindTypes, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $accounts[] = $row;
    }
    mysqli_stmt_close($stmt);
}

// Read the current duty owner once for the account-status display. A stale
// record is ignored using the same 30-minute timeout as the login rule.
$dutyResult = mysqli_query($connect, "SELECT user_id FROM superadmin_duty WHERE lock_id = 1 AND user_id IS NOT NULL AND last_seen >= DATE_SUB(NOW(), INTERVAL 30 MINUTE) LIMIT 1");
if ($dutyResult && ($duty = mysqli_fetch_assoc($dutyResult))) {
    $onDutySuperadminId = (string)($duty['user_id'] ?? '');
    mysqli_free_result($dutyResult);
}
$myAccount = [];
$myAccountStmt = mysqli_prepare($connect, "SELECT user_id, first_name, middle_name, last_name, extension_name, date_of_birth, age, sex, street, barangay, city_municipality, province, country, zip_code, username, email, role, approval_status, onboarding_required FROM users WHERE user_id = ? LIMIT 1");
if ($myAccountStmt) {
    mysqli_stmt_bind_param($myAccountStmt, 's', $currentUserId);
    mysqli_stmt_execute($myAccountStmt);
    $myAccountResult = mysqli_stmt_get_result($myAccountStmt);
    $myAccount = $myAccountResult ? (mysqli_fetch_assoc($myAccountResult) ?: []) : [];
    mysqli_stmt_close($myAccountStmt);
}
$deleteRequests = [];
if ($isSuperAdmin) {
    $requestResult = mysqli_query($connect, "SELECT dr.*, u.first_name, u.middle_name, u.last_name, u.extension_name, u.email, u.username, u.employee_id, u.role, u.approval_status, r.username AS requester_username FROM account_delete_requests dr LEFT JOIN users u ON u.user_id = dr.target_user_id LEFT JOIN users r ON r.user_id = dr.requested_by WHERE dr.status = 'pending' ORDER BY dr.created_at DESC");
    if ($requestResult) {
        while ($request = mysqli_fetch_assoc($requestResult)) {
            $deleteRequests[] = $request;
        }
        mysqli_free_result($requestResult);
    }
}
$pendingDeleteRequests = count($deleteRequests);
$activityLogs = [];
if (in_array($_SESSION['auth_role'] ?? 'user', ['admin', 'superadmin'], true) && $isActivityLogsView) {
    $activityVisibility = $isSuperAdmin ? '' : " WHERE role <> 'superadmin'";
    $activityResult = mysqli_query($connect, "SELECT user_id, name, role, section, browser_os, time_in, time_out, CASE WHEN ip_address IN ('::1', '0:0:0:0:0:0:0:1') THEN '127.0.0.1' ELSE ip_address END AS ip_address, details FROM activity_logs" . $activityVisibility . " ORDER BY time_in DESC LIMIT 250");
    if ($activityResult) {
        while ($activity = mysqli_fetch_assoc($activityResult)) {
            $activityLogs[] = $activity;
        }
        mysqli_free_result($activityResult);
    }
}
$superadminSlotsFull = count_superadmin_slots($connect) >= 2;
$nextPrivilegedUserId = next_privileged_user_id($connect);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $isSuperAdmin ? 'Super Admin Panel' : 'Admin Panel'; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../css/admin.css?v=1.4">
</head>
<body class="<?php echo $isSuperAdmin ? 'super-admin-page' : 'admin-page'; ?>">
    <div class="admin-wrap">
        <div class="page-header">
            <div class="user-chip-wrap">
                <div class="user-chip">Welcome, <?php echo htmlspecialchars($_SESSION['auth_username'] ?? 'Admin'); ?></div>
            </div>
        </div>
        <?php if ($accountMessage !== ''): ?><p class="account-result" role="status"><?php echo htmlspecialchars($accountMessage); ?></p><?php endif; ?>

        <div class="dashboard-layout">
            <aside class="sidebar" aria-label="Admin navigation">
                <div class="sidebar-brand">
                    <span class="brand-mark">UD</span>
                    <div>
                        <strong>Ube Delights</strong>
                        <span><?php echo $isSuperAdmin ? 'Super Admin' : 'Administrator'; ?></span>
                    </div>
                </div>
                <nav class="sidebar-nav">
                    <a class="sidebar-link <?php echo !$isMyAccountView && !$isDeleteRequestsView && !$isActivityLogsView ? 'active' : ''; ?>" href="?status=all#dashboard">Dashboard</a>
                    <a class="sidebar-link <?php echo $filterStatus === 'pending' ? 'active' : ''; ?>" href="?status=pending#accounts">Pending Accounts</a>
                    <a class="sidebar-link <?php echo $filterStatus === 'blocked' ? 'active' : ''; ?>" href="?status=blocked#accounts">Blocked Accounts</a>
                    <a class="sidebar-link <?php echo $isMyAccountView ? 'active' : ''; ?>" href="?view=my-account#my-account">My Account</a>
                    <a class="sidebar-link <?php echo !$isMyAccountView && !$isDeleteRequestsView && !$isActivityLogsView && in_array($filterStatus, ['', 'all'], true) ? 'active' : ''; ?>" href="?status=all#accounts">Account Management</a>
                    <?php if ($isSuperAdmin): ?><a class="sidebar-link <?php echo $isDeleteRequestsView ? 'active' : ''; ?>" href="?view=delete-requests#delete-requests">Delete Requests<?php if ($pendingDeleteRequests > 0): ?> <span class="request-count"><?php echo $pendingDeleteRequests; ?></span><?php endif; ?></a><?php endif; ?>
                    <a class="sidebar-link <?php echo $isActivityLogsView ? 'active' : ''; ?>" href="?view=activity-logs#activity-logs">Activity Logs</a>
                </nav>
                <form method="POST" action="../server/logout.php" class="sidebar-logout-form">
                    <button type="submit" class="sidebar-logout">Log Out</button>
                </form>
            </aside>

            <main class="dashboard-content">
        <?php if (!$isMyAccountView && !$isDeleteRequestsView && !$isActivityLogsView): ?>
        <section id="dashboard" class="summary-grid" aria-label="Account summary">
            <div class="summary-card pending-card">
                <span class="summary-label">Pending Accounts</span>
                <strong><?php echo $pendingAccounts; ?></strong>
            </div>
            <div class="summary-card blocked-card">
                <span class="summary-label">Blocked Accounts</span>
                <strong><?php echo $blockedAccounts; ?></strong>
            </div>
            <div class="summary-card total-card">
                <span class="summary-label">Total Users</span>
                <strong><?php echo $totalUsers; ?></strong>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($isMyAccountView): ?>
        <section class="panel" id="my-account">
            <div class="panel-header">
                <h2>My Account</h2>
            </div>
            <div class="details-grid">
                <div class="details-section-title">Personal Information</div>
                <div><span>User ID</span><strong><?php echo htmlspecialchars($myAccount['user_id'] ?? $currentUserId); ?></strong></div>
                <div><span>Name</span><strong><?php echo htmlspecialchars(trim(($myAccount['first_name'] ?? '') . ' ' . ($myAccount['middle_name'] ?? '') . ' ' . ($myAccount['last_name'] ?? '') . ' ' . ($myAccount['extension_name'] ?? ''))); ?></strong></div>
                <div><span>Date of Birth</span><strong><?php echo htmlspecialchars($myAccount['date_of_birth'] ?? 'Not provided'); ?></strong></div>
                <div><span>Age</span><strong><?php echo htmlspecialchars((string)($myAccount['age'] ?? 'Not provided')); ?></strong></div>
                <div><span>Sex</span><strong><?php echo htmlspecialchars($myAccount['sex'] ?? 'Not provided'); ?></strong></div>
                <div class="details-section-title">Account Information</div>
                <div><span>Username</span><strong><?php echo htmlspecialchars($myAccount['username'] ?? 'Not provided'); ?></strong></div>
                <div><span>Email</span><strong><?php echo htmlspecialchars($myAccount['email'] ?? 'Not provided'); ?></strong></div>
                <div><span>Role</span><strong><?php echo htmlspecialchars($myAccount['role'] ?? 'Not provided'); ?></strong></div>
                <div><span>Status</span><strong><?php echo htmlspecialchars(ucfirst(account_display_status($myAccount))); ?></strong></div>
                <div class="details-section-title">Address</div>
                <div><span>Street</span><strong><?php echo htmlspecialchars($myAccount['street'] ?? 'Not provided'); ?></strong></div>
                <div><span>Barangay</span><strong><?php echo htmlspecialchars($myAccount['barangay'] ?? 'Not provided'); ?></strong></div>
                <div><span>City / Municipality</span><strong><?php echo htmlspecialchars($myAccount['city_municipality'] ?? 'Not provided'); ?></strong></div>
                <div><span>Province</span><strong><?php echo htmlspecialchars($myAccount['province'] ?? 'Not provided'); ?></strong></div>
                <div><span>Country</span><strong><?php echo htmlspecialchars($myAccount['country'] ?? 'Not provided'); ?></strong></div>
                <div><span>ZIP Code</span><strong><?php echo htmlspecialchars($myAccount['zip_code'] ?? 'Not provided'); ?></strong></div>
            </div>
        </section>
        <?php endif; ?>

        <?php if (!$isMyAccountView && !$isDeleteRequestsView && !$isActivityLogsView): ?>
        <section class="panel" id="accounts">
            <div class="panel-header">
                <h2>Account Management</h2>
                <?php if ($isSuperAdmin): ?>
                    <button type="button" class="primary-action add-admin-action open-create-modal" aria-label="Add administrator">+</button>
                <?php endif; ?>
            </div>
            <form method="GET" class="filter-form">
                <div class="form-group">
                    <input type="text" name="user_id" value="<?php echo htmlspecialchars($filterUserId); ?>" placeholder="Search User ID">
                </div>
                <div class="form-group">
                    <input type="text" name="name" value="<?php echo htmlspecialchars($filterName); ?>" placeholder="Search Name">
                </div>
                <div class="form-group">
                    <select name="role" aria-label="Filter by role">
                        <option value="">All Roles</option>
                        <option value="user" <?php echo $filterRole === 'user' ? 'selected' : ''; ?>>User</option>
                        <option value="admin" <?php echo $filterRole === 'admin' ? 'selected' : ''; ?>>Administrator</option>
                        <option value="superadmin" <?php echo $filterRole === 'superadmin' ? 'selected' : ''; ?>>Super Administrator</option>
                    </select>
                </div>
                <div class="form-group">
                    <select name="status" aria-label="Filter by status">
                        <option value="">All Statuses</option>
                        <option value="pending" <?php echo $filterStatus === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="active" <?php echo $filterStatus === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="blocked" <?php echo $filterStatus === 'blocked' ? 'selected' : ''; ?>>Blocked</option>
                    </select>
                </div>
                <button type="submit">Filter</button>
            </form>
        </section>

        <section class="panel" id="account-list">
            <div class="panel-header">
                <h2><?php echo $filterStatus === 'pending' ? 'Pending Accounts' : ($filterStatus === 'blocked' ? 'Blocked Accounts' : 'Account Management'); ?></h2>
            </div>
            <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>User ID</th>
                        <th>Name</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($accounts as $user): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($user['user_id'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars($user['username'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($user['email'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($user['role'] ?? 'user'); ?></td>
                            <?php $displayStatus = account_display_status($user); ?>
                            <td class="status <?php echo htmlspecialchars($displayStatus); ?>"><?php echo htmlspecialchars(ucfirst($displayStatus)); ?></td>
                            <td class="action-cell">
                                <div class="action-menu">
                                    <button type="button" class="action-menu-toggle" aria-label="Open actions for <?php echo htmlspecialchars($user['username'] ?? 'user'); ?>" aria-haspopup="menu" aria-expanded="false">
                                        <i class="fa-solid fa-ellipsis" aria-hidden="true"></i>
                                    </button>
                                    <div class="row-actions" role="menu">
                                    <button type="button" class="icon-button view-user-button" title="View user details" aria-label="View details for <?php echo htmlspecialchars($user['username'] ?? 'user'); ?>"
                                        data-user-id="<?php echo htmlspecialchars($user['user_id'] ?? '', ENT_QUOTES); ?>"
                                        data-name="<?php echo htmlspecialchars(trim(($user['first_name'] ?? '') . ' ' . ($user['middle_name'] ?? '') . ' ' . ($user['last_name'] ?? '') . ' ' . ($user['extension_name'] ?? '')), ENT_QUOTES); ?>"
                                        data-first-name="<?php echo htmlspecialchars($user['first_name'] ?? '', ENT_QUOTES); ?>"
                                        data-middle-name="<?php echo htmlspecialchars($user['middle_name'] ?? '', ENT_QUOTES); ?>"
                                        data-last-name="<?php echo htmlspecialchars($user['last_name'] ?? '', ENT_QUOTES); ?>"
                                        data-extension-name="<?php echo htmlspecialchars($user['extension_name'] ?? '', ENT_QUOTES); ?>"
                                        data-date-of-birth="<?php echo htmlspecialchars($user['date_of_birth'] ?? '', ENT_QUOTES); ?>"
                                        data-age="<?php echo htmlspecialchars((string)($user['age'] ?? ''), ENT_QUOTES); ?>"
                                        data-sex="<?php echo htmlspecialchars($user['sex'] ?? '', ENT_QUOTES); ?>"
                                        data-username="<?php echo htmlspecialchars($user['username'] ?? '', ENT_QUOTES); ?>"
                                        data-email="<?php echo htmlspecialchars($user['email'] ?? '', ENT_QUOTES); ?>"
                                        data-street="<?php echo htmlspecialchars($user['street'] ?? '', ENT_QUOTES); ?>"
                                        data-barangay="<?php echo htmlspecialchars($user['barangay'] ?? '', ENT_QUOTES); ?>"
                                        data-city="<?php echo htmlspecialchars($user['city_municipality'] ?? '', ENT_QUOTES); ?>"
                                        data-province="<?php echo htmlspecialchars($user['province'] ?? '', ENT_QUOTES); ?>"
                                        data-country="<?php echo htmlspecialchars($user['country'] ?? '', ENT_QUOTES); ?>"
                                        data-zip-code="<?php echo htmlspecialchars($user['zip_code'] ?? '', ENT_QUOTES); ?>"
                                        data-role="<?php echo htmlspecialchars($user['role'] ?? 'user', ENT_QUOTES); ?>"
                                        data-privileges="<?php echo htmlspecialchars($user['privileges'] ?? '', ENT_QUOTES); ?>"
                                        data-status="<?php echo htmlspecialchars(ucfirst(account_display_status($user)), ENT_QUOTES); ?>"
                                        data-created-at="<?php echo htmlspecialchars($user['created_at'] ?? '', ENT_QUOTES); ?>">
                                        <span>View Profile</span><i class="fa-solid fa-eye" aria-hidden="true"></i>
                                    </button>
                                    <?php if ($canUpdate): ?>
                                        <button type="button" class="edit-user-button"><i class="fa-solid fa-pen" aria-hidden="true"></i> Edit</button>
                                    <?php endif; ?>
                                    <?php if ($canApprove && ($user['approval_status'] ?? 'pending') === 'pending'): ?>
                                        <button type="button" class="action-modal-trigger success" data-action="approve_account" data-user-id="<?php echo htmlspecialchars($user['user_id'] ?? '', ENT_QUOTES); ?>" data-action-label="approve">Approve</button>
                                    <?php endif; ?>
                                    <?php if ($canApprove && ($user['approval_status'] ?? 'pending') === 'blocked'): ?>
                                        <button type="button" class="action-modal-trigger success" data-action="unblock_account" data-user-id="<?php echo htmlspecialchars($user['user_id'] ?? '', ENT_QUOTES); ?>" data-target-role="<?php echo htmlspecialchars($user['role'] ?? '', ENT_QUOTES); ?>" data-action-label="unblock" <?php echo ($user['user_id'] ?? '') === $currentUserId ? 'disabled' : ''; ?>>Unblock</button>
                                    <?php elseif ($canApprove): ?>
                                        <button type="button" class="action-modal-trigger" data-action="block_account" data-user-id="<?php echo htmlspecialchars($user['user_id'] ?? '', ENT_QUOTES); ?>" data-action-label="block" <?php echo ($user['user_id'] ?? '') === $currentUserId ? 'disabled' : ''; ?>>Block</button>
                                    <?php endif; ?>
                                    <?php if ($isSuperAdmin): ?>
                                        <button type="button" class="action-modal-trigger danger" data-action="delete_account" data-user-id="<?php echo htmlspecialchars($user['user_id'] ?? '', ENT_QUOTES); ?>" data-action-label="delete" <?php echo ($user['user_id'] ?? '') === $currentUserId ? 'disabled' : ''; ?>>Delete</button>
                                        <button type="button" class="action-modal-trigger" data-action="reset_password" data-user-id="<?php echo htmlspecialchars($user['user_id'] ?? '', ENT_QUOTES); ?>" data-action-label="reset password" <?php echo ($user['user_id'] ?? '') === $currentUserId ? 'disabled' : ''; ?>><i class="fa-solid fa-key" aria-hidden="true"></i> Reset Password</button>
                                    <?php elseif ($canManage): ?>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="request_delete">
                                        <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($user['user_id'] ?? ''); ?>">
                                        <input type="text" name="reason" placeholder="Reason for deletion" required>
                                        <button type="submit" class="danger">Request Delete</button>
                                    </form>
                                    <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </section>

        <?php if ($isSuperAdmin && !$isActivityLogsView && ($isDeleteRequestsView || count($deleteRequests) > 0)): ?>
        <section class="panel" id="delete-requests">
            <div class="panel-header">
                <h2>Delete Requests</h2>
            </div>
            <?php if (count($deleteRequests) === 0): ?>
                <p class="empty-state">There are no pending delete requests.</p>
            <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>User Information</th><th>Requested By</th><th>Reason</th><th>Requested</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($deleteRequests as $request): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars(trim(($request['first_name'] ?? '') . ' ' . ($request['middle_name'] ?? '') . ' ' . ($request['last_name'] ?? '') . ' ' . ($request['extension_name'] ?? ''))); ?></strong><br>
                                ID: <?php echo htmlspecialchars($request['target_user_id']); ?><br>
                                Username: <?php echo htmlspecialchars($request['username'] ?? ''); ?><br>
                                Email: <?php echo htmlspecialchars($request['email'] ?? ''); ?><br>
                                Role: <?php echo htmlspecialchars($request['role'] ?? 'user'); ?><br>
                                Status: <?php echo htmlspecialchars($request['approval_status'] ?? ''); ?>
                            </td>
                            <td><?php echo htmlspecialchars($request['requester_username'] ?? $request['requested_by']); ?></td>
                            <td><?php echo nl2br(htmlspecialchars($request['reason'])); ?></td>
                            <td><?php echo htmlspecialchars($request['created_at']); ?></td>
                            <td class="action-cell"><div class="action-menu">
                                <button type="button" class="action-menu-toggle" aria-label="Open delete request actions" aria-haspopup="menu" aria-expanded="false"><i class="fa-solid fa-ellipsis" aria-hidden="true"></i></button>
                                <div class="row-actions" role="menu">
                                <form method="POST"><input type="hidden" name="action" value="approve_delete_request"><input type="hidden" name="request_id" value="<?php echo (int)$request['id']; ?>"><button type="submit" class="danger">Approve Delete</button></form>
                                <form method="POST"><input type="hidden" name="action" value="reject_delete_request"><input type="hidden" name="request_id" value="<?php echo (int)$request['id']; ?>"><button type="submit">Reject</button></form>
                                </div>
                            </div></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>
        <?php endif; ?>

        <?php if (in_array($_SESSION['auth_role'] ?? 'user', ['admin', 'superadmin'], true) && $isActivityLogsView): ?>
        <section class="panel" id="activity-logs">
            <div class="panel-header">
                <h2>Activity Logs</h2>
            </div>
            <?php if (count($activityLogs) === 0): ?>
                <p class="empty-state">There are no activity logs yet.</p>
            <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>User ID</th>
                            <th>Name</th>
                            <th>Role</th>
                            <th>Section</th>
                            <th>Browser / OS</th>
                            <th>Time In</th>
                            <th>Time Out</th>
                            <th>IP Address</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($activityLogs as $activity): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($activity['user_id'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($activity['name'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($activity['role'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($activity['section'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($activity['browser_os'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($activity['time_in'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($activity['time_out'] ?? 'Still active'); ?></td>
                            <td><?php echo htmlspecialchars($activity['ip_address'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($activity['details'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>
            </main>
        </div>

        <?php if ($isSuperAdmin): ?>
        <div class="modal-backdrop" id="create-account" aria-hidden="true">
          <section class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="create-account-title">
            <button type="button" class="modal-close close-create-modal" aria-label="Close add administrator dialog">&times;</button>
            <div class="panel-header">
                <h2 id="create-account-title">Create Account</h2>
            </div>
            <?php if ($accountMessage !== ''): ?><p role="status"><?php echo htmlspecialchars($accountMessage); ?></p><?php endif; ?>
            <form method="POST" class="create-form">
                <input type="hidden" name="action" value="create_privileged_account">
                <div class="form-group">
                    <label for="create-username">Username</label>
                    <input id="create-username" type="text" name="username" placeholder="xxxxx_12345" autocomplete="username" required>
                </div>
                <div class="form-group">
                    <label for="create-email">Email Address</label>
                    <input id="create-email" type="email" name="email" placeholder="admin@example.com" autocomplete="email" required>
                </div>
                <div class="form-group">
                    <label for="create-user-id">User ID (Auto-generated)</label>
                    <input id="create-user-id" type="text" value="<?php echo htmlspecialchars($nextPrivilegedUserId); ?>" readonly aria-readonly="true">
                </div>
                <div class="form-group">
                    <label for="create-password">Password</label>
                    <input id="create-password" type="password" name="password" minlength="8" autocomplete="new-password" required>
                </div>
                <div class="form-group">
                    <label for="create-role">Role</label>
                    <select id="create-role" name="role" required>
                        <option value="user">User</option>
                        <option value="admin">Administrator</option>
                        <option value="superadmin"<?php echo $superadminSlotsFull ? ' disabled' : ''; ?>>Super Administrator</option>
                    </select>
                    <?php if ($superadminSlotsFull): ?>
                    <p class="role-cap-note">Two super administrators are already active. Block one to enable this option.</p>
                    <?php endif; ?>
                </div>
                <button type="submit">Create Account</button>
            </form>
                    </section>
                </div>
        <?php endif; ?>

        <?php if ($canUpdate): ?>
        <div class="modal-backdrop" id="edit-account-modal" aria-hidden="true">
        <section class="modal-panel edit-account-modal" role="dialog" aria-modal="true" aria-labelledby="edit-account-title">
            <button type="button" class="modal-close close-edit-modal" aria-label="Close edit account dialog">&times;</button>
            <div class="panel-header">
                <h2 id="edit-account-title">Edit Account</h2>
            </div>
            <form method="POST" class="update-form">
                <input type="hidden" name="action" value="update_account">
                <div class="form-group">
                    <label for="update-user-id">User ID</label>
                    <input id="update-user-id" type="text" name="user_id" readonly required>
                </div>
                <div class="form-group">
                    <label for="update-first-name">First Name</label>
                    <input id="update-first-name" type="text" name="first_name" placeholder="First Name">
                </div>
                <div class="form-group">
                    <label for="update-last-name">Last Name</label>
                    <input id="update-last-name" type="text" name="last_name" placeholder="Last Name">
                </div>
                <div class="form-group">
                    <label for="update-username">Username</label>
                    <input id="update-username" type="text" name="username" placeholder="Username">
                </div>
                <div class="form-group">
                    <label for="update-email">Email</label>
                    <input id="update-email" type="email" name="email" placeholder="Email">
                </div>
                <div class="form-group">
                    <select id="update-role" name="role">
                        <option value="user">User</option>
                        <option value="admin">Administrator</option>
                        <?php if ($isSuperAdmin): ?><option value="superadmin"<?php echo $superadminSlotsFull ? ' disabled' : ''; ?>>Super Administrator</option><?php endif; ?>
                    </select>
                    <?php if ($isSuperAdmin): ?>
                    <p class="role-cap-note" id="update-role-cap-note"<?php echo $superadminSlotsFull ? '' : ' hidden'; ?>>Two super administrators are already active. Block one to enable this option.</p>
                    <?php endif; ?>
                </div>
                <input type="hidden" id="update-status" name="approval_status" value="">
                <div class="form-group privilege-box">
                    <label>Privileges</label>
                    <div class="checkbox-grid">
                        <label><input type="checkbox" name="privileges[]" value="read_users"> Read Users</label>
                        <label><input type="checkbox" name="privileges[]" value="manage_users"> Manage Users</label>
                        <label><input type="checkbox" name="privileges[]" value="approve_users"> Approve Users</label>
                        <label><input type="checkbox" name="privileges[]" value="update_accounts"> Update Accounts</label>
                        <?php if ($isSuperAdmin): ?><label><input type="checkbox" name="privileges[]" value="delete_accounts"> Delete Accounts</label><?php endif; ?>
                    </div>
                </div>
                <button type="submit">Save Changes</button>
            </form>
        </section>
                </div>
        <?php endif; ?>

                <div class="modal-backdrop" id="user-details-modal" aria-hidden="true">
                    <section class="modal-panel details-modal" role="dialog" aria-modal="true" aria-labelledby="user-details-title">
                        <button type="button" class="modal-close close-details-modal" aria-label="Close user details dialog">&times;</button>
                        <h2 id="user-details-title">User Details</h2>
                        <div class="details-grid">
                            <div class="details-section-title">Personal Information</div>
                            <div><span>User ID</span><strong id="detail-user-id"></strong></div>
                            <div><span>Name</span><strong id="detail-name"></strong></div>
                            <div><span>Date of Birth</span><strong id="detail-date-of-birth"></strong></div>
                            <div><span>Age</span><strong id="detail-age"></strong></div>
                            <div><span>Sex</span><strong id="detail-sex"></strong></div>
                            <div class="details-section-title">Account Information</div>
                            <div><span>Username</span><strong id="detail-username"></strong></div>
                            <div><span>Email</span><strong id="detail-email"></strong></div>
                            <div><span>Role</span><strong id="detail-role"></strong></div>
                            <div><span>Status</span><strong id="detail-status"></strong></div>
                            <div><span>Registered</span><strong id="detail-created-at"></strong></div>
                            <div class="details-wide"><span>Privileges</span><strong id="detail-privileges"></strong></div>
                            <div class="details-section-title">Address Information</div>
                            <div><span>Street</span><strong id="detail-street"></strong></div>
                            <div><span>Barangay</span><strong id="detail-barangay"></strong></div>
                            <div><span>City / Municipality</span><strong id="detail-city"></strong></div>
                            <div><span>Province</span><strong id="detail-province"></strong></div>
                            <div><span>Country</span><strong id="detail-country"></strong></div>
                            <div><span>ZIP Code</span><strong id="detail-zip-code"></strong></div>
                        </div>
                    </section>
                </div>
                <?php if ($isSuperAdmin): ?>
                <div class="modal-backdrop" id="account-action-modal" aria-hidden="true">
                    <section class="modal-panel action-modal" role="dialog" aria-modal="true" aria-labelledby="account-action-title">
                        <button type="button" class="modal-close close-action-modal" aria-label="Close account action dialog">&times;</button>
                        <form method="POST" id="account-action-form">
                            <input type="hidden" name="action" id="account-action-input">
                            <input type="hidden" name="user_id" id="account-action-user-id">
                            <div id="action-password-step">
                                <h2>Super Admin Verification</h2>
                                <p>Enter your password before continuing.</p>
                                <div class="action-password-fields">
                                    <label for="current-action-password">Super-admin password</label>
                                    <input type="password" name="current_password" id="current-action-password" autocomplete="current-password" required>
                                </div>
                                <button type="button" id="continue-action-button">Continue</button>
                            </div>
                            <div id="action-confirm-step" hidden>
                                <h2 id="account-action-title">Confirm Account Action</h2>
                                <p id="account-action-message"></p>
                                <div id="unblock-role-choice" hidden>
                                    <p class="unblock-choice-label">Choose the role for this account after unblocking:</p>
                                    <div class="unblock-choice-group">
                                        <label class="unblock-option">
                                            <input type="radio" name="unblock_resolution" value="retain" checked>
                                            <span class="unblock-option-body">
                                                <strong>Retain</strong>
                                                <span>Remains a Super Administrator with the same privileges.</span>
                                            </span>
                                        </label>
                                        <label class="unblock-option">
                                            <input type="radio" name="unblock_resolution" value="demote">
                                            <span class="unblock-option-body">
                                                <strong>Demote</strong>
                                                <span>Becomes an Administrator and privileges are adjusted.</span>
                                            </span>
                                        </label>
                                    </div>
                                </div>
                                <div id="new-password-fields" hidden>
                                    <label for="new-action-password">New user password</label>
                                    <input type="password" name="new_password" id="new-action-password" autocomplete="new-password">
                                    <label for="confirm-action-password">Confirm new user password</label>
                                    <input type="password" name="confirm_password" id="confirm-action-password" autocomplete="new-password">
                                </div>
                                <div class="modal-actions">
                                    <button type="button" class="modal-secondary back-action-password">Back</button>
                                    <button type="submit" id="account-action-submit">Confirm</button>
                                </div>
                            </div>
                        </form>
                    </section>
                </div>
                <?php endif; ?>
    </div>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/js/all.min.js"></script>
        <script>
            var currentUserId = '<?php echo htmlspecialchars($currentUserId, ENT_QUOTES, 'UTF-8'); ?>';
            var currentRole = '<?php echo htmlspecialchars($_SESSION['auth_role'] ?? '', ENT_QUOTES, 'UTF-8'); ?>';
            var superadminSlotsFull = <?php echo $superadminSlotsFull ? 'true' : 'false'; ?>;
            window.addEventListener('pageshow', function (event) {
                if (event.persisted) window.location.reload();
            });
            function closeActionMenus(exceptMenu) {
                document.querySelectorAll('.action-menu').forEach(function (menu) {
                    if (menu === exceptMenu) return;
                    menu.classList.remove('is-open');
                    var toggle = menu.querySelector('.action-menu-toggle');
                    if (toggle) toggle.setAttribute('aria-expanded', 'false');
                });
            }
            document.querySelectorAll('.action-menu-toggle').forEach(function (toggle) {
                toggle.addEventListener('click', function (event) {
                    event.stopPropagation();
                    var menu = toggle.closest('.action-menu');
                    var willOpen = !menu.classList.contains('is-open');
                    closeActionMenus(menu);
                    menu.classList.toggle('is-open', willOpen);
                    if (willOpen) {
                        var menuPanel = menu.querySelector('.row-actions');
                        var toggleBounds = toggle.getBoundingClientRect();
                        var viewportPadding = 8;
                        var menuHeight = menuPanel.offsetHeight;
                        var spaceBelow = window.innerHeight - toggleBounds.bottom - viewportPadding;
                        var spaceAbove = toggleBounds.top - viewportPadding;
                        var top = spaceBelow >= menuHeight || spaceBelow >= spaceAbove
                            ? toggleBounds.bottom + viewportPadding
                            : Math.max(viewportPadding, toggleBounds.top - menuHeight - viewportPadding);
                        var left = Math.min(
                            Math.max(viewportPadding, toggleBounds.right - menuPanel.offsetWidth),
                            window.innerWidth - menuPanel.offsetWidth - viewportPadding
                        );
                        menuPanel.style.top = top + 'px';
                        menuPanel.style.left = Math.max(viewportPadding, left) + 'px';
                    }
                    toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
                });
            });
            document.addEventListener('click', function (event) {
                if (!event.target.closest('.action-menu')) closeActionMenus();
            });
            document.querySelectorAll('.view-user-button').forEach(function (button) {
                button.addEventListener('click', function () {
                    var fields = ['user-id', 'name', 'date-of-birth', 'age', 'sex', 'username', 'email', 'street', 'barangay', 'city', 'province', 'country', 'zip-code', 'role', 'status', 'created-at', 'privileges'];
                    fields.forEach(function (field) {
                        document.getElementById('detail-' + field).textContent = button.getAttribute('data-' + field) || 'Not provided';
                    });
                    document.getElementById('user-details-modal').classList.add('is-open');
                    document.getElementById('user-details-modal').setAttribute('aria-hidden', 'false');
                });
            });
            document.querySelectorAll('.edit-user-button').forEach(function (button) {
                button.addEventListener('click', function () {
                    var profileButton = button.closest('.action-menu').querySelector('.view-user-button');
                    if (!profileButton) return;
                    var setValue = function (id, attribute) {
                        document.getElementById(id).value = profileButton.getAttribute(attribute) || '';
                    };
                    setValue('update-user-id', 'data-user-id');
                    setValue('update-first-name', 'data-first-name');
                    setValue('update-last-name', 'data-last-name');
                    setValue('update-username', 'data-username');
                    setValue('update-email', 'data-email');
                    var targetRole = profileButton.getAttribute('data-role') || 'user';
                    var targetId = profileButton.getAttribute('data-user-id') || '';
                    var roleSelect = document.getElementById('update-role');
                    var userOption = roleSelect.querySelector('option[value="user"]');
                    roleSelect.disabled = false;
                    if (userOption) userOption.disabled = false;
                    var superadminOption = roleSelect.querySelector('option[value="superadmin"]');
                    var superadminOptionDisabled = superadminSlotsFull && targetRole !== 'superadmin';
                    if (superadminOption) superadminOption.disabled = superadminOptionDisabled;
                    var roleCapNote = document.getElementById('update-role-cap-note');
                    if (roleCapNote) roleCapNote.hidden = !superadminOptionDisabled;
                    if (targetId === currentUserId) {
                        roleSelect.disabled = true;
                    } else if (targetRole === 'superadmin' || targetRole === 'admin') {
                        if (userOption) userOption.disabled = true;
                        if (roleSelect.value === 'user') roleSelect.value = targetRole;
                    }
                    roleSelect.value = targetRole;
                    document.getElementById('update-status').value = (profileButton.getAttribute('data-status') || 'pending').toLowerCase();
                    var privileges = (profileButton.getAttribute('data-privileges') || '').split(',');
                    document.querySelectorAll('#edit-account-modal input[name="privileges[]"]').forEach(function (input) {
                        input.checked = privileges.indexOf(input.value) !== -1;
                    });
                    closeActionMenus();
                    toggleModal('edit-account-modal', true);
                });
            });
            function toggleModal(id, open) {
                var modal = document.getElementById(id);
                if (!modal) return;
                modal.classList.toggle('is-open', open);
                modal.setAttribute('aria-hidden', open ? 'false' : 'true');
            }
            document.querySelectorAll('.open-create-modal').forEach(function (trigger) {
                trigger.addEventListener('click', function (event) {
                    event.preventDefault();
                    toggleModal('create-account', true);
                });
            });
            document.querySelector('.close-create-modal')?.addEventListener('click', function () { toggleModal('create-account', false); });
            document.querySelector('.close-details-modal')?.addEventListener('click', function () { toggleModal('user-details-modal', false); });
            document.querySelector('.close-edit-modal')?.addEventListener('click', function () { toggleModal('edit-account-modal', false); });
            document.querySelectorAll('.action-modal-trigger').forEach(function (trigger) {
                trigger.addEventListener('click', function () {
                    var action = trigger.getAttribute('data-action');
                    var label = trigger.getAttribute('data-action-label');
                    if (!<?php echo $isSuperAdmin ? 'true' : 'false'; ?>) {
                        var directActionForm = document.createElement('form');
                        directActionForm.method = 'POST';
                        directActionForm.hidden = true;
                        [['action', action], ['user_id', trigger.getAttribute('data-user-id')]].forEach(function (entry) {
                            var input = document.createElement('input');
                            input.type = 'hidden';
                            input.name = entry[0];
                            input.value = entry[1] || '';
                            directActionForm.appendChild(input);
                        });
                        document.body.appendChild(directActionForm);
                        directActionForm.submit();
                        return;
                    }
                    document.getElementById('account-action-input').value = action;
                    document.getElementById('account-action-user-id').value = trigger.getAttribute('data-user-id');
                    document.getElementById('account-action-title').textContent = action === 'reset_password'
                        ? 'Reset User Password'
                        : label.charAt(0).toUpperCase() + label.slice(1) + ' Account';
                    document.getElementById('account-action-message').textContent = 'Are you sure you want to ' + label + ' this account?';
                    document.getElementById('account-action-submit').textContent = label.charAt(0).toUpperCase() + label.slice(1);
                    var isPasswordReset = action === 'reset_password';
                    document.getElementById('new-password-fields').hidden = !isPasswordReset;
                    document.getElementById('new-action-password').required = isPasswordReset;
                    document.getElementById('confirm-action-password').required = isPasswordReset;
                    var roleChoice = document.getElementById('unblock-role-choice');
                    var showRoleChoice = action === 'unblock_account' && trigger.getAttribute('data-target-role') === 'superadmin';
                    if (roleChoice) {
                        roleChoice.hidden = !showRoleChoice;
                        if (showRoleChoice) {
                            var retainRadio = roleChoice.querySelector('input[value="retain"]');
                            if (retainRadio) retainRadio.checked = true;
                        } else {
                            roleChoice.querySelectorAll('input[name="unblock_resolution"]').forEach(function (radio) {
                                radio.checked = radio.value === 'retain';
                            });
                        }
                    }
                    document.getElementById('current-action-password').value = '';
                    document.getElementById('new-action-password').value = '';
                    document.getElementById('confirm-action-password').value = '';
                    document.getElementById('action-password-step').hidden = false;
                    document.getElementById('action-confirm-step').hidden = true;
                    toggleModal('account-action-modal', true);
                });
            });
            function advanceActionToConfirm() {
                var password = document.getElementById('current-action-password');
                if (!password.value) {
                    password.reportValidity();
                    return;
                }
                var action = document.getElementById('account-action-input').value;
                var label = document.querySelector('[data-action="' + action + '"]')?.getAttribute('data-action-label') || action.replace('_account', '').replace('_', ' ');
                document.getElementById('account-action-title').textContent = action === 'reset_password' ? 'Reset User Password' : label.charAt(0).toUpperCase() + label.slice(1) + ' Account';
                document.getElementById('account-action-message').textContent = action === 'reset_password'
                    ? 'Choose a new password for this user.'
                    : 'Confirm this ' + label + ' action.';
                document.getElementById('action-password-step').hidden = true;
                document.getElementById('action-confirm-step').hidden = false;
            }
            document.getElementById('continue-action-button')?.addEventListener('click', advanceActionToConfirm);
            document.getElementById('account-action-form')?.addEventListener('submit', function (event) {
                if (!document.getElementById('action-password-step').hidden) {
                    event.preventDefault();
                    advanceActionToConfirm();
                }
            });
            document.querySelector('.back-action-password')?.addEventListener('click', function () {
                document.getElementById('action-password-step').hidden = false;
                document.getElementById('action-confirm-step').hidden = true;
            });
            document.querySelectorAll('.close-action-modal').forEach(function (button) {
                button.addEventListener('click', function () { toggleModal('account-action-modal', false); });
            });
            document.querySelectorAll('.modal-backdrop').forEach(function (modal) {
                modal.addEventListener('click', function (event) { if (event.target === modal) toggleModal(modal.id, false); });
            });
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    closeActionMenus();
                    document.querySelectorAll('.modal-backdrop.is-open').forEach(function (modal) { toggleModal(modal.id, false); });
                }
            });
        </script>
    <script src="../javascript/register.js"></script>
    <script src="../javascript/admin_update.js?v=3.0"></script>
</body>
</html>
