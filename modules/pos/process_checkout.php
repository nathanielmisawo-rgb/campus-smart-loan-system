<?php
session_start();
require_once '../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $facility_id = $_POST['facility_id'];
    $student_id = trim($_POST['student_id']);
    $amount = floatval($_POST['amount']);

    $stmt = $pdo->prepare("SELECT * FROM users WHERE student_id = ? AND role = 'STUDENT'");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch();

    if (!$student) {
        echo "<script>alert('Error: Student record not found.'); window.location.href='checkout.php';</script>";
        exit;
    }

    if ($student['account_status'] === 'HELD') {
        echo "<script>alert('Declined: Account is under CMS Hold!'); window.location.href='checkout.php';</script>";
        exit;
    }

    $available_credit = $student['credit_limit'] - $student['current_balance'];

    if ($amount > $available_credit) {
        $log = $pdo->prepare("INSERT INTO transactions (user_id, facility_id, amount, status) VALUES (?, ?, ?, 'DECLINED')");
        $log->execute([$student['user_id'], $facility_id, $amount]);
        echo "<script>alert('Declined: Credit limit exceeded!'); window.location.href='checkout.php';</script>";
        exit;
    }

    $new_balance = $student['current_balance'] + $amount;
    $pdo->beginTransaction();
    
    $update = $pdo->prepare("UPDATE users SET current_balance = ? WHERE user_id = ?");
    $update->execute([$new_balance, $student['user_id']]);

    $log = $pdo->prepare("INSERT INTO transactions (user_id, facility_id, amount, status) VALUES (?, ?, ?, 'APPROVED')");
    $log->execute([$student['user_id'], $facility_id, $amount]);

    $pdo->commit();

    echo "<script>alert('Transaction Approved! charged KSh {$amount}'); window.location.href='checkout.php';</script>";
}
?>