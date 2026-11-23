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

$reference = $_SESSION['invoice_demo_payment']['reference'] ?? '';

if ($reference === '') {
    header("Location: pay_invoice.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| Get payment details
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT 
        mp.payment_id,
        mp.amount,
        mp.phone_number,
        mp.mpesa_receipt_number,
        mp.payment_status,
        mp.result_description,
        mp.created_at,
        si.invoice_id,
        si.semester_code,
        si.total_amount,
        si.amount_paid,
        si.payment_status AS invoice_status
    FROM mpesa_payments mp
    INNER JOIN semester_invoices si
        ON mp.invoice_id = si.invoice_id
    WHERE mp.mpesa_receipt_number = ?
      AND mp.user_id = ?
      AND mp.payment_status = 'SUCCESS'
    LIMIT 1
");

$stmt->execute([$reference, $userId]);
$payment = $stmt->fetch();

if (!$payment) {
    die("Payment receipt not found.");
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div style="max-width: 550px; margin: 0 auto;">

    <div class="card">

        <h2 style="text-align: center;">
            Payment Successful
        </h2>

        <p style="text-align: center;">
            Your semester invoice payment has been recorded successfully.
        </p>

        <hr>

        <h3>Payment Receipt</h3>

        <p>
            <strong>Payment Reference:</strong><br>
            <?= htmlspecialchars($payment['mpesa_receipt_number']) ?>
        </p>

        <p>
            <strong>Invoice Number:</strong><br>
            <?= htmlspecialchars($payment['invoice_id']) ?>
        </p>

        <p>
            <strong>Semester:</strong><br>
            <?= htmlspecialchars($payment['semester_code']) ?>
        </p>

        <p>
            <strong>Amount Paid:</strong><br>
            KSh <?= number_format($payment['amount'], 2) ?>
        </p>

        <p>
            <strong>Phone Number:</strong><br>
            <?= htmlspecialchars($payment['phone_number']) ?>
        </p>

        <p>
            <strong>Payment Date:</strong><br>
            <?= htmlspecialchars($payment['created_at']) ?>
        </p>

        <p>
            <strong>Payment Status:</strong><br>

            <span style="color: green; font-weight: bold;">
                SUCCESS
            </span>
        </p>

        <hr>

        <p>
            <strong>Invoice Total:</strong>
            KSh <?= number_format($payment['total_amount'], 2) ?>
        </p>

        <p>
            <strong>Total Paid:</strong>
            KSh <?= number_format($payment['amount_paid'], 2) ?>
        </p>

        <p>
            <strong>Invoice Status:</strong>
            <?= htmlspecialchars($payment['invoice_status']) ?>
        </p>

        <div style="
            background: #fff3cd;
            padding: 12px;
            border-radius: 5px;
            margin-top: 20px;
        ">
            <strong>Demo Payment Notice</strong><br>
            This is a simulated M-Pesa payment for the Campus Smart Loan
            System demonstration. No real money was transferred.
        </div>

        <div style="margin-top: 20px;">

            <button
                onclick="window.print()"
                class="btn"
                style="width: 100%; margin-bottom: 10px;"
            >
                Print Receipt
            </button>

            <a
                href="../auth/dashboard.php"
                class="btn"
                style="display: block; text-align: center;"
            >
                Back to Dashboard
            </a>

        </div>

    </div>

</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>