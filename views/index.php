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

$fullName = trim(($userProfile['first_name'] ?? '') . ' ' . ($userProfile['last_name'] ?? ''));
$displayName = $fullName !== '' ? $fullName : ($userProfile['username'] ?? 'Customer');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ube Delights - Customer Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../css/admin.css?v=1.2">
</head>
<body class="user-dashboard-page">
    <div class="admin-wrap">
        <div class="page-header">
            <h1>Customer Dashboard</h1>
            <div class="user-chip-wrap">
                <div class="user-chip">Welcome, <?php echo htmlspecialchars($displayName); ?></div>
            </div>
        </div>

        <div class="dashboard-layout">
            <aside class="sidebar" aria-label="User navigation">
                <div class="sidebar-brand">
                    <span class="brand-mark">UD</span>
                    <div>
                        <strong>Ube Delights</strong>
                        <span>Customer Portal</span>
                    </div>
                </div>
                <nav class="sidebar-nav">
                    <a class="sidebar-link active" href="#dashboard">Dashboard</a>
                    <a class="sidebar-link" href="#profile">Profile</a>
                    <a class="sidebar-link" href="#order-now">Order Now</a>
                    <a class="sidebar-link" href="#orders">Orders</a>
                </nav>
                <form method="POST" action="../server/logout.php" class="sidebar-logout-form">
                    <button type="submit" class="sidebar-logout">Log Out</button>
                </form>
            </aside>

            <main class="dashboard-content">
                <section id="dashboard" class="summary-grid" aria-label="Customer overview">
                    <div class="summary-card user-card">
                        <span class="summary-label">Total Orders</span>
                        <strong>0</strong>
                    </div>
                    <div class="summary-card pending-card">
                        <span class="summary-label">Pending</span>
                        <strong>0</strong>
                    </div>
                    <div class="summary-card total-card">
                        <span class="summary-label">Confirmed</span>
                        <strong>0</strong>
                    </div>
                </section>

                <section class="panel" id="my-account">
                    <div class="panel-header">
                        <h2>My Profile</h2>
                        <button type="button" class="primary-action" id="view-profile-btn">View Details</button>
                    </div>
                    <div class="details-grid">
                        <div class="details-section-title">Account Information</div>
                        <div><span>User ID</span><strong><?php echo htmlspecialchars($userId); ?></strong></div>
                        <div><span>Name</span><strong><?php echo htmlspecialchars($displayName); ?></strong></div>
                        <div><span>Username</span><strong><?php echo htmlspecialchars($userProfile['username'] ?? ''); ?></strong></div>
                        <div><span>Email</span><strong><?php echo htmlspecialchars($userProfile['email'] ?: 'No email'); ?></strong></div>
                        <div><span>Role</span><strong><?php echo htmlspecialchars(ucfirst($userProfile['role'] ?? 'user')); ?></strong></div>
                    </div>
                </section>

                <div id="profile-modal" class="modal-backdrop" aria-hidden="true">
                    <section class="modal-panel details-modal" role="dialog" aria-modal="true" aria-labelledby="profile-modal-title">
                        <button type="button" class="modal-close close-profile-modal" aria-label="Close profile">&times;</button>
                        <h2 id="profile-modal-title">Profile Details</h2>
                        <div class="details-grid">
                            <div class="details-section-title">Personal Information</div>
                            <div><span>Full Name</span><strong><?php echo htmlspecialchars($displayName); ?></strong></div>
                            <div><span>Username</span><strong><?php echo htmlspecialchars($userProfile['username'] ?? ''); ?></strong></div>
                            <div><span>Email</span><strong><?php echo htmlspecialchars($userProfile['email'] ?? ''); ?></strong></div>
                            <div><span>Role</span><strong><?php echo htmlspecialchars(ucfirst($userProfile['role'] ?? 'user')); ?></strong></div>
                        </div>
                    </section>
                </div>

                <section id="orders" class="panel">
                    <div class="panel-header">
                        <h2>Orders</h2>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Order Date</th>
                                    <th>Price</th>
                                    <th>Status</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                            </tbody>
                        </table>
                    </div>
                    <div class="empty-state">
                        <div class="empty-icon"><i class="fa-solid fa-folder-open"></i></div>
                        <h3>No orders yet</h3>
                        <p>You haven't placed any orders yet. <a href="#order-now">Order now here!</a></p>
                    </div>
                </section>
            </main>
        </div>
    </div>

    <script src="../javascript/disable_back.js"></script>
    <script src="../javascript/index.js"></script>
    <script>
    document.getElementById('view-profile-btn')?.addEventListener('click', function() {
        var modal = document.getElementById('profile-modal');
        if (modal) {
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
        }
    });
    document.querySelector('.close-profile-modal')?.addEventListener('click', function() {
        var modal = document.getElementById('profile-modal');
        if (modal) {
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
        }
    });
    document.querySelectorAll('.modal-backdrop').forEach(function(modal) {
        modal.addEventListener('click', function(event) {
            if (event.target === modal) {
                modal.classList.remove('is-open');
                modal.setAttribute('aria-hidden', 'true');
            }
        });
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-backdrop.is-open').forEach(function(m) {
                m.classList.remove('is-open');
                m.setAttribute('aria-hidden', 'true');
            });
        }
    });
    </script>
</body>
</html>
