<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'ADMIN') {
    header("Location: ../auth/login.php");
    exit;
}

if (isset($_POST['trigger_hold'])) {
    $stmt = $pdo->prepare("UPDATE users SET account_status = 'HELD' WHERE current_balance > 0 AND role = 'STUDENT'");
    $stmt->execute();
    $affected = $stmt->rowCount();

    echo "<script>alert('CMS Hold enforced on {$affected} overdue account(s).'); window.location.href='../auth/dashboard.php';</script>";
}
?>