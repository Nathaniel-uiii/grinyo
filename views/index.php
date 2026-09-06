<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['auth_user_id'])) {
    header('Location: ./login.php');
    exit();
}

$userId = (string)$_SESSION['auth_user_id'];
$userProfile = [
    'first_name' => '',
    'last_name' => '',
    'username' => $_SESSION['auth_username'] ?? '',
    'email' => '',
    'employee_id' => '',
    'role' => $_SESSION['auth_role'] ?? 'user'
];

require_once __DIR__ . '/../server/db.php';
if ($connect) {
    $sql = "SELECT first_name, last_name, username, email, employee_id, role FROM users WHERE user_id = ? LIMIT 1";
    if ($stmt = mysqli_prepare($connect, $sql)) {
        mysqli_stmt_bind_param($stmt, 's', $userId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($res) {
            $row = mysqli_fetch_assoc($res);
            if ($row) {
                $userProfile['first_name'] = $row['first_name'] ?? '';
                $userProfile['last_name'] = $row['last_name'] ?? '';
                $userProfile['username'] = $row['username'] ?? ($userProfile['username'] ?? '');
                $userProfile['email'] = $row['email'] ?? '';
                $userProfile['employee_id'] = $row['employee_id'] ?? '';
                $userProfile['role'] = $row['role'] ?? ($userProfile['role'] ?? 'user');
            }
        }
        mysqli_stmt_close($stmt);
    }
    mysqli_close($connect);
}

$orders = [
    ['order_id' => 'UD-1042', 'item' => 'Classic Ube Cake', 'qty' => 2, 'total' => '₱1,200', 'status' => 'Delivered', 'date' => '2026-08-12'],
    ['order_id' => 'UD-1038', 'item' => 'Ube Cheesecake', 'qty' => 1, 'total' => '₱780', 'status' => 'Preparing', 'date' => '2026-08-18'],
    ['order_id' => 'UD-1029', 'item' => 'Ube Roll', 'qty' => 3, 'total' => '₱1,980', 'status' => 'Shipped', 'date' => '2026-08-22']
];

$totalOrders = count($orders);
$pendingOrders = count(array_filter($orders, fn($order) => in_array($order['status'], ['Preparing', 'Shipped'], true)));
$totalSpent = '₱3,960';
$fullName = trim(($userProfile['first_name'] ?? '') . ' ' . ($userProfile['last_name'] ?? ''));
$displayName = $fullName !== '' ? $fullName : ($userProfile['username'] ?? 'Customer');
$initials = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $displayName ?: 'U'), 0, 2) ?: 'U');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ube Delights - User Dashboard</title>
    <link rel="stylesheet" href="../css/admin.css?v=1.0">
