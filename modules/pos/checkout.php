<?php
// modules/pos/checkout.php  [Module 2 - POS interface]
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'VENDOR' && $_SESSION['role'] !== 'ADMIN')) {
    header("Location: ../auth/login.php");
    exit;
}

$facilities = $pdo->query("SELECT facility_id, facility_name, category FROM facilities ORDER BY facility_name")->fetchAll();

// Last 10 transactions across all facilities
$recent = $pdo->query(
    "SELECT t.created_at, u.student_id, u.full_name, f.facility_name, t.amount, t.status
       FROM transactions t
       JOIN users u      ON u.user_id = t.user_id
       JOIN facilities f ON f.facility_id = t.facility_id
      ORDER BY t.created_at DESC
      LIMIT 10"
)->fetchAll();

// Message left by process_checkout.php: ['type' => 'success'|'error', 'message' => '...']
$flash = $_SESSION['pos_flash'] ?? null;
unset($_SESSION['pos_flash']);

require_once '../../includes/header.php';
?>

<div class="dashboard-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.75rem;">
    <div>
        <h2 style="display: flex; align-items: center; gap: 0.6rem; margin-bottom: 0.25rem;">
            <i data-lucide="scan-line" style="width: 24px; height: 24px; color: var(--neon-cyan);"></i>
            POS Micro-Loan Terminal
        </h2>
        <p style="margin: 0; color: var(--text-secondary); font-size: 0.9rem;">
            Real-time student tab authorization and campus vendor ledger.
        </p>
    </div>
    <span class="status-pill status-active">
        <i data-lucide="wifi" style="width: 13px; height: 13px;"></i>
        Terminal Online
    </span>
</div>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 1.5rem; align-items: start;">
    <!-- Left Column: Authorization Terminal -->
    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
            <h3 style="margin: 0; font-size: 1.1rem;">Charge Student Tab</h3>
            <span style="font-size: 0.75rem; color: var(--text-muted); font-family: monospace;">ISO-8583 / CampusTab</span>
        </div>

        <?php if ($flash): ?>
            <div class="alert <?= ($flash['type'] ?? '') === 'success' ? 'alert-success' : 'alert-error' ?>" role="alert">
                <i data-lucide="<?= ($flash['type'] ?? '') === 'success' ? 'check-circle-2' : 'x-circle' ?>" style="width: 18px; height: 18px; flex-shrink: 0;"></i>
                <span><?= htmlspecialchars($flash['message']) ?></span>
            </div>
        <?php endif; ?>

        <form action="process_checkout.php" method="POST" autocomplete="off">
            <div class="form-group">
                <label for="facility_id">Facility Endpoint</label>
                <select id="facility_id" name="facility_id" required>
                    <?php foreach ($facilities as $fac): ?>
                        <option value="<?= (int)$fac['facility_id'] ?>">
                            <?= htmlspecialchars($fac['facility_name']) ?> (<?= htmlspecialchars($fac['category']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="student_id">Student ID (Scan ID / QR)</label>
                <input
                    id="student_id"
                    type="text"
                    name="student_id"
                    required
                    placeholder="e.g. 100001 or 100002"
                    autofocus
                >
            </div>

            <div class="form-group">
                <label for="pos_amount">Amount (KSh)</label>
                <input
                    id="pos_amount"
                    type="number"
                    step="0.01"
                    min="0.01"
                    name="amount"
                    required
                    placeholder="0.00"
                    class="tabular-nums"
                    style="font-size: 1.15rem; font-weight: 700;"
                >
                <div style="display: flex; gap: 0.45rem; margin-top: 0.6rem; flex-wrap: wrap;">
                    <?php foreach ([50, 100, 250, 500] as $preset): ?>
                        <button
                            type="button"
                            class="btn btn-outline"
                            style="padding: 0.3rem 0.65rem; font-size: 0.78rem;"
                            onclick="document.getElementById('pos_amount').value='<?= $preset ?>';"
                        >
                            +<?= $preset ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 0.5rem;">
                <i data-lucide="zap" style="width: 17px; height: 17px;"></i>
                Authorize Micro-Loan
            </button>
        </form>
    </div>

    <!-- Right Column: Live Transaction Feed -->
    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
            <h3 style="margin: 0; font-size: 1.1rem;">Recent Authorizations</h3>
            <span style="font-size: 0.8rem; color: var(--text-muted);">Last 10 charges</span>
        </div>

        <?php if (!$recent): ?>
            <div style="text-align: center; padding: 2rem 1rem; color: var(--text-muted);">
                <i data-lucide="receipt" style="width: 32px; height: 32px; margin-bottom: 0.5rem; opacity: 0.6;"></i>
                <p style="margin: 0;">No transactions yet. Approved and declined charges will appear here.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Student</th>
                            <th>Facility</th>
                            <th>Amount</th>
                            <th>Result</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recent as $r): ?>
                        <tr>
                            <td style="font-size: 0.82rem; color: var(--text-muted);">
                                <?= htmlspecialchars(date('H:i · M d', strtotime($r['created_at']))) ?>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($r['student_id']) ?></strong>
                                <span style="display: block; font-size: 0.76rem; color: var(--text-muted);">
                                    <?= htmlspecialchars($r['full_name']) ?>
                                </span>
                            </td>
                            <td style="font-size: 0.86rem;"><?= htmlspecialchars($r['facility_name']) ?></td>
                            <td class="tabular-nums" style="font-weight: 700;">
                                KSh <?= number_format((float)$r['amount'], 2) ?>
                            </td>
                            <td>
                                <span class="status-pill <?= $r['status'] === 'APPROVED' ? 'status-active' : 'status-held' ?>">
                                    <?= $r['status'] === 'APPROVED' ? 'Approved' : 'Declined' ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>