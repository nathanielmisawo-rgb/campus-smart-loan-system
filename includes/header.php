<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Campus Smart Loan Tab System</title>
    <link rel="stylesheet" href="/campus_smart_loan_system/public/css/style.css">
</head>
<body>
    <header class="navbar">
        <div class="nav-container">
            <h1 class="logo">Campus Smart Loan Tab System</h1>
            <nav>
                <ul class="nav-links">
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <li><a href="/campus_smart_loan_system/modules/auth/dashboard.php">Dashboard</a></li>
                        <li><a href="/campus_smart_loan_system/modules/pos/checkout.php">POS Checkout</a></li>
                        <li><a href="/campus_smart_loan_system/modules/billing/pay_mpesa.php">M-Pesa Payment</a></li>
                        <li><a href="/campus_smart_loan_system/modules/auth/login.php" class="btn-logout">Logout</a></li>
                    <?php else: ?>
                        <li><a href="/campus_smart_loan_system/modules/auth/login.php">Login</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    </header>
    <main class="main-content"></main>