</head>
<body>
    <div class="customer-app-shell">
        <aside class="customer-sidebar" aria-label="User navigation">
            <div class="sidebar-brand">
                <span class="brand-mark">UD</span>
                <div>
                    <strong>Ube Delights</strong>
                    <span>Customer Portal</span>
                </div>
            </div>

            <nav class="customer-nav">
                <a class="customer-nav-link active" href="#dashboard">Dashboard</a>
                <a class="customer-nav-link" href="#profile">Profile</a>
                <a class="customer-nav-link" href="#order-now">Order Now</a>
                <a class="customer-nav-link" href="#orders">Orders</a>
            </nav>

            <form method="POST" action="../server/logout.php" class="sidebar-logout-form">
                <button type="submit" class="sidebar-logout">Log Out</button>
            </form>
        </aside>

        <main class="customer-dashboard-shell">
            <h1>Customer Dashboard</h1>
            <p class="dashboard-subtitle">Welcome back, <?php echo htmlspecialchars($displayName); ?>! Manage your orders and profile.</p>

            <section id="dashboard" class="customer-hero" aria-label="Customer overview">
                <div class="customer-avatar"><?php echo htmlspecialchars($initials); ?></div>
                <div class="customer-details">
                    <h2><?php echo htmlspecialchars($displayName); ?></h2>
                    <p><?php echo htmlspecialchars($userProfile['email'] ?: 'no email available'); ?></p>
                    <button type="button" class="customer-primary-action" id="view-profile-btn">View Profile</button>
                </div>
            </section>

            <div id="profile-modal" class="profile-modal hidden" aria-hidden="true">
                <div class="profile-modal-content" role="dialog" aria-modal="true" aria-labelledby="profile-modal-title">
                    <button type="button" class="profile-modal-close" id="close-profile-modal" aria-label="Close profile">×</button>
                    <h3 id="profile-modal-title">Profile</h3>
                    <div class="profile-modal-grid">
                        <div class="profile-modal-field">
                            <label>Full Name</label>
                            <span><?php echo htmlspecialchars($displayName); ?></span>
                        </div>
                        <div class="profile-modal-field">
                            <label>Username</label>
                            <span><?php echo htmlspecialchars($userProfile['username'] ?? ''); ?></span>
                        </div>
                        <div class="profile-modal-field">
                            <label>Email</label>
                            <span><?php echo htmlspecialchars($userProfile['email'] ?? ''); ?></span>
                        </div>
                        <div class="profile-modal-field">
                            <label>Role</label>
                            <span><?php echo htmlspecialchars(ucfirst($userProfile['role'] ?? 'user')); ?></span>
                        </div>
                        <div class="profile-modal-field">
                            <label>Address</label>
                            <span><?php echo htmlspecialchars('123 Sample Street, Brgy. Sample, City of Sample'); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <section class="customer-stats" aria-label="Order summary">
                <div class="stat-card stat-card-purple">
                    <span class="stat-number">0</span>
                    <span class="stat-label">TOTAL ORDERS</span>
                </div>
                <div class="stat-card stat-card-blue">
                    <span class="stat-number">0</span>
                    <span class="stat-label">PENDING</span>
                </div>
                <div class="stat-card stat-card-orange">
                    <span class="stat-number">0</span>
                    <span class="stat-label">CONFIRMED</span>
                </div>
            </section>

            <section id="profile" class="profile-panel">
                <h2>Profile</h2>
                <div class="profile-divider"></div>
                <div class="profile-grid">
                    <div class="info-card">
                        <label>USERNAME</label>
                        <div class="info-value"><?php echo htmlspecialchars($userProfile['username'] ?? ''); ?></div>
                    </div>
                    <div class="info-card">
                        <label>EMAIL</label>
                        <div class="info-value"><?php echo htmlspecialchars($userProfile['email'] ?? ''); ?></div>
                    </div>
                    <div class="info-card">
                        <label>ROLE</label>
                        <div class="info-value"><?php echo htmlspecialchars(ucfirst($userProfile['role'] ?? 'user')); ?></div>
                    </div>
                </div>
            </section>

            <section id="orders" class="my-bookings-panel">
                <div class="bookings-header">
                    <span class="booking-icon">📅</span>
                    <h2>Orders</h2>
                </div>

                <div class="bookings-filters">
                    <input type="text" placeholder="Search order or date..." aria-label="Search order or date" />
                    <select aria-label="Filter status">
                        <option>All Status</option>
                    </select>
                </div>

                <div class="bookings-table-header">
                    <span>Item</span>
                    <span>Order Date</span>
                    <span>Price</span>
                    <span>Status</span>
                    <span>Notes</span>
                </div>

                <div class="empty-state">
                    <div class="empty-icon">📁</div>
                    <h3>No orders yet</h3>
                    <p>You haven't placed any orders yet. <a href="#order-now">Order now here!</a></p>
                </div>
            </section>
        </main>
    </div>

    <script src="../javascript/disable_back.js"></script>
    <script src="../javascript/index.js"></script>
    <script src="../javascript/routing.js"></script>
    <script src="../javascript/inspect.js"></script>
</body>
</html>