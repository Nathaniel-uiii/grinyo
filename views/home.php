<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
// If user is already logged in, redirect to dashboard
if (isset($_SESSION['auth_user_id'])) {
    header('Location: ./index.php');
    exit();
}
$vs = isset($_GET['viewsource']) ? '?viewsource=1' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ube Delights - Home</title>
    <link rel="stylesheet" href="../css/home.css?v=1.0">
</head>
<body>
    <nav class="navbar">
        <div class="nav-container">
            <div class="nav-logo">
                <a onclick="getHome()" class="logo-link">
                    <img src="../images/logo.png" alt="Ube Roll Logo" class="logo-image">
                    <h2>Ube Delights</h2>
                </a>
            </div>
            <div class="nav-menu">
                <a onclick="getLogin()" class="nav-link">Login</a>
                <a onclick="getRegister()" class="nav-link">Register</a>
            </div>
        </div>
    </nav>

    <main class="main-content">
        <div class="content-wrapper">
            <div class="description-section">
                <header>
                    <h1>Welcome to Ube Delights</h1>
                </header>
                <div class="description-content">
                    <p>Indulge in the rich, vibrant flavors of our signature ube cakes made with authentic purple yam from the Philippines.</p>
                    
                    <p>Each cake features layers of moist, fluffy sponge with natural ube flavor and smooth cream cheese frosting.</p>
                </div>
            </div>
            <div class="image-section">
                <img src="../images/cake.png" alt="Delicious Ube Cake" class="hero-image">
            </div>
        </div>
    </main>

    <footer class="footer">
        <p>&copy; 2025 Ube Delights. All rights reserved.</p>
    </footer>
    <script src="../javascript/routing.js"></script>
    <script src="../javascript/inspect.js"></script>
</body>
</html>
