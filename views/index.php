<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['auth_user_id'])) {
    header('Location: ./login.php');
    exit();
}

$userId = (string)$_SESSION['auth_user_id'];
$userProfile = [
    'first_name' => '',
    'middle_name' => '',
    'last_name' => '',
    'extension_name' => '',
    'username' => $_SESSION['auth_username'] ?? '',
    'email' => '',
    'employee_id' => '',
    'role' => $_SESSION['auth_role'] ?? 'user',
    'approval_status' => 'pending',
    'created_at' => ''
];
$userActivityLogs = [];

require_once __DIR__ . '/../server/db.php';
if ($connect) {
    $profileSql = "SELECT first_name, middle_name, last_name, extension_name, username, email, employee_id, role, approval_status, created_at FROM users WHERE user_id = ? LIMIT 1";
    if ($profileStmt = mysqli_prepare($connect, $profileSql)) {
        mysqli_stmt_bind_param($profileStmt, 's', $userId);
        mysqli_stmt_execute($profileStmt);
        $profileResult = mysqli_stmt_get_result($profileStmt);
        if ($profileResult && ($profileRow = mysqli_fetch_assoc($profileResult))) {
            foreach ($userProfile as $field => $value) {
                if (array_key_exists($field, $profileRow)) {
                    $userProfile[$field] = $profileRow[$field] ?? $value;
                }
            }
        }
        mysqli_stmt_close($profileStmt);
    }

    // Show only administrator events linked to this account. The detail checks
    // keep older account events visible until they have a target_user_id value.
    $activitySql = "SELECT name, section, details, time_in
        FROM activity_logs
        WHERE role = 'admin'
          AND (
              target_user_id = ?
              OR (
                  target_user_id IS NULL
                  AND (
                      details LIKE CONCAT('Approved account ', ?, '%')
                      OR details LIKE CONCAT('Blocked account ', ?, '%')
                      OR details LIKE CONCAT('Unblocked account ', ?, '%')
                      OR details LIKE CONCAT('Edited account ', ?, '%')
                  )
              )
          )
        ORDER BY time_in DESC, id DESC
        LIMIT 8";
    if ($activityStmt = mysqli_prepare($connect, $activitySql)) {
        mysqli_stmt_bind_param($activityStmt, 'sssss', $userId, $userId, $userId, $userId, $userId);
        mysqli_stmt_execute($activityStmt);
        $activityResult = mysqli_stmt_get_result($activityStmt);
        if ($activityResult) {
            while ($activityRow = mysqli_fetch_assoc($activityResult)) {
                $userActivityLogs[] = $activityRow;
            }
            mysqli_free_result($activityResult);
        }
        mysqli_stmt_close($activityStmt);
    }

    mysqli_close($connect);
}

$fullName = trim(implode(' ', array_filter([
    $userProfile['first_name'] ?? '',
    $userProfile['middle_name'] ?? '',
    $userProfile['last_name'] ?? '',
    $userProfile['extension_name'] ?? ''
])));
$displayName = $fullName !== '' ? $fullName : ($userProfile['username'] ?? 'Customer');

$statusCode = strtolower((string)($userProfile['approval_status'] ?? 'pending'));
if (!in_array($statusCode, ['active', 'pending', 'blocked'], true)) {
    $statusCode = 'pending';
}
$statusLabels = ['active' => 'Active', 'pending' => 'Pending', 'blocked' => 'Blocked'];
$accountStatusLabel = $statusLabels[$statusCode];
$createdTimestamp = !empty($userProfile['created_at']) ? strtotime((string)$userProfile['created_at']) : false;
$memberSince = $createdTimestamp ? date('M Y', $createdTimestamp) : '—';
$role = strtolower((string)($userProfile['role'] ?? 'user'));
$roleLabel = $role === 'user' ? 'Customer' : ucfirst($role);

