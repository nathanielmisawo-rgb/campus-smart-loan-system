<?php
/**
 * Module 1: Authentication & User Management
 * Author: Misawo Nathaniel
 * File: modules/auth/sso_callback.php
 * Description: SSO Service Provider (SP) Callback Handler.
 *              Validates signed IdP assertion token, initializes secure PHP session,
 *              logs authentication audit events, and routes users by role.
 */

declare(strict_types=1);
session_start();

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/sso_auth.php';

const SSO_AUDIT_LOG = __DIR__ . '/sso_audit.log';

function logSsoEvent(string $status, string $identity, string $details): void
{
    $ip   = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    $line = sprintf("[%s] [%s] [IP:%s] [ID:%s] %s%s", date('Y-m-d H:i:s'), $status, $ip, $identity, $details, PHP_EOL);
    file_put_contents(SSO_AUDIT_LOG, $line, FILE_APPEND | LOCK_EX);
}

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));

if ($token === '') {
    logSsoEvent('REJECTED', 'NONE', 'Missing SSO assertion token on callback.');
    header('Location: login.php?error=missing_token');
    exit;
}

$claims = verifySsoToken($token);

if (!$claims) {
    logSsoEvent('REJECTED', 'INVALID_TOKEN', 'Signature verification failed or SSO assertion expired.');
    header('Location: login.php?error=invalid_token');
    exit;
}

// Fetch latest authoritative user record from DB
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ? LIMIT 1");
$stmt->execute([(int) $claims['sub']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || $user['account_status'] === 'SUSPENDED') {
    logSsoEvent('BLOCKED', (string) ($claims['student_id'] ?? 'UNKNOWN'), 'User missing or SUSPENDED during callback.');
    header('Location: login.php?error=account_unavailable');
    exit;
}

// Prevent session fixation attacks
session_regenerate_id(true);

$_SESSION['user_id']        = (int) $user['user_id'];
$_SESSION['student_id']     = $user['student_id'];
$_SESSION['full_name']      = $user['full_name'];
$_SESSION['email']          = $user['email'];
$_SESSION['role']           = $user['role'];
$_SESSION['sso_authenticated'] = true;
$_SESSION['sso_login_time'] = date('Y-m-d H:i:s');

logSsoEvent('SUCCESS', (string) $user['student_id'], "Authenticated via Strathmore SSO as {$user['role']}.");

$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'], 3), '/\\');

if ($user['role'] === 'VENDOR') {
    header('Location: ' . $baseUrl . '/modules/pos/checkout.php');
} else {
    header('Location: ' . $baseUrl . '/modules/auth/dashboard.php');
}
exit;