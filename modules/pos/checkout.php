<?php
session_start();
require_once '../../config/db.php';

$facilities = $pdo->query("SELECT * FROM facilities")->fetchAll();
?>

<!DOCTYPE html>
<html>
<head><title>POS Micro-Loan Checkout</title></head>
<body>
    <h2>Campus POS Micro-Loan Terminal</h2>
    <form action="process_checkout.php" method="POST">
        <label>Student ID (Scan QR / ID):</label><br>
        <input type="text" name="student_id" required placeholder="100001"><br><br>

        <label>Select Facility:</label><br>
        <select name="facility_id" required>
            <?php foreach($facilities as $f): ?>
                <option value="<?= $f['facility_id'] ?>"><?= $f['facility_name'] ?></option>
            <?php endforeach; ?>
        </select><br><br>

        <label>Transaction Amount (KES):</label><br>
        <input type="number" step="0.01" name="amount" required><br><br>

        <button type="submit">Authorize Micro-Loan</button>
    </form>
</body>
</html>