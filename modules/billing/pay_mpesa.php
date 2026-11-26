<?php
session_start();

require_once __DIR__ . '/../../config/db.php';

if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'STUDENT'
) {
    header("Location: ../auth/login.php");
    exit;
}

$userId = (int) $_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT user_id, full_name, phone, current_balance, account_status
    FROM users
    WHERE user_id = ?
      AND role = 'STUDENT'
");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    die("Student account not found.");
}

/*
|--------------------------------------------------------------------------
| Get the student's outstanding semester invoice
|--------------------------------------------------------------------------
*/
$invoiceStmt = $pdo->prepare("
    SELECT *
    FROM semester_invoices
    WHERE user_id = ?
      AND payment_status IN ('UNPAID', 'OVERDUE')
      AND total_amount > amount_paid
    ORDER BY created_at DESC
    LIMIT 1
");
$invoiceStmt->execute([$userId]);
$invoice = $invoiceStmt->fetch();

$error = '';
$showPin = false;

/*
|--------------------------------------------------------------------------
| Stage 1: Request payment
|--------------------------------------------------------------------------
*/
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'request_payment'
) {
    $phone = trim($_POST['phone'] ?? '');

    // Normalize 07XXXXXXXX or 01XXXXXXXX to 2547XXXXXXXX / 2541XXXXXXXX
    if (strlen($phone) === 10 && $phone[0] === '0' && ($phone[1] === '7' || $phone[1] === '1')) {
        $phone = '254' . substr($phone, 1);
    }

    $isValidPhone = (
        strlen($phone) === 12 &&
        str_starts_with($phone, '254') &&
        ($phone[3] === '7' || $phone[3] === '1') &&
        ctype_digit($phone)
    );

    if (!$invoice) {
        $error = "You have no outstanding semester invoice.";
    } elseif (!$isValidPhone) {
        $error = "Enter a valid Kenyan mobile number (e.g. 254712345678).";
    } else {
        $remaining = (float) $invoice['total_amount'] - (float) $invoice['amount_paid'];

        if (abs($remaining - (float) $user['current_balance']) > 0.01) {
            $error = "Your invoice and outstanding balance do not match. Please contact the administrator.";
        } elseif ($remaining <= 0) {
            $error = "There is no outstanding amount to pay.";
        } else {
            $_SESSION['pending_demo_payment'] = [
                'invoice_id' => (int) $invoice['invoice_id'],
                'phone'      => $phone,
                'amount'     => $remaining,
                'created_at' => time()
            ];

            $showPin = true;
        }
    }
}

