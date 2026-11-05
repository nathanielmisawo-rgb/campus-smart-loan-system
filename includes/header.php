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
    <title>Campus Smart Loan System</title>
    <link rel="stylesheet" href="/campus_smart_loan_system/public/css/style.css">
</head>
<body>
    <header class="navbar">
        <div class="nav-container">
            <h2 class="logo">Campus Smart Loan System</h2>
            <nav>
                <ul class="nav-links">
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <?php if ($_SESSION['role'] === 'ADMIN'): ?>
                            <li><a href="/campus_smart_loan_system/modules/auth/dashboard.php">Admin Panel</a></li>
                            <li><a href="/campus_smart_loan_system/modules/auth/student_view.php">Student Directory</a></li>
                        <?php elseif ($_SESSION['role'] === 'VENDOR'): ?>
                            <li><a href="/campus_smart_loan_system/modules/pos/checkout.php">POS Terminal</a></li>
                        <?php else: ?>
                            <li><a href="/campus_smart_loan_system/modules/auth/dashboard.php">Dashboard</a></li>
                            <li><a href="/campus_smart_loan_system/modules/billing/pay_mpesa.php">M-Pesa Payment</a></li>
                        <?php endif; ?>
                        <li><a href="/campus_smart_loan_system/modules/auth/login.php?logout=1" class="btn-logout">Logout</a></li>
                    <?php else: ?>
                        <li><a href="/campus_smart_loan_system/modules/auth/login.php">Login</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    </header>
    <main class="container"></main>