<?php
/**
 * Module 3: Billing & Access Control
 * Author: Lloyd
 * File: modules/billing/batch_invoice.php
 * Description: Production batch invoicing script for campus_smart_loan.
 *              Aggregates unbilled COMPLETED POS transactions per student, generates
 *              cycle-locked invoices, links billed transactions, and logs audit events.
 */

declare(strict_types=1);
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';

const BILLING_GRACE_DAYS = 14;
const BILLING_CURRENCY   = 'KES';
const AUDIT_LOG_PATH     = __DIR__ . '/billing_audit.log';

function logBillingEvent(string $level, string $message): void {
    $line = sprintf("[%s] [%s] %s%s", date('Y-m-d H:i:s'), $level, $message, PHP_EOL);
    file_put_contents(AUDIT_LOG_PATH, $line, FILE_APPEND | LOCK_EX);
}

function buildInvoiceNumber(int $studentId, string $cycle): string {
    return sprintf(
        'INV-%s-%05d-%s',
        $cycle,
        $studentId,
        strtoupper(bin2hex(random_bytes(3)))
    );
}

function processBatchInvoices(PDO $pdo): array {
    $cycle     = date('Ym');
    $now       = date('Y-m-d H:i:s');
    $dueDate   = date('Y-m-d H:i:s', strtotime('+' . BILLING_GRACE_DAYS . ' days'));
    $created   = [];
    $skipped   = 0;
    $totalSum  = 0.0;

    $pdo->beginTransaction();

    try {
        // Lock unbilled completed transactions to prevent race conditions during batch execution
        $fetchStmt = $pdo->query("
            SELECT 
                student_id, 
                SUM(amount) AS total_due, 
                COUNT(id) AS transaction_count
            FROM transactions
            WHERE status = 'COMPLETED' 
              AND is_invoiced = 0
            GROUP BY student_id
            HAVING total_due > 0
            FOR UPDATE
        ");
        $unbilledAccounts = $fetchStmt->fetchAll();

        if (empty($unbilledAccounts)) {
            $pdo->commit();
            return [
                'status'           => 'success',
                'billing_cycle'    => $cycle,
                'invoices_created' => 0,
                'total_billed'     => 0.00,
                'currency'         => BILLING_CURRENCY,
                'message'          => 'No unbilled completed transactions found.'
            ];
        }

        // Prepared statements for cycle duplicate check, invoice creation, and transaction linking
        $checkDuplicateStmt = $pdo->prepare("
            SELECT id 
            FROM invoices 
            WHERE student_id = :student_id 
              AND invoice_number LIKE :cycle_pattern 
            LIMIT 1
        ");

        $insertInvoiceStmt = $pdo->prepare("
            INSERT INTO invoices (
                student_id, 
                invoice_number, 
                amount_due, 
                amount_paid, 
                status, 
                due_date, 
                created_at
            ) VALUES (
                :student_id, 
                :invoice_number, 
                :amount_due, 
                0.00, 
                'UNPAID', 
                :due_date, 
                :created_at
            )
        ");

        $linkTransactionsStmt = $pdo->prepare("
            UPDATE transactions
            SET is_invoiced = 1, 
                invoice_id = :invoice_id
            WHERE student_id = :student_id 
              AND status = 'COMPLETED' 
              AND is_invoiced = 0
        ");

        foreach ($unbilledAccounts as $account) {
            $studentId = (int) $account['student_id'];
            $amountDue = round((float) $account['total_due'], 2);
            $txCount   = (int) $account['transaction_count'];

            // Prevent duplicate invoice within the same billing cycle
            $checkDuplicateStmt->execute([
                ':student_id'    => $studentId,
                ':cycle_pattern' => "INV-{$cycle}-%"
            ]);

            if ($checkDuplicateStmt->fetch()) {
                $skipped++;
                logBillingEvent('SKIPPED', "Student #{$studentId} already has an invoice for cycle {$cycle}.");
                continue;
            }

            $invoiceNumber = buildInvoiceNumber($studentId, $cycle);

            $insertInvoiceStmt->execute([
                ':student_id'     => $studentId,
                ':invoice_number' => $invoiceNumber,
                ':amount_due'     => $amountDue,
                ':due_date'       => $dueDate,
                ':created_at'     => $now
            ]);

            $invoiceId = (int) $pdo->lastInsertId();

            $linkTransactionsStmt->execute([
                ':invoice_id' => $invoiceId,
                ':student_id' => $studentId
            ]);

            $totalSum += $amountDue;
            $created[] = [
                'invoice_id'         => $invoiceId,
                'invoice_number'     => $invoiceNumber,
                'student_id'         => $studentId,
                'transactions_count' => $txCount,
                'amount_due'         => number_format($amountDue, 2, '.', ''),
                'status'             => 'UNPAID',
                'due_date'           => $dueDate
            ];

            logBillingEvent('CREATED', "Invoice {$invoiceNumber} (ID: {$invoiceId}) issued to Student #{$studentId} for {$amountDue} " . BILLING_CURRENCY);
        }

        $pdo->commit();

        return [
            'status'           => 'success',
            'billing_cycle'    => $cycle,
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