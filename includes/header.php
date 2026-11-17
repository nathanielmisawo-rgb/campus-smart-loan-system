<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'], 3), '/\\');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Campus Smart Loan System</title>
    <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl) ?>/public/css/style.css">
</head>
<body>
    <nav class="navbar">
        <div class="nav-brand">
            <a href="<?= htmlspecialchars($baseUrl) ?>/modules/auth/dashboard.php">Campus Smart Loan System</a>
        </div>
        <ul class="nav-links">
            <?php if (isset($_SESSION['user_id'])): ?>
                <li><a href="<?= htmlspecialchars($baseUrl) ?>/modules/auth/dashboard.php">Dashboard</a></li>
                <li><a href="<?= htmlspecialchars($baseUrl) ?>/modules/billing/pay_mpesa.php">M-Pesa Payment</a></li>
                <li><a href="<?= htmlspecialchars($baseUrl) ?>/modules/auth/logout.php">Logout</a></li>
            <?php else: ?>
                <li><a href="<?= htmlspecialchars($baseUrl) ?>/modules/auth/dashboard.php">Dashboard</a></li>
                <li><a href="<?= htmlspecialchars($baseUrl) ?>/modules/billing/pay_mpesa.php">M-Pesa Payment</a></li>
                <li><a href="<?= htmlspecialchars($baseUrl) ?>/modules/auth/login.php">Logout</a></li>
            <?php endif; ?>
        </ul>
    </nav>
    <main class="main-content">