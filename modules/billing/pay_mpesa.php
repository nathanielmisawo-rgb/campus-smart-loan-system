<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'STUDENT') {
    header("Location: ../auth/login.php");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pay_amount = floatval($_POST['amount']);

    if ($pay_amount <= 0 || $pay_amount > $user['current_balance']) {
        $error = "Invalid payment amount.";
    } else {
        $new_balance = $user['current_balance'] - $pay_amount;
        $status = ($new_balance == 0) ? 'ACTIVE' : $user['account_status'];

        $update = $pdo->prepare("UPDATE users SET current_balance = ?, account_status = ? WHERE user_id = ?");
        $update->execute([$new_balance, $status, $user['user_id']]);

        echo "<script>alert('Payment Received via M-Pesa!'); window.location.href='../auth/dashboard.php';</script>";
        exit;
    }
}

require_once '../../includes/header.php';
?>

<div style="max-width: 450px; margin: 0 auto;">
    <div class="card">
        <h3>M-Pesa Tab Settlement</h3>
        <p><strong>Outstanding Balance:</strong> KSh <?= number_format($user['current_balance'], 2) ?></p>
        <?php if (!empty($error)): ?><p style="color: var(--danger);"><?= $error ?></p><?php endif; ?>
        <form method="POST">
            <div class="form-group">
                <label>M-Pesa Mobile Number</label>
                <input type="text" name="phone" value="<?= htmlspecialchars($user['phone']) ?>" required>
            </div>
            <div class="form-group">
                <label>Amount to Pay (KSh)</label>
                <input type="number" step="0.01" name="amount" value="<?= $user['current_balance'] ?>" required>
            </div>
            <button type="submit" class="btn" style="width: 100%;">Pay via M-Pesa Express</button>
        </form>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>