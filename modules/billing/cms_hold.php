<?php

declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../config/db.php';

// Enforce Admin-only access before outputting any HTML
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'ADMIN') {
    header('Location: ../auth/login.php');
    exit;
}

const HOLD_AUDIT_LOG = __DIR__ . '/cms_hold_audit.log';

function logHoldEvent(string $action, string $details): void {
    $entry = sprintf(
        "[%s] [ADMIN:%s] [%s] %s%s",
        date('Y-m-d H:i:s'),
        $_SESSION['student_id'] ?? $_SESSION['user_id'] ?? 'UNKNOWN',
        $action,
        $details,
        PHP_EOL
    );
    file_put_contents(HOLD_AUDIT_LOG, $entry, FILE_APPEND | LOCK_EX);
}


function enforceCmsHolds(PDO $pdo, ?string $semesterCode = null): array {
    $pdo->beginTransaction();

    try {
        if ($semesterCode !== null && $semesterCode !== '') {
            $invStmt = $pdo->prepare("
                UPDATE semester_invoices
                SET payment_status = 'OVERDUE',
                    cms_hold_applied = 1
                WHERE payment_status IN ('UNPAID', 'OVERDUE')
                  AND semester_code = :semester_code
            ");
            $invStmt->execute([':semester_code' => $semesterCode]);
        } else {
            $invStmt = $pdo->query("
                UPDATE semester_invoices
                SET payment_status = 'OVERDUE',
                    cms_hold_applied = 1
                WHERE payment_status IN ('UNPAID', 'OVERDUE')
            ");
        }
        $invoicesFlagged = $invStmt->rowCount();

        $userStmt = $pdo->prepare("
            UPDATE users u
            SET u.account_status = 'HELD'
            WHERE u.role = 'STUDENT'
              AND u.account_status = 'ACTIVE'
              AND (
                  u.current_balance > 0
                  OR EXISTS (
                      SELECT 1
                      FROM semester_invoices si
                      WHERE si.user_id = u.user_id
                        AND si.payment_status IN ('UNPAID', 'OVERDUE')
                  )
              )
        ");
        $userStmt->execute();
        $accountsHeld = $userStmt->rowCount();

        $pdo->commit();

        logHoldEvent(
            'ENFORCE_HOLD',
            "Accounts held: {$accountsHeld}, Invoices marked OVERDUE: {$invoicesFlagged}"
        );

        return [
            'accounts_held'    => $accountsHeld,
            'invoices_flagged' => $invoicesFlagged
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        logHoldEvent('ERROR', 'Failed to enforce CMS holds: ' . $e->getMessage());
        throw $e;
    }
}


function releaseClearedHolds(PDO $pdo, ?int $targetUserId = null): array {
    $pdo->beginTransaction();

    try {
        $clearInvSql = "
            UPDATE semester_invoices
            SET cms_hold_applied = 0
            WHERE payment_status = 'PAID'
              AND cms_hold_applied = 1
        ";
        if ($targetUserId !== null) {
            $clearInvSql .= " AND user_id = :user_id";
            $invStmt = $pdo->prepare($clearInvSql);
            $invStmt->execute([':user_id' => $targetUserId]);
        } else {
            $invStmt = $pdo->query($clearInvSql);
        }
        $invoicesCleared = $invStmt->rowCount();

        $releaseUserSql = "
            UPDATE users u
            SET u.account_status = 'ACTIVE'
            WHERE u.role = 'STUDENT'
              AND u.account_status = 'HELD'
              AND u.current_balance <= 0
              AND NOT EXISTS (
                  SELECT 1
                  FROM semester_invoices si
                  WHERE si.user_id = u.user_id
                    AND si.payment_status IN ('UNPAID', 'OVERDUE')
              )
        ";
        if ($targetUserId !== null) {
            $releaseUserSql .= " AND u.user_id = :user_id";
            $userStmt = $pdo->prepare($releaseUserSql);
            $userStmt->execute([':user_id' => $targetUserId]);
        } else {
            $userStmt = $pdo->prepare($releaseUserSql);
            $userStmt->execute();
        }
        $accountsReleased = $userStmt->rowCount();

        $pdo->commit();

        $scope = $targetUserId !== null ? "User ID #{$targetUserId}" : "All eligible accounts";
        logHoldEvent(
            'RELEASE_HOLD',
            "Scope: {$scope} | Accounts restored to ACTIVE: {$accountsReleased}, Paid invoices cleared: {$invoicesCleared}"
        );

        return [
            'accounts_released' => $accountsReleased,
            'invoices_cleared'  => $invoicesCleared
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        logHoldEvent('ERROR', 'Failed to release CMS holds: ' . $e->getMessage());
        throw $e;
    }
}


$flashMessage = null;
$flashType    = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['trigger_hold'])) {
            $semesterFilter = isset($_POST['semester_code']) ? trim($_POST['semester_code']) : null;
            $result = enforceCmsHolds($pdo, $semesterFilter);
            $msg = "CMS Hold enforced on {$result['accounts_held']} overdue account(s) ({$result['invoices_flagged']} invoice(s) flagged OVERDUE).";

            if (!isset($_POST['stay_on_page'])) {
                echo "<script>alert(" . json_encode($msg) . "); window.location.href='../auth/dashboard.php';</script>";
                exit;
            }
            $flashMessage = $msg;

        } elseif (isset($_POST['release_hold'])) {
            $targetUserId = isset($_POST['user_id']) && $_POST['user_id'] !== '' ? (int) $_POST['user_id'] : null;
            $result = releaseClearedHolds($pdo, $targetUserId);
            $msg = "CMS Hold cleared for {$result['accounts_released']} paid account(s).";

            if (!isset($_POST['stay_on_page'])) {
                echo "<script>alert(" . json_encode($msg) . "); window.location.href='../auth/dashboard.php';</script>";
                exit;
            }
            $flashMessage = $msg;
        }
    } catch (Throwable $e) {
        $flashType = 'error';
        $flashMessage = 'Operation failed: ' . $e->getMessage();
    }
}


$heldStudentsStmt = $pdo->query("
    SELECT 
        u.user_id,
        u.student_id,
        u.full_name,
        u.email,
        u.current_balance,
        u.account_status,
        COUNT(CASE WHEN si.payment_status IN ('UNPAID', 'OVERDUE') THEN 1 END) AS open_invoices,
        COALESCE(SUM(CASE WHEN si.payment_status IN ('UNPAID', 'OVERDUE') THEN si.total_amount ELSE 0 END), 0) AS overdue_total
    FROM users u
    LEFT JOIN semester_invoices si ON si.user_id = u.user_id
    WHERE u.role = 'STUDENT'
      AND (u.account_status = 'HELD' OR u.current_balance > 0)
    GROUP BY u.user_id, u.student_id, u.full_name, u.email, u.current_balance, u.account_status
    ORDER BY u.account_status DESC, u.current_balance DESC
");
$students = $heldStudentsStmt->fetchAll();


require_once __DIR__ . '/../../includes/header.php';
?>

<style>
    .cms-card { background: var(--surface, #ffffff); padding: 24px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.06); margin: 20px auto; max-width: 1050px; }
    .cms-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border, #e5e7eb); padding-bottom: 16px; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
    .cms-header h2 { margin: 0; font-size: 1.35rem; }
    .cms-actions { display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
    .cms-btn { padding: 10px 16px; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 0.9rem; text-decoration: none; display: inline-block; transition: opacity 0.15s ease; }
    .cms-btn:hover { opacity: 0.9; }
    .cms-btn-danger { background: #dc2626; color: #fff; }
    .cms-btn-success { background: #16a34a; color: #fff; }
    .cms-btn-secondary { background: #4b5563; color: #fff; }
    .cms-btn-sm { padding: 6px 10px; font-size: 0.8rem; }
    .cms-alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; font-size: 0.92rem; }
    .cms-alert-success { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
    .cms-alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
    .cms-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    .cms-table th, .cms-table td { padding: 12px; text-align: left; border-bottom: 1px solid var(--border, #e5e7eb); font-size: 0.92rem; }
    .cms-table th { background: rgba(0, 0, 0, 0.03); font-weight: 600; }
    .cms-badge { padding: 4px 10px; border-radius: 999px; font-size: 0.78rem; font-weight: 700; display: inline-block; }
    .cms-badge-held { background: #fee2e2; color: #991b1b; }
    .cms-badge-active { background: #dcfce7; color: #166534; }
</style>

<div class="cms-card">
    <div class="cms-header">
        <div>
            <h2>Module 3: CMS Registration Hold Console</h2>
            <p style="margin: 4px 0 0; font-size: 0.88rem; color: var(--text-muted, #6b7280);">
                Enforce registration holds on overdue accounts or restore access after M-Pesa settlement.
            </p>
        </div>
        <a href="../auth/dashboard.php" class="cms-btn cms-btn-secondary cms-btn-sm">&larr; Back to Dashboard</a>
    </div>

    <?php if ($flashMessage !== null): ?>
        <div class="cms-alert <?= $flashType === 'error' ? 'cms-alert-error' : 'cms-alert-success' ?>">
            <?= htmlspecialchars($flashMessage) ?>
        </div>
    <?php endif; ?>

    <div class="cms-actions">
        <form method="POST" style="display:inline;">
            <input type="hidden" name="stay_on_page" value="1">
            <button type="submit" name="trigger_hold" class="cms-btn cms-btn-danger"
                    onclick="return confirm('Enforce CMS holds on all overdue student accounts?');">
                Enforce CMS Holds on Overdue Accounts
            </button>
        </form>

        <form method="POST" style="display:inline;">
            <input type="hidden" name="stay_on_page" value="1">
            <button type="submit" name="release_hold" class="cms-btn cms-btn-success">
                Clear Holds for All Paid Accounts
            </button>
        </form>
    </div>

    <table class="cms-table">
        <thead>
            <tr>
                <th>Student ID</th>
                <th>Full Name</th>
                <th>Current Balance (KES)</th>
                <th>Open Invoices</th>
                <th>Account Status</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($students)): ?>
                <tr>
                    <td colspan="6" style="text-align:center; color: var(--text-muted, #6b7280);">
                        No held or overdue student accounts found.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($students as $s): ?>
                    <tr>
                        <td><?= htmlspecialchars($s['student_id']) ?></td>
                        <td><?= htmlspecialchars($s['full_name']) ?></td>
                        <td><?= number_format((float) $s['current_balance'], 2) ?></td>
                        <td><?= (int) $s['open_invoices'] ?></td>
                        <td>
                            <span class="cms-badge <?= $s['account_status'] === 'HELD' ? 'cms-badge-held' : 'cms-badge-active' ?>">
                                <?= htmlspecialchars($s['account_status']) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($s['account_status'] === 'HELD' && (float) $s['current_balance'] <= 0 && (int) $s['open_invoices'] === 0): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="stay_on_page" value="1">
                                    <input type="hidden" name="user_id" value="<?= (int) $s['user_id'] ?>">
                                    <button type="submit" name="release_hold" class="cms-btn cms-btn-success cms-btn-sm">Release Hold</button>
                                </form>
                            <?php elseif ($s['account_status'] === 'HELD'): ?>
                                <span style="color:#991b1b; font-size:0.82rem;">Awaiting M-Pesa settlement</span>
                            <?php else: ?>
                                <span style="color: var(--text-muted, #6b7280); font-size:0.82rem;">Pending hold sweep</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php

require_once __DIR__ . '/../../includes/footer.php';
?>