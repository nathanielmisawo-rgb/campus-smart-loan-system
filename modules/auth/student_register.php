<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'ADMIN') {
    header("Location: login.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_id = trim($_POST['student_id'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $grade = trim($_POST['grade'] ?? '');
    $credit_limit = floatval($_POST['credit_limit'] ?? 5000.00);
    $raw_pass = $_POST['pass'] ?? '';

    if (empty($student_id) || empty($full_name) || empty($email) || empty($phone) || empty($grade) || empty($raw_pass)) {
        $error = "All fields are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif ($credit_limit < 0) {
        $error = "Credit limit cannot be negative.";
    } elseif (strlen($raw_pass) < 6) {
        $error = "Initial SSO password must be at least 6 characters.";
    } else {
        $pass = password_hash($raw_pass, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("
            INSERT INTO users (student_id, full_name, email, phone, grade, role, credit_limit, pass) 
            VALUES (?, ?, ?, ?, ?, 'STUDENT', ?, ?)
        ");
        try {
            $stmt->execute([$student_id, $full_name, $email, $phone, $grade, $credit_limit, $pass]);
            header("Location: student_view.php?msg=registered");
            exit;
        } catch (PDOException $e) {
            $error = "Registration failed: Student ID or Email already exists.";
        }
    }
}

require_once '../../includes/header.php';
?>

<div style="max-width: 500px; margin: 0 auto;">
    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
            <h3 style="margin: 0;">Register Student Credit Tab Profile</h3>
            <a href="student_view.php" style="font-size: 0.9rem;">&larr; Back to Directory</a>
        </div>

        <?php if (!empty($error)): ?>
            <p style="color: var(--danger); font-weight: 500;"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>Student ID (Admission No)</label>
                <input type="text" name="student_id" required placeholder="e.g. 100003" value="<?= htmlspecialchars($_POST['student_id'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="full_name" required placeholder="e.g. Jane Wanjiku" value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" required placeholder="e.g. jane.wanjiku@strathmore.edu" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Phone Number</label>
                <input type="text" name="phone" required placeholder="e.g. 0712345678" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Grade / Year</label>
                <input type="text" name="grade" placeholder="e.g. Year 2" required value="<?= htmlspecialchars($_POST['grade'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Credit Limit (KSh)</label>
                <input type="number" step="0.01" min="0" name="credit_limit" value="<?= htmlspecialchars($_POST['credit_limit'] ?? '5000.00') ?>" required>
            </div>
            <div class="form-group">
                <label>Initial SSO Password</label>
                <input type="password" name="pass" minlength="6" required>
            </div>
            <button type="submit" class="btn" style="width: 100%;">Create Credit Account</button>
        </form>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>