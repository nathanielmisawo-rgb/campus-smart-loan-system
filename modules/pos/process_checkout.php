<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['role']) || !in_array(strtoupper($_SESSION['role']), ['VENDOR', 'ADMIN'], true)) {
    header("Location: ../auth/login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: checkout.php");
    exit;
}

$facility_id  = filter_input(INPUT_POST, 'facility_id', FILTER_VALIDATE_INT);
$student_id   = trim($_POST['student_id'] ?? '');
$amount_input = trim($_POST['amount'] ?? '');

if (!$facility_id || $student_id === '' || !is_numeric($amount_input) || !is_finite((float)$amount_input) || (float)$amount_input <= 0) {
    $_SESSION['pos_flash'] = ['type' => 'error', 'message' => 'Please enter valid transaction details.'];
    header("Location: checkout.php");
    exit;
}

$amount = round((float)$amount_input, 2);

try {
    $pdo->beginTransaction();

    // 1. Verify facility exists
    $facility_stmt = $pdo->prepare("SELECT facility_id, facility_name FROM facilities WHERE facility_id = ?");
    $facility_stmt->execute([$facility_id]);
    $facility = $facility_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$facility) {
        throw new Exception("Selected facility does not exist.");
    }

    // 2. Find and lock student account
    $stmt = $pdo->prepare("
        SELECT user_id, full_name, student_id, credit_limit, current_balance, account_status
        FROM users
        WHERE student_id = ? AND role = 'STUDENT'
        FOR UPDATE
    ");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        throw new Exception("Student record not found.");
    }

    if ((float)$student['credit_limit'] <= 0) {
        throw new Exception("Student has not activated their Smart Loan Tab yet. Ask them to self-register on their dashboard.");
    }

    // 3. Check account status
    if (strtoupper($student['account_status']) !== 'ACTIVE') {
        $log = $pdo->prepare("INSERT INTO transactions (user_id, facility_id, amount, status) VALUES (?, ?, ?, 'DECLINED')");
        $log->execute([$student['user_id'], $facility_id, $amount]);
        $pdo->commit();

        throw new Exception("Transaction declined. Student account is " . $student['account_status'] . ".");
    }

    // 4. Check available credit
    $credit_limit     = (float)$student['credit_limit'];
    $current_balance  = (float)$student['current_balance'];
    $available_credit = $credit_limit - $current_balance;

    if ($amount > $available_credit) {
        $log = $pdo->prepare("INSERT INTO transactions (user_id, facility_id, amount, status) VALUES (?, ?, ?, 'DECLINED')");
        $log->execute([$student['user_id'], $facility_id, $amount]);
        $pdo->commit();

        throw new Exception("Insufficient credit. Available credit: KSh " . number_format($available_credit, 2));
    }

    // 5. Update student balance & log approved transaction
    $new_balance = $current_balance + $amount;
    $update = $pdo->prepare("UPDATE users SET current_balance = ? WHERE user_id = ?");
    $update->execute([$new_balance, $student['user_id']]);

    $log = $pdo->prepare("INSERT INTO transactions (user_id, facility_id, amount, status) VALUES (?, ?, ?, 'APPROVED')");
    $log->execute([$student['user_id'], $facility_id, $amount]);

    $pdo->commit();

    $_SESSION['pos_flash'] = [
        'type'    => 'success',
        'message' => 'Transaction approved! KSh ' . number_format($amount, 2) . ' charged to ' . $student['full_name'] . '.'
    ];
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['pos_flash'] = ['type' => 'error', 'message' => $e->getMessage()];
}

header("Location: checkout.php");
exit;