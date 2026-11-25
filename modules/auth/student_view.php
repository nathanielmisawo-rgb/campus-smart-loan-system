<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'ADMIN') {
    header("Location: login.php");
    exit;
}

$alert = '';
$alert_type = 'success';

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'registered') {
        $alert = 'Student credit profile registered successfully.';
    } elseif ($_GET['msg'] === 'updated') {
        $alert = 'Student credit record updated successfully.';
    } elseif ($_GET['msg'] === 'deleted') {
        $alert = 'Student record deleted.';
    }
}

// Handle safe deletion
if (isset($_GET['delete'])) {
    $uid = (int)$_GET['delete'];

    $check_stmt = $pdo->prepare("SELECT current_balance FROM users WHERE user_id = ? AND role = 'STUDENT'");
    $check_stmt->execute([$uid]);
    $target = $check_stmt->fetch();

    if (!$target) {
        $alert = 'Student record not found.';
        $alert_type = 'error';
    } elseif ((float)$target['current_balance'] > 0) {
        $alert = 'Cannot delete student with an outstanding balance owed. Settle balance to KSh 0.00 first.';
        $alert_type = 'error';
    } else {
        try {
            $del_stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ? AND role = 'STUDENT'");
            $del_stmt->execute([$uid]);
            header("Location: student_view.php?msg=deleted");
            exit;
        } catch (PDOException $e) {
            $alert = 'Cannot delete student because they have linked transaction or invoice records. Set status to SUSPENDED instead.';
            $alert_type = 'error';
        }
    }
}

// Handle search filter
$search = trim($_GET['q'] ?? '');
if ($search !== '') {
    $like = '%' . $search . '%';
    $stmt = $pdo->prepare("
        SELECT * FROM users 
        WHERE role = 'STUDENT' 
          AND (student_id LIKE ? OR full_name LIKE ? OR email LIKE ?)
        ORDER BY created_at DESC
    ");
    $stmt->execute([$like, $like, $like]);
    $students = $stmt->fetchAll();
} else {
    $students = $pdo->query("SELECT * FROM users WHERE role = 'STUDENT' ORDER BY created_at DESC")->fetchAll();
}

require_once '../../includes/header.php';
?>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 1rem;">
        <h3 style="margin: 0;">Student Loan Directory</h3>
        <div>
            <a href="dashboard.php" class="btn" style="margin-right: 6px;">&larr; Dashboard</a>
            <a href="student_register.php" class="btn">Register New Profile</a>
        </div>
    </div>

    <?php if ($alert !== ''): ?>
        <p style="color: <?= $alert_type === 'error' ? 'var(--danger, #dc2626)' : 'var(--primary-hover, #15803d)' ?>; font-weight: 600;">
            <?= htmlspecialchars($alert) ?>
        </p>
    <?php endif; ?>

    <form method="GET" style="display: flex; gap: 8px; margin-bottom: 1rem;">
        <input type="text" name="q" placeholder="Search by Student ID, Name, or Email..." value="<?= htmlspecialchars($search) ?>" style="flex: 1;">
        <button type="submit" class="btn">Search</button>
        <?php if ($search !== ''): ?>
            <a href="student_view.php" class="btn">Reset</a>
        <?php endif; ?>
    </form>

    <table>
        <thead>
            <tr>
                <th>Student ID</th>
                <th>Name</th>
                <th>Email</th>
                <th>Grade</th>
                <th>Credit Limit</th>
                <th>Owed Balance</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($students)): ?>
                <tr>
                    <td colspan="8" style="text-align: center; color: var(--text-muted);">No student credit accounts found.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($students as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['student_id']) ?></td>
                    <td><?= htmlspecialchars($row['full_name']) ?></td>
                    <td><?= htmlspecialchars($row['email']) ?></td>
                    <td><?= htmlspecialchars($row['grade']) ?></td>
                    <td>KSh <?= number_format((float)$row['credit_limit'], 2) ?></td>
                    <td>KSh <?= number_format((float)$row['current_balance'], 2) ?></td>
                    <td>
                        <strong style="color: <?= $row['account_status'] === 'ACTIVE' ? 'var(--primary-hover, #15803d)' : 'var(--danger, #dc2626)' ?>;">
                            <?= htmlspecialchars($row['account_status']) ?>
                        </strong>
                    </td>
                    <td>
                        <a href="student_edit.php?uid=<?= (int)$row['user_id'] ?>" class="btn" style="padding: 0.3rem 0.6rem;">Edit</a>
                        <a href="student_view.php?delete=<?= (int)$row['user_id'] ?>" class="btn btn-danger" style="padding: 0.3rem 0.6rem;" onclick="return confirm('Delete this student record?')">Delete</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once '../../includes/footer.php'; ?>