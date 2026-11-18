
<?php

session_start();

require_once __DIR__ . '/../../config/db.php';

$mpesa = require __DIR__ . '/../../config/mpesa_config.php';

if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'STUDENT'
) {
    header("Location: ../auth/login.php");
    exit;
}

$userId = (int) $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header("Location: ../auth/login.php");
    exit;
}

$error = '';
$success = '';

// Fetch unpaid invoices belonging to this student.
$invoiceStmt = $pdo->prepare("
    SELECT invoice_id, semester_code, total_amount,
           amount_paid, payment_status
    FROM semester_invoices
    WHERE user_id = ?
      AND payment_status IN ('UNPAID', 'OVERDUE')
    ORDER BY created_at DESC
");

$invoiceStmt->execute([$userId]);
$invoices = $invoiceStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
    $phone = preg_replace('/\s+/', '', $_POST['phone'] ?? '');

    // Convert Kenyan phone numbers to 254 format.
    if (preg_match('/^0[17][0-9]{8}$/', $phone)) {
        $phone = '254' . substr($phone, 1);
    } elseif (preg_match('/^\+[17][0-9]{8,11}$/', $phone)) {
        $phone = substr($phone, 1);
    }

    if (!preg_match('/^254[17][0-9]{8}$/', $phone)) {
        $error = "Enter a valid Kenyan M-Pesa phone number.";
    } else {

        $findInvoice = $pdo->prepare("
            SELECT invoice_id, total_amount, amount_paid,
                   payment_status
            FROM semester_invoices
            WHERE invoice_id = ?
              AND user_id = ?
              AND payment_status IN ('UNPAID', 'OVERDUE')
            LIMIT 1
        ");

        $findInvoice->execute([$invoiceId, $userId]);
        $invoice = $findInvoice->fetch();

        if (!$invoice) {
            $error = "Invoice not found or already paid.";
        } else {

            $amountDue = round(
                (float) $invoice['total_amount'] -
                (float) $invoice['amount_paid'],
                2
            );

            if ($amountDue <= 0) {
                $error = "There is no outstanding amount on this invoice.";
            } elseif ($amountDue != floor($amountDue)) {
                $error = "Invoice amount must be a whole number of KES.";
            } else {

                // Prevent duplicate STK requests while one is pending.
                $pendingCheck = $pdo->prepare("
                    SELECT payment_id
                    FROM mpesa_payments
                    WHERE invoice_id = ?
                      AND payment_status = 'PENDING'
                    LIMIT 1
                ");

                $pendingCheck->execute([$invoiceId]);

                if ($pendingCheck->fetch()) {
                    $error = "A payment request is already pending for this invoice.";
                } else {

                    try {

                        // Check M-Pesa configuration.
                        foreach (
                            ['consumer_key', 'consumer_secret',
                             'shortcode', 'passkey', 'callback_url'] as $key
                        ) {
                            if (empty($mpesa[$key])) {
                                throw new Exception(
                                    "M-Pesa configuration is incomplete."
                                );
                            }
                        }

                        $baseUrl = $mpesa['environment'] === 'sandbox'
                            ? 'https://sandbox.safaricom.co.ke'
                            : 'https://api.safaricom.co.ke';

                        // 1. Get access token.
                        $curl = curl_init(
                            $baseUrl .
                            '/oauth/v1/generate?grant_type=client_credentials'
                        );

                        curl_setopt_array($curl, [
                            CURLOPT_HTTPHEADER => [
                                'Authorization: Basic ' .
                                base64_encode(
                                    $mpesa['consumer_key'] . ':' .
                                    $mpesa['consumer_secret']
                                )
                            ],
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_TIMEOUT => 30
                        ]);

                        $tokenResponse = curl_exec($curl);
                        $tokenStatus = curl_getinfo(
                            $curl,
                            CURLINFO_HTTP_CODE
                        );

                        if ($tokenResponse === false) {
                            throw new Exception("Could not connect to M-Pesa.");
                        }

                        curl_close($curl);

                        $tokenData = json_decode($tokenResponse, true);

                        if (
                            $tokenStatus !== 200 ||
                            empty($tokenData['access_token'])
                        ) {
                            throw new Exception(
                                "M-Pesa authentication failed."
                            );
                        }

                        $accessToken = $tokenData['access_token'];

                        // 2. Generate password and timestamp.
                        $timestamp = date('YmdHis');

                        $password = base64_encode(
                            $mpesa['shortcode'] .
                            $mpesa['passkey'] .
                            $timestamp
                        );

                        // 3. Prepare STK Push.
                        $payload = [
                            'BusinessShortCode' => $mpesa['shortcode'],
                            'Password' => $password,
                            'Timestamp' => $timestamp,
                            'TransactionType' => 'CustomerPayBillOnline',
                            'Amount' => (int) $amountDue,
                            'PartyA' => $phone,
                            'PartyB' => $mpesa['shortcode'],
                            'PhoneNumber' => $phone,
                            'CallBackURL' => $mpesa['callback_url'],
                            'AccountReference' => 'INV-' . $invoiceId,
                            'TransactionDesc' => 'Campus Smart Loan Payment'
                        ];

                        $curl = curl_init(
                            $baseUrl . '/mpesa/stkpush/v1/processrequest'
                        );

                        curl_setopt_array($curl, [
                            CURLOPT_HTTPHEADER => [
                                'Content-Type: application/json',
                                'Authorization: Bearer ' . $accessToken
                            ],
                            CURLOPT_POST => true,
                            CURLOPT_POSTFIELDS => json_encode($payload),
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_TIMEOUT => 30
                        ]);

                        $stkResponse = curl_exec($curl);
                        $stkStatus = curl_getinfo(
                            $curl,
                            CURLINFO_HTTP_CODE
                        );

                        if ($stkResponse === false) {
                            throw new Exception("STK Push connection failed.");
                        }

                        curl_close($curl);

                        $stkData = json_decode($stkResponse, true);

                        if (
                            $stkStatus !== 200 ||
                            ($stkData['ResponseCode'] ?? '') !== '0'
                        ) {
                            throw new Exception(
                                $stkData['ResponseDescription'] ??
                                'M-Pesa rejected the payment request.'
                            );
                        }

                        // 4. Save payment as PENDING.
                        $savePayment = $pdo->prepare("
                            INSERT INTO mpesa_payments (
                                user_id,
                                invoice_id,
                                phone_number,
                                amount,
                                checkout_request_id,
                                merchant_request_id,
                                payment_status
                            ) VALUES (?, ?, ?, ?, ?, ?, 'PENDING')
                        ");

                        $savePayment->execute([
                            $userId,
                            $invoiceId,
                            $phone,
                            $amountDue,
                            $stkData['CheckoutRequestID'],
                            $stkData['MerchantRequestID']
                        ]);

                        $success = "STK Push sent successfully! Check your phone and enter your M-Pesa PIN. Your invoice will update after Safaricom confirms payment.";

                    } catch (Throwable $e) {

                        error_log('M-Pesa error: ' . $e->getMessage());

                        $error = "Payment request failed. Please check your M-Pesa configuration and try again.";
                    }
                }
            }
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div style="max-width: 450px; margin: 0 auto;">
    <div class="card">

        <h3>M-Pesa Tab Settlement</h3>

        <p>
            <strong>Outstanding Balance:</strong>
            KSh <?= number_format((float)$user['current_balance'], 2) ?>
        </p>

        <?php if ($error): ?>
            <p style="color: var(--danger);">
                <?= htmlspecialchars($error) ?>
            </p>
        <?php endif; ?>

        <?php if ($success): ?>
            <p style="color: green;">
                <?= htmlspecialchars($success) ?>
            </p>
        <?php endif; ?>

        <?php if (!empty($invoices) && !$success): ?>

            <form method="POST">

                <div class="form-group">
                    <label>Select Invoice</label>

                    <select name="invoice_id" required>
                        <option value="">Choose an invoice</option>

                        <?php foreach ($invoices as $inv): ?>
                            <?php
                            $due = (float)$inv['total_amount']
                                - (float)$inv['amount_paid'];
                            ?>

                            <?php if ($due > 0): ?>
                                <option value="<?= (int)$inv['invoice_id'] ?>">
                                    <?= htmlspecialchars($inv['semester_code']) ?>
                                    - KSh <?= number_format($due, 2) ?>
                                </option>
                            <?php endif; ?>

                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>M-Pesa Mobile Number</label>

                    <input
                        type="text"
                        name="phone"
                        value="<?= htmlspecialchars($user['phone'] ?? '') ?>"
                        placeholder="07XXXXXXXX"
                        required
                    >
                </div>

                <p>
                    The outstanding invoice amount will be requested
                    through M-Pesa.
                </p>

                <button type="submit" class="btn" style="width: 100%;">
                    Pay via M-Pesa Express
                </button>

            </form>

        <?php elseif (!$success): ?>

            <p>You currently have no unpaid invoices.</p>

        <?php endif; ?>

    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
