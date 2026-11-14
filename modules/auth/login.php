<?php
session_start();
require_once '../../config/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = trim($_POST['student_id'] ?? $_POST['user_id'] ?? '');

    if ($id !== '') {
        // Check database for matching student/staff ID
        $stmt = $pdo->prepare("SELECT * FROM students WHERE student_id = ? OR admission_no = ? LIMIT 1");
        $stmt->execute([$id, $id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $_SESSION['user_id'] = $user['student_id'] ?? $id;
            $_SESSION['student_id'] = $user['student_id'] ?? $id;
            $_SESSION['full_name'] = $user['full_name'] ?? 'Campus User';
            $_SESSION['role'] = $user['role'] ?? 'admin';

            header('Location: /campus-smart-loan-system/modules/auth/dashboard.php');
            exit;
        } else {
            // Fallback demo SSO login if ID isn't seeded in students table yet
            $_SESSION['user_id'] = $id;
            $_SESSION['student_id'] = $id;
            $_SESSION['full_name'] = 'Admin User';
            $_SESSION['role'] = 'admin';

            header('Location: /campus-smart-loan-system/modules/auth/dashboard.php');
            exit;
        }
    } else {
        $error = 'Please enter a valid Student or Staff ID.';
    }
}

require_once '../../includes/header.php';
?>

<div class="container" style="max-width: 450px; margin: 60px auto;">
    <div class="card">
        <h2 style="margin-bottom: 10px;">Strathmore Single Sign-On (SSO)</h2>
        <p style="color: #94a3b8; margin-bottom: 20px;">Sign in to access the Campus Smart Loan System.</p>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger" style="margin-bottom: 15px;">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group" style="margin-bottom: 15px;">
                <label for="student_id">Student / Staff ID:</label>
                <input type="text" id="student_id" name="student_id" value="100001" required>
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%;">Sign In via SSO</button>
        </form>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>