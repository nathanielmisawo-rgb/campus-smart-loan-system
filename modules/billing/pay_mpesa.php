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

    if (!$invoice) {

        $error = "You have no outstanding semester invoice.";

    } elseif (!preg_match('/^254[17][0-9]{8}$/', $phone)) {

        $error = "Enter a valid Kenyan mobile number.";

    } else {

        $remaining = (float) $invoice['total_amount']
                   - (float) $invoice['amount_paid'];

        if (
            abs($remaining - (float) $user['current_balance']) > 0.01
        ) {

            $error = "Your invoice and outstanding balance do not match. Please contact the administrator.";

        } elseif ($remaining <= 0) {

            $error = "There is no outstanding amount to pay.";

        } else {

            $_SESSION['pending_demo_payment'] = [
                'invoice_id' => (int) $invoice['invoice_id'],
                'phone' => $phone,
                'amount' => $remaining,
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
    $pin = trim($_POST['demo_pin'] ?? '');

    if (
        !$pending ||
        (time() - $pending['created_at']) > 600
    ) {

        unset($_SESSION['pending_demo_payment']);

        $error = "Payment request expired. Please start again.";

    } elseif (!preg_match('/^[0-9]{4}$/', $pin)) {

        $error = "Enter exactly four digits.";

        $showPin = true;

    } elseif ($pin !== '1234') {

        $error = "Incorrect demonstration PIN. Please try again.";

        $showPin = true;

    } else {

        try {

            $pdo->beginTransaction();

            /*
            | Lock the invoice and user records
            */

            $lockInvoice = $pdo->prepare("
                SELECT *
                FROM semester_invoices
                WHERE invoice_id = ?
                AND user_id = ?
                FOR UPDATE
            ");

            $lockInvoice->execute([
                $pending['invoice_id'],
                $userId
            ]);

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
                !in_array(
                    $lockedInvoice['payment_status'],
                    ['UNPAID', 'OVERDUE'],
                    true
                )
            ) {

                throw new Exception(
                    "Invoice is unavailable or has already been paid."
                );
            }

            $remaining = (float) $lockedInvoice['total_amount']
                       - (float) $lockedInvoice['amount_paid'];

            if (
                abs($remaining - (float) $pending['amount']) > 0.01 ||
                abs($remaining - (float) $lockedUser['current_balance']) > 0.01 ||
                $remaining <= 0
            ) {

                throw new Exception(
                    "Outstanding balance has changed. Please try again."
                );
            }

            /*
            | Generate a simulated payment reference
            */

            $reference = 'DEMO' . strtoupper(
                bin2hex(random_bytes(5))
            );

            /*
            | Save successful payment
            */

            $paymentStmt = $pdo->prepare("
                INSERT INTO mpesa_payments
                (
                    user_id,
                    invoice_id,
                    phone_number,
                    amount,
                    mpesa_receipt_number,
                    payment_status,
                    result_description
                )
                VALUES (?, ?, ?, ?, ?, 'SUCCESS', ?)
            ");

            $paymentStmt->execute([
                $userId,
                $pending['invoice_id'],
                $pending['phone'],
                $remaining,
                $reference,
                'SIMULATED PAYMENT - DEMO PIN VERIFIED. NO REAL MONEY TRANSFERRED.'
            ]);

            /*
            | Mark semester invoice as fully paid
            */

            $updateInvoice = $pdo->prepare("
                UPDATE semester_invoices
                SET amount_paid = total_amount,
                    payment_status = 'PAID'
                WHERE invoice_id = ?
                AND user_id = ?
            ");

            $updateInvoice->execute([
                $pending['invoice_id'],
                $userId
            ]);

            /*
            | Clear the student's outstanding balance
            */

            $updateUser = $pdo->prepare("
                UPDATE users
                SET current_balance = 0
                WHERE user_id = ?
            ");

            $updateUser->execute([$userId]);

            $pdo->commit();

            $_SESSION['invoice_demo_payment'] = [
                'reference' => $reference,
                'amount' => $remaining,
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

            <?php
            $remaining = (float) $invoice['total_amount']
                       - (float) $invoice['amount_paid'];
            ?>

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

            <p style="color: green;">
                You have no outstanding semester invoices.
            </p>

        <?php endif; ?>

        <?php if ($error): ?>
            <p style="color: var(--danger);">
                <?= htmlspecialchars($error) ?>
            </p>
        <?php endif; ?>

        <?php if (!$showPin && $invoice): ?>

            <form method="POST">

                <input
                    type="hidden"
                    name="action"
                    value="request_payment"
                >

                <div class="form-group">

                    <label>M-Pesa Mobile Number</label>

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

                <button
                    type="submit"
                    class="btn"
                    style="width: 100%;"
                >
                    Pay via M-Pesa
                </button>
            </form>

        <?php endif; ?>

        <?php if ($showPin): ?>

            <div style="
                border: 1px solid #ddd;
                padding: 20px;
                border-radius: 10px;
                margin-top: 20px;
                text-align: center;
            ">

                <h3>Payment Request</h3>

                <p>
                    M-Pesa payment confirmation
                </p>

                <h2 style="color: green;">
                    KSh <?= number_format(
                        $_SESSION['pending_demo_payment']['amount'],
                        2
                    ) ?>
                </h2>

                <p>
                    Phone:
                    <?= htmlspecialchars(
                        $_SESSION['pending_demo_payment']['phone']
                    ) ?>
                </p>

                <form method="POST">

                    <input
                        type="hidden"
                        name="action"
                        value="confirm_pin"
                    >

                    <div class="form-group">

                        <label>Enter 4-digit DEMO PIN</label>

                        <input
                            type="password"
                            name="demo_pin"
                            maxlength="4"
                            minlength="4"
                            pattern="[0-9]{4}"
                            inputmode="numeric"
                            placeholder="Enter demo PIN"
                            required
                        >

                    </div>

                    <button
                        type="submit"
                        class="btn"
                        style="width: 100%;"
                    >
                        Confirm Payment
                    </button>

                </form>

                <p style="
                    color: #b7791f;
                    font-size: 12px;
                    margin-top: 15px;
                ">
                    DEMONSTRATION ONLY. Use demo PIN 1234.
                    Never enter your real M-Pesa PIN here.
                    No real money will be transferred.
                </p>

            </div>

        <?php endif; ?>
    </div>

</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>