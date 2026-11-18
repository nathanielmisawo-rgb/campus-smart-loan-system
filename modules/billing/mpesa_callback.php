<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json');

function callbackResponse(string $message): void {
    echo json_encode([
        'ResultCode' => 0,
        'ResultDesc' => $message
    ]);
    exit;
}

try {

    // 1. Receive callback data from Safaricom.
    $rawData = file_get_contents('php://input');
    $data = json_decode($rawData, true);

    $callback = $data['Body']['stkCallback'] ?? null;

    if (!$callback) {
        error_log('Invalid M-Pesa callback received.');
        callbackResponse('Callback received.');
    }

    $checkoutRequestId = $callback['CheckoutRequestID'] ?? '';
    $resultCode = (int) ($callback['ResultCode'] ?? -1);
    $resultDescription = $callback['ResultDesc'] ?? 'Unknown result';

    if ($checkoutRequestId === '') {
        callbackResponse('Missing checkout request ID.');
    }

    // 2. Find the original pending payment.
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT *
        FROM mpesa_payments
        WHERE checkout_request_id = ?
        LIMIT 1
        FOR UPDATE
    ");

    $stmt->execute([$checkoutRequestId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        $pdo->rollBack();
        error_log('M-Pesa callback has no matching payment.');
        callbackResponse('Payment record not found.');
    }

    // Prevent duplicate callback processing.
    if ($payment['payment_status'] !== 'PENDING') {
        $pdo->commit();
        callbackResponse('Payment already processed.');
    }

    // 3. Handle unsuccessful payment.
    if ($resultCode !== 0) {

        $update = $pdo->prepare("
            UPDATE mpesa_payments
            SET payment_status = 'FAILED',
                result_description = ?
            WHERE payment_id = ?
        ");

        $update->execute([
            $resultDescription,
            $payment['payment_id']
        ]);

        $pdo->commit();

        callbackResponse('Payment failure recorded.');
    }

    // 4. Extract successful payment details.
    $metadata = [];

    foreach (
        $callback['CallbackMetadata']['Item'] ?? [] as $item
    ) {
        $metadata[$item['Name']] = $item['Value'] ?? null;
    }

    $receiptNumber = $metadata['MpesaReceiptNumber'] ?? '';
    $paidAmount = (float) ($metadata['Amount'] ?? 0);

    if (
        $receiptNumber === '' ||
        $paidAmount <= 0 ||
        abs($paidAmount - (float)$payment['amount']) > 0.01
    ) {
        throw new Exception('Payment confirmation details do not match.');
    }

    // 5. Update payment record.
    $updatePayment = $pdo->prepare("
        UPDATE mpesa_payments
        SET payment_status = 'SUCCESS',
            mpesa_receipt_number = ?,
            result_description = ?
        WHERE payment_id = ?
    ");

    $updatePayment->execute([
        $receiptNumber,
        $resultDescription,
        $payment['payment_id']
    ]);

    // 6. Update invoice amount paid.
    $updateInvoice = $pdo->prepare("
        UPDATE semester_invoices
        SET amount_paid = amount_paid + ?,
            payment_status = CASE
                WHEN amount_paid + ? >= total_amount
                THEN 'PAID'
                ELSE payment_status
            END,
            cms_hold_applied = CASE
                WHEN amount_paid + ? >= total_amount
                THEN 0
                ELSE cms_hold_applied
            END
        WHERE invoice_id = ?
          AND user_id = ?
    ");

    $updateInvoice->execute([
        $paidAmount,
        $paidAmount,
        $paidAmount,
        $payment['invoice_id'],
        $payment['user_id']
    ]);

    if ($updateInvoice->rowCount() !== 1) {
        throw new Exception('Invoice could not be updated.');
    }

    // 7. Reduce the student's outstanding balance.
    $updateBalance = $pdo->prepare("
        UPDATE users
        SET current_balance = GREATEST(current_balance - ?, 0)
        WHERE user_id = ?
    ");

    $updateBalance->execute([
        $paidAmount,
        $payment['user_id']
    ]);

    // 8. Release account hold only if no outstanding invoices remain.
    $checkAccount = $pdo->prepare("
        SELECT
            current_balance,
            account_status,
            (
                SELECT COUNT(*)
                FROM semester_invoices
                WHERE user_id = users.user_id
                  AND payment_status IN ('UNPAID', 'OVERDUE')
            ) AS unpaid_invoices
        FROM users
        WHERE user_id = ?
    ");

    $checkAccount->execute([$payment['user_id']]);
    $account = $checkAccount->fetch(PDO::FETCH_ASSOC);

    if (
        $account &&
        (float)$account['current_balance'] <= 0 &&
        (int)$account['unpaid_invoices'] === 0
    ) {
        $releaseHold = $pdo->prepare("
            UPDATE users
            SET account_status = 'ACTIVE'
            WHERE user_id = ?
              AND account_status = 'HELD'
        ");

        $releaseHold->execute([$payment['user_id']]);
    }

    // 9. Save all successful updates together.
    $pdo->commit();

    error_log(
        'M-Pesa payment confirmed. Receipt: ' . $receiptNumber
    );

    callbackResponse('Payment successfully processed.');

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('M-Pesa callback error: ' . $e->getMessage());

    http_response_code(500);

    echo json_encode([
        'ResultCode' => 1,
        'ResultDesc' => 'Payment processing failed.'
    ]);
}
