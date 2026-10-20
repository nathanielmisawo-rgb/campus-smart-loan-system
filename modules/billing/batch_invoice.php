<?php
require_once '../../config/db.php';

// Consolidated End-of-Semester Batch Billing Job
$semester = "2026-SEM1";

// Get all students with outstanding balances
$students = $pdo->query("SELECT * FROM users WHERE current_balance > 0 AND role = 'STUDENT'")->fetchAll();

foreach ($students as $student) {
    // Create Semester Invoice
    $stmt = $pdo->prepare("INSERT INTO semester_invoices (user_id, semester_code, total_amount, payment_status) VALUES (?, ?, ?, 'UNPAID')");
    $stmt->execute([$student['user_id'], $semester, $student['current_balance']]);
}

echo "Batch Invoice Processing Complete for " . count($students) . " students.";
?>