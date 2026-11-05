<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Fetch user profile details
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

// Initialize variables with default values to prevent undefined variable warnings
$total_students = 0;
$total_debt = 0.00;
$available_credit = 0.00;
$transactions = [];

if ($user['role'] === 'ADMIN') {
    $total_students = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'STUDENT'")->fetchColumn();
    $total_debt = $pdo->query("SELECT SUM(current_balance) FROM users WHERE role = 'STUDENT'")->fetchColumn();
} else {
    $available_credit = $user['credit_limit'] - $user['current_balance'];
    
    $tx_stmt = $pdo->prepare("SELECT t.*, f.facility_name FROM transactions t JOIN facilities f ON t.facility_id = f.facility_id WHERE t.user_id = ? ORDER BY t.created_at DESC");
    $tx_stmt->execute([$_SESSION['user_id']]);
    $transactions = $tx_stmt->fetchAll();
}

require_once '../../includes/header.php';
?>

<?php if ($user['role'] === 'ADMIN'): ?>
    <div class="card">
        <h3>Bursar Admin Dashboard</h3>
        <p><strong>Total Registered Student Credit Accounts:</strong> <?= $total_students ?></p>
        <p><strong>Total Outstanding System Credit Balance:</strong> KSh <?= number_format($total_debt, 2) ?></p>
    </div>
    <div class="card">
        <h3>Admin Actions</h3>
        <a href="student_register.php" class="btn">Register Student Credit Tab Profile</a>
        <a href="student_view.php" class="btn">View & Edit Student Ledger</a>
        <form action="../billing/cms_hold.php" method="POST" style="display: inline-block; margin-left: 10px;">
            <button type="submit" name="trigger_hold" class="btn btn-danger" onclick="return confirm('Enforce CMS Registration Hold for overdue debts?')">Run CMS Hold Engine</button>
        </form>
    </div>
<?php else: ?>
    <div class="card">
        <h3>Student Credit Summary</h3>
        <p><strong>Student ID:</strong> <?= htmlspecialchars($user['student_id']) ?></p>
        <p><strong>Credit Limit:</strong> KSh <?= number_format($user['credit_limit'], 2) ?></p>
        <p><strong>Current Balance Owed:</strong> KSh <?= number_format($user['current_balance'], 2) ?></p>
        <p><strong>Available Credit:</strong> KSh <?= number_format($available_credit, 2) ?></p>
        <p><strong>CMS Status:</strong> 
            <span style="color: <?= $user['account_status'] === 'ACTIVE' ? 'var(--primary-hover)' : 'var(--danger)' ?>;">
                <?= $user['account_status'] ?>
            </span>
        </p>
        <a href="../billing/pay_mpesa.php" class="btn">Settle Tab via M-Pesa</a>
    </div>

    <div class="card">
        <h3>Recent POS Transactions</h3>
        <table>
            <thead>
                <tr>
                    <th>Date & Time</th>
                    <th>Facility</th>
                    <th>Amount</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($transactions)): ?>
                    <tr>
                        <td colspan="4" style="text-align: center; color: var(--text-muted);">No transactions recorded yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($transactions as $tx): ?>
                    <tr>
                        <td><?= $tx['created_at'] ?></td>
                        <td><?= htmlspecialchars($tx['facility_name']) ?></td>
                        <td>KSh <?= number_format($tx['amount'], 2) ?></td>
                        <td><?= $tx['status'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>