/*
|--------------------------------------------------------------------------
| Stage 2: Confirm simulated PIN
|--------------------------------------------------------------------------
*/
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'confirm_pin'
) {
    $pending = $_SESSION['pending_demo_payment'] ?? null;
    $pin     = trim($_POST['demo_pin'] ?? '');

    if (!$pending || (time() - $pending['created_at']) > 600) {
        unset($_SESSION['pending_demo_payment']);
        $error = "Payment request expired. Please start again.";
    } elseif (strlen($pin) !== 4 || !ctype_digit($pin)) {
        $error = "Enter exactly four digits.";
        $showPin = true;
    } elseif ($pin !== '1234') {
        $error = "Incorrect demonstration PIN. Please try again.";
        $showPin = true;
    } else {
        try {
            $pdo->beginTransaction();

            // Lock the invoice and user records
            $lockInvoice = $pdo->prepare("
                SELECT *
                FROM semester_invoices
                WHERE invoice_id = ?
                  AND user_id = ?
                FOR UPDATE
            ");
            $lockInvoice->execute([$pending['invoice_id'], $userId]);
            $lockedInvoice = $lockInvoice->fetch();

            $lockUser = $pdo->prepare("
                SELECT current_balance
                FROM users
                WHERE user_id = ?
                FOR UPDATE
            ");
            $lockUser->execute([$userId]);
            $lockedUser = $lockUser->fetch();

            if (
                !$lockedInvoice ||
                !$lockedUser ||
                !in_array($lockedInvoice['payment_status'], ['UNPAID', 'OVERDUE'], true)
            ) {
                throw new Exception("Invoice is unavailable or has already been paid.");
            }

            $remaining = (float) $lockedInvoice['total_amount'] - (float) $lockedInvoice['amount_paid'];

            if (
                abs($remaining - (float) $pending['amount']) > 0.01 ||
                abs($remaining - (float) $lockedUser['current_balance']) > 0.01 ||
                $remaining <= 0
            ) {
                throw new Exception("Outstanding balance has changed. Please try again.");
            }

            // Generate simulated M-Pesa receipt & Daraja CheckoutRequestID
            $reference         = 'DEMO' . strtoupper(bin2hex(random_bytes(5)));
            $checkoutRequestId = 'ws_CO_' . date('YmdHis') . '_' . random_int(1000, 9999);

            // Save successful payment
            $paymentStmt = $pdo->prepare("
                INSERT INTO mpesa_payments
                (
                    user_id,
                    invoice_id,
                    phone_number,
                    amount,
                    checkout_request_id,
                    mpesa_receipt_number,
                    payment_status,
                    result_description
                )
                VALUES (?, ?, ?, ?, ?, ?, 'SUCCESS', ?)
            ");
            $paymentStmt->execute([
                $userId,
                $pending['invoice_id'],
                $pending['phone'],
                $remaining,
                $checkoutRequestId,
                $reference,
                'SIMULATED PAYMENT - DEMO PIN VERIFIED. NO REAL MONEY TRANSFERRED.'
            ]);

            // Mark semester invoice as fully paid and clear invoice hold flag
            $updateInvoice = $pdo->prepare("
                UPDATE semester_invoices
                SET amount_paid = total_amount,
                    payment_status = 'PAID',
                    cms_hold_applied = 0
                WHERE invoice_id = ?
                  AND user_id = ?
            ");
            $updateInvoice->execute([$pending['invoice_id'], $userId]);

            // Clear student's outstanding balance and automatically lift HELD status
            $updateUser = $pdo->prepare("
                UPDATE users
                SET current_balance = 0,
                    account_status = CASE WHEN account_status = 'HELD' THEN 'ACTIVE' ELSE account_status END
                WHERE user_id = ?
            ");
            $updateUser->execute([$userId]);

            $pdo->commit();

            $_SESSION['account_status'] = 'ACTIVE';
            $_SESSION['invoice_demo_payment'] = [
                'reference'  => $reference,
                'amount'     => $remaining,
                'invoice_id' => $pending['invoice_id']
            ];

            unset($_SESSION['pending_demo_payment']);

            header("Location: invoice_payment_success.php");
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = "Payment failed: " . $e->getMessage();
            $showPin = true;
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div style="max-width: 450px; margin: 0 auto;">
    <div class="card">
        <h3>Pay via M-Pesa</h3>

        <?php if ($invoice): ?>
            <?php $remaining = (float) $invoice['total_amount'] - (float) $invoice['amount_paid']; ?>

            <p>
                <strong>Semester:</strong>
                <?= htmlspecialchars($invoice['semester_code']) ?>
            </p>

            <p>
                <strong>Invoice Number:</strong>
                <?= (int) $invoice['invoice_id'] ?>
            </p>

            <p>
                <strong>Outstanding Balance:</strong>
                KSh <?= number_format($remaining, 2) ?>
            </p>
        <?php else: ?>
            <p style="color: #10b981; font-weight: 600;">
                You have no outstanding semester invoices.
            </p>
            <a href="../auth/dashboard.php" class="btn btn-primary" style="display: block; text-align: center; margin-top: 1rem;">
                Back to Dashboard
            </a>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error" style="margin: 1rem 0;">
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <?php if (!$showPin && $invoice): ?>
            <form method="POST">
                <input type="hidden" name="action" value="request_payment">

                <div class="form-group">
                    <label for="phone">M-Pesa Mobile Number</label>
                    <input
                        id="phone"
                        type="text"
                        name="phone"
                        value="<?= htmlspecialchars($user['phone'] ?? '') ?>"
                        placeholder="2547XXXXXXXX"
                        required
                    >
                </div>

                <div class="form-group">
                    <label>Amount to Pay (KSh)</label>
                    <input
                        type="text"
                        value="<?= number_format($remaining, 2) ?>"
                        readonly
                    >
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%;">
                    Pay via M-Pesa
                </button>
            </form>
        <?php endif; ?>

        <?php if ($showPin && isset($_SESSION['pending_demo_payment'])): ?>
            <div style="
                border: 1px solid rgba(148, 163, 184, 0.25);
                padding: 20px;
                border-radius: 12px;
                margin-top: 20px;
                text-align: center;
                background: rgba(15, 23, 42, 0.65);
            ">
                <h3>M-Pesa STK Prompt</h3>
                <p>Confirm CampusTab Semester Settlement</p>

                <h2 style="color: #10b981;">
                    KSh <?= number_format($_SESSION['pending_demo_payment']['amount'], 2) ?>
                </h2>

                <p>
                    Phone: <strong><?= htmlspecialchars($_SESSION['pending_demo_payment']['phone']) ?></strong>
                </p>

                <form method="POST">
                    <input type="hidden" name="action" value="confirm_pin">

                    <div class="form-group">
                        <label for="demo_pin">Enter 4-digit DEMO PIN</label>
                        <input
                            id="demo_pin"
                            type="password"
                            name="demo_pin"
                            maxlength="4"
                            minlength="4"
                            pattern="[0-9]{4}"
                            inputmode="numeric"
                            placeholder="1234"
                            required
                            autofocus
                        >
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        Confirm Payment
                    </button>
                </form>

                <p style="
                    color: #fbbf24;
                    font-size: 12px;
                    margin-top: 15px;
                    margin-bottom: 0;
                ">
                    DEMONSTRATION ONLY. Use demo PIN <strong>1234</strong>.
                    Never enter your real M-Pesa PIN here. No real money will be transferred.
                </p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>