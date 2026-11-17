<?php

session_start();
require_once '../../config/db.php';

// Check user authorization
if (!isset($_SESSION['role']) ||
    !in_array($_SESSION['role'], ['VENDOR', 'ADMIN'])) {

    header("Location: ../auth/login.php");
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: checkout.php");
    exit;
}

// Get submitted information
$facility_id = filter_input(INPUT_POST, 'facility_id', FILTER_VALIDATE_INT);
$student_id = trim($_POST['student_id'] ?? '');
$amount_input = $_POST['amount'] ?? '';

// Validate input
if (!$facility_id || $student_id === '' ||
    !is_numeric($amount_input) ||
    !is_finite((float)$amount_input) ||
    (float)$amount_input <= 0) {

    $_SESSION['pos_flash'] = [
        'type' => 'error',
        'message' => 'Please enter valid transaction details.'
    ];

    header("Location: checkout.php");
    exit;
}

$amount = round((float)$amount_input, 2);

try {

    $pdo->beginTransaction();

    // Verify facility
    $facility = $pdo->prepare(
        "SELECT facility_id FROM facilities WHERE facility_id = ?"
    );

    $facility->execute([$facility_id]);

    if (!$facility->fetch()) {
        throw new Exception("Invalid facility selected.");
    }

    // Find and lock student account
    $stmt = $pdo->prepare(
        "SELECT * FROM users
         WHERE student_id = ?
         AND role = 'STUDENT'
         FOR UPDATE"
    );

    $stmt->execute([$student_id]);
    $student = $stmt->fetch();

    if (!$student) {
        throw new Exception("Student record not found.");
    }

    // Check account status
    if ($student['account_status'] !== 'ACTIVE') {

        $log = $pdo->prepare(
            "INSERT INTO transactions
            (user_id, facility_id, amount, status)
            VALUES (?, ?, ?, 'DECLINED')"
        );

        $log->execute([
            $student['user_id'],
            $facility_id,
            $amount
        ]);

        $pdo->commit();

        throw new Exception(
            "Transaction declined. Student account is " .
            $student['account_status'] . "."
        );
    }

    // Calculate available credit
    $available_credit =
        (float)$student['credit_limit'] -
        (float)$student['current_balance'];

    if ($amount > $available_credit) {

        $log = $pdo->prepare(
            "INSERT INTO transactions
            (user_id, facility_id, amount, status)
            VALUES (?, ?, ?, 'DECLINED')"
        );

        $log->execute([
            $student['user_id'],
            $facility_id,
            $amount
        ]);

        $pdo->commit();

        throw new Exception(
            "Insufficient credit. Available credit: KSh " .
            number_format($available_credit, 2)
        );
    }

    // Update student balance
    $new_balance =
        (float)$student['current_balance'] + $amount;

    $update = $pdo->prepare(
        "UPDATE users
         SET current_balance = ?
         WHERE user_id = ?"
    );

    $update->execute([
        $new_balance,
        $student['user_id']
    ]);

    // Record approved transaction
    $log = $pdo->prepare(
        "INSERT INTO transactions
        (user_id, facility_id, amount, status)
        VALUES (?, ?, ?, 'APPROVED')"
    );

    $log->execute([
        $student['user_id'],
        $facility_id,
        $amount
    ]);

    $pdo->commit();

    $_SESSION['pos_flash'] = [
        'type' => 'success',
        'message' => 'Transaction approved! KSh ' .
                     number_format($amount, 2) .
                     ' charged to ' . $student['full_name'] . '.'
    ];

} catch (Exception $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['pos_flash'] = [
        'type' => 'error',
        'message' => $e->getMessage()
    ];
}

header("Location: checkout.php");
exit;

?>