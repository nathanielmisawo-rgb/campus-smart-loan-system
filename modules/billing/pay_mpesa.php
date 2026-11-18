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

<div class="auth-shell" style="min-height: calc(100vh - 200px);">
    <div class="card auth-card" style="max-width: 480px;">
        <div class="auth-header" style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem;">
            <div>
                <h3 style="display: flex; align-items: center; gap: 0.55rem; margin-bottom: 0.35rem;">
                    <i data-lucide="smartphone" style="width: 22px; height: 22px; color: var(--neon-green);"></i>
                    M-Pesa Tab Settlement
                </h3>
                <p style="margin: 0; font-size: 0.88rem;">Instant STK Push clearing via Safaricom Daraja API.</p>
            </div>
            <span class="status-pill status-active">Daraja STK</span>
        </div>

        <!-- Balance Summary Banner -->
        <div style="background: rgba(15, 23, 42, 0.72); border: 1px solid rgba(56, 189, 248, 0.2); border-radius: var(--radius-md); padding: 1rem 1.15rem; margin: 1.25rem 0; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <span style="display: block; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--text-muted);">
                    Outstanding Tab Balance
                </span>
                <span class="tabular-nums" style="font-size: 1.45rem; font-weight: 800; color: var(--text-primary);">
                    KSh <?= number_format((float)$user['current_balance'], 2) ?>
                </span>
            </div>
            <div style="text-align: right;">
                <span style="display: block; font-size: 0.75rem; color: var(--text-muted);">Account Status</span>
                <span class="status-pill <?= $user['account_status'] === 'ACTIVE' ? 'status-active' : 'status-held' ?>" style="margin-top: 0.25rem;">
                    <?= htmlspecialchars($user['account_status']) ?>
                </span>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i data-lucide="alert-circle" style="width: 18px; height: 18px; flex-shrink: 0;"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i data-lucide="check-circle-2" style="width: 18px; height: 18px; flex-shrink: 0;"></i>
                <span><?= htmlspecialchars($success) ?></span>
            </div>
            <a href="../auth/dashboard.php" class="btn btn-outline" style="width: 100%; margin-top: 0.75rem;">
                <i data-lucide="arrow-left" style="width: 16px; height: 16px;"></i>
                Return to Student Dashboard
            </a>
        <?php endif; ?>

        <?php if (!empty($invoices) && !$success): ?>
            <form method="POST">
                <div class="form-group">
                    <label for="invoice_id">Select Semester Invoice</label>
                    <select id="invoice_id" name="invoice_id" required>
                        <option value="">Choose an open invoice...</option>
                        <?php foreach ($invoices as $inv): ?>
                            <?php $due = (float)$inv['total_amount'] - (float)$inv['amount_paid']; ?>
                            <?php if ($due > 0): ?>
                                <option value="<?= (int)$inv['invoice_id'] ?>">
                                    <?= htmlspecialchars($inv['semester_code']) ?> — KSh <?= number_format($due, 2) ?> (<?= htmlspecialchars($inv['payment_status']) ?>)
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="phone">M-Pesa Mobile Number</label>
                    <input
                        id="phone"
                        type="text"
                        name="phone"
                        value="<?= htmlspecialchars($user['phone'] ?? '') ?>"
                        placeholder="07XXXXXXXX or 2547XXXXXXXX"
                        required
                    >
                </div>

                <p style="font-size: 0.82rem; color: var(--text-muted); margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.45rem;">
                    <i data-lucide="shield-check" style="width: 15px; height: 15px; color: var(--neon-cyan); flex-shrink: 0;"></i>
                    An M-Pesa PIN prompt will be pushed directly to your handset.
                </p>

                <button type="submit" class="btn btn-primary" style="width: 100%;">
                    <i data-lucide="send" style="width: 17px; height: 17px;"></i>
                    Pay via M-Pesa Express
                </button>
            </form>
        <?php elseif (!$success): ?>
            <div style="text-align: center; padding: 1.5rem 0.5rem; color: var(--text-secondary);">
                <i data-lucide="badge-check" style="width: 36px; height: 36px; color: var(--neon-green); margin-bottom: 0.5rem;"></i>
                <p style="margin: 0 0 1rem; font-weight: 600; color: var(--text-primary);">All Clear — No Unpaid Invoices</p>
                <p style="margin: 0 0 1.25rem; font-size: 0.86rem; color: var(--text-muted);">
                    You have no pending semester invoices requiring M-Pesa settlement right now.
                </p>
                <a href="../auth/dashboard.php" class="btn btn-outline" style="width: 100%;">
                    <i data-lucide="arrow-left" style="width: 16px; height: 16px;"></i>
                    Back to Dashboard
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>