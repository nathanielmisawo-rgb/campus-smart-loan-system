<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'ADMIN') {
    header("Location: login.php");
    exit;
}

$uid = isset($_GET['uid']) ? (int)$_GET['uid'] : (int)($_POST['user_id'] ?? 0);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $grade = trim($_POST['grade'] ?? '');
    $credit_limit = floatval($_POST['credit_limit'] ?? 0);
    $current_balance = floatval($_POST['current_balance'] ?? 0);
    $account_status = $_POST['account_status'] ?? 'ACTIVE';
    $allowed_statuses = ['ACTIVE', 'HELD', 'SUSPENDED'];

    if (empty($full_name) || empty($email) || empty($phone) || empty($grade)) {
        $error = "Name, email, phone, and grade are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif ($credit_limit < 0 || $current_balance < 0) {
        $error = "Credit limit and current balance cannot be negative.";
    } elseif (!in_array($account_status, $allowed_statuses, true)) {
        $error = "Invalid account status selected.";
    } else {
        try {
            $stmt = $pdo->prepare("
                UPDATE users 
                SET full_name = ?, email = ?, phone = ?, grade = ?, credit_limit = ?, current_balance = ?, account_status = ? 
                WHERE user_id = ? AND role = 'STUDENT'
            ");
            $stmt->execute([$full_name, $email, $phone, $grade, $credit_limit, $current_balance, $account_status, $uid]);
            header("Location: student_view.php?msg=updated");
            exit;
        } catch (PDOException $e) {
            $error = "Update failed: That email address is already assigned to another account.";
        }
    }
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ? AND role = 'STUDENT'");
$stmt->execute([$uid]);
$student = $stmt->fetch();

if (!$student) {
    header("Location: student_view.php");
    exit;
}

require_once '../../includes/header.php';
?>

<div style="max-width: 500px; margin: 0 auto;">
    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
            <h3 style="margin: 0;">Edit Student Credit Record</h3>
            <a href="student_view.php" style="font-size: 0.9rem;">&larr; Back to Directory</a>
        </div>

        <p style="color: var(--text-muted); margin-top: 0;">
            <strong>Student ID:</strong> <?= htmlspecialchars($student['student_id']) ?>
        </p>

        <?php if ($error !== ''): ?>
            <p style="color: var(--danger); font-weight: 500;"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="user_id" value="<?= (int)$student['user_id'] ?>">
            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="full_name" value="<?= htmlspecialchars($_POST['full_name'] ?? $student['full_name']) ?>" required>
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? $student['email']) ?>" required>
            </div>
            <div class="form-group">
                <label>Phone Number</label>
                <input type="text" name="phone" value="<?= htmlspecialchars($_POST['phone'] ?? $student['phone']) ?>" required>
            </div>
            <div class="form-group">
                <label>Grade / Year</label>
                <input type="text" name="grade" value="<?= htmlspecialchars($_POST['grade'] ?? $student['grade']) ?>" required>
            </div>
            <div class="form-group">
                <label>Credit Limit (KSh)</label>
                <input type="number" step="0.01" min="0" name="credit_limit" value="<?= htmlspecialchars($_POST['credit_limit'] ?? $student['credit_limit']) ?>" required>
            </div>
            <div class="form-group">
                <label>Current Balance (KSh)</label>
                <input type="number" step="0.01" min="0" name="current_balance" value="<?= htmlspecialchars($_POST['current_balance'] ?? $student['current_balance']) ?>" required>
            </div>
            <div class="form-group">
                <label>CMS Status</label>
                <?php $selected_status = $_POST['account_status'] ?? $student['account_status']; ?>
                <select name="account_status">
                    <option value="ACTIVE" <?= $selected_status === 'ACTIVE' ? 'selected' : '' ?>>ACTIVE</option>
                    <option value="HELD" <?= $selected_status === 'HELD' ? 'selected' : '' ?>>HELD</option>
                    <option value="SUSPENDED" <?= $selected_status === 'SUSPENDED' ? 'selected' : '' ?>>SUSPENDED</option>
                </select>
            </div>
            <button type="submit" class="btn" style="width: 100%;">Save Changes</button>
        </form>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>