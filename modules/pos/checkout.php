<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'VENDOR' && $_SESSION['role'] !== 'ADMIN')) {
    header("Location: ../auth/login.php");
    exit;
}

$facilities = $pdo->query("SELECT * FROM facilities")->fetchAll();

require_once '../../includes/header.php';
?>

<div style="max-width: 450px; margin: 0 auto;">
    <div class="card">
        <h3>POS Micro-Loan Terminal</h3>
        <form action="process_checkout.php" method="POST">
            <div class="form-group">
                <label>Facility Endpoint</label>
                <select name="facility_id" required>
                    <?php foreach ($facilities as $fac): ?>
                        <option value="<?= $fac['facility_id'] ?>"><?= htmlspecialchars($fac['facility_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Student ID (Scan ID / QR)</label>
                <input type="text" name="student_id" required placeholder="e.g. 100001">
            </div>
            <div class="form-group">
                <label>Amount (KSh)</label>
                <input type="number" step="0.01" name="amount" required placeholder="0.00">
            </div>
            <button type="submit" class="btn" style="width: 100%;">Authorize Micro-Loan</button>
        </form>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>