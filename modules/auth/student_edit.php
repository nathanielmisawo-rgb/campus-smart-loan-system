<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'ADMIN') {
    header("Location: login.php");
    exit;
}

$uid = $_GET['uid'] ?? 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uid = $_POST['user_id'];
    $full_name = $_POST['full_name'];
    $credit_limit = floatval($_POST['credit_limit']);
    $current_balance = floatval($_POST['current_balance']);
    $account_status = $_POST['account_status'];

    $stmt = $pdo->prepare("UPDATE users SET full_name=?, credit_limit=?, current_balance=?, account_status=? WHERE user_id=?");
    $stmt->execute([$full_name, $credit_limit, $current_balance, $account_status, $uid]);
    header("Location: student_view.php");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$uid]);
$student = $stmt->fetch();

require_once '../../includes/header.php';
?>

<div style="max-width: 500px; margin: 0 auto;">
    <div class="card">
        <h3>Edit Student Credit Record</h3>
        <form method="POST">
            <input type="hidden" name="user_id" value="<?= $student['user_id'] ?>">
            <div class="form-group"><label>Full Name</label><input type="text" name="full_name" value="<?= htmlspecialchars($student['full_name']) ?>" required></div>
            <div class="form-group"><label>Credit Limit (KSh)</label><input type="number" step="0.01" name="credit_limit" value="<?= $student['credit_limit'] ?>" required></div>
            <div class="form-group"><label>Current Balance (KSh)</label><input type="number" step="0.01" name="current_balance" value="<?= $student['current_balance'] ?>" required></div>
            <div class="form-group">
                <label>CMS Status</label>
                <select name="account_status">
                    <option value="ACTIVE" <?= $student['account_status'] === 'ACTIVE' ? 'selected' : '' ?>>ACTIVE</option>
                    <option value="HELD" <?= $student['account_status'] === 'HELD' ? 'selected' : '' ?>>HELD</option>
                    <option value="SUSPENDED" <?= $student['account_status'] === 'SUSPENDED' ? 'selected' : '' ?>>SUSPENDED</option>
                </select>
            </div>
            <button type="submit" class="btn" style="width: 100%;">Save Changes</button>
        </form>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>