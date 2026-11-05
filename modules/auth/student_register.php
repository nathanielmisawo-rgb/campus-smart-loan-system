<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'ADMIN') {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_id = trim($_POST['student_id']);
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $grade = trim($_POST['grade']);
    $credit_limit = floatval($_POST['credit_limit']);
    $pass = password_hash($_POST['pass'], PASSWORD_BCRYPT);

    $stmt = $pdo->prepare("INSERT INTO users (student_id, full_name, email, phone, grade, role, credit_limit, pass) VALUES (?, ?, ?, ?, ?, 'STUDENT', ?, ?)");
    try {
        $stmt->execute([$student_id, $full_name, $email, $phone, $grade, $credit_limit, $pass]);
        header("Location: student_view.php");
        exit;
    } catch (PDOException $e) {
        $error = "Registration failed: Student ID or Email already exists.";
    }
}

require_once '../../includes/header.php';
?>

<div style="max-width: 500px; margin: 0 auto;">
    <div class="card">
        <h3>Register Student Credit Tab Profile</h3>
        <?php if (!empty($error)): ?><p style="color: var(--danger);"><?= $error ?></p><?php endif; ?>
        <form method="POST">
            <div class="form-group"><label>Student ID (Admission No)</label><input type="text" name="student_id" required placeholder="e.g. 100003"></div>
            <div class="form-group"><label>Full Name</label><input type="text" name="full_name" required></div>
            <div class="form-group"><label>Email</label><input type="email" name="email" required></div>
            <div class="form-group"><label>Phone Number</label><input type="text" name="phone" required></div>
            <div class="form-group"><label>Grade / Year</label><input type="text" name="grade" placeholder="e.g. Year 2" required></div>
            <div class="form-group"><label>Credit Limit (KSh)</label><input type="number" step="0.01" name="credit_limit" value="5000.00" required></div>
            <div class="form-group"><label>Initial SSO Password</label><input type="password" name="pass" required></div>
            <button type="submit" class="btn" style="width: 100%;">Create Credit Account</button>
        </form>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>