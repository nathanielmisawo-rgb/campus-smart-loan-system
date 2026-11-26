<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'STUDENT') {
    header("Location: login.php");
    exit;
}

$userId = (int) $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ? AND role = 'STUDENT' LIMIT 1");
$stmt->execute([$userId]);
$student = $stmt->fetch();

if (!$student) {
    session_destroy();
    header("Location: login.php");
    exit;
}

// If already enrolled with an active credit limit, return to dashboard
if ((float) $student['credit_limit'] > 0) {
    header("Location: dashboard.php");
    exit;
}

$error = '';
$allowedTiers = [
    2500.00 => 'Starter Tier — KSh 2,500.00 (Meals & Printing)',
    5000.00 => 'Standard Tier — KSh 5,000.00 (Full Campus Essentials)',
    7500.00 => 'Extended Tier — KSh 7,500.00 (Senior / Finalist Tab)'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone        = trim($_POST['phone'] ?? '');
    $grade        = trim($_POST['grade'] ?? '');
    $selectedTier = (float) ($_POST['credit_limit'] ?? 0);
    $agreedTerms  = isset($_POST['agree_terms']);

    if ($phone === '' || $grade === '') {
        $error = 'Please confirm your M-Pesa phone number and current academic year.';
    } elseif (!preg_match('/^(254|0)[17][0-9]{8}$/', $phone)) {
        $error = 'Enter a valid Kenyan mobile number (e.g. 254712345678 or 0712345678).';
    } elseif (!array_key_exists($selectedTier, $allowedTiers)) {
        $error = 'Please select a valid Smart Loan credit tier.';
    } elseif (!$agreedTerms) {
        $error = 'You must agree to the end-of-semester repayment and CMS hold policy.';
    } elseif ($student['account_status'] === 'SUSPENDED') {
        $error = 'Your account is currently suspended. Please contact the Strathmore Bursar Office.';
    } else {
        // Normalize phone to 2547XXXXXXXX format for M-Pesa STK compatibility
        if (str_starts_with($phone, '0')) {
            $phone = '254' . substr($phone, 1);
        }

        $updateStmt = $pdo->prepare("
            UPDATE users
            SET phone = ?,
                grade = ?,
                credit_limit = ?,
                account_status = 'ACTIVE'
            WHERE user_id = ? AND role = 'STUDENT'
        ");
        $updateStmt->execute([$phone, $grade, $selectedTier, $userId]);

        $_SESSION['account_status'] = 'ACTIVE';
        $_SESSION['flash_success']  = 'Smart Loan Credit Tab activated! Your semester limit is KSh ' . number_format($selectedTier, 2) . '.';

        header("Location: dashboard.php");
        exit;
    }
}

require_once '../../includes/header.php';
?>

<div style="max-width: 560px; margin: 0 auto;">
    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
            <div>
                <h2 style="margin: 0 0 0.25rem; display: flex; align-items: center; gap: 0.5rem;">
                    <i data-lucide="sparkles" style="width: 22px; height: 22px; color: #10b981;"></i>
                    Activate Student Smart Loan Tab
                </h2>
                <p style="margin: 0; font-size: 0.88rem; color: var(--text-muted);">
                    Self-enroll your Strathmore SSO profile for zero-interest campus micro-credit.
                </p>
            </div>
            <a href="dashboard.php" class="btn btn-ghost" style="padding: 0.45rem 0.8rem; font-size: 0.82rem;">&larr; Back</a>
        </div>

        <div style="background: rgba(15, 23, 42, 0.65); border: 1px solid rgba(148, 163, 184, 0.16); border-radius: 12px; padding: 0.95rem 1.1rem; margin-bottom: 1.25rem; font-size: 0.88rem;">
            <div style="display: flex; justify-content: space-between; margin-bottom: 0.35rem;">
                <span style="color: var(--text-muted);">Student Name:</span>
                <strong><?= htmlspecialchars($student['full_name']) ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; margin-bottom: 0.35rem;">
                <span style="color: var(--text-muted);">Admission ID:</span>
                <strong><?= htmlspecialchars($student['student_id']) ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between;">
                <span style="color: var(--text-muted);">Strathmore Email:</span>
                <span><?= htmlspecialchars($student['email']) ?></span>
            </div>
        </div>

        <?php if ($error !== ''): ?>
            <div class="alert alert-error" style="margin-bottom: 1.15rem;">
                <i data-lucide="alert-circle" style="width: 18px; height: 18px; flex-shrink: 0;"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label for="grade">Current Academic Year / Grade</label>
                <?php $selectedGrade = $_POST['grade'] ?? ($student['grade'] ?: 'Year 1'); ?>
                <select id="grade" name="grade" required>
                    <?php foreach (['Year 1', 'Year 2', 'Year 3', 'Year 4', 'Postgraduate'] as $yr): ?>
                        <option value="<?= $yr ?>" <?= $selectedGrade === $yr ? 'selected' : '' ?>><?= $yr ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="phone">M-Pesa Phone Number (For Tab Repayments)</label>
                <input
                    id="phone"
                    type="text"
                    name="phone"
                    value="<?= htmlspecialchars($_POST['phone'] ?? ($student['phone'] ?? '')) ?>"
                    placeholder="e.g. 254712345678"
                    required
                >
            </div>

            <div class="form-group">
                <label for="credit_limit">Select Semester Credit Limit (0% Interest)</label>
                <?php $chosenLimit = (float) ($_POST['credit_limit'] ?? 5000.00); ?>
                <select id="credit_limit" name="credit_limit" required>
                    <?php foreach ($allowedTiers as $amount => $label): ?>
                        <option value="<?= number_format($amount, 2, '.', '') ?>" <?= abs($chosenLimit - $amount) < 0.01 ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="display: flex; align-items: flex-start; gap: 0.6rem; margin-top: 1rem;">
                <input
                    id="agree_terms"
                    type="checkbox"
                    name="agree_terms"
                    value="1"
                    style="width: auto; margin-top: 0.2rem;"
                    <?= isset($_POST['agree_terms']) ? 'checked' : '' ?>
                    required
                >
                <label for="agree_terms" style="font-size: 0.83rem; line-height: 1.45; color: var(--text-secondary); font-weight: 400;">
                    I authorize Strathmore University to consolidate my POS cafeteria, printing, and bookstore charges into a semester invoice, and understand that unpaid balances after the grace period trigger an automatic CMS registration hold.
                </label>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 0.6rem; padding: 0.85rem;">
                <i data-lucide="check-circle-2" style="width: 18px; height: 18px;"></i>
                Activate My Smart Loan Tab
            </button>
        </form>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>