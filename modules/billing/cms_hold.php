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

<div class="card" style="max-width: 1080px; margin: 0 auto;">
    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid rgba(148, 163, 184, 0.14); padding-bottom: 1.25rem; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h2 style="display: flex; align-items: center; gap: 0.6rem; margin: 0 0 0.25rem; font-size: 1.35rem;">
                <i data-lucide="shield-alert" style="width: 24px; height: 24px; color: var(--danger);"></i>
                CMS Registration Hold Console
            </h2>
            <p style="margin: 0; font-size: 0.88rem; color: var(--text-secondary);">
                Enforce registration holds on overdue accounts or restore access after M-Pesa settlement.
            </p>
        </div>
        <a href="../auth/dashboard.php" class="btn btn-outline" style="padding: 0.5rem 0.9rem; font-size: 0.85rem;">
            <i data-lucide="arrow-left" style="width: 15px; height: 15px;"></i>
            Back to Dashboard
        </a>
    </div>

    <?php if ($flashMessage !== null): ?>
        <div class="alert <?= $flashType === 'error' ? 'alert-error' : 'alert-success' ?>">
            <i data-lucide="<?= $flashType === 'error' ? 'alert-circle' : 'check-circle-2' ?>" style="width: 18px; height: 18px; flex-shrink: 0;"></i>
            <span><?= htmlspecialchars($flashMessage) ?></span>
        </div>
    <?php endif; ?>

    <div style="display: flex; gap: 0.75rem; margin-bottom: 1.5rem; flex-wrap: wrap;">
        <form method="POST" style="display:inline;">
            <input type="hidden" name="stay_on_page" value="1">
            <button type="submit" name="trigger_hold" class="btn btn-danger"
                    onclick="return confirm('Enforce CMS holds on all overdue student accounts?');">
                <i data-lucide="lock" style="width: 16px; height: 16px;"></i>
                Enforce CMS Holds on Overdue Accounts
            </button>
        </form>

        <form method="POST" style="display:inline;">
            <input type="hidden" name="stay_on_page" value="1">
            <button type="submit" name="release_hold" class="btn btn-primary">
                <i data-lucide="unlock" style="width: 16px; height: 16px;"></i>
                Clear Holds for All Paid Accounts
            </button>
        </form>
    </div>

    <div class="table-wrap">
        <table>
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
                        <td colspan="6" style="text-align: center; padding: 2rem; color: var(--text-muted);">
                            No held or overdue student accounts found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($students as $s): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($s['student_id']) ?></strong></td>
                            <td>
                                <?= htmlspecialchars($s['full_name']) ?>
                                <span style="display: block; font-size: 0.76rem; color: var(--text-muted);">
                                    <?= htmlspecialchars($s['email']) ?>
                                </span>
                            </td>
                            <td class="tabular-nums" style="font-weight: 700;">
                                KSh <?= number_format((float) $s['current_balance'], 2) ?>
                            </td>
                            <td class="tabular-nums"><?= (int) $s['open_invoices'] ?></td>
                            <td>
                                <span class="status-pill <?= $s['account_status'] === 'HELD' ? 'status-held' : 'status-active' ?>">
                                    <?= htmlspecialchars($s['account_status']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($s['account_status'] === 'HELD' && (float) $s['current_balance'] <= 0 && (int) $s['open_invoices'] === 0): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="stay_on_page" value="1">
                                        <input type="hidden" name="user_id" value="<?= (int) $s['user_id'] ?>">
                                        <button type="submit" name="release_hold" class="btn btn-primary" style="padding: 0.35rem 0.75rem; font-size: 0.8rem;">
                                            Release Hold
                                        </button>
                                    </form>
                                <?php elseif ($s['account_status'] === 'HELD'): ?>
                                    <span style="color: var(--danger); font-size: 0.82rem; font-weight: 500;">
                                        Awaiting M-Pesa settlement
                                    </span>
                                <?php else: ?>
                                    <span style="color: var(--text-muted); font-size: 0.82rem;">
                                        Pending hold sweep
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>