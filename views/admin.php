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
$currentPrivileges = [];
$currentPrivilegeString = '';
$currentPrivilegeStmt = mysqli_prepare($connect, "SELECT privileges FROM users WHERE user_id = ? LIMIT 1");
if ($currentPrivilegeStmt) {
    mysqli_stmt_bind_param($currentPrivilegeStmt, 's', $currentUserId);
    mysqli_stmt_execute($currentPrivilegeStmt);
    $currentPrivilegeResult = mysqli_stmt_get_result($currentPrivilegeStmt);
    $currentPrivilegeRow = $currentPrivilegeResult ? mysqli_fetch_assoc($currentPrivilegeResult) : null;
    if ($currentPrivilegeRow) {
        $currentPrivilegeString = (string)($currentPrivilegeRow['privileges'] ?? '');
    }
    mysqli_stmt_close($currentPrivilegeStmt);
}
$_SESSION['auth_privileges'] = $currentPrivilegeString;
$currentPrivileges = array_filter(array_map('trim', explode(',', $currentPrivilegeString)));
$canApprove = $isSuperAdmin || in_array('approve_users', $currentPrivileges, true);
$canUpdate = $isSuperAdmin || in_array('update_accounts', $currentPrivileges, true);
$canManage = $isSuperAdmin || in_array('manage_users', $currentPrivileges, true);
$showCreateAccountResult = false;
$accountMessage = '';
if (array_key_exists('create_account_result', $_SESSION)) {
    $accountMessage = (string)$_SESSION['create_account_result'];
    unset($_SESSION['create_account_result']);
    // Keep the result in the page status message without reopening the dialog.
}
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

