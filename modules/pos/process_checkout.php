<?php
require_once '../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_id = trim($_POST['student_id']);
    $facility_id = (int)$_POST['facility_id'];
    $amount = (float)$_POST['amount'];

    // 1. Fetch Student
    $stmt = $pdo->prepare("SELECT * FROM users WHERE student_id = ? AND role = 'STUDENT'");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch();

    if (!$student) {
        die("Transaction Declined: Student ID not found.");
    }

    $available_credit = $student['credit_limit'] - $student['current_balance'];

    // 2. Validate Credit Balance
    if ($amount > $available_credit) {
        // Record Declined Transaction
        $pdo->prepare("INSERT INTO transactions (user_id, facility_id, amount, status) VALUES (?, ?, ?, 'DECLINED')")
            ->execute([$student['user_id'], $facility_id, $amount]);
        
        die("Transaction Declined: Insufficient Credit Balance. Available KES " . number_format($available_credit, 2));
    }

    // 3. Process Ledger Entry & Deduct Credit
    $pdo->beginTransaction();
    try {
        // Insert Ledger Entry
        $stmt = $pdo->prepare("INSERT INTO transactions (user_id, facility_id, amount, status) VALUES (?, ?, ?, 'APPROVED')");
        $stmt->execute([$student['user_id'], $facility_id, $amount]);

        // Update Student Balance
        $stmt = $pdo->prepare("UPDATE users SET current_balance = current_balance + ? WHERE user_id = ?");
        $stmt->execute([$amount, $student['user_id']]);

        $pdo->commit();
        echo "<h3>Transaction Approved! KES " . number_format($amount, 2) . " debited from credit tab.</h3>";
        echo "<a href='checkout.php'>Back to POS Terminal</a>";
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "Transaction Error: " . $e->getMessage();
    }
}
?>