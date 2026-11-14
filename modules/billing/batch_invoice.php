<?php
/**
 * Module 3: Billing & Access Control
 * Author: Lloyd
 * File: modules/billing/batch_invoice.php
 * Description: Production batch invoicing script for campus_smart_loan.
 *              Aggregates APPROVED POS transactions per student, generates
 *              cycle-locked semester invoices, and logs audit events.
 */

declare(strict_types=1);
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';

const BILLING_CURRENCY = 'KES';
const AUDIT_LOG_PATH   = __DIR__ . '/billing_audit.log';

function logBillingEvent(string $level, string $message): void {
    $line = sprintf("[%s] [%s] %s%s", date('Y-m-d H:i:s'), $level, $message, PHP_EOL);
    file_put_contents(AUDIT_LOG_PATH, $line, FILE_APPEND | LOCK_EX);
}

function getSemesterCode(): string {
    $month = (int) date('n');
    $sem = ($month <= 6) ? 'SEM1' : 'SEM2';
    return date('Y') . '-' . $sem;
}

function processBatchInvoices(PDO $pdo): array {
    $semesterCode = getSemesterCode();
    $created      = [];
    $skipped      = 0;
    $totalSum     = 0.0;

    $pdo->beginTransaction();

    try {
        // Lock approved transactions per student to prevent race conditions during batch execution
        $fetchStmt = $pdo->query("
            SELECT 
                user_id, 
                SUM(amount) AS total_due, 
                COUNT(transaction_id) AS transaction_count
            FROM transactions
            WHERE status = 'APPROVED'
            GROUP BY user_id
            HAVING total_due > 0
            FOR UPDATE
        ");
        $unbilledAccounts = $fetchStmt->fetchAll();

        if (empty($unbilledAccounts)) {
            $pdo->commit();
            return [
                'status'           => 'success',
                'semester_code'    => $semesterCode,
                'invoices_created' => 0,
                'total_billed'     => '0.00',
                'currency'         => BILLING_CURRENCY,
                'message'          => 'No approved student transactions found for billing.'
            ];
        }

        // Prepared statements aligned with semester_invoices schema
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
                payment_status
            ) VALUES (
                :user_id, 
                :semester_code, 
                :total_amount, 
                'UNPAID'
            )
        ");

        foreach ($unbilledAccounts as $account) {
            $userId    = (int) $account['user_id'];
            $amountDue = round((float) $account['total_due'], 2);
            $txCount   = (int) $account['transaction_count'];

            // Prevent duplicate invoice within the same semester cycle
            $checkDuplicateStmt->execute([
                ':user_id'       => $userId,
                ':semester_code' => $semesterCode
            ]);

            if ($checkDuplicateStmt->fetch()) {
                $skipped++;
                logBillingEvent('SKIPPED', "User #{$userId} already has an invoice for cycle {$semesterCode}.");
                continue;
            }

            $insertInvoiceStmt->execute([
                ':user_id'       => $userId,
                ':semester_code' => $semesterCode,
                ':total_amount'  => $amountDue
            ]);

            $invoiceId = (int) $pdo->lastInsertId();

            $totalSum += $amountDue;
            $created[] = [
                'invoice_id'         => $invoiceId,
                'semester_code'      => $semesterCode,
                'user_id'            => $userId,
                'transactions_count' => $txCount,
                'total_amount'       => number_format($amountDue, 2, '.', ''),
                'payment_status'     => 'UNPAID'
            ];

            logBillingEvent('CREATED', "Invoice #{$invoiceId} ({$semesterCode}) issued to User #{$userId} for {$amountDue} " . BILLING_CURRENCY);
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