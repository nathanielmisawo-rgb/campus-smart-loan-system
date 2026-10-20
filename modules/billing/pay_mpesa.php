<?php
session_start();
require_once '../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['user_id'];
    $phone = $_POST['phone'];

    // Fetch debt
    $stmt = $pdo->prepare("SELECT current_balance FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $balance = $stmt->fetchColumn();

    if ($balance > 0) {
        // Mock M-Pesa STK Push Settlement
        $pdo->beginTransaction();
        
        // Zero balance
        $pdo->prepare("UPDATE users SET current_balance = 0.00, account_status = 'ACTIVE' WHERE user_id = ?")->execute([$user_id]);
        
        // Update Invoices
        $pdo->prepare("UPDATE semester_invoices SET payment_status = 'PAID' WHERE user_id = ?")->execute([$user_id]);

        $pdo->commit();
        echo "<h3>M-Pesa Payment Received from $phone. KES " . number_format($balance, 2) . " Cleared! Account is active.</h3>";
        echo "<a href='../auth/dashboard.php'>Return to Dashboard</a>";
        exit;
    } else {
        echo "No outstanding balance to clear.";
    }
}
?>

<!DOCTYPE html>
<html>
<head><title>Pay Tab via M-Pesa</title></head>
<body>
    <h2>M-Pesa Settlement Gateway</h2>
    <form method="POST">
        <label>M-Pesa Phone Number:</label><br>
        <input type="text" name="phone" placeholder="254712345678" required><br><br>
        <button type="submit">Pay Outstanding Balance</button>
    </form>
</body>
</html>