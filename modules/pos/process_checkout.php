**File: modules/pos/process_checkout.php**

```php
<?php

session_start();

require_once '../../config/db.php';


// ============================================
// 1. VENDOR / ADMIN AUTHORIZATION
// ============================================

if (
    !isset($_SESSION['role']) ||
    !in_array($_SESSION['role'], ['VENDOR', 'ADMIN'], true)
) {
    header("Location: ../auth/login.php");
    exit;
}


// ============================================
// 2. ACCEPT POST REQUESTS ONLY
// ============================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: checkout.php");
    exit;
}


// ============================================
// 3. RESPONSE FUNCTION
// ============================================

function respond($message)
{
    echo "<script>";
    echo "alert(" . json_encode($message) . ");";
    echo "window.location.href = 'checkout.php';";
    echo "</script>";
    exit;
}


// ============================================
// 4. CONVERT MONEY TO CENTS
// ============================================

function toCents($value)
{
    $parts = explode('.', (string)$value, 2);

    $whole = (int)$parts[0];

    $decimal = (int)str_pad(
        $parts[1] ?? '0',
        2,
        '0'
    );

    return ($whole * 100) + $decimal;
}


// ============================================
// 5. RECORD TRANSACTION IN LEDGER
// ============================================

function recordTransaction(
    PDO $pdo,
    $userId,
    $facilityId,
    $amount,
    $status
) {

    $stmt = $pdo->prepare("
        INSERT INTO transactions
        (
            user_id,
            facility_id,
            amount,
            transaction_type,
            transaction_status
        )
        VALUES (?, ?, ?, 'purchase', ?)
    ");

    $stmt->execute([
        $userId,
        $facilityId,
        $amount,
        $status
    ]);
}


// ============================================
// 6. PROCESS CHECKOUT
// ============================================

try {

    // Collect submitted information

    $student_id = trim($_POST['student_id'] ?? '');

    $facility_id = filter_var(
        $_POST['facility_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    $amount_input = trim($_POST['amount'] ?? '');


    // Validate student ID and facility

    if (
        $student_id === '' ||
        $facility_id === false ||
        $facility_id === null ||
        $facility_id <= 0
    ) {
        respond("Please provide a valid student ID and facility.");
    }


    // Validate amount

    if (
        !preg_match('/^\d+(?:\.\d{1,2})?$/D', $amount_input)
    ) {
        respond("Invalid purchase amount.");
    }

    $amount_cents = toCents($amount_input);

    // DECIMAL(10,2) maximum
    if ($amount_cents <= 0 || $amount_cents > 9999999999) {
        respond("Purchase amount is outside the permitted range.");
    }

    $amount = number_format(
        $amount_cents / 100,
        2,
        '.',
        ''
    );


    // ========================================
    // 7. BEGIN DATABASE TRANSACTION
    // ========================================

    $pdo->beginTransaction();


    // ========================================
    // 8. VERIFY FACILITY
    // ========================================

    $facility_stmt = $pdo->prepare("
        SELECT facility_id, facility_name, status
        FROM facilities
        WHERE facility_id = ?
        AND status = 'active'
    ");

    $facility_stmt->execute([$facility_id]);

    $facility = $facility_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$facility) {

        $pdo->rollBack();

        respond("Selected facility does not exist or is inactive.");
    }


    // ========================================
    // 9. FIND AND LOCK STUDENT ACCOUNT
    // ========================================

    $stmt = $pdo->prepare("
        SELECT
            user_id,
            full_name,
            student_number,
            credit_limit,
            outstanding_balance,
            account_status

        FROM users

        WHERE student_number = ?
        AND role = 'student'

        FOR UPDATE
    ");

    $stmt->execute([$student_id]);

    $student = $stmt->fetch(PDO::FETCH_ASSOC);


    // Check student existence

    if (!$student) {

        $pdo->rollBack();

        respond("Student record not found.");
    }


    // ========================================
    // 10. CHECK STUDENT ACCOUNT STATUS
    // ========================================

    if ($student['account_status'] !== 'active') {

        recordTransaction(
            $pdo,
            $student['user_id'],
            $facility_id,
            $amount,
            'failed'
        );

        $pdo->commit();

        respond(
            "Transaction declined. Student account is suspended or inactive."
        );
    }


    // ========================================
    // 11. CALCULATE AVAILABLE CREDIT
    // ========================================

    $credit_limit = toCents(
        $student['credit_limit'] ?? 0
    );

    $outstanding_balance = toCents(
        $student['outstanding_balance'] ?? 0
    );

    $available_credit = $credit_limit - $outstanding_balance;


    // ========================================
    // 12. CHECK AVAILABLE CREDIT
    // ========================================

    if ($amount_cents > $available_credit) {

        recordTransaction(
            $pdo,
            $student['user_id'],
            $facility_id,
            $amount,
            'failed'
        );

        $pdo->commit();

        respond(
            "Transaction declined! Insufficient credit."
        );
    }


    // ========================================
    // 13. UPDATE STUDENT OUTSTANDING BALANCE
    // ========================================

    $update = $pdo->prepare("
        UPDATE users

        SET outstanding_balance =
            COALESCE(outstanding_balance, 0) + ?

        WHERE user_id = ?
    ");

    $update->execute([
        $amount,
        $student['user_id']
    ]);


    // ========================================
    // 14. RECORD APPROVED TRANSACTION
    // ========================================

    recordTransaction(
        $pdo,
        $student['user_id'],
        $facility_id,
        $amount,
        'completed'
    );


    // ========================================
    // 15. COMMIT DATABASE CHANGES
    // ========================================

    $pdo->commit();


    // ========================================
    // 16. SUCCESS MESSAGE
    // ========================================

    respond(
        "Transaction approved!\n\n" .
        "Student: " . $student['full_name'] . "\n" .
        "Facility: " . $facility['facility_name'] . "\n" .
        "Amount: KSh " . $amount
    );


} catch (Throwable $e) {


    // ========================================
    // 17. ROLLBACK IF AN ERROR OCCURS
    // ========================================

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        "POS Transaction Error: " . $e->getMessage()
    );

    respond(
        "Transaction could not be completed. Please try again."
    );

}

?>
```