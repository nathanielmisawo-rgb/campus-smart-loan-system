<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Fetch user profile details
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit;
}

$flashSuccess = $_SESSION['flash_success'] ?? '';
$flashError   = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Handle Student Self-Service Loan Request
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['take_student_loan']) &&
    $user['role'] === 'STUDENT'
) {
    $facility_id  = filter_input(INPUT_POST, 'facility_id', FILTER_VALIDATE_INT);
    $amount_input = trim($_POST['loan_amount'] ?? '');
    $purpose      = trim($_POST['loan_purpose'] ?? '');
    $amount       = is_numeric($amount_input) ? round((float)$amount_input, 2) : 0.0;

    if (!$facility_id || $amount <= 0) {
        $_SESSION['flash_error'] = 'Please select a campus facility/category and enter a valid loan amount greater than KSh 0.';
        header("Location: dashboard.php");
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Lock student row to prevent concurrent over-borrowing
        $lockStmt = $pdo->prepare("
            SELECT user_id, credit_limit, current_balance, account_status
            FROM users
            WHERE user_id = ? AND role = 'STUDENT'
            FOR UPDATE
        ");
        $lockStmt->execute([$user['user_id']]);
        $lockedUser = $lockStmt->fetch();

        // Verify facility exists
        $facStmt = $pdo->prepare("SELECT facility_id, facility_name FROM facilities WHERE facility_id = ?");
        $facStmt->execute([$facility_id]);
        $facility = $facStmt->fetch();

        if (!$lockedUser || !$facility) {
            throw new Exception('Invalid student account or campus facility selected.');
        }

        $creditLimit     = (float)$lockedUser['credit_limit'];
        $currentBalance  = (float)$lockedUser['current_balance'];
        $availableCredit = max(0, $creditLimit - $currentBalance);

        if ($creditLimit <= 0) {
            throw new Exception('You have not activated your Smart Loan Tab yet. Please self-register first.');
        }

        if (strtoupper($lockedUser['account_status']) !== 'ACTIVE') {
            $logDeclined = $pdo->prepare("INSERT INTO transactions (user_id, facility_id, amount, status) VALUES (?, ?, ?, 'DECLINED')");
            $logDeclined->execute([$lockedUser['user_id'], $facility_id, $amount]);
            $pdo->commit();

            $_SESSION['flash_error'] = 'Loan declined: Your account is currently ' . $lockedUser['account_status'] . '. Settle overdue balances or contact the Admin.';
            header("Location: dashboard.php");
            exit;
        }

        // Enforce student credit limit check
        if ($amount > $availableCredit) {
            $logDeclined = $pdo->prepare("INSERT INTO transactions (user_id, facility_id, amount, status) VALUES (?, ?, ?, 'DECLINED')");
            $logDeclined->execute([$lockedUser['user_id'], $facility_id, $amount]);
            $pdo->commit();

            $_SESSION['flash_error'] = 'Loan request of KSh ' . number_format($amount, 2)
                . ' declined! It exceeds your available credit limit of KSh ' . number_format($availableCredit, 2)
                . '. Visit the Bursar Admin if you need a higher credit limit.';
            header("Location: dashboard.php");
            exit;
        }

        // Approved: update student's owed balance and log transaction
        $newBalance = $currentBalance + $amount;
        $updStmt = $pdo->prepare("UPDATE users SET current_balance = ? WHERE user_id = ?");
        $updStmt->execute([$newBalance, $lockedUser['user_id']]);

        $logApproved = $pdo->prepare("INSERT INTO transactions (user_id, facility_id, amount, status) VALUES (?, ?, ?, 'APPROVED')");
        $logApproved->execute([$lockedUser['user_id'], $facility_id, $amount]);

        $pdo->commit();

        $note = $purpose !== '' ? ' (' . $purpose . ')' : '';
        $_SESSION['flash_success'] = 'Loan approved! KSh ' . number_format($amount, 2)
            . ' credited for ' . $facility['facility_name'] . $note
            . '. New balance owed: KSh ' . number_format($newBalance, 2) . '.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['flash_error'] = 'Loan request failed: ' . $e->getMessage();
    }

    header("Location: dashboard.php");
    exit;
}

// Initialize dashboard variables
$total_students   = 0;
$held_students    = 0;
$total_debt       = 0.00;
$available_credit = 0.00;
$transactions     = [];
$invoices         = [];
$facilities       = [];
$is_enrolled      = false;

if ($user['role'] === 'ADMIN') {
    $total_students = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'STUDENT' AND credit_limit > 0")->fetchColumn();
    $held_students  = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'STUDENT' AND account_status != 'ACTIVE'")->fetchColumn();
    $total_debt     = (float)($pdo->query("SELECT SUM(current_balance) FROM users WHERE role = 'STUDENT'")->fetchColumn() ?? 0);
} elseif ($user['role'] === 'STUDENT') {
    $is_enrolled      = ((float)$user['credit_limit'] > 0);
    $available_credit = max(0, (float)$user['credit_limit'] - (float)$user['current_balance']);
    $facilities       = $pdo->query("SELECT facility_id, facility_name, category FROM facilities ORDER BY facility_name")->fetchAll();

    $tx_stmt = $pdo->prepare("
        SELECT t.*, f.facility_name 
        FROM transactions t 
        JOIN facilities f ON t.facility_id = f.facility_id 
        WHERE t.user_id = ? 
        ORDER BY t.created_at DESC
    ");
    $tx_stmt->execute([$_SESSION['user_id']]);
    $transactions = $tx_stmt->fetchAll();

    try {
        $inv_stmt = $pdo->prepare("SELECT * FROM semester_invoices WHERE user_id = ? ORDER BY created_at DESC");
        $inv_stmt->execute([$_SESSION['user_id']]);
        $invoices = $inv_stmt->fetchAll();
    } catch (PDOException $e) {
        $invoices = [];
    }
}

require_once '../../includes/header.php';
?>

<?php if ($flashSuccess !== ''): ?>
    <div class="alert alert-success" style="margin-bottom: 1.25rem;">
        <i data-lucide="check-circle-2" style="width: 18px; height: 18px; flex-shrink: 0;"></i>
        <span><?= htmlspecialchars($flashSuccess) ?></span>
    </div>
<?php endif; ?>

<?php if ($flashError !== ''): ?>
    <div class="alert alert-error" style="margin-bottom: 1.25rem;">
        <i data-lucide="alert-circle" style="width: 18px; height: 18px; flex-shrink: 0;"></i>
        <span><?= htmlspecialchars($flashError) ?></span>
    </div>
<?php endif; ?>

<!-- Top Hero Header -->
<div class="dashboard-hero">
    <div>
        <span class="hero-eyebrow">
            <span class="pulse-dot"></span>
            Strathmore Smart Campus Finance
        </span>
        <h1 class="hero-title">Welcome back, <?= htmlspecialchars(explode(' ', $user['full_name'])[0]) ?></h1>
        <p class="hero-subtitle">
            <?php if ($user['role'] === 'ADMIN'): ?>
                Monitor campus credit exposure, self-enrolled student ledgers, and automated CMS holds in real time.
            <?php elseif ($user['role'] === 'VENDOR'): ?>
                Ready to process instant student meal and campus facility tab transactions.
            <?php else: ?>
                ID: <strong><?= htmlspecialchars($user['student_id']) ?></strong> &bull; <?= htmlspecialchars($user['grade'] ?? 'Student Account') ?>
            <?php endif; ?>
        </p>
    </div>

    <div class="hero-actions">
        <?php if ($user['role'] === 'ADMIN'): ?>
            <a href="student_view.php" class="btn btn-primary">
                <i data-lucide="users"></i> Student Credit Ledger
            </a>
            <a href="../billing/cms_hold.php" class="btn btn-ghost">
                <i data-lucide="shield-alert"></i> CMS Holds
            </a>
        <?php elseif ($user['role'] === 'VENDOR'): ?>
            <a href="../pos/checkout.php" class="btn btn-primary">
                <i data-lucide="scan-line"></i> Launch POS Terminal
            </a>
        <?php elseif (!$is_enrolled): ?>
            <a href="student_self_register.php" class="btn btn-primary">
                <i data-lucide="sparkles"></i> Activate Smart Loan Tab
            </a>
        <?php else: ?>
            <a href="#self-loan-box" class="btn btn-primary">
                <i data-lucide="hand-coins"></i> Request Instant Loan
            </a>
            <a href="../billing/pay_mpesa.php" class="btn btn-ghost">
                <i data-lucide="smartphone"></i> Settle Tab via M-Pesa
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($user['role'] === 'ADMIN'): ?>
    <!-- Admin KPI Grid -->
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-top">
                <span class="kpi-label">Enrolled Credit Accounts</span>
                <span class="kpi-icon cyan"><i data-lucide="users"></i></span>
            </div>
            <div class="kpi-value"><?= number_format($total_students) ?></div>
            <div class="kpi-foot">Self-enrolled student credit profiles</div>
        </div>

        <div class="kpi-card">
            <div class="kpi-top">
                <span class="kpi-label">Accounts on Hold / Suspended</span>
                <span class="kpi-icon rose"><i data-lucide="shield-alert"></i></span>
            </div>
            <div class="kpi-value text-rose"><?= number_format($held_students) ?></div>
            <div class="kpi-foot">Restricted from POS &amp; CMS registration</div>
        </div>

        <div class="kpi-card">
            <div class="kpi-top">
                <span class="kpi-label">Total System Credit Exposure</span>
                <span class="kpi-icon emerald"><i data-lucide="banknote"></i></span>
            </div>
            <div class="kpi-value text-emerald">KSh <?= number_format($total_debt, 2) ?></div>
            <div class="kpi-foot">Aggregate unpaid student tab balance</div>
        </div>
    </div>

    <!-- Admin Control Center -->
    <div class="card">
        <div class="card-header">
            <div>
                <h3>Bursar Command Center</h3>
                <p class="card-desc">Adjust student credit limits, audit transactions, or trigger automated CMS holds.</p>
            </div>
        </div>
        <div class="action-bar">
            <a href="student_view.php" class="btn btn-primary">
                <i data-lucide="book-open"></i> View &amp; Manage Student Ledger
            </a>
            <a href="../billing/cms_hold.php" class="btn btn-ghost">
                <i data-lucide="shield-alert"></i> Open CMS Hold Console
            </a>
            <form action="../billing/cms_hold.php" method="POST" style="display: inline-flex;">
                <button type="submit" name="trigger_hold" class="btn btn-danger" onclick="return confirm('Enforce CMS Registration Hold for overdue debts?')">
                    <i data-lucide="lock"></i> Run CMS Hold Engine
                </button>
            </form>
        </div>
    </div>

<?php elseif ($user['role'] === 'VENDOR'): ?>
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-top">
                <span class="kpi-label">Terminal Operator</span>
                <span class="kpi-icon cyan"><i data-lucide="badge-check"></i></span>
            </div>
            <div class="kpi-value" style="font-size: 1.5rem;"><?= htmlspecialchars($user['full_name']) ?></div>
            <div class="kpi-foot">Operator ID: <?= htmlspecialchars($user['student_id']) ?></div>
        </div>

        <div class="kpi-card">
            <div class="kpi-top">
                <span class="kpi-label">POS Gateway Status</span>
                <span class="kpi-icon emerald"><i data-lucide="wifi"></i></span>
            </div>
            <div class="kpi-value text-emerald" style="font-size: 1.5rem;">ONLINE</div>
            <div class="kpi-foot">Instant student tab authorization active</div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <h3>Campus Vendor POS Portal</h3>
                <p class="card-desc">Process student meal and facility tab charges directly at the Point of Sale.</p>
            </div>
            <a href="../pos/checkout.php" class="btn btn-primary">
                <i data-lucide="arrow-up-right"></i> Open POS Checkout Terminal
            </a>
        </div>
    </div>

<?php elseif (!$is_enrolled): ?>
    <!-- Student Not Yet Enrolled: Self-Registration Callout -->
    <div class="card featured-card" style="max-width: 720px; margin: 1rem auto; padding: 2rem;">
        <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.75rem;">
            <span class="kpi-icon emerald"><i data-lucide="wallet"></i></span>
            <div>
                <h2 style="margin: 0; font-size: 1.35rem;">You haven't activated your Smart Loan Tab yet</h2>
                <p style="margin: 0.2rem 0 0; color: var(--text-secondary); font-size: 0.9rem;">
                    Your Strathmore SSO identity (<strong><?= htmlspecialchars($user['student_id']) ?></strong>) is verified and eligible for self-enrollment.
                </p>
            </div>
        </div>

        <ul style="margin: 1.25rem 0 1.5rem; padding-left: 1.25rem; color: var(--text-secondary); line-height: 1.7; font-size: 0.92rem;">
            <li>Choose a semester credit limit of <strong>KSh 2,500</strong>, <strong>KSh 5,000</strong>, or <strong>KSh 7,500</strong> at <strong>0% interest</strong>.</li>
            <li>Borrow funds directly from your student portal or tap your Student ID at campus facilities.</li>
            <li>Settle your consolidated semester tab anytime via M-Pesa before exams.</li>
        </ul>

        <a href="student_self_register.php" class="btn btn-primary" style="padding: 0.85rem 1.4rem;">
            <i data-lucide="sparkles"></i> Self-Register for Smart Loan Tab Now
        </a>
    </div>

<?php else: ?>
    <?php
        $credit_limit    = (float)$user['credit_limit'];
        $current_balance = (float)$user['current_balance'];
        $usage_pct       = $credit_limit > 0 ? min(100, round(($current_balance / $credit_limit) * 100, 1)) : 0;
        $is_active       = ($user['account_status'] === 'ACTIVE');
    ?>

    <!-- Student KPI Bento Grid -->
    <div class="kpi-grid">
        <div class="kpi-card featured-card">
            <div class="kpi-top">
                <span class="kpi-label">Available Credit</span>
                <span class="kpi-icon emerald"><i data-lucide="sparkles"></i></span>
            </div>
            <div class="kpi-value text-emerald">KSh <?= number_format($available_credit, 2) ?></div>
            <div class="kpi-foot">Out of KSh <?= number_format($credit_limit, 2) ?> semester limit</div>
        </div>

        <div class="kpi-card">
            <div class="kpi-top">
                <span class="kpi-label">Current Balance Owed</span>
                <span class="kpi-icon cyan"><i data-lucide="receipt"></i></span>
            </div>
            <div class="kpi-value">KSh <?= number_format($current_balance, 2) ?></div>
            <div class="kpi-foot"><?= $usage_pct ?>% of credit limit utilized</div>
        </div>

        <div class="kpi-card">
            <div class="kpi-top">
                <span class="kpi-label">CMS Academic Status</span>
                <span class="kpi-icon <?= $is_active ? 'emerald' : 'rose' ?>">
                    <i data-lucide="<?= $is_active ? 'shield-check' : 'shield-alert' ?>"></i>
                </span>
            </div>
            <div class="kpi-value" style="font-size: 1.55rem;">
                <span class="status-pill <?= $is_active ? 'pill-success' : 'pill-danger' ?>">
                    <?= htmlspecialchars($user['account_status']) ?>
                </span>
            </div>
            <div class="kpi-foot"><?= $is_active ? 'Cleared for exam & unit registration' : 'Action required: settle overdue tab' ?></div>
        </div>
    </div>

    <!-- Self-Service Student Micro-Loan Drawer -->
    <div id="self-loan-box" class="card">
        <div class="card-header">
            <div>
                <h3 style="display: flex; align-items: center; gap: 0.5rem;">
                    <i data-lucide="hand-coins" style="width: 20px; height: 20px; color: #10b981;"></i>
                    Request / Take Student Micro-Loan
                </h3>
                <p class="card-desc">
                    Borrow instantly up to your available credit limit (<strong>KSh <?= number_format($available_credit, 2) ?></strong>). Requests above your limit require Admin limit adjustment.
                </p>
            </div>
            <span class="status-pill <?= $is_active ? 'pill-success' : 'pill-danger' ?>">
                Max Eligible: KSh <?= number_format($available_credit, 2) ?>
            </span>
        </div>

        <form method="POST" action="dashboard.php" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 1rem; align-items: end;">
            <div class="form-group" style="margin-bottom: 0;">
                <label for="facility_id">Campus Facility / Expense Category</label>
                <select id="facility_id" name="facility_id" required>
                    <?php foreach ($facilities as $fac): ?>
                        <option value="<?= (int)$fac['facility_id'] ?>">
                            <?= htmlspecialchars($fac['facility_name']) ?> (<?= htmlspecialchars($fac['category']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin-bottom: 0;">
                <label for="loan_amount">Loan Amount (KSh)</label>
                <input
                    id="loan_amount"
                    type="number"
                    step="0.01"
                    min="1"
                    name="loan_amount"
                    placeholder="e.g. 2500"
                    required
                >
            </div>

            <div class="form-group" style="margin-bottom: 0;">
                <label for="loan_purpose">Note / Purpose (Optional)</label>
                <input
                    id="loan_purpose"
                    type="text"
                    name="loan_purpose"
                    placeholder="e.g. Lunch & Printing"
                >
            </div>

            <div>
                <button type="submit" name="take_student_loan" value="1" class="btn btn-primary" style="width: 100%; padding: 0.78rem 1rem;">
                    <i data-lucide="zap"></i> Apply &amp; Draw Loan
                </button>
            </div>
        </form>

        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-top: 0.85rem;">
            <span style="font-size: 0.8rem; color: var(--text-muted); align-self: center;">Quick amounts:</span>
            <?php foreach ([500, 1000, 2500, 4000] as $preset): ?>
                <button
                    type="button"
                    class="btn btn-ghost"
                    style="padding: 0.25rem 0.65rem; font-size: 0.78rem;"
                    onclick="document.getElementById('loan_amount').value='<?= $preset ?>';"
                >
                    KSh <?= number_format($preset) ?>
                </button>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Credit Utilization Meter Card -->
    <div class="card utilization-card">
        <div class="utilization-header">
            <div>
                <h3>Credit Tab Utilization</h3>
                <p class="card-desc">Keep your balance within limit to avoid automatic CMS holds at semester end.</p>
            </div>
            <span class="utilization-pct"><?= $usage_pct ?>% Used</span>
        </div>
        <div class="progress-track">
            <div class="progress-fill <?= $usage_pct > 85 ? 'danger' : ($usage_pct > 60 ? 'warning' : '') ?>" style="width: <?= $usage_pct ?>%;"></div>
        </div>
        <div class="utilization-meta">
            <span>Spent: <strong>KSh <?= number_format($current_balance, 2) ?></strong></span>
            <span>Limit: <strong>KSh <?= number_format($credit_limit, 2) ?></strong></span>
        </div>
    </div>

    <!-- Recent Loan & POS Transactions -->
    <div class="card">
        <div class="card-header">
            <div>
                <h3>Recent Loan &amp; POS Transactions</h3>
                <p class="card-desc">Live feed of your self-service loan draws and campus facility charges.</p>
            </div>
            <span class="count-badge"><?= count($transactions) ?> Records</span>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Date &amp; Time</th>
                        <th>Facility</th>
                        <th>Amount</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($transactions)): ?>
                        <tr>
                            <td colspan="4" class="empty-state">
                                <i data-lucide="inbox"></i>
                                <span>No transactions recorded yet. Use the form above to take a loan.</span>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($transactions as $tx): ?>
                        <tr>
                            <td class="td-muted"><?= htmlspecialchars($tx['created_at']) ?></td>
                            <td><strong><?= htmlspecialchars($tx['facility_name']) ?></strong></td>
                            <td class="td-num">KSh <?= number_format((float)$tx['amount'], 2) ?></td>
                            <td>
                                <?php $txOk = in_array(strtoupper($tx['status']), ['COMPLETED', 'APPROVED', 'PAID', 'SUCCESS']); ?>
                                <span class="status-pill <?= $txOk ? 'pill-success' : 'pill-danger' ?>">
                                    <?= htmlspecialchars($tx['status']) ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Consolidated Semester Invoices -->
    <?php if (!empty($invoices)): ?>
    <div class="card">
        <div class="card-header">
            <div>
                <h3>Consolidated Semester Invoices</h3>
                <p class="card-desc">Official end-of-semester billing statements synced with Academic Registry.</p>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Semester Code</th>
                        <th>Total Billed</th>
                        <th>CMS Hold</th>
                        <th>Payment Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $inv): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($inv['semester_code'] ?? '2026-SEM1') ?></strong></td>
                        <td class="td-num">KSh <?= number_format((float)($inv['total_amount'] ?? 0), 2) ?></td>
                        <td>
                            <?php $hasHold = !empty($inv['cms_hold_applied']); ?>
                            <span class="status-pill <?= $hasHold ? 'pill-danger' : 'pill-neutral' ?>">
                                <?= $hasHold ? 'APPLIED' : 'NONE' ?>
                            </span>
                        </td>
                        <td>
                            <?php $isPaid = strtoupper($inv['payment_status'] ?? 'UNPAID') === 'PAID'; ?>
                            <span class="status-pill <?= $isPaid ? 'pill-success' : 'pill-warning' ?>">
                                <?= htmlspecialchars($inv['payment_status'] ?? 'UNPAID') ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>