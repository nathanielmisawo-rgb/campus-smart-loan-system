<?php
session_start();
require_once '../../config/db.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_id = trim($_POST['student_id']);
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE student_id = ?");
    $stmt->execute([$student_id]);
    $user = $stmt->fetch();

    if ($user) {
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['student_id'] = $user['student_id'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];

        header("Location: dashboard.php");
        exit;
    } else {
        $message = 'Invalid Strathmore Credentials.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Strathmore SSO Login</title>
    <link rel="stylesheet" href="/campus_smart_loan_system/public/css/style.css">
</head>
<body>
    <main class="container" style="display:flex;align-items:center;justify-content:center;flex:1;">
        <div class="card" style="max-width:400px;width:100%;">
            <h2 style="margin-top:0;color:var(--primary-hover);">Strathmore Single Sign-On (SSO)</h2>
            <?php if ($message): ?>
                <p style="color:var(--danger);"><?= htmlspecialchars($message) ?></p>
            <?php endif; ?>
            <form method="POST">
                <div class="form-group">
                    <label for="student_id">Student / Staff ID:</label>
                    <input type="text" id="student_id" name="student_id" required placeholder="e.g., 100001">
                </div>
                <button type="submit" class="btn" style="width:100%;">Sign In via SSO</button>
            </form>
        </div>
    </main>
</body>
</html>
