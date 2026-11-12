<?php


header('Content-Type: application/json');


function createInvoiceNumber(int $studentId): string {
    $period = date('Ym');
    $paddedId = str_pad((string)$studentId, 4, '0', STR_PAD_LEFT);
    $suffix = random_int(100, 999);
    return "INV-{$period}-{$paddedId}-{$suffix}";
}

function buildBatchInvoices(array $unbilledRecords, int $graceDays = 14): array {
    $invoices = [];
    $dueDate = date('Y-m-d H:i:s', strtotime("+{$graceDays} days"));
    $createdAt = date('Y-m-d H:i:s');

    foreach ($unbilledRecords as $record) {
        if ($record['total_due'] <= 0) {
            continue;
        }

        $invoices[] = [
            'student_id'     => $record['student_id'],
            'invoice_number' => createInvoiceNumber($record['student_id']),
            'amount_due'     => number_format((float)$record['total_due'], 2, '.', ''),
            'status'         => 'UNPAID',
            'due_date'       => $dueDate,
            'created_at'     => $createdAt
        ];
    }

    return $invoices;
}


$sampleUnbilled = [
    ['student_id' => 101, 'total_due' => 1450.00],
    ['student_id' => 102, 'total_due' => 820.50],
    ['student_id' => 103, 'total_due' => 0.00] // Ignored automatically
];

$generatedInvoices = buildBatchInvoices($sampleUnbilled);

echo json_encode([
    'module'           => 'Module 3 - Billing & Access Control',
    'script'           => 'batch_invoice.php',
    'status'           => 'success',
    'invoices_created' => count($generatedInvoices),
    'data'             => $generatedInvoices
], JSON_PRETTY_PRINT);