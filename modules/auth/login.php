<?php
session_start();
require_once '../../config/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = trim($_POST['user_id'] ?? $_POST['student_id'] ?? '');

    if ($id !== '') {
        // Look up by student_id (e.g., 100005, 100001) OR user_id (e.g., admin)
        $stmt = $pdo->prepare("SELECT * FROM users WHERE student_id = ? OR user_id = ? LIMIT 1");
        $stmt->execute([$id, $id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['student_id'] = $user['student_id'] ?? $id;
            $_SESSION['full_name'] = $user['full_name'] ?? 'Campus User';
            $_SESSION['role'] = $user['role'] ?? 'student';

            $baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'], 3), '/\\');
            header('Location: ' . $baseUrl . '/modules/auth/dashboard.php');
            exit;
        } else {
            $error = 'No Student or Staff account found for ID: ' . htmlspecialchars($id);
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
                <?= $error ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group" style="margin-bottom: 15px;">
                <label for="user_id">Student / Staff ID:</label>
                <input type="text" id="user_id" name="user_id" value="100005" required>
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%;">Sign In via SSO</button>
        </form>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>