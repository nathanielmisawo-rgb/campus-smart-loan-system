<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'], 3), '/\\');
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Campus Smart Loan System</title>
    <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl) ?>/public/css/style.css?v=<?= time() ?>">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>
    <nav class="navbar">
        <div class="nav-inner">
            <div class="nav-brand">
                <a href="<?= htmlspecialchars($baseUrl) ?>/modules/auth/dashboard.php" class="brand-logo">
                    <span class="brand-icon"><i data-lucide="wallet"></i></span>
                    <span>Campus<span class="brand-accent">Tab</span></span>
                </a>
            </div>

            <ul class="nav-links">
                <?php if (isset($_SESSION['user_id'])): ?>
                    <?php $role = $_SESSION['role'] ?? 'STUDENT'; ?>

                    <?php if ($role === 'ADMIN'): ?>
                        <li><a href="<?= htmlspecialchars($baseUrl) ?>/modules/auth/dashboard.php" class="<?= $currentPage === 'dashboard.php' ? 'active' : '' ?>">Dashboard</a></li>
                        <li><a href="<?= htmlspecialchars($baseUrl) ?>/modules/auth/student_view.php" class="<?= $currentPage === 'student_view.php' ? 'active' : '' ?>">Students</a></li>
                        <li><a href="<?= htmlspecialchars($baseUrl) ?>/modules/billing/cms_hold.php" class="<?= $currentPage === 'cms_hold.php' ? 'active' : '' ?>">CMS Holds</a></li>
                        <li><a href="<?= htmlspecialchars($baseUrl) ?>/modules/pos/checkout.php" class="<?= $currentPage === 'checkout.php' ? 'active' : '' ?>">POS</a></li>
                    <?php elseif ($role === 'VENDOR'): ?>
                        <li><a href="<?= htmlspecialchars($baseUrl) ?>/modules/pos/checkout.php" class="<?= $currentPage === 'checkout.php' ? 'active' : '' ?>">POS Terminal</a></li>
                        <li><a href="<?= htmlspecialchars($baseUrl) ?>/modules/auth/dashboard.php" class="<?= $currentPage === 'dashboard.php' ? 'active' : '' ?>">Portal</a></li>
                    <?php else: ?>
                        <li><a href="<?= htmlspecialchars($baseUrl) ?>/modules/auth/dashboard.php" class="<?= $currentPage === 'dashboard.php' ? 'active' : '' ?>">Dashboard</a></li>
                        <li><a href="<?= htmlspecialchars($baseUrl) ?>/modules/billing/pay_mpesa.php" class="<?= $currentPage === 'pay_mpesa.php' ? 'active' : '' ?>">M-Pesa Payment</a></li>
                    <?php endif; ?>
                <?php else: ?>
                    <li><a href="<?= htmlspecialchars($baseUrl) ?>/modules/auth/login.php">SSO Login</a></li>
                <?php endif; ?>
            </ul>

            <?php if (isset($_SESSION['user_id'])): ?>
                <div class="nav-user">
                    <div class="user-chip">
                        <span class="user-name"><?= htmlspecialchars($_SESSION['full_name'] ?? 'User') ?></span>
                        <span class="role-badge role-<?= strtolower(htmlspecialchars($role)) ?>"><?= htmlspecialchars($role) ?></span>
                    </div>
                    <a href="<?= htmlspecialchars($baseUrl) ?>/modules/auth/logout.php" class="btn-logout" title="Logout">
                        <i data-lucide="log-out"></i>
                        <span>Logout</span>
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </nav>
    <main class="main-content container">
