<?php

session_start();

require_once '../../config/db.php';

// =============================================
// 1. VENDOR / ADMIN AUTHORIZATION
// =============================================

if (
    !isset($_SESSION['role']) ||
    !in_array(strtoupper($_SESSION['role']), ['VENDOR', 'ADMIN'], true)
) {
    header("Location: ../auth/login.php");
    exit;
}

// =============================================
// 2. ACCEPT POST REQUESTS ONLY
// =============================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: checkout.php");
    exit;
}

// =============================================
// 3. RETRIEVE SUBMITTED INFORMATION
// =============================================

$facility_id = filter_input(INPUT_POST, 'facility_id', FILTER_VALIDATE_INT);
$student_id = trim($_POST['student_id'] ?? '');
$amount_input = trim($_POST['amount'] ?? '');

// =============================================
// 4. VALIDATE INPUT
// =============================================

if (
    !$facility_id ||
    $student_id === '' ||
    !is_numeric($amount_input) ||
    !is_finite((float)$amount_input) ||
    (float)$amount_input <= 0
) {
    $_SESSION['pos_flash'] = [
        'type' => 'error',
        'message' => 'Please enter valid transaction details.'
    ];

    header("Location: checkout.php");
    exit;
}

// Convert amount to cents to avoid floating-point comparison issues.
$amount = round((float)$amount_input, 2);

if ($amount <= 0) {
    $_SESSION['pos_flash'] = [
        'type' => 'error',
        'message' => 'Transaction amount must be greater than zero.'
    ];

    header("Location: checkout.php");
    exit;
}

// =============================================
// 5. PROCESS TRANSACTION
// =============================================

try {

    $pdo->beginTransaction();

    // =========================================
    // 5.1 VERIFY FACILITY
    // =========================================

    $facility_stmt = $pdo->prepare(
        "SELECT facility_id, facility_name
         FROM facilities
         WHERE facility_id = ?
         AND status = 'active'"
    );

    $facility_stmt->execute([$facility_id]);

    $facility = $facility_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$facility) {
        throw new Exception(
            "Selected facility does not exist or is inactive."
        );
    }

    // =========================================
    // 5.2 FIND AND LOCK STUDENT ACCOUNT
    // =========================================

    $stmt = $pdo->prepare(
        "SELECT
            user_id,
            full_name,
            student_number,
            credit_limit,
            outstanding_balance,
            account_status
         FROM users
         WHERE student_number = ?
         AND role = 'student'
         FOR UPDATE"
    );

    $stmt->execute([$student_id]);

    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        throw new Exception("Student record not found.");
    }

    // =========================================
    // 5.3 CHECK STUDENT ACCOUNT STATUS
    // =========================================

    if (strtolower($student['account_status']) !== 'active') {

        $log = $pdo->prepare(
            "INSERT INTO transactions
            (
                user_id,
                facility_id,
                amount,
                transaction_type,
                payment_method,
                transaction_status,
                reference_number,
                transaction_date
            )
            VALUES (?, ?, ?, 'purchase', 'POS', 'failed', ?, NOW())"
        );

        $reference = 'POS-' . strtoupper(bin2hex(random_bytes(6)));

        $log->execute([
            $student['user_id'],
            $facility_id,
            $amount,
            $reference
        ]);

        $pdo->commit();

        throw new Exception(
            "Transaction declined. Student account is " .
            $student['account_status'] . "."
        );
    }

    // =========================================
    // 5.4 CALCULATE AVAILABLE CREDIT
    // =========================================

    $credit_limit = (float)$student['credit_limit'];
    $current_balance = (float)$student['outstanding_balance'];

    $available_credit = $credit_limit - $current_balance;

    if ($amount > $available_credit) {

        $log = $pdo->prepare(
            "INSERT INTO transactions
            (
                user_id,
                facility_id,
                amount,
                transaction_type,
                payment_method,
                transaction_status,
                reference_number,
                transaction_date
            )
            VALUES (?, ?, ?, 'purchase', 'POS', 'failed', ?, NOW())"
        );

        $reference = 'POS-' . strtoupper(bin2hex(random_bytes(6)));

        $log->execute([
            $student['user_id'],
            $facility_id,
            $amount,
            $reference
        ]);

        $pdo->commit();

        throw new Exception(
            "Insufficient credit. Available credit: KSh " .
            number_format($available_credit, 2)
        );
    }

    // =========================================
    // 5.5 UPDATE STUDENT BALANCE
    // =========================================

    $new_balance = $current_balance + $amount;

    $update = $pdo->prepare(
        "UPDATE users
         SET outstanding_balance = ?
         WHERE user_id = ?"
    );

    $update->execute([
        $new_balance,
        $student['user_id']
    ]);

    // =========================================
    // 5.6 RECORD APPROVED TRANSACTION
    // =========================================

    $reference = 'POS-' . strtoupper(bin2hex(random_bytes(6)));

    $log = $pdo->prepare(
        "INSERT INTO transactions
        (
            user_id,
            facility_id,
            amount,
            transaction_type,
            payment_method,
            transaction_status,
            reference_number,
            transaction_date
        )
        VALUES (?, ?, ?, 'purchase', 'POS', 'completed', ?, NOW())"
    );

    $log->execute([
        $student['user_id'],
        $facility_id,
        $amount,
        $reference
    ]);

    // =========================================
    // 5.7 COMMIT TRANSACTION
    // =========================================

    $pdo->commit();

    $_SESSION['pos_flash'] = [
        'type' => 'success',
        'message' => 'Transaction approved! KSh ' .
            number_format($amount, 2) .
            ' charged to ' . $student['full_name'] . '.'
    ];

} catch (Exception $e) {

    // Roll back only if a transaction is still active.
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['pos_flash'] = [
        'type' => 'error',
        'message' => $e->getMessage()
    ];
}

// =============================================
// 6. REDIRECT BACK TO CHECKOUT
// =============================================

header("Location: checkout.php");
exit;

?>