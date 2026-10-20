<?php
require_once '../../config/db.php';

// Automated CMS Registration Hold Job
$unpaid = $pdo->query("SELECT i.invoice_id, u.user_id, u.student_id, u.full_name, i.total_amount 
                       FROM semester_invoices i 
                       JOIN users u ON i.user_id = u.user_id 
                       WHERE i.payment_status = 'UNPAID'")->fetchAll();

foreach ($unpaid as $record) {
    // 1. Update system status to HELD
    $pdo->prepare("UPDATE users SET account_status = 'HELD' WHERE user_id = ?")->execute([$record['user_id']]);
    $pdo->prepare("UPDATE semester_invoices SET cms_hold_applied = 1 WHERE invoice_id = ?")->execute([$record['invoice_id']]);

    // 2. Mock CMS REST API Call
    /*
    $ch = curl_init('https://cms.strathmore.edu/api/holds');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['student_id' => $record['student_id'], 'hold' => true]));
    curl_exec($ch);
    */
    
    echo "CMS Registration Hold Applied to Student ID: " . $record['student_id'] . " (Amount Due: KES " . $record['total_amount'] . ")<br>";
}
?>