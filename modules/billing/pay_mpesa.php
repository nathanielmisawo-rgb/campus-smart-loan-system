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
    SELECT user_id, full_name, phone, credit_limit, current_balance, account_status
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
| Fetch or auto-sync outstanding semester invoice with current_balance
|--------------------------------------------------------------------------
*/
$invoiceStmt = $pdo->prepare("
    SELECT *
    FROM semester_invoices
    WHERE user_id = ?
      AND payment_status IN ('UNPAID', 'OVERDUE')
    ORDER BY created_at DESC
    LIMIT 1
");
$invoiceStmt->execute([$userId]);
$invoice = $invoiceStmt->fetch();

$currentBalance = (float) $user['current_balance'];

if ($currentBalance > 0) {
    if (!$invoice) {
        $createInv = $pdo->prepare("
            INSERT INTO semester_invoices (user_id, semester_code, total_amount, amount_paid, payment_status)
            VALUES (?, 'SEM_1_2026', ?, 0.00, 'UNPAID')
        ");
        $createInv->execute([$userId, $currentBalance]);

        $invoiceStmt->execute([$userId]);
        $invoice = $invoiceStmt->fetch();
    } else {
        $expectedTotal = (float) $invoice['amount_paid'] + $currentBalance;
        if (abs((float) $invoice['total_amount'] - $expectedTotal) > 0.01) {
            $syncInv = $pdo->prepare("
                UPDATE semester_invoices
                SET total_amount = ?
                WHERE invoice_id = ?
            ");
            $syncInv->execute([$expectedTotal, $invoice['invoice_id']]);

            $invoiceStmt->execute([$userId]);
            $invoice = $invoiceStmt->fetch();
        }
    }
}

$error = '';
$showPin = false;

/*
|--------------------------------------------------------------------------
| Stage 1: Request full or partial payment
|--------------------------------------------------------------------------
*/
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'request_payment'
) {
    $phone     = trim($_POST['phone'] ?? '');
    $rawAmount = trim($_POST['pay_amount'] ?? '');
    $payAmount = is_numeric($rawAmount) ? round((float) $rawAmount, 2) : 0.0;

    if (strlen($phone) === 10 && $phone[0] === '0' && ($phone[1] === '7' || $phone[1] === '1')) {
        $phone = '254' . substr($phone, 1);
    }

    $isValidPhone = (
        strlen($phone) === 12 &&
        str_starts_with($phone, '254') &&
        ($phone[3] === '7' || $phone[3] === '1') &&
        ctype_digit($phone)
    );

    if (!$invoice || $currentBalance <= 0) {
        $error = "You have no outstanding balance to pay.";
    } elseif (!$isValidPhone) {
        $error = "Enter a valid Kenyan mobile number (e.g. 254712345678).";
    } elseif ($payAmount <= 0) {
        $error = "Enter a valid payment amount greater than KSh 0.";
    } elseif ($payAmount > $currentBalance + 0.01) {
        $error = "Payment amount cannot exceed your outstanding balance of KSh " . number_format($currentBalance, 2) . ".";
    } else {
        $_SESSION['pending_demo_payment'] = [
            'invoice_id' => (int) $invoice['invoice_id'],
            'phone'      => $phone,
            'amount'     => $payAmount,
            'created_at' => time()
        ];

        $showPin = true;
    }
}

/*
|--------------------------------------------------------------------------
| Stage 2: Confirm simulated PIN & apply partial or full settlement
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
                SELECT credit_limit, current_balance, account_status
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

            $payAmount     = (float) $pending['amount'];
            $lockedBalance = (float) $lockedUser['current_balance'];

            if ($payAmount <= 0 || $payAmount > $lockedBalance + 0.01) {
                throw new Exception("Outstanding balance has changed. Please try again.");
            }

            $newBalance     = max(0.0, round($lockedBalance - $payAmount, 2));
            $newAmountPaid  = round((float) $lockedInvoice['amount_paid'] + $payAmount, 2);
            $isFullyPaid    = ($newBalance <= 0.01);
            $newInvStatus   = $isFullyPaid ? 'PAID' : $lockedInvoice['payment_status'];
            $newHoldApplied = $isFullyPaid ? 0 : (int) $lockedInvoice['cms_hold_applied'];

            // Reduce credit limit by the mid-semester payment if desired (or keep revolving limit)
            $newCreditLimit = max(0.0, round((float) $lockedUser['credit_limit'] - $payAmount, 2));

            $reference         = 'DEMO' . strtoupper(bin2hex(random_bytes(5)));
            $checkoutRequestId = 'ws_CO_' . date('YmdHis') . '_' . random_int(1000, 9999);

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
                $payAmount,
                $checkoutRequestId,
                $reference,
                $isFullyPaid
                    ? 'SIMULATED FULL SETTLEMENT - DEMO PIN VERIFIED.'
                    : 'SIMULATED PARTIAL INSTALLMENT - DEMO PIN VERIFIED.'
            ]);

            $updateInvoice = $pdo->prepare("
                UPDATE semester_invoices
                SET amount_paid = ?,
                    payment_status = ?,
                    cms_hold_applied = ?
                WHERE invoice_id = ?
                  AND user_id = ?
            ");
            $updateInvoice->execute([
                $isFullyPaid ? (float) $lockedInvoice['total_amount'] : $newAmountPaid,
                $newInvStatus,
                $newHoldApplied,
                $pending['invoice_id'],
                $userId
            ]);

            $newAccountStatus = ($isFullyPaid && $lockedUser['account_status'] === 'HELD')
                ? 'ACTIVE'
                : $lockedUser['account_status'];

            $updateUser = $pdo->prepare("
                UPDATE users
                SET current_balance = ?,
                    credit_limit = ?,
                    account_status = ?
                WHERE user_id = ?
            ");
            $updateUser->execute([
                $newBalance,
                $newCreditLimit,
                $newAccountStatus,
                $userId
            ]);

            $pdo->commit();

            $_SESSION['account_status'] = $newAccountStatus;
            $_SESSION['invoice_demo_payment'] = [
                'reference'  => $reference,
                'amount'     => $payAmount,
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

        <?php if ($invoice && $currentBalance > 0): ?>
            <p>
                <strong>Semester:</strong>
                <?= htmlspecialchars($invoice['semester_code']) ?>
            </p>

            <p>
                <strong>Invoice Number:</strong>
                #<?= (int) $invoice['invoice_id'] ?>
            </p>

            <p>
                <strong>Outstanding Balance:</strong>
                KSh <?= number_format($currentBalance, 2) ?>
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

        <?php if (!$showPin && $invoice && $currentBalance > 0): ?>
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
                    <label for="pay_amount">Amount to Pay (KSh) — Full or Partial</label>
                    <input
                        id="pay_amount"
                        type="number"
                        step="0.01"
                        min="1"
                        max="<?= number_format($currentBalance, 2, '.', '') ?>"
                        name="pay_amount"
                        value="<?= number_format($currentBalance, 2, '.', '') ?>"
                        required
                    >
                    <small style="color: var(--text-muted); display: block; margin-top: 0.35rem;">
                        Mid-semester installments are allowed (e.g., pay KSh 700.00 now and clear the rest before semester end).
                    </small>
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
                <p>Confirm CampusTab Installment Payment</p>

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