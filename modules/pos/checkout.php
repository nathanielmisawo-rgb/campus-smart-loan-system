<?php
// modules/pos/checkout.php  [Module 2 - POS interface]
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'VENDOR' && $_SESSION['role'] !== 'ADMIN')) {
    header("Location: ../auth/login.php");
    exit;
}

$facilities = $pdo->query("SELECT facility_id, facility_name, category FROM facilities ORDER BY facility_name")->fetchAll();

// Last 10 transactions across all facilities (the table has no operator column yet)
$recent = $pdo->query(
    "SELECT t.created_at, u.student_id, f.facility_name, t.amount, t.status
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

<div style="max-width: 450px; margin: 0 auto;">
    <div class="card">
        <h3>POS Micro-Loan Terminal</h3>

        <?php if ($flash): ?>
            <p role="alert"><?= htmlspecialchars($flash['message']) ?></p>
        <?php endif; ?>

        <form action="process_checkout.php" method="POST" autocomplete="off">
            <div class="form-group">
                <label>Facility Endpoint</label>
                <select name="facility_id" required>
                    <?php foreach ($facilities as $fac): ?>
                        <option value="<?= (int)$fac['facility_id'] ?>">
                            <?= htmlspecialchars($fac['facility_name']) ?> (<?= htmlspecialchars($fac['category']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Student ID (Scan ID / QR)</label>
                <input type="text" name="student_id" required placeholder="e.g. 100001" autofocus>
            </div>
            <div class="form-group">
                <label>Amount (KSh)</label>
                <input type="number" step="0.01" min="0.01" name="amount" required placeholder="0.00">
            </div>
            <button type="submit" class="btn" style="width: 100%;">Authorize Micro-Loan</button>
        </form>
    </div>

    <div class="card">
        <h3>Recent transactions</h3>
        <?php if (!$recent): ?>
            <p>No transactions yet. Approved and declined charges will appear here.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr><th>Time</th><th>Student</th><th>Facility</th><th>KSh</th><th>Result</th></tr>
                </thead>
                <tbody>
                <?php foreach ($recent as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars($r['created_at']) ?></td>
                        <td><?= htmlspecialchars($r['student_id']) ?></td>
                        <td><?= htmlspecialchars($r['facility_name']) ?></td>
                        <td><?= number_format((float)$r['amount'], 2) ?></td>
                        <td><?= $r['status'] === 'APPROVED' ? 'Approved' : 'Declined' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