// Counts non-blocked super administrators, including accounts still in onboarding,
// so fresh accounts cannot bypass the two-account cap.
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
    $targetEmail = '';
    $targetPrivileges = '';
    if ($targetUserId !== '') {
        $targetRoleStmt = mysqli_prepare($connect, "SELECT first_name, middle_name, last_name, extension_name, role, approval_status, email, privileges FROM users WHERE user_id = ? LIMIT 1");
        if ($targetRoleStmt) {
            mysqli_stmt_bind_param($targetRoleStmt, 's', $targetUserId);
            mysqli_stmt_execute($targetRoleStmt);
            $targetRoleResult = mysqli_stmt_get_result($targetRoleStmt);
            $targetRoleRow = $targetRoleResult ? mysqli_fetch_assoc($targetRoleResult) : null;
            $targetIsSuperAdmin = ($targetRoleRow['role'] ?? '') === 'superadmin';
            $targetRole = (string)($targetRoleRow['role'] ?? 'user');
            $targetStatus = (string)($targetRoleRow['approval_status'] ?? 'pending');
            $targetEmail = trim((string)($targetRoleRow['email'] ?? ''));
            $targetPrivileges = (string)($targetRoleRow['privileges'] ?? '');
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
                    activity_log_event($connect, $currentUserId, $actorName, $_SESSION['auth_role'] ?? 'superadmin', 'Account Management', 'Reset password for ' . $targetUserId . ($targetName !== '' ? ' (' . $targetName . ')' : ''), $targetUserId);
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
                    activity_log_event($connect, $currentUserId, $actorName, $_SESSION['auth_role'] ?? 'admin', 'Account Management', 'Approved account ' . $targetUserId . ($targetName !== '' ? ' (' . $targetName . ')' : ''), $targetUserId);
                }
            }
        }
    }

    if ($action === 'block_account') {
        if ($canApprove && ($isSuperAdmin || $targetRole === 'user') && $targetUserId !== '' && $targetUserId !== $currentUserId) {
            $stmt = mysqli_prepare($connect, "UPDATE users SET approval_status = 'blocked', updated_by = ? WHERE user_id = ?");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'ss', $currentUserId, $targetUserId);
                $blocked = mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                if ($blocked) {
                    activity_log_event($connect, $currentUserId, $actorName, $_SESSION['auth_role'] ?? 'admin', 'Account Management', 'Blocked account ' . $targetUserId . ($targetName !== '' ? ' (' . $targetName . ')' : ''), $targetUserId);
                }
            }
        }
    }

    if ($action === 'unblock_account') {
        if ($canApprove && ($isSuperAdmin || $targetRole === 'user') && $targetUserId !== '' && $targetUserId !== $currentUserId) {
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
                    activity_log_event($connect, $currentUserId, $actorName, $_SESSION['auth_role'] ?? 'admin', 'Account Management', 'Unblocked account ' . $targetUserId . ($targetName !== '' ? ' (' . $targetName . ')' : '') . $resolutionNote, $targetUserId);
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
        $isSelfEdit = $targetUserId === $currentUserId;
        $profileErrors = [];
        $newPassword = (string)($_POST['new_password'] ?? '');
        $newPasswordHash = null;
        if ($newPassword !== '') {
            if (!$isSuperAdmin && !$isSelfEdit) {
                $profileErrors[] = 'Only a super administrator may change another account password.';
            } elseif (strlen($newPassword) < 8 || strlen($newPassword) > 50 || preg_match('/\s/', $newPassword) || !preg_match('/[A-Z]/', $newPassword) || !preg_match('/[a-z]/', $newPassword) || !preg_match('/\d/', $newPassword) || !preg_match('/[^A-Za-z0-9]/', $newPassword)) {
                $profileErrors[] = 'New password must be 8-50 characters with uppercase, lowercase, number, special character, and no spaces.';
            } else {
                $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            }
        }

        // Personal and address details are only editable on the signed-in
        // account, so editing somebody else never blanks out those columns.
        $middleName = '';
        $extensionName = '';
        $dateOfBirth = '';
        $age = 0;
        $sex = '';
        $street = '';
        $barangay = '';
        $city = '';
        $province = '';
        $country = '';
        $zipCode = '';

        if ($firstName === '') {
            $profileErrors[] = 'First name is required.';
        } elseif (mb_strlen($firstName) > 50) {
            $profileErrors[] = 'First name must be 50 characters or fewer.';
        }
        if ($lastName === '') {
            $profileErrors[] = 'Last name is required.';
        } elseif (mb_strlen($lastName) > 50) {
            $profileErrors[] = 'Last name must be 50 characters or fewer.';
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $profileErrors[] = 'Enter a valid email address.';
        } elseif (mb_strlen($email) > 100) {
            $profileErrors[] = 'Email must be 100 characters or fewer.';
        }
        if ($username === '') {
            $profileErrors[] = 'Username is required.';
        } elseif (mb_strlen($username) > 30 || preg_match('/\s/', $username)) {
            $profileErrors[] = 'Username must be 30 characters or fewer and cannot contain spaces.';
        }

        if ($isSelfEdit) {
            $middleName = trim((string)($_POST['middle_name'] ?? ''));
            $extensionName = trim((string)($_POST['extension_name'] ?? ''));
            $dateOfBirthRaw = trim((string)($_POST['date_of_birth'] ?? ''));
            $sex = trim((string)($_POST['sex'] ?? ''));
            $street = trim((string)($_POST['street'] ?? ''));
            $barangay = trim((string)($_POST['barangay'] ?? ''));
            $city = trim((string)($_POST['city_municipality'] ?? ''));
            $province = trim((string)($_POST['province'] ?? ''));
            $country = trim((string)($_POST['country'] ?? ''));
            $zipCode = trim((string)($_POST['zip_code'] ?? ''));

            if ($middleName !== '' && mb_strlen($middleName) > 50) {
                $profileErrors[] = 'Middle name must be 50 characters or fewer.';
            }
            if ($extensionName !== '' && mb_strlen($extensionName) > 10) {
                $profileErrors[] = 'Extension must be 10 characters or fewer.';
            }

            $birthDate = DateTime::createFromFormat('!Y-m-d', $dateOfBirthRaw);
            if ($dateOfBirthRaw === '' || !$birthDate || $birthDate->format('Y-m-d') !== $dateOfBirthRaw) {
                $profileErrors[] = 'Enter a valid date of birth.';
            } elseif ($birthDate->getTimestamp() > time()) {
                $profileErrors[] = 'Date of birth cannot be in the future.';
            } else {
                $dateOfBirth = $dateOfBirthRaw;
                $age = (int)(new DateTime('today'))->diff($birthDate)->y;
            }

            if (!in_array($sex, ['', 'Male', 'Female'], true)) {
                $profileErrors[] = 'Choose Male or Female for sex.';
            }

            $addressChecks = [
                ['street', $street, 100, 'Street'],
                ['barangay', $barangay, 50, 'Barangay'],
                ['city_municipality', $city, 50, 'City / Municipality'],
                ['province', $province, 50, 'Province'],
                ['country', $country, 50, 'Country'],
                ['zip_code', $zipCode, 10, 'ZIP Code']
            ];
            foreach ($addressChecks as $addressCheck) {
                if ($addressCheck[1] === '') {
                    $profileErrors[] = $addressCheck[3] . ' is required.';
                } elseif (mb_strlen($addressCheck[1]) > $addressCheck[2]) {
                    $profileErrors[] = $addressCheck[3] . ' must be ' . $addressCheck[2] . ' characters or fewer.';
                }
            }
            if ($zipCode !== '' && !preg_match('/^[0-9A-Za-z \-]{1,10}$/', $zipCode)) {
                $profileErrors[] = 'ZIP code may only contain letters, numbers, spaces, and dashes.';
            }
        }

        if (!preg_match('/^\d{4}-\d{4}$/', $targetUserId)) {
            $accountMessage = 'User ID must contain numbers in the format xxxx-xxxx.';
        } elseif ($profileErrors) {
            $accountMessage = implode(' ', $profileErrors);
        } else {
            $allowedUpdateRoles = $isSuperAdmin ? ['user', 'admin', 'superadmin'] : ['user', 'admin'];
            // The role select is disabled while editing your own account, so the
            // browser omits it from the POST. Keep the stored role in that case:
            // falling back to 'user' made every self-save fail with
            // "You cannot change your own role."
            if (array_key_exists('role', $_POST)) {
                $postedRole = is_string($_POST['role']) ? $_POST['role'] : '';
                $role = in_array($postedRole, $allowedUpdateRoles, true) ? $postedRole : 'user';
            } else {
                $role = $targetRole;
            }
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
                $postedStatus = is_string($_POST['approval_status'] ?? null) ? $_POST['approval_status'] : '';
                // The account list renders other super administrators as
                // "Inactive" while one is on duty; that display-only label must
                // not be written back as a real status change.
                $canUpdateTargetStatus = $canApprove && ($isSuperAdmin || ($targetRole === 'user' && $role === 'user'));
                if (!$canUpdateTargetStatus) {
                    $status = in_array($targetStatus, ['pending', 'active', 'blocked'], true) ? $targetStatus : 'pending';
                } elseif (in_array($postedStatus, ['pending', 'active', 'blocked'], true)) {
                    $status = $postedStatus;
                } else {
                    $status = in_array($targetStatus, ['pending', 'active', 'blocked'], true) ? $targetStatus : 'pending';
                }
                if ($isSuperAdmin) {
                    $privileges = $normalizePrivileges($_POST['privileges'] ?? ['read_users']);
                } else {
                    $privileges = $targetPrivileges;
                }

                // username and email are UNIQUE, so check before writing.
                $duplicateId = null;
                $duplicateStmt = mysqli_prepare($connect, "SELECT user_id FROM users WHERE (username = ? OR email = ?) AND user_id <> ? LIMIT 1");
                if ($duplicateStmt) {
                    mysqli_stmt_bind_param($duplicateStmt, 'sss', $username, $email, $targetUserId);
                    mysqli_stmt_execute($duplicateStmt);
                    $duplicateResult = mysqli_stmt_get_result($duplicateStmt);
                    $duplicateRow = $duplicateResult ? mysqli_fetch_assoc($duplicateResult) : null;
                    $duplicateId = $duplicateRow['user_id'] ?? null;
                    mysqli_stmt_close($duplicateStmt);
                }

                if ($duplicateId) {
                    $accountMessage = 'The username or email is already in use.';
                } else {
                    $passwordSetSql = $newPasswordHash !== null ? ', password_hash = ?' : '';
                    if ($isSelfEdit) {
                        $selfUpdateSql = "UPDATE users SET first_name = ?, middle_name = ?, last_name = ?, extension_name = ?, date_of_birth = ?, age = ?, sex = ?, street = ?, barangay = ?, city_municipality = ?, province = ?, country = ?, zip_code = ?, username = ?, email = ?" . $passwordSetSql . ", role = ?, approval_status = ?, privileges = ?, updated_by = ? WHERE user_id = ?";
                        $stmt = mysqli_prepare($connect, $selfUpdateSql);
                        if ($stmt) {
                            if ($newPasswordHash !== null) {
                                $selfTypes = str_repeat('s', 21);
                                mysqli_stmt_bind_param($stmt, $selfTypes, $firstName, $middleName, $lastName, $extensionName, $dateOfBirth, $age, $sex, $street, $barangay, $city, $province, $country, $zipCode, $username, $email, $newPasswordHash, $role, $status, $privileges, $currentUserId, $targetUserId);
                            } else {
                                $selfTypes = str_repeat('s', 20);
                                mysqli_stmt_bind_param($stmt, $selfTypes, $firstName, $middleName, $lastName, $extensionName, $dateOfBirth, $age, $sex, $street, $barangay, $city, $province, $country, $zipCode, $username, $email, $role, $status, $privileges, $currentUserId, $targetUserId);
                            }
                        }
                    } else {
                        $otherUpdateSql = "UPDATE users SET first_name = ?, last_name = ?, username = ?, email = ?" . $passwordSetSql . ", role = ?, approval_status = ?, privileges = ?, updated_by = ? WHERE user_id = ?";
                        $stmt = mysqli_prepare($connect, $otherUpdateSql);
                        if ($stmt) {
                            if ($newPasswordHash !== null) {
                                mysqli_stmt_bind_param($stmt, 'ssssssssss', $firstName, $lastName, $username, $email, $newPasswordHash, $role, $status, $privileges, $currentUserId, $targetUserId);
                            } else {
                                mysqli_stmt_bind_param($stmt, 'sssssssss', $firstName, $lastName, $username, $email, $role, $status, $privileges, $currentUserId, $targetUserId);
                            }
                        }
                    }

                    if ($stmt) {
                        $updated = mysqli_stmt_execute($stmt);
                        mysqli_stmt_close($stmt);
                        if ($updated) {
                            $updatedName = trim($firstName . ' ' . $lastName);
                            $accountIdentity = $targetUserId . ($updatedName !== '' ? ' (' . $updatedName . ')' : '');
                            $logDetails = ($targetRole !== 'superadmin' && $role === 'superadmin')
                                ? 'Promoted account ' . $accountIdentity . ' from ' . $targetRole . ' to super administrator'
                                : 'Edited account ' . $accountIdentity . ' [' . $role . ']';
                            if ($newPasswordHash !== null) { $logDetails .= ' and password changed'; }
                            activity_log_event($connect, $currentUserId, $actorName, $_SESSION['auth_role'] ?? 'admin', 'Account Management', $logDetails, $targetUserId);
                            $accountMessage = $newPasswordHash !== null ? 'Account and password updated successfully.' : 'Account updated successfully.';
                        } else {
                            $accountMessage = 'Unable to save the account changes. The username or email may already be in use.';
                        }
                    } else {
                        $accountMessage = 'Unable to prepare the account update request.';
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
            $duplicateRows = [];
            $duplicateCheckFailed = false;
            $duplicateStmt = mysqli_prepare($connect, "SELECT username, email FROM users WHERE username = ? OR email = ?");
            if ($duplicateStmt) {
                mysqli_stmt_bind_param($duplicateStmt, 'ss', $username, $email);
                if (!mysqli_stmt_execute($duplicateStmt)) {
                    $duplicateCheckFailed = true;
                    error_log('Account duplicate check failed: ' . mysqli_stmt_error($duplicateStmt));
                } else {
                    $duplicateResult = mysqli_stmt_get_result($duplicateStmt);
                    if (!$duplicateResult) {
                        $duplicateCheckFailed = true;
                        error_log('Account duplicate check result failed: ' . mysqli_stmt_error($duplicateStmt));
                    } else {
                        while ($duplicateRow = mysqli_fetch_assoc($duplicateResult)) {
                            $duplicateRows[] = $duplicateRow;
                        }
                    }
                }
                mysqli_stmt_close($duplicateStmt);
            } else {
                $duplicateCheckFailed = true;
                error_log('Account duplicate check prepare failed: ' . mysqli_error($connect));
            }

            if ($duplicateCheckFailed) {
                $accountMessage = 'Unable to check whether the username or email is already in use. Please try again.';
            } elseif (!empty($duplicateRows)) {
                $usernameTaken = false;
                $emailTaken = false;
                foreach ($duplicateRows as $duplicateRow) {
                    if (strcasecmp((string)($duplicateRow['username'] ?? ''), $username) === 0) {
                        $usernameTaken = true;
                    }
                    if (strcasecmp((string)($duplicateRow['email'] ?? ''), $email) === 0) {
                        $emailTaken = true;
                    }
                }
                if ($usernameTaken && $emailTaken) {
                    $accountMessage = '';
                } elseif ($usernameTaken) {
                    $accountMessage = '';
                } else {
                    $accountMessage = '';
                }
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
                $onboardingRequired = 1;

                $createStmt = mysqli_prepare($connect, "INSERT INTO users (user_id, first_name, middle_name, last_name, extension_name, date_of_birth, age, sex, street, barangay, city_municipality, province, country, zip_code, username, email, password_hash, q1, a1, q2, a2, q3, a3, role, approval_status, privileges, created_by, is_active, onboarding_required) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if ($createStmt) {
                    mysqli_stmt_bind_param($createStmt, 'ssssssissssssssssssssssssssii', $userId, $firstName, $middleName, $lastName, $extensionName, $dateOfBirth, $age, $sex, $street, $barangay, $city, $province, $country, $zipCode, $username, $email, $passwordHash, $q1, $a1, $q2, $a2, $q3, $a3, $role, $approvalStatus, $privileges, $currentUserId, $isActive, $onboardingRequired);
                    $transactionStarted = mysqli_begin_transaction($connect);
                    $created = $transactionStarted && mysqli_stmt_execute($createStmt);
                    if (!$created) {
                        error_log('Administrator creation failed: ' . mysqli_stmt_error($createStmt));
                    }
                    mysqli_stmt_close($createStmt);
                    if ($created) {
                        $stageStmt = mysqli_prepare($connect, "INSERT INTO staged_accounts (user_id) VALUES (?)");
                        $staged = false;
                        if ($stageStmt) {
                            mysqli_stmt_bind_param($stageStmt, 's', $userId);
                            $staged = mysqli_stmt_execute($stageStmt);
                            if (!$staged) {
                                error_log('Staged account registration failed: ' . mysqli_stmt_error($stageStmt));
                            }
                            mysqli_stmt_close($stageStmt);
                        } else {
                            error_log('Staged account registration prepare failed: ' . mysqli_error($connect));
                        }
                        if ($staged && mysqli_commit($connect)) {
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
                                ? 'Account is Created. User ID: ' . $userId . ($credentialsSent ? ' The mail server accepted the credentials email; ask the recipient to check Inbox, Spam, or All Mail.' : ' The account was created, but SMTP failed. Check the PHP error log for the SMTP error.')
                                : 'The account was created, but its role was not saved correctly.';
                        } else {
                            mysqli_rollback($connect);
                            $accountMessage = 'Unable to add the account to staged accounts.';
                        }
                    } else {
                        if ($transactionStarted) {
                            mysqli_rollback($connect);
                        }
                        $accountMessage = 'Unable to create the account.';
                    }
                } else {
                    error_log('Administrator creation prepare failed: ' . mysqli_error($connect));
                    $accountMessage = 'Unable to prepare the account creation request.';
                }
            }
        }
    }

    if ($action === 'delete_account' && $isSuperAdmin) {
        $reason = trim((string)($_POST['reason'] ?? ''));
        if ($targetUserId === '' || $targetUserId === $currentUserId) {
            $accountMessage = 'Choose another account to delete.';
        } elseif ($reason === '' || strlen($reason) > 1000) {
            $accountMessage = 'Enter a deletion reason of 1 to 1000 characters.';
        } elseif (!filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
            $accountMessage = 'This account has no valid email address, so a deletion notice cannot be sent.';
        } elseif (!mysqli_begin_transaction($connect)) {
            $accountMessage = 'Unable to start the account deletion. Please try again.';
        } else {
            $stmt = mysqli_prepare($connect, "DELETE FROM users WHERE user_id = ? AND user_id <> ?");
            $deleted = false;
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'ss', $targetUserId, $currentUserId);
                $deleted = mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) === 1;
                mysqli_stmt_close($stmt);
            }

            if (!$deleted) {
                mysqli_rollback($connect);
                $accountMessage = 'The account could not be deleted.';
            } elseif (!send_account_deletion_notice($targetEmail, $targetName, $targetUserId, $reason)) {
                mysqli_rollback($connect);
                $accountMessage = 'The account was not deleted because its notice email could not be sent. Check SMTP settings and try again.';
            } elseif (mysqli_commit($connect)) {
                $accountMessage = 'Account deleted and notice email sent.';
                $accountIdentity = $targetName !== '' ? $targetName : $targetUserId;
                $activityReason = function_exists('mb_substr') ? mb_substr($reason, 0, 170, 'UTF-8') : (preg_match('/^.{0,170}/us', $reason, $reasonParts) ? $reasonParts[0] : '');
                activity_log_event($connect, $currentUserId, $actorName, $_SESSION['auth_role'] ?? 'superadmin', 'Account Management', 'Deleted account ' . $targetUserId . '. Reason: ' . $activityReason, $targetUserId);
            } else {
                mysqli_rollback($connect);
                $accountMessage = 'The deletion could not be finalized. Please check the account and try again.';
            }
        }
    }

    if ($action === 'request_delete' && !$isSuperAdmin && $canManage) {
        $reason = trim((string)($_POST['reason'] ?? ''));
        if ($targetUserId === '' || $targetIsSuperAdmin || $targetUserId === $currentUserId) {
            $accountMessage = $targetUserId === $currentUserId ? 'You cannot request deletion of your own account.' : 'Choose a valid user account to request for deletion.';
        } elseif ($reason === '' || strlen($reason) > 1000) {
            $accountMessage = 'Enter a deletion reason of 1 to 1000 characters.';
        } elseif (!filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
            $accountMessage = 'This account has no valid email address, so a deletion notice cannot be sent if the request is approved.';
        } else {
            $stmt = mysqli_prepare($connect, "INSERT INTO account_delete_requests (target_user_id, requested_by, reason) VALUES (?, ?, ?)");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'sss', $targetUserId, $currentUserId, $reason);
                $requested = mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                if ($requested) {
                    $accountMessage = 'Deletion request submitted. The user will be emailed this reason if it is approved.';
                    $accountIdentity = $targetName !== '' ? $targetName : $targetUserId;
                    $activityReason = function_exists('mb_substr') ? mb_substr($reason, 0, 170, 'UTF-8') : (preg_match('/^.{0,170}/us', $reason, $reasonParts) ? $reasonParts[0] : '');
                    activity_log_event($connect, $currentUserId, $actorName, $_SESSION['auth_role'] ?? 'admin', 'Account Management', 'Requested deletion of account ' . $targetUserId . '. Reason: ' . $activityReason, $targetUserId);
                } else {
                    $accountMessage = 'Unable to submit the deletion request.';
                }
            } else {
                $accountMessage = 'Unable to prepare the deletion request.';
            }
        }
    }

    if ($action === 'approve_delete_request' && $isSuperAdmin) {
        $requestId = (int)($_POST['request_id'] ?? 0);
        if ($requestId > 0) {
            $stmt = mysqli_prepare($connect, "SELECT dr.target_user_id, dr.reason, u.email, u.first_name, u.middle_name, u.last_name, u.extension_name, u.role FROM account_delete_requests dr INNER JOIN users u ON u.user_id = dr.target_user_id WHERE dr.id = ? AND dr.status = 'pending' LIMIT 1");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'i', $requestId);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                $request = $result ? mysqli_fetch_assoc($result) : null;
                mysqli_stmt_close($stmt);

                if (!$request) {
                    $accountMessage = 'This deletion request is no longer available.';
                } else {
                    $reason = trim((string)($request['reason'] ?? ''));
                    $requestTargetId = (string)$request['target_user_id'];
                    $requestTargetName = trim(($request['first_name'] ?? '') . ' ' . ($request['middle_name'] ?? '') . ' ' . ($request['last_name'] ?? '') . ' ' . ($request['extension_name'] ?? ''));
                    $requestTargetEmail = trim((string)($request['email'] ?? ''));

                    if ($requestTargetId === $currentUserId) {
                        $accountMessage = 'You cannot delete your own account.';
                    } elseif ($reason === '' || strlen($reason) > 1000) {
                        $accountMessage = 'This request has no valid deletion reason, so it cannot be approved.';
                    } elseif (!filter_var($requestTargetEmail, FILTER_VALIDATE_EMAIL)) {
                        $accountMessage = 'The user has no valid email address, so the deletion notice cannot be sent.';
                    } elseif (!mysqli_begin_transaction($connect)) {
                        $accountMessage = 'Unable to start the account deletion. Please try again.';
                    } else {
                        $reviewStmt = mysqli_prepare($connect, "UPDATE account_delete_requests SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = 'pending'");
                        $reviewed = false;
                        if ($reviewStmt) {
                            mysqli_stmt_bind_param($reviewStmt, 'si', $currentUserId, $requestId);
                            $reviewed = mysqli_stmt_execute($reviewStmt) && mysqli_stmt_affected_rows($reviewStmt) === 1;
                            mysqli_stmt_close($reviewStmt);
                        }

                        $deleteStmt = $reviewed ? mysqli_prepare($connect, "DELETE FROM users WHERE user_id = ? AND user_id <> ?") : false;
                        $deleted = false;
                        if ($deleteStmt) {
                            mysqli_stmt_bind_param($deleteStmt, 'ss', $requestTargetId, $currentUserId);
                            $deleted = mysqli_stmt_execute($deleteStmt) && mysqli_stmt_affected_rows($deleteStmt) === 1;
                            mysqli_stmt_close($deleteStmt);
                        }

                        if (!$reviewed || !$deleted) {
                            mysqli_rollback($connect);
                            $accountMessage = 'The deletion request could not be approved.';
                        } elseif (!send_account_deletion_notice($requestTargetEmail, $requestTargetName, $requestTargetId, $reason)) {
                            mysqli_rollback($connect);
                            $accountMessage = 'The request was not approved because the user notice could not be sent. Check SMTP settings and try again.';
                        } elseif (mysqli_commit($connect)) {
                            $accountMessage = 'Deletion approved and notice email sent.';
                            $accountIdentity = $requestTargetName !== '' ? $requestTargetName : $requestTargetId;
                            $activityReason = function_exists('mb_substr') ? mb_substr($reason, 0, 170, 'UTF-8') : (preg_match('/^.{0,170}/us', $reason, $reasonParts) ? $reasonParts[0] : '');
                            activity_log_event($connect, $currentUserId, $actorName, $_SESSION['auth_role'] ?? 'superadmin', 'Account Management', 'Approved deletion of account ' . $requestTargetId . '. Reason: ' . $activityReason, $requestTargetId);
                        } else {
                            mysqli_rollback($connect);
                            $accountMessage = 'The deletion could not be finalized. Please check the request and try again.';
                        }
                    }
                }
            } else {
                $accountMessage = 'Unable to prepare the deletion request review.';
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


if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isSuperAdmin && ($_POST['action'] ?? '') === 'create_privileged_account') {
    $_SESSION['create_account_result'] = $accountMessage;
    $redirectUrl = $_SERVER['PHP_SELF'] ?? 'admin.php';
    if (!empty($_GET)) {
        $redirectUrl .= '?' . http_build_query($_GET);
    }
    header('Location: ' . $redirectUrl);
    exit();
}
$filterUserId = trim($_GET['user_id'] ?? '');
$filterName = trim($_GET['name'] ?? '');
$filterRole = trim($_GET['role'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$isMyAccountView = ($_GET['view'] ?? '') === 'my-account';
$isDeleteRequestsView = ($_GET['view'] ?? '') === 'delete-requests';
$isActivityLogsView = ($_GET['view'] ?? '') === 'activity-logs';
$isStagedAccountsView = $isSuperAdmin && ($_GET['view'] ?? '') === 'staged-accounts';
$stagedAccountCount = 0;
if ($isSuperAdmin) {
    $stagedResult = mysqli_query($connect, "SELECT COUNT(*) AS staged_count FROM staged_accounts");
    if ($stagedResult) {
        $stagedSummary = mysqli_fetch_assoc($stagedResult);
        $stagedAccountCount = (int)($stagedSummary['staged_count'] ?? 0);
        mysqli_free_result($stagedResult);
    }
}
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
if ($isStagedAccountsView) {
    $sql = "SELECT users.*, EXISTS (SELECT 1 FROM activity_logs AS onboarding_log WHERE onboarding_log.user_id = users.user_id AND onboarding_log.section = 'Administrator Onboarding') AS onboarding_started FROM users WHERE users.onboarding_required = 1 AND users.user_id IN (SELECT user_id FROM staged_accounts)";
} else {
    $sql = "SELECT * FROM users WHERE onboarding_required = 0" . ($isSuperAdmin ? '' : " AND role <> 'superadmin'");
}
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
$activityTotal = 0;
$activityPage = 1;
$activityPerPage = 10;
$activityTotalPages = 1;
$activityOffset = 0;
$activityShowingFrom = 0;
$activityShowingTo = 0;
$activityWindowStart = 1;
$activityWindowEnd = 1;
$activitySearch = '';
$activityAction = '';
$activityFrom = '';
$activityTo = '';
$activityHasFilters = false;
$activityActionOptions = [
    'edit' => 'Edit accounts',
    'promote' => 'Promote accounts',
    'delete' => 'Delete accounts',
    'block' => 'Block accounts',
    'unblock' => 'Unblock accounts',
    'login' => 'Login',
    'logout' => 'Logout'
];
$activityActionPatterns = [
    'edit' => 'Edited account%',
    'promote' => 'Promoted account%',
    'delete' => 'Deleted account%',
    'block' => 'Blocked account%',
    'unblock' => 'Unblocked account%',
    'login' => 'Successful login%',
    'logout' => 'Logged out%'
];
$activityUrl = function (array $overrides = []) {
    $params = array_merge($_GET, ['view' => 'activity-logs'], $overrides);
    $params = array_filter($params, function ($value) { return $value !== '' && $value !== null; });
    return '?' . http_build_query($params);
};
$activityLike = function ($value) use ($connect) {
    $escaped = mysqli_real_escape_string($connect, (string)$value);
    return '%' . str_replace(['%', '_'], ['\%', '\_'], $escaped) . '%';
};

if (in_array($_SESSION['auth_role'] ?? 'user', ['admin', 'superadmin'], true) && $isActivityLogsView) {
    $activitySearch = trim((string)($_GET['q'] ?? ''));
    $requestedAction = (string)($_GET['log_action'] ?? '');
    $activityAction = isset($activityActionPatterns[$requestedAction]) ? $requestedAction : '';

    $fromRaw = trim((string)($_GET['from'] ?? ''));
    $fromParsed = DateTime::createFromFormat('!Y-m-d', $fromRaw);
    $activityFrom = ($fromParsed && $fromParsed->format('Y-m-d') === $fromRaw) ? $fromRaw : '';
    $toRaw = trim((string)($_GET['to'] ?? ''));
    $toParsed = DateTime::createFromFormat('!Y-m-d', $toRaw);
    $activityTo = ($toParsed && $toParsed->format('Y-m-d') === $toRaw) ? $toRaw : '';
    if ($activityFrom !== '' && $activityTo !== '' && $activityFrom > $activityTo) {
        $swap = $activityFrom;
        $activityFrom = $activityTo;
        $activityTo = $swap;
    }

    $activityHasFilters = $activitySearch !== '' || $activityAction !== '' || $activityFrom !== '' || $activityTo !== '';
    $activityPage = max(1, (int)($_GET['page'] ?? 1));

    $where = [];
    if (!$isSuperAdmin) {
        $where[] = "l.role <> 'superadmin'";
    }
    if ($activitySearch !== '') {
        $like = $activityLike($activitySearch);
        $where[] = "(l.user_id LIKE '$like' OR l.name LIKE '$like'"
            . " OR (SELECT username FROM users WHERE user_id = l.user_id LIMIT 1) LIKE '$like'"
            . " OR l.details LIKE '$like')";
    }
    if ($activityAction !== '') {
        $where[] = "l.details LIKE '" . $activityActionPatterns[$activityAction] . "'";
    }
    if ($activityFrom !== '') {
        $where[] = "l.time_in >= '" . $activityFrom . " 00:00:00'";
    }
    if ($activityTo !== '') {
        $where[] = "l.time_in <= '" . $activityTo . " 23:59:59'";
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $countResult = mysqli_query($connect, 'SELECT COUNT(*) FROM activity_logs l' . $whereSql);
    $activityTotal = $countResult ? (int)mysqli_fetch_row($countResult)[0] : 0;
    $activityTotalPages = max(1, (int)ceil($activityTotal / $activityPerPage));
    if ($activityPage > $activityTotalPages) {
        $activityPage = $activityTotalPages;
    }
    $activityOffset = ($activityPage - 1) * $activityPerPage;

    $activityResult = mysqli_query(
        $connect,
        'SELECT l.user_id, l.name, l.role, l.section, l.browser_os, l.time_in, l.time_out,'
            . " CASE WHEN l.ip_address IN ('::1', '0:0:0:0:0:0:0:1') THEN '127.0.0.1' ELSE l.ip_address END AS ip_address, l.details"
            . ' FROM activity_logs l' . $whereSql
            . ' ORDER BY l.time_in DESC, l.id DESC'
            . ' LIMIT ' . (int)$activityPerPage . ' OFFSET ' . (int)$activityOffset
    );
    if ($activityResult) {
        while ($activity = mysqli_fetch_assoc($activityResult)) {
            $activityLogs[] = $activity;
        }
        mysqli_free_result($activityResult);
    }

    $activityShowingFrom = $activityTotal > 0 ? $activityOffset + 1 : 0;
    $activityShowingTo = $activityOffset + count($activityLogs);
    $activityWindowStart = max(1, $activityPage - 2);
    $activityWindowEnd = min($activityTotalPages, $activityPage + 2);
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
        <link rel="stylesheet" href="../css/admin.css?v=2.0">
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
                    <a class="sidebar-link <?php echo !$isMyAccountView && !$isDeleteRequestsView && !$isActivityLogsView && !$isStagedAccountsView ? 'active' : ''; ?>" href="?status=all#dashboard">Dashboard</a>
                    <a class="sidebar-link <?php echo $filterStatus === 'pending' ? 'active' : ''; ?>" href="?status=pending#accounts">Pending Accounts</a>
                    <a class="sidebar-link <?php echo $filterStatus === 'blocked' ? 'active' : ''; ?>" href="?status=blocked#accounts">Blocked Accounts</a>
                    <a class="sidebar-link <?php echo $isMyAccountView ? 'active' : ''; ?>" href="?view=my-account#my-account">My Account</a>
                    <a class="sidebar-link <?php echo !$isMyAccountView && !$isDeleteRequestsView && !$isActivityLogsView && !$isStagedAccountsView && in_array($filterStatus, ['', 'all'], true) ? 'active' : ''; ?>" href="?status=all#accounts">Account Management</a>
                    <?php if ($isSuperAdmin): ?><a class="sidebar-link <?php echo $isStagedAccountsView ? 'active' : ''; ?>" href="?view=staged-accounts#accounts">Staged Accounts<?php if ($stagedAccountCount > 0): ?> <span class="request-count"><?php echo $stagedAccountCount; ?></span><?php endif; ?></a><?php endif; ?>
                    <?php if ($isSuperAdmin): ?><a class="sidebar-link <?php echo $isDeleteRequestsView ? 'active' : ''; ?>" href="?view=delete-requests#delete-requests">Delete Requests<?php if ($pendingDeleteRequests > 0): ?> <span class="request-count"><?php echo $pendingDeleteRequests; ?></span><?php endif; ?></a><?php endif; ?>
                    <a class="sidebar-link <?php echo $isActivityLogsView ? 'active' : ''; ?>" href="?view=activity-logs#activity-logs">Activity Logs</a>
                </nav>
                <form method="POST" action="../server/logout.php" class="sidebar-logout-form">
                    <button type="submit" class="sidebar-logout">Log Out</button>
                </form>
            </aside>

            <main class="dashboard-content">
        <?php if (!$isMyAccountView && !$isDeleteRequestsView && !$isActivityLogsView && !$isStagedAccountsView && !in_array($filterStatus, ['pending', 'blocked'], true)): ?>
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
        <?php if (!$isStagedAccountsView && !in_array($filterStatus, ['pending', 'blocked'], true)): ?>
        <section class="panel" id="accounts">
            <div class="panel-header">
                <div>
                    <h2><?php echo $isStagedAccountsView ? 'Staged Accounts' : 'Account Management'; ?></h2>
                    <?php if ($isStagedAccountsView): ?><p class="staged-accounts-note">These accounts stay here until all three onboarding steps are complete. In progress means the account has signed in and started setup.</p><?php endif; ?>
                </div>
                <?php if ($isSuperAdmin): ?>
                    <button type="button" class="primary-action add-admin-action open-create-modal" aria-label="Add administrator">+</button>
                <?php endif; ?>
            </div>
            <form method="GET" class="filter-form">
                <?php if ($isStagedAccountsView): ?><input type="hidden" name="view" value="staged-accounts"><?php endif; ?>
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
        <?php endif; ?>

        <section class="panel" id="account-list">
            <div class="panel-header">
                <h2><?php echo $isStagedAccountsView ? 'Staged Accounts' : ($filterStatus === 'pending' ? 'Pending Accounts' : ($filterStatus === 'blocked' ? 'Blocked Accounts' : 'Account Management')); ?></h2>
            </div>
            <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>User ID</th>
                        <?php if (!$isStagedAccountsView): ?><th>Name</th><?php endif; ?>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Role</th>
                        <?php if ($isStagedAccountsView): ?><th>Setup</th><?php endif; ?>
                        <th><?php echo $isStagedAccountsView ? 'Account Status' : 'Status'; ?></th>
                        <?php if ($isStagedAccountsView): ?><th>Created</th><?php endif; ?>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$accounts): ?>
                        <tr><td colspan="<?php echo $isStagedAccountsView ? '8' : '7'; ?>"><?php echo $isStagedAccountsView ? 'No staged accounts found.' : 'No accounts found.'; ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($accounts as $user): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($user['user_id'] ?? ''); ?></td>
                            <?php if (!$isStagedAccountsView): ?><td><?php echo htmlspecialchars(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')); ?></td><?php endif; ?>
                            <td><?php echo htmlspecialchars($user['username'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($user['email'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($user['role'] ?? 'user'); ?></td>
                            <?php if ($isStagedAccountsView): ?><td><?php echo !empty($user['onboarding_started']) ? 'In progress' : 'Not started'; ?></td><?php endif; ?>
                            <?php $displayStatus = $isStagedAccountsView ? (string)($user['approval_status'] ?? 'pending') : account_display_status($user); ?>
                            <td class="status <?php echo htmlspecialchars($displayStatus); ?>"><?php echo htmlspecialchars(ucfirst($displayStatus)); ?></td>
                            <?php if ($isStagedAccountsView): ?>
                                <?php $createdTimestamp = strtotime((string)($user['created_at'] ?? '')); ?>
                                <td><?php echo $createdTimestamp ? htmlspecialchars(date('M j, Y', $createdTimestamp)) : 'Not available'; ?></td>
                            <?php endif; ?>
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
                                        data-status="<?php echo htmlspecialchars(ucfirst((string)($user['approval_status'] ?? 'pending')), ENT_QUOTES); ?>"
                                        data-created-at="<?php echo htmlspecialchars($user['created_at'] ?? '', ENT_QUOTES); ?>">
                                        <span>View Profile</span><i class="fa-solid fa-eye" aria-hidden="true"></i>
                                    </button>
                                    <?php if ($canUpdate): ?>
                                        <button type="button" class="edit-user-button"><i class="fa-solid fa-pen" aria-hidden="true"></i> Edit</button>
                                    <?php endif; ?>
                                    <?php if ($canApprove && ($user['approval_status'] ?? 'pending') === 'pending'): ?>
                                        <button type="button" class="action-modal-trigger success" data-action="approve_account" data-user-id="<?php echo htmlspecialchars($user['user_id'] ?? '', ENT_QUOTES); ?>" data-action-label="approve">Approve</button>
                                    <?php endif; ?>
                                    <?php $canChangeTargetStatus = $canApprove && ($isSuperAdmin || ($user['role'] ?? 'user') === 'user'); ?>
                                    <?php if ($canChangeTargetStatus && ($user['approval_status'] ?? 'pending') === 'blocked'): ?>
                                        <button type="button" class="action-modal-trigger success" data-action="unblock_account" data-user-id="<?php echo htmlspecialchars($user['user_id'] ?? '', ENT_QUOTES); ?>" data-target-role="<?php echo htmlspecialchars($user['role'] ?? '', ENT_QUOTES); ?>" data-action-label="unblock" <?php echo ($user['user_id'] ?? '') === $currentUserId ? 'disabled' : ''; ?>>Unblock</button>
                                    <?php elseif ($canChangeTargetStatus): ?>
                                        <button type="button" class="action-modal-trigger" data-action="block_account" data-user-id="<?php echo htmlspecialchars($user['user_id'] ?? '', ENT_QUOTES); ?>" data-action-label="block" <?php echo ($user['user_id'] ?? '') === $currentUserId ? 'disabled' : ''; ?>>Block</button>
                                    <?php endif; ?>
                                    <?php if ($isSuperAdmin): ?>
                                        <button type="button" class="action-modal-trigger danger" data-action="delete_account" data-user-id="<?php echo htmlspecialchars($user['user_id'] ?? '', ENT_QUOTES); ?>" data-action-label="delete" <?php echo ($user['user_id'] ?? '') === $currentUserId ? 'disabled' : ''; ?>>Delete</button>
                                        <button type="button" class="action-modal-trigger" data-action="reset_password" data-user-id="<?php echo htmlspecialchars($user['user_id'] ?? '', ENT_QUOTES); ?>" data-action-label="reset password" <?php echo ($user['user_id'] ?? '') === $currentUserId ? 'disabled' : ''; ?>><i class="fa-solid fa-key" aria-hidden="true"></i> Reset Password</button>
                                    <?php elseif ($canManage && ($user['user_id'] ?? '') !== $currentUserId): ?>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="request_delete">
                                        <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($user['user_id'] ?? ''); ?>">
                                        <textarea name="reason" placeholder="Reason for deletion" maxlength="1000" rows="2" required></textarea>
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
        <?php endif; ?>

        <?php if ($isSuperAdmin && $isDeleteRequestsView): ?>
        <section class="panel" id="delete-requests">
            <div class="panel-header">
                <h2>Delete Requests</h2>
            </div>
            <?php if (count($deleteRequests) === 0): ?>
                <div class="empty-state">
                    <div class="empty-icon"><i class="fa-solid fa-inbox"></i></div>
                    <h3>No delete requests</h3>
                    <p>When someone asks to have an account deleted, the request will appear here for review.</p>
                </div>
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

        <?php if (in_array($_SESSION['auth_role'] ?? 'user', ['admin', 'superadmin'], true) && $isActivityLogsView): ?>
        <section class="panel" id="activity-logs">
            <div class="panel-header">
                <h2>Activity Logs</h2>
            </div>
            <form method="GET" class="filter-form log-filter-form">
                <input type="hidden" name="view" value="activity-logs">
                <div class="form-group">
                    <label for="log-search">Search</label>
                    <input type="text" id="log-search" name="q" value="<?php echo htmlspecialchars($activitySearch); ?>" placeholder="Name, ID, username or details">
                </div>
                <div class="form-group">
                    <label for="log-action">Action</label>
                    <select id="log-action" name="log_action">
                        <option value="">All actions</option>
                        <?php foreach ($activityActionOptions as $actionValue => $actionLabel): ?>
                        <option value="<?php echo $actionValue; ?>"<?php echo $activityAction === $actionValue ? ' selected' : ''; ?>><?php echo $actionLabel; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="log-from">From</label>
                    <input type="date" id="log-from" name="from" value="<?php echo htmlspecialchars($activityFrom); ?>">
                </div>
                <div class="form-group">
                    <label for="log-to">To</label>
                    <input type="date" id="log-to" name="to" value="<?php echo htmlspecialchars($activityTo); ?>">
                </div>
                <div class="form-group log-filter-actions">
                    <button type="submit"><i class="fa-solid fa-filter" aria-hidden="true"></i> Filter</button>
                    <a href="?view=activity-logs"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i> Reset</a>
                </div>
            </form>
            <?php if (count($activityLogs) === 0): ?>
                <div class="empty-state">
                    <div class="empty-icon"><i class="fa-solid fa-clock-rotate-left"></i></div>
                    <h3><?php echo $activityHasFilters ? 'No matching activity' : 'No activity logs yet'; ?></h3>
                    <p><?php echo $activityHasFilters ? 'Try adjusting your search, action or date range.' : 'Sign-ins and account changes will show up here.'; ?></p>
                    <?php if ($activityHasFilters): ?>
                    <p><a href="?view=activity-logs">Clear all filters</a></p>
                    <?php endif; ?>
                </div>
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
            <div class="log-pagination">
                <span class="log-pagination-summary">Showing <?php echo $activityShowingFrom; ?>&ndash;<?php echo $activityShowingTo; ?> of <?php echo $activityTotal; ?> entries</span>
                <?php if ($activityTotalPages > 1): ?>
                <nav class="log-pagination-controls" aria-label="Activity log pages">
                    <span class="log-page-status">Page <?php echo $activityPage; ?> of <?php echo $activityTotalPages; ?></span>
                    <?php if ($activityPage > 1): ?>
                    <a href="<?php echo htmlspecialchars($activityUrl(['page' => $activityPage - 1])); ?>" rel="prev">&laquo; Prev</a>
                    <?php endif; ?>
                    <?php if ($activityWindowStart > 1): ?>
                    <a href="<?php echo htmlspecialchars($activityUrl(['page' => 1])); ?>">1</a>
                    <?php if ($activityWindowStart > 2): ?><span class="log-page-gap">&hellip;</span><?php endif; ?>
                    <?php endif; ?>
                    <?php for ($pageNumber = $activityWindowStart; $pageNumber <= $activityWindowEnd; $pageNumber++): ?>
                    <?php if ($pageNumber === $activityPage): ?>
                    <span class="log-page current" aria-current="page"><?php echo $pageNumber; ?></span>
                    <?php else: ?>
                    <a href="<?php echo htmlspecialchars($activityUrl(['page' => $pageNumber])); ?>"><?php echo $pageNumber; ?></a>
                    <?php endif; ?>
                    <?php endfor; ?>
                    <?php if ($activityWindowEnd < $activityTotalPages): ?>
                    <?php if ($activityWindowEnd < $activityTotalPages - 1): ?><span class="log-page-gap">&hellip;</span><?php endif; ?>
                    <a href="<?php echo htmlspecialchars($activityUrl(['page' => $activityTotalPages])); ?>"><?php echo $activityTotalPages; ?></a>
                    <?php endif; ?>
                    <?php if ($activityPage < $activityTotalPages): ?>
                    <a href="<?php echo htmlspecialchars($activityUrl(['page' => $activityPage + 1])); ?>" rel="next">Next &raquo;</a>
                    <?php endif; ?>
                </nav>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>
            </main>
        </div>

        <?php if ($isSuperAdmin): ?>
        <div class="modal-backdrop<?php echo $showCreateAccountResult ? ' is-open' : ''; ?>" id="create-account" aria-hidden="<?php echo $showCreateAccountResult ? 'false' : 'true'; ?>">
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
            <form method="POST" class="update-form" id="edit-account-form">
                <input type="hidden" name="action" value="update_account">

                <div class="edit-form-section" id="edit-personal-section">
                    <p class="edit-section-title">Personal Information</p>
                    <div class="form-group">
                        <label for="update-user-id">User ID</label>
                        <input id="update-user-id" type="text" name="user_id" readonly required>
                    </div>
                    <div class="form-group">
                        <label for="update-first-name">First Name</label>
                        <input id="update-first-name" type="text" name="first_name" placeholder="First Name" maxlength="50" required>
                    </div>
                    <div class="form-group">
                        <label for="update-last-name">Last Name</label>
                        <input id="update-last-name" type="text" name="last_name" placeholder="Last Name" maxlength="50" required>
                    </div>
                    <div class="edit-personal-extra" id="edit-personal-extra" hidden>
                        <div class="form-group">
                            <label for="update-middle-name">Middle Name</label>
                            <input id="update-middle-name" type="text" name="middle_name" placeholder="Middle Name" maxlength="50">
                        </div>
                        <div class="form-group">
                            <label for="update-extension-name">Extension</label>
                            <input id="update-extension-name" type="text" name="extension_name" placeholder="Jr., Sr., III" maxlength="10">
                        </div>
                        <div class="form-group">
                            <label for="update-date-of-birth">Date of Birth</label>
                            <input id="update-date-of-birth" type="date" name="date_of_birth" max="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="form-group">
                            <label for="update-age">Age</label>
                            <input id="update-age" type="number" name="age" min="0" max="150" readonly aria-readonly="true" placeholder="Auto-computed">
                        </div>
                        <div class="form-group">
                            <label for="update-sex">Sex</label>
                            <select id="update-sex" name="sex">
                                <option value="">Not provided</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="edit-form-section" id="edit-address-section" hidden>
                    <p class="edit-section-title">Address Information</p>
                    <div class="form-group">
                        <label for="update-street">Street</label>
                        <input id="update-street" type="text" name="street" placeholder="Street / Purok" maxlength="100">
                    </div>
                    <div class="form-group">
                        <label for="update-barangay">Barangay</label>
                        <input id="update-barangay" type="text" name="barangay" placeholder="Barangay" maxlength="50">
                    </div>
                    <div class="form-group">
                        <label for="update-city">City / Municipality</label>
                        <input id="update-city" type="text" name="city_municipality" placeholder="City / Municipality" maxlength="50">
                    </div>
                    <div class="form-group">
                        <label for="update-province">Province</label>
                        <input id="update-province" type="text" name="province" placeholder="Province" maxlength="50">
                    </div>
                    <div class="form-group">
                        <label for="update-country">Country</label>
                        <input id="update-country" type="text" name="country" placeholder="Country" maxlength="50">
                    </div>
                    <div class="form-group">
                        <label for="update-zip-code">ZIP Code</label>
                        <input id="update-zip-code" type="text" name="zip_code" placeholder="ZIP Code" maxlength="10">
                    </div>
                </div>

                <div class="edit-form-section" id="edit-account-section">
                    <p class="edit-section-title">Account Information</p>
                    <div class="form-group">
                        <label for="update-username">Username</label>
                        <input id="update-username" type="text" name="username" placeholder="Username" maxlength="30" required>
                    </div>
                    <div class="form-group">
                        <label for="update-email">Email</label>
                        <input id="update-email" type="email" name="email" placeholder="Email" maxlength="100" required>
                    </div>
                    <div class="form-group">
                        <label for="update-role">Role</label>
                        <select id="update-role" name="role">
                            <option value="user">User</option>
                            <option value="admin">Administrator</option>
                            <?php if ($isSuperAdmin): ?><option value="superadmin"<?php echo $superadminSlotsFull ? ' disabled' : ''; ?>>Super Administrator</option><?php endif; ?>
                        </select>
                        <?php if ($isSuperAdmin): ?>
                        <p class="role-cap-note" id="update-role-cap-note"<?php echo $superadminSlotsFull ? '' : ' hidden'; ?>>Two super administrators are already active. Block one to enable this option.</p>
                        <?php endif; ?>
                    </div>
                    <div class="form-group" id="update-new-password-group">
                        <label for="update-new-password">New Password</label>
                        <input id="update-new-password" type="password" name="new_password" minlength="8" maxlength="50" autocomplete="new-password">
                    </div>
                    <input type="hidden" id="update-status" name="approval_status" value="">
                    <?php if ($isSuperAdmin): ?>
                    <div class="form-group privilege-box">
                        <label>Privileges</label>
                        <div class="checkbox-grid">
                            <label><input type="checkbox" name="privileges[]" value="read_users"> Read Users</label>
                            <label><input type="checkbox" name="privileges[]" value="manage_users"> Manage Users</label>
                            <label><input type="checkbox" name="privileges[]" value="approve_users"> Approve Users</label>
                            <label><input type="checkbox" name="privileges[]" value="update_accounts"> Update Accounts</label>
                            <label><input type="checkbox" name="privileges[]" value="delete_accounts"> Delete Accounts</label>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="edit-form-actions">
                    <p class="edit-step-indicator" id="edit-step-indicator">Step 1 of 2 &mdash; Personal Information</p>
                    <div class="edit-step-buttons">
                        <button type="button" class="edit-secondary-btn" id="edit-back-btn" hidden>Back</button>
                        <button type="button" class="edit-secondary-btn" id="edit-next-btn">Next</button>
                        <button type="submit" id="edit-save-btn" hidden>Save Changes</button>
                    </div>
                </div>
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
                                <div id="delete-reason-fields" hidden>
                                    <label for="delete-action-reason">Reason for account deletion</label>
                                    <div class="delete-reason-suggestion-group">
                                        <label class="delete-reason-suggestion-label" for="delete-reason-template">Suggested reasons</label>
                                        <select id="delete-reason-template">
                                            <option value="">Choose a suggested reason</option>
                                            <option value="Account is no longer needed">Account is no longer needed</option>
                                            <option value="Account holder requested deletion">Account holder requested deletion</option>
                                            <option value="Confirmed violation of account policies">Confirmed violation of account policies</option>
                                            <option value="Account created by mistake">Account created by mistake</option>
                                            <option value="other">Other reason</option>
                                        </select>
                                    </div>
                                    <div id="delete-custom-reason-group" hidden>
                                        <textarea name="reason" id="delete-action-reason" rows="4" maxlength="1000" placeholder="Explain why this account is being deleted" aria-describedby="delete-reason-help"></textarea>
                                        <small id="delete-reason-help">This explanation will be included in the email sent to the user.</small>
                                    </div>
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
            function syncAgeFromDateOfBirth() {
                var dobField = document.getElementById('update-date-of-birth');
                var ageField = document.getElementById('update-age');
                if (!dobField || !ageField) return;
                var parts = dobField.value ? dobField.value.split('-') : [];
                if (parts.length !== 3) { ageField.value = ''; return; }
                var birth = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
                if (isNaN(birth.getTime())) { ageField.value = ''; return; }
                var today = new Date();
                var age = today.getFullYear() - birth.getFullYear();
                var monthDiff = today.getMonth() - birth.getMonth();
                if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birth.getDate())) age--;
                ageField.value = age >= 0 ? String(age) : '';
            }
            document.getElementById('update-date-of-birth').addEventListener('change', syncAgeFromDateOfBirth);

            var editStep = 1;
            var editIsOwnAccount = false;
            var editAddressFieldIds = ['update-street', 'update-barangay', 'update-city', 'update-province', 'update-country', 'update-zip-code'];

            function setEditAddressRequired(isOwn) {
                editAddressFieldIds.concat(['update-date-of-birth']).forEach(function (id) {
                    var field = document.getElementById(id);
                    if (field) field.required = isOwn;
                });
            }

            function renderEditStep() {
                var personalSection = document.getElementById('edit-personal-section');
                var addressSection = document.getElementById('edit-address-section');
                var accountSection = document.getElementById('edit-account-section');
                var personalExtra = document.getElementById('edit-personal-extra');
                if (personalExtra) personalExtra.hidden = !editIsOwnAccount;
                if (personalSection) personalSection.hidden = false;
                if (addressSection) addressSection.hidden = !(editIsOwnAccount && editStep === 1);
                if (accountSection) accountSection.hidden = editStep !== 2;
                document.getElementById('edit-back-btn').hidden = editStep !== 2;
                document.getElementById('edit-next-btn').hidden = editStep === 2;
                document.getElementById('edit-save-btn').hidden = editStep !== 2;
                document.getElementById('edit-step-indicator').innerHTML = editStep === 1
                    ? 'Step 1 of 2 &mdash; Personal Information'
                    : 'Step 2 of 2 &mdash; Account Information';
            }

            function setEditStep(step) {
                editStep = step === 2 ? 2 : 1;
                renderEditStep();
            }

            document.getElementById('edit-next-btn').addEventListener('click', function () {
                var stepOneFields = document.querySelectorAll('#edit-personal-section input, #edit-personal-section select, #edit-address-section input, #edit-address-section select');
                var firstInvalid = null;
                stepOneFields.forEach(function (field) {
                    if (!firstInvalid && field.checkValidity && !field.checkValidity()) firstInvalid = field;
                });
                if (firstInvalid) {
                    firstInvalid.reportValidity();
                    return;
                }
                setEditStep(2);
            });

            document.getElementById('edit-back-btn').addEventListener('click', function () {
                setEditStep(1);
            });

            document.getElementById('edit-account-form').addEventListener('keydown', function (event) {
                if (event.key !== 'Enter' || editStep !== 1) return;
                if (event.target && (event.target.tagName === 'TEXTAREA' || event.target.type === 'submit')) return;
                event.preventDefault();
                document.getElementById('edit-next-btn').click();
            });

            document.getElementById('edit-account-form').addEventListener('invalid', function (event) {
                if (editStep === 2 && event.target && event.target.closest && !event.target.closest('#edit-account-section')) {
                    setEditStep(1);
                }
            }, true);


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
                    document.getElementById('update-username').setAttribute('data-original-username', profileButton.getAttribute('data-username') || '');
                    setValue('update-email', 'data-email');
                    setValue('update-middle-name', 'data-middle-name');
                    setValue('update-extension-name', 'data-extension-name');
                    setValue('update-date-of-birth', 'data-date-of-birth');
                    setValue('update-sex', 'data-sex');
                    setValue('update-street', 'data-street');
                    setValue('update-barangay', 'data-barangay');
                    setValue('update-city', 'data-city');
                    setValue('update-province', 'data-province');
                    setValue('update-country', 'data-country');
                    setValue('update-zip-code', 'data-zip-code');
                    var targetRole = profileButton.getAttribute('data-role') || 'user';
                    var targetId = profileButton.getAttribute('data-user-id') || '';
                    var isOwnAccount = targetId === currentUserId;
                    var canChangeTargetPassword = isOwnAccount || <?php echo $isSuperAdmin ? 'true' : 'false'; ?>;
                    var passwordGroupDisplay = canChangeTargetPassword ? '' : 'none';
                    document.getElementById('update-new-password-group').style.display = passwordGroupDisplay;
                    document.getElementById('update-new-password').value = '';
                    editIsOwnAccount = isOwnAccount;
                    setEditAddressRequired(isOwnAccount);
                    setEditStep(1);
                    syncAgeFromDateOfBirth();
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
            var reasonTemplate = document.getElementById('delete-reason-template');
            var customReasonGroup = document.getElementById('delete-custom-reason-group');
            var reasonField = document.getElementById('delete-action-reason');
            reasonTemplate?.addEventListener('change', function () {
                if (!customReasonGroup || !reasonField) return;
                var selectedReason = reasonTemplate.value;
                var isOtherReason = selectedReason === 'other';
                customReasonGroup.hidden = !isOtherReason;
                reasonField.required = isOtherReason;
                reasonField.value = isOtherReason ? '' : selectedReason;
                if (isOtherReason) reasonField.focus();
            });
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
                    var isDeleteAction = action === 'delete_account';
                    var deleteReasonFields = document.getElementById('delete-reason-fields');
                    var deleteReason = document.getElementById('delete-action-reason');
                    var deleteReasonTemplate = document.getElementById('delete-reason-template');
                    var customReasonGroup = document.getElementById('delete-custom-reason-group');
                    deleteReasonFields.hidden = !isDeleteAction;
                    deleteReasonTemplate.required = isDeleteAction;
                    deleteReasonTemplate.value = '';
                    customReasonGroup.hidden = true;
                    deleteReason.required = false;
                    deleteReason.value = '';
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
                    : action === 'delete_account'
                        ? 'The reason below will be emailed to the account owner.'
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
    <script src="../javascript/admin_update.js?v=3.3"></script>
</body>
</html>