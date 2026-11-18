<?php
/**
 * Module 3: Billing & Access Control
 * Author: Lloyd
 * File: modules/billing/batch_invoice.php
 * Description: Admin-protected batch invoicing script for campus_smart_loan.
 *              Aligned with official database.sql (users, transactions, semester_invoices).
 *              Bills authoritative users.current_balance, prevents duplicate semester invoices,
 *              applies CMS holds, and logs audit events.
 */

declare(strict_types=1);
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';

// 1. Access Control: Restrict batch invoicing to ADMIN accounts (or CLI cron execution)
if (PHP_SAPI !== 'cli') {
    if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'ADMIN') {
        http_response_code(403);
        echo json_encode([
            'status'  => 'error',
            'message' => 'Unauthorized: Administrator privileges required to run batch invoicing.'
        ], JSON_PRETTY_PRINT);
        exit;
    }
}

const BILLING_CURRENCY = 'KES';
const AUDIT_LOG_PATH   = __DIR__ . '/billing_audit.log';

function logBillingEvent(string $level, string $message): void {
    $line = sprintf("[%s] [%s] %s%s", date('Y-m-d H:i:s'), $level, $message, PHP_EOL);
    file_put_contents(AUDIT_LOG_PATH, $line, FILE_APPEND | LOCK_EX);
}

function getCurrentSemesterCode(): string {
    $requested = trim((string) ($_REQUEST['semester_code'] ?? ''));
    if ($requested !== '') {
        return substr($requested, 0, 20);
    }
    $term = ((int) date('n') <= 6) ? 'S1' : 'S2';
    return date('Y') . '-' . $term;
}

function processBatchInvoices(PDO $pdo): array {
    $semesterCode = getCurrentSemesterCode();
    $now          = date('Y-m-d H:i:s');
    $created      = [];
    $skipped      = 0;
    $totalSum     = 0.0;

    $pdo->beginTransaction();

    try {
        // Lock student accounts with an authoritative outstanding balance > 0
        $fetchStmt = $pdo->query("
            SELECT 
                u.user_id,
                u.student_id,
                u.full_name,
                u.current_balance,
                COUNT(t.transaction_id) AS transaction_count
            FROM users u
            LEFT JOIN transactions t 
                ON t.user_id = u.user_id 
               AND t.status = 'APPROVED'
            WHERE u.role = 'STUDENT'
              AND u.current_balance > 0
            GROUP BY u.user_id, u.student_id, u.full_name, u.current_balance
            FOR UPDATE
        ");
        $billableStudents = $fetchStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($billableStudents)) {
            $pdo->commit();
            return [
                'status'           => 'success',
                'semester_code'    => $semesterCode,
                'invoices_created' => 0,
                'accounts_skipped' => 0,
                'total_billed'     => '0.00',
                'currency'         => BILLING_CURRENCY,
                'message'          => 'No student accounts with an outstanding balance found.'
            ];
        }

        $checkDuplicateStmt = $pdo->prepare("
            SELECT invoice_id 
            FROM semester_invoices 
            WHERE user_id = :user_id 
              AND semester_code = :semester_code 
            LIMIT 1
        ");

        $insertInvoiceStmt = $pdo->prepare("
            INSERT INTO semester_invoices (
                user_id, 
                semester_code, 
                total_amount, 
                payment_status, 
                cms_hold_applied, 
                created_at
            ) VALUES (
                :user_id, 
                :semester_code, 
                :total_amount, 
                'UNPAID', 
                1, 
                :created_at
            )
        ");

        $applyUserHoldStmt = $pdo->prepare("
            UPDATE users 
            SET account_status = 'HELD' 
            WHERE user_id = :user_id 
              AND account_status = 'ACTIVE'
        ");

        foreach ($billableStudents as $student) {
            $userId         = (int) $student['user_id'];
            $admissionNo    = (string) $student['student_id'];
            $fullName       = (string) $student['full_name'];
            $currentBalance = round((float) $student['current_balance'], 2);
            $txCount        = (int) $student['transaction_count'];

            $checkDuplicateStmt->execute([
                ':user_id'       => $userId,
                ':semester_code' => $semesterCode
            ]);

            if ($checkDuplicateStmt->fetch()) {
                $skipped++;
                logBillingEvent('SKIPPED', "User #{$userId} ({$admissionNo}) already has an invoice for {$semesterCode}.");
                continue;
            }

            $insertInvoiceStmt->execute([
                ':user_id'       => $userId,
                ':semester_code' => $semesterCode,
                ':total_amount'  => $currentBalance,
                ':created_at'    => $now
            ]);

            $invoiceId = (int) $pdo->lastInsertId();
            $applyUserHoldStmt->execute([':user_id' => $userId]);

            $totalSum += $currentBalance;
            $created[] = [
                'invoice_id'         => $invoiceId,
                'user_id'            => $userId,
                'student_id'         => $admissionNo,
                'full_name'          => $fullName,
                'semester_code'      => $semesterCode,
                'transactions_count' => $txCount,
                'total_amount'       => number_format($currentBalance, 2, '.', ''),
                'payment_status'     => 'UNPAID',
                'cms_hold_applied'   => 1
            ];

            logBillingEvent(
                'CREATED',
                "Invoice #{$invoiceId} ({$semesterCode}) issued to {$fullName} [User #{$userId} / {$admissionNo}] for {$currentBalance} " . BILLING_CURRENCY . " (CMS Hold Applied)."
            );
        }

        $pdo->commit();

        return [
            'status'           => 'success',
            'semester_code'    => $semesterCode,
            'invoices_created' => count($created),
            'accounts_skipped' => $skipped,
            'total_billed'     => number_format($totalSum, 2, '.', ''),
            'currency'         => BILLING_CURRENCY,
            'invoices'         => $created
        ];

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        logBillingEvent('ERROR', 'Batch billing transaction rolled back: ' . $e->getMessage());
        http_response_code(500);

        return [
            'status'  => 'error',
            'message' => 'Batch invoicing failed: ' . $e->getMessage()
        ];
    }
}

echo json_encode(processBatchInvoices($pdo), JSON_PRETTY_PRINT);