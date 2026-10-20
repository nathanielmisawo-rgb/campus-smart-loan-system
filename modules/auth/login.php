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
        $message = "Invalid Strathmore Credentials.";
    }
}
?>

<!DOCTYPE html>
<html>
<head><title>Strathmore SSO Login</title></head>
<body>
    <h2>Strathmore Single Sign-On (SSO)</h2>
    <?php if($message): ?><p style="color:red;"><?= $message ?></p><?php endif; ?>
    <form method="POST">
        <label>Student / Staff ID:</label><br>
        <input type="text" name="student_id" required placeholder="e.g., 100001"><br><br>
        <button type="submit">Sign In via SSO</button>
    </form>
</body>
</html>