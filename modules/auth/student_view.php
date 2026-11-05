<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'ADMIN') {
    header("Location: login.php");
    exit;
}

if (isset($_GET['delete'])) {
    $uid = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ? AND role = 'STUDENT'");
    $stmt->execute([$uid]);
    header("Location: student_view.php");
    exit;
}

$students = $pdo->query("SELECT * FROM users WHERE role = 'STUDENT'")->fetchAll();

require_once '../../includes/header.php';
?>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <h3 style="margin: 0;">Student Loan Directory</h3>
        <a href="student_register.php" class="btn">Register New Profile</a>
    </div>
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
            <?php foreach ($students as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['student_id']) ?></td>
                <td><?= htmlspecialchars($row['full_name']) ?></td>
                <td><?= htmlspecialchars($row['email']) ?></td>
                <td><?= htmlspecialchars($row['grade']) ?></td>
                <td>KSh <?= number_format($row['credit_limit'], 2) ?></td>
                <td>KSh <?= number_format($row['current_balance'], 2) ?></td>
                <td><?= $row['account_status'] ?></td>
                <td>
                    <a href="student_edit.php?uid=<?= $row['user_id'] ?>" class="btn" style="padding: 0.3rem 0.6rem;">Edit</a>
                    <a href="student_view.php?delete=<?= $row['user_id'] ?>" class="btn btn-danger" style="padding: 0.3rem 0.6rem;" onclick="return confirm('Delete record?')">Delete</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once '../../includes/footer.php'; ?>