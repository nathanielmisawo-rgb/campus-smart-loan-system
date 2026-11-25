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

// Initialize variables with default values
$total_students = 0;
$held_students = 0;
$total_debt = 0.00;
$available_credit = 0.00;
$transactions = [];
$invoices = [];

if ($user['role'] === 'ADMIN') {
    $total_students = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'STUDENT'")->fetchColumn();
    $held_students = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'STUDENT' AND account_status != 'ACTIVE'")->fetchColumn();
    $total_debt = (float)($pdo->query("SELECT SUM(current_balance) FROM users WHERE role = 'STUDENT'")->fetchColumn() ?? 0);
} elseif ($user['role'] === 'STUDENT') {
    $available_credit = max(0, (float)$user['credit_limit'] - (float)$user['current_balance']);

    $tx_stmt = $pdo->prepare("
        SELECT t.*, f.facility_name 
        FROM transactions t 
        JOIN facilities f ON t.facility_id = f.facility_id 
        WHERE t.user_id = ? 
        ORDER BY t.created_at DESC
    ");
    $tx_stmt->execute([$_SESSION['user_id']]);
    $transactions = $tx_stmt->fetchAll();

    // Fetch consolidated semester invoices
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
                Monitor campus credit exposure, student ledgers, and automated CMS holds in real time.
            <?php elseif ($user['role'] === 'VENDOR'): ?>
                Ready to process instant student meal and campus facility tab transactions.
            <?php else: ?>
                ID: <strong><?= htmlspecialchars($user['student_id']) ?></strong> &bull; <?= htmlspecialchars($user['grade'] ?? 'Student Account') ?>
            <?php endif; ?>
        </p>
    </div>

    <div class="hero-actions">
        <?php if ($user['role'] === 'ADMIN'): ?>
            <a href="student_register.php" class="btn btn-primary">
                <i data-lucide="user-plus"></i> Register Student
            </a>
            <a href="student_view.php" class="btn btn-ghost">
                <i data-lucide="users"></i> Student Ledger
            </a>
        <?php elseif ($user['role'] === 'VENDOR'): ?>
            <a href="../pos/checkout.php" class="btn btn-primary">
                <i data-lucide="scan-line"></i> Launch POS Terminal
            </a>
        <?php else: ?>
            <a href="../billing/pay_mpesa.php" class="btn btn-primary">
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
                <span class="kpi-label">Registered Credit Accounts</span>
                <span class="kpi-icon cyan"><i data-lucide="users"></i></span>
            </div>
            <div class="kpi-value"><?= number_format($total_students) ?></div>
            <div class="kpi-foot">Active student credit profiles</div>
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
                <p class="card-desc">Manage student credit limits, review facility transactions, or trigger automated registration holds.</p>
            </div>
        </div>
        <div class="action-bar">
            <a href="student_register.php" class="btn btn-primary">
                <i data-lucide="user-plus"></i> Register Student Credit Tab Profile
            </a>
            <a href="student_view.php" class="btn btn-ghost">
                <i data-lucide="book-open"></i> View &amp; Edit Student Ledger
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

<?php else: ?>
    <?php
        $credit_limit = (float)$user['credit_limit'];
        $current_balance = (float)$user['current_balance'];
        $usage_pct = $credit_limit > 0 ? min(100, round(($current_balance / $credit_limit) * 100, 1)) : 0;
        $is_active = ($user['account_status'] === 'ACTIVE');
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

    <!-- Recent POS Transactions -->
    <div class="card">
        <div class="card-header">
            <div>
                <h3>Recent POS Transactions</h3>
                <p class="card-desc">Live feed of your campus cafeteria, bookstore, and facility tab charges.</p>
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
                                <span>No POS transactions recorded yet.</span>
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
                                <span class="status-pill <?= $txOk ? 'pill-success' : 'pill-warning' ?>">
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