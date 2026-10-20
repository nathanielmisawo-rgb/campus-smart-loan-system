<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

$available_credit = $user['credit_limit'] - $user['current_balance'];
?>

<!DOCTYPE html>
<html>
<head><title>Student Credit Dashboard</title></head>
<body>
    <h1>Welcome, <?= htmlspecialchars($user['full_name']) ?></h1>
    <p>Account Status: <strong><?= $user['account_status'] ?></strong></p>
    
    <h3>Credit Tab Summary</h3>
    <ul>
        <li>Total Credit Limit: KES <?= number_format($user['credit_limit'], 2) ?></li>
        <li>Current Outstanding Debt: KES <?= number_format($user['current_balance'], 2) ?></li>
        <li><strong>Available Credit: KES <?= number_format($available_credit, 2) ?></strong></li>
    </ul>

    <a href="../pos/checkout.php">Go to POS Terminal (Simulator)</a> | 
    <a href="../billing/pay_mpesa.php">Settle Tab via M-Pesa</a>
</body>
</html>