function customer_activity_message($details, $userId)
{
    $messages = [
        'Approved account ' => 'Your account was approved by an administrator.',
        'Blocked account ' => 'An administrator changed your account access.',
        'Unblocked account ' => 'An administrator restored your account access.',
        'Edited account ' => 'An administrator updated your account details.'
    ];
    foreach ($messages as $prefix => $message) {
        if (strpos((string)$details, $prefix . $userId) === 0) {
            return $message;
        }
    }
    return (string)$details;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ube Delights - Customer Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../css/admin.css?v=1.2">
    <link rel="stylesheet" href="../css/user_dashboard.css?v=1.0">
</head>
<body class="user-dashboard-page">
    <div class="admin-wrap">
        <header class="page-header">
            <div class="welcome-copy">
                <span class="portal-eyebrow">UBE DELIGHTS <span aria-hidden="true">/</span> CUSTOMER PORTAL</span>
                <h1>Welcome back, <?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></h1>
                <p>Your account details and updates, all in one place.</p>
            </div>
            <div class="user-chip-wrap">
                <div class="user-chip"><i class="fa-regular fa-id-card" aria-hidden="true"></i> Account ID <strong><?php echo htmlspecialchars($userId, ENT_QUOTES, 'UTF-8'); ?></strong></div>
            </div>
        </header>

        <div class="dashboard-layout">
            <aside class="sidebar" aria-label="Customer navigation">
                <div class="sidebar-brand">
                    <span class="brand-mark">UD</span>
                    <div>
                        <strong>Ube Delights</strong>
                        <span>Customer Portal</span>
                    </div>
                </div>
                <nav class="sidebar-nav">
                    <a class="sidebar-link customer-nav-link active" href="#dashboard"><i class="fa-solid fa-house" aria-hidden="true"></i> Overview</a>
                    <a class="sidebar-link customer-nav-link" href="#my-account"><i class="fa-regular fa-user" aria-hidden="true"></i> My profile</a>
                    <a class="sidebar-link customer-nav-link" href="#activity-log"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> Admin activity</a>
                    <a class="sidebar-link customer-nav-link" href="#orders"><i class="fa-solid fa-bag-shopping" aria-hidden="true"></i> Order history</a>
                </nav>
                <form method="POST" action="../server/logout.php" class="sidebar-logout-form">
                    <button type="submit" class="sidebar-logout"><i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i> Log Out</button>
                </form>
            </aside>

            <main class="dashboard-content">
                <section id="dashboard" class="summary-grid" aria-label="Account overview">
                    <article class="summary-card user-status-card">
                        <span class="summary-label">Account status</span>
                        <strong class="status-value status-<?php echo htmlspecialchars($statusCode, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($accountStatusLabel, ENT_QUOTES, 'UTF-8'); ?></strong>
                        <i class="fa-solid fa-shield-halved summary-icon" aria-hidden="true"></i>
                        <span class="summary-note">Your account standing</span>
                    </article>
                    <article class="summary-card member-card">
                        <span class="summary-label">Member since</span>
                        <strong><?php echo htmlspecialchars($memberSince, ENT_QUOTES, 'UTF-8'); ?></strong>
                        <i class="fa-regular fa-calendar summary-icon" aria-hidden="true"></i>
                        <span class="summary-note">Your Ube Delights account</span>
                    </article>
                    <article class="summary-card account-type-card">
                        <span class="summary-label">Account type</span>
                        <strong><?php echo htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8'); ?></strong>
                        <i class="fa-regular fa-id-badge summary-icon" aria-hidden="true"></i>
                        <span class="summary-note">Account ID <?php echo htmlspecialchars($userId, ENT_QUOTES, 'UTF-8'); ?></span>
                    </article>
                </section>

                <section class="panel profile-panel" id="my-account">
                    <div class="panel-header">
                        <div>
                            <span class="panel-kicker">YOUR DETAILS</span>
                            <h2>My profile</h2>
                        </div>
                        <span class="profile-status status-<?php echo htmlspecialchars($statusCode, ENT_QUOTES, 'UTF-8'); ?>">
                            <span class="status-dot" aria-hidden="true"></span>
                            <?php echo htmlspecialchars($accountStatusLabel, ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>
                    <div class="details-grid">
                        <div class="details-section-title">Account information</div>
                        <div><span>User ID</span><strong><?php echo htmlspecialchars($userId, ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><span>Name</span><strong><?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><span>Username</span><strong><?php echo htmlspecialchars($userProfile['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><span>Email</span><strong><?php echo htmlspecialchars($userProfile['email'] ?: 'No email', ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><span>Account type</span><strong><?php echo htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8'); ?></strong></div>
                    </div>
                </section>

                <section class="panel activity-panel" id="activity-log" aria-labelledby="activity-title">
                    <div class="panel-header">
                        <div>
                            <span class="panel-kicker">ACCOUNT HISTORY</span>
                            <h2 id="activity-title">Admin activity</h2>
                            <p class="panel-description">Recent administrator updates connected to your account.</p>
                        </div>
                        <span class="activity-count"><?php echo count($userActivityLogs); ?> recent</span>
                    </div>
                    <?php if (!$userActivityLogs): ?>
                        <div class="activity-empty">
                            <span class="activity-empty-icon"><i class="fa-regular fa-bell" aria-hidden="true"></i></span>
                            <h3>No account updates yet</h3>
                            <p>When an administrator makes a change connected to your account, it will appear here.</p>
                        </div>
                    <?php else: ?>
                        <ol class="activity-list">
                            <?php foreach ($userActivityLogs as $activity): ?>
                                <?php
                                $activityTimestamp = strtotime((string)($activity['time_in'] ?? ''));
                                $activityActor = trim((string)($activity['name'] ?? ''));
                                if ($activityActor === '') { $activityActor = 'Administrator'; }
                                ?>
                                <li class="activity-entry">
                                    <span class="activity-marker"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i></span>
                                    <div class="activity-copy">
                                        <strong><?php echo htmlspecialchars(customer_activity_message($activity['details'] ?? '', $userId), ENT_QUOTES, 'UTF-8'); ?></strong>
                                        <span><?php echo htmlspecialchars($activityActor, ENT_QUOTES, 'UTF-8'); ?> <span class="activity-role">ADMIN</span></span>
                                    </div>
                                    <time<?php echo $activityTimestamp ? ' datetime="' . htmlspecialchars(date(DATE_ATOM, $activityTimestamp), ENT_QUOTES, 'UTF-8') . '"' : ''; ?>>
                                        <?php echo $activityTimestamp ? htmlspecialchars(date('M j, Y · g:i A', $activityTimestamp), ENT_QUOTES, 'UTF-8') : 'Time unavailable'; ?>
                                    </time>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                </section>

                <section id="orders" class="panel order-panel">
                    <div class="panel-header">
                        <div>
                            <span class="panel-kicker">YOUR PURCHASES</span>
                            <h2>Order history</h2>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Order date</th>
                                    <th>Price</th>
                                    <th>Status</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                    <div class="empty-state order-empty">
                        <div class="empty-icon"><i class="fa-solid fa-bag-shopping" aria-hidden="true"></i></div>
                        <h3>No order history yet</h3>
                        <p>Your order details and updates will appear here when orders are available.</p>
                    </div>
                </section>
            </main>
        </div>
    </div>

    <script src="../javascript/disable_back.js"></script>
    <script src="../javascript/index.js"></script>
</body>
</html>