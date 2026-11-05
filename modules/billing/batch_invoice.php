<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'ADMIN') {
    header("Location: ../auth/login.php");
    exit;
}

// Generate semester invoices for accounts with outstanding balances
$stmt = $pdo->prepare("SELECT user_id, current_balance FROM users WHERE role = 'STUDENT' AND current_balance > 0");
$stmt->execute();
$debtors = $stmt->fetchAll();

foreach ($debtors as $row) {
    $inv = $pdo->prepare("INSERT INTO semester_invoices (user_id, semester_code, total_amount) VALUES (?, 'SEM_1_2026', ?)");
    $inv->execute([$row['user_id'], $row['current_balance']]);
}

echo "<script>alert('Batch Invoices Generated!'); window.location.href='../auth/dashboard.php';</script>";
?>