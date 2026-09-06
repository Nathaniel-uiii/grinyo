<?php
include '../server/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['auth_user_id']) || !in_array($_SESSION['auth_role'] ?? 'user', ['admin', 'superadmin'], true)) {
    header('Location: ./login.php');
    exit();
}

$isSuperAdmin = ($_SESSION['auth_role'] ?? 'user') === 'superadmin';

$currentUserId = (string)$_SESSION['auth_user_id'];
$currentPrivileges = array_filter(array_map('trim', explode(',', (string)($_SESSION['auth_privileges'] ?? ''))));
$isAdmin = ($_SESSION['auth_role'] ?? 'user') === 'admin';
$canApprove = $isSuperAdmin || $isAdmin || in_array('approve_users', $currentPrivileges, true);
$canUpdate = $isSuperAdmin || in_array('update_accounts', $currentPrivileges, true);
$canManage = $isSuperAdmin || in_array('manage_users', $currentPrivileges, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $targetUserId = trim($_POST['user_id'] ?? '');

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
        if ($canApprove && $targetUserId !== '') {
            $stmt = mysqli_prepare($connect, "UPDATE users SET approval_status = 'approved', updated_by = ? WHERE user_id = ?");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'ss', $currentUserId, $targetUserId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        }
    }

    if ($action === 'block_account') {
        if ($canApprove && $targetUserId !== '') {
            $stmt = mysqli_prepare($connect, "UPDATE users SET approval_status = 'blocked', updated_by = ? WHERE user_id = ?");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'ss', $currentUserId, $targetUserId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
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
        $employeeId = trim($_POST['employee_id'] ?? '');
        $allowedUpdateRoles = $isSuperAdmin ? ['user', 'admin', 'superadmin'] : ['user', 'admin'];
        $role = in_array($_POST['role'] ?? 'user', $allowedUpdateRoles, true) ? $_POST['role'] : 'user';
        $status = in_array($_POST['approval_status'] ?? 'pending', ['pending','approved','blocked']) ? $_POST['approval_status'] : 'pending';
        $privileges = $normalizePrivileges($_POST['privileges'] ?? ['read_users']);
        if (!$isSuperAdmin) {
            $privileges = implode(',', array_diff(explode(',', $privileges), ['delete_accounts']));
        }

        $stmt = mysqli_prepare($connect, "UPDATE users SET first_name = ?, last_name = ?, username = ?, email = ?, employee_id = ?, role = ?, approval_status = ?, privileges = ?, updated_by = ? WHERE user_id = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'ssssssssss', $firstName, $lastName, $username, $email, $employeeId, $role, $status, $privileges, $currentUserId, $targetUserId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }

    if ($action === 'delete_account') {
        if ($targetUserId !== '' && $targetUserId !== $currentUserId) {
            $reason = trim($_POST['reason'] ?? '');
            if ($isSuperAdmin && $reason !== '') {
                $stmt = mysqli_prepare($connect, "DELETE FROM users WHERE user_id = ?");
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, 's', $targetUserId);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                }
            }
        }
    }

    if ($action === 'request_delete' && !$isSuperAdmin && $canManage) {
        $reason = trim($_POST['reason'] ?? '');
        if ($targetUserId !== '' && $reason !== '') {
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

$filterEmployeeId = trim($_GET['employee_id'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
if (!in_array($filterStatus, ['all', 'pending', 'blocked'], true)) {
    $filterStatus = '';
}
$accounts = [];
$pendingAccounts = 0;
$blockedAccounts = 0;
$totalUsers = 0;
$summaryResult = mysqli_query($connect, "SELECT COUNT(*) AS total_users, SUM(CASE WHEN COALESCE(approval_status, 'pending') = 'pending' THEN 1 ELSE 0 END) AS pending_accounts, SUM(CASE WHEN approval_status = 'blocked' THEN 1 ELSE 0 END) AS blocked_accounts FROM users");
if ($summaryResult && ($summary = mysqli_fetch_assoc($summaryResult))) {
    $totalUsers = (int)($summary['total_users'] ?? 0);
    $pendingAccounts = (int)($summary['pending_accounts'] ?? 0);
    $blockedAccounts = (int)($summary['blocked_accounts'] ?? 0);
    mysqli_free_result($summaryResult);
}
$sql = "SELECT * FROM users WHERE 1=1";
$bindTypes = '';
$params = [];
if ($filterEmployeeId !== '') {
    $sql .= " AND employee_id LIKE ?";
    $params[] = '%' . $filterEmployeeId . '%';
    $bindTypes .= 's';
}
if (in_array($filterStatus, ['pending', 'blocked'], true)) {
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Panel</title>
    <link rel="stylesheet" href="../css/admin.css?v=1.0">
</head>
<body>
    <div class="admin-wrap">
        <div class="page-header">
            <h1>Super Admin Panel</h1>
            <div class="user-chip-wrap">
                <div class="user-chip">Welcome, <?php echo htmlspecialchars($_SESSION['auth_username'] ?? 'Admin'); ?></div>
                <form method="POST" action="../server/logout.php" class="logout-form">
                    <button type="submit" class="logout-button">Log Out</button>
                </form>
            </div>
        </div>

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
                    <a class="sidebar-link active" href="#dashboard">Dashboard</a>
                    <a class="sidebar-link <?php echo $filterStatus === 'pending' ? 'active' : ''; ?>" href="?status=pending#accounts">Pending Accounts</a>
                    <a class="sidebar-link <?php echo $filterStatus === 'blocked' ? 'active' : ''; ?>" href="?status=blocked#accounts">Blocked Accounts</a>
                    <a class="sidebar-link <?php echo in_array($filterStatus, ['', 'all'], true) ? 'active' : ''; ?>" href="?status=all#accounts">Account Management</a>
                    <a class="sidebar-link" href="#update-account">Update Account</a>
                    <?php if ($isSuperAdmin): ?><a class="sidebar-link" href="#delete-requests">Delete Requests<?php if ($pendingDeleteRequests > 0): ?> <span class="request-count"><?php echo $pendingDeleteRequests; ?></span><?php endif; ?></a><?php endif; ?>
                </nav>
                <form method="POST" action="../server/logout.php" class="sidebar-logout-form">
                    <button type="submit" class="sidebar-logout">Log Out</button>
                </form>
            </aside>

            <main class="dashboard-content">
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

        <section class="panel" id="accounts">
            <div class="panel-header">
                <h2>Account Management</h2>
            </div>
            <form method="GET" class="filter-form">
                <div class="form-group">
                    <input type="text" name="employee_id" value="<?php echo htmlspecialchars($filterEmployeeId); ?>" placeholder="Search Employee ID">
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
                        <th>Employee ID</th>
                        <th>Name</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Privileges</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($accounts as $user): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($user['user_id'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($user['employee_id'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars($user['username'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($user['email'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($user['role'] ?? 'user'); ?></td>
                            <td><?php echo htmlspecialchars($user['privileges'] ?? ''); ?></td>
                            <td class="status <?php echo htmlspecialchars($user['approval_status'] ?? 'pending'); ?>"><?php echo htmlspecialchars($user['approval_status'] ?? 'pending'); ?></td>
                            <td>
                                <div class="row-actions">
                                    <?php if ($canApprove): ?><form method="POST">
                                        <input type="hidden" name="action" value="approve_account">
                                        <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($user['user_id'] ?? ''); ?>">
                                        <button type="submit" class="success">Approve</button>
                                    </form><?php endif; ?>
                                    <?php if ($canApprove): ?><form method="POST">
                                        <input type="hidden" name="action" value="block_account">
                                        <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($user['user_id'] ?? ''); ?>">
                                        <button type="submit">Block</button>
                                    </form><?php endif; ?>
                                    <?php if ($isSuperAdmin): ?>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="delete_account">
                                        <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($user['user_id'] ?? ''); ?>">
                                        <input type="text" name="reason" placeholder="Reason for deletion" required>
                                        <button type="submit" class="danger" <?php echo ($user['user_id'] ?? '') === $currentUserId ? 'disabled' : ''; ?>>Delete</button>
                                    </form>
                                    <?php elseif ($canManage): ?>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="request_delete">
                                        <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($user['user_id'] ?? ''); ?>">
                                        <input type="text" name="reason" placeholder="Reason for deletion" required>
                                        <button type="submit" class="danger">Request Delete</button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </section>

        <?php if ($isSuperAdmin && count($deleteRequests) > 0): ?>
        <section class="panel" id="delete-requests">
            <div class="panel-header">
                <h2>Delete Requests</h2>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>User Information</th><th>Requested By</th><th>Reason</th><th>Requested</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($deleteRequests as $request): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars(trim(($request['first_name'] ?? '') . ' ' . ($request['middle_name'] ?? '') . ' ' . ($request['last_name'] ?? '') . ' ' . ($request['extension_name'] ?? ''))); ?></strong><br>
                                ID: <?php echo htmlspecialchars($request['target_user_id']); ?><br>
                                Employee ID: <?php echo htmlspecialchars($request['employee_id'] ?? ''); ?><br>
                                Username: <?php echo htmlspecialchars($request['username'] ?? ''); ?><br>
                                Email: <?php echo htmlspecialchars($request['email'] ?? ''); ?><br>
                                Role: <?php echo htmlspecialchars($request['role'] ?? 'user'); ?><br>
                                Status: <?php echo htmlspecialchars($request['approval_status'] ?? ''); ?>
                            </td>
                            <td><?php echo htmlspecialchars($request['requester_username'] ?? $request['requested_by']); ?></td>
                            <td><?php echo nl2br(htmlspecialchars($request['reason'])); ?></td>
                            <td><?php echo htmlspecialchars($request['created_at']); ?></td>
                            <td><div class="row-actions">
                                <form method="POST"><input type="hidden" name="action" value="approve_delete_request"><input type="hidden" name="request_id" value="<?php echo (int)$request['id']; ?>"><button type="submit" class="danger">Approve Delete</button></form>
                                <form method="POST"><input type="hidden" name="action" value="reject_delete_request"><input type="hidden" name="request_id" value="<?php echo (int)$request['id']; ?>"><button type="submit">Reject</button></form>
                            </div></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>
            </main>
        </div>

        <?php if ($canUpdate): ?>
        <section class="panel" id="update-account">
            <div class="panel-header">
                <h2>Update Account</h2>
            </div>
            <form method="POST" class="update-form">
                <input type="hidden" name="action" value="update_account">
                <div class="form-group">
                    <input type="text" name="user_id" placeholder="User ID to Update" required>
                </div>
                <div class="form-group">
                    <input type="text" name="first_name" placeholder="First Name">
                </div>
                <div class="form-group">
                    <input type="text" name="last_name" placeholder="Last Name">
                </div>
                <div class="form-group">
                    <input type="text" name="username" placeholder="Username">
                </div>
                <div class="form-group">
                    <input type="email" name="email" placeholder="Email">
                </div>
                <div class="form-group">
                    <input type="text" name="employee_id" placeholder="Employee ID">
                </div>
                <div class="form-group">
                    <select name="role">
                        <option value="user">User</option>
                        <option value="admin">Administrator</option>
                        <?php if ($isSuperAdmin): ?><option value="superadmin">Super Administrator</option><?php endif; ?>
                    </select>
                </div>
                <div class="form-group">
                    <select name="approval_status">
                        <option value="pending">Pending</option>
                        <option value="approved">Approved</option>
                        <option value="blocked">Blocked</option>
                    </select>
                </div>
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
                <button type="submit">Update</button>
            </form>
        </section>
        <?php endif; ?>
    </div>
</body>
</html>
