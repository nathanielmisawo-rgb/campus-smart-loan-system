<?php
/**
 * Module 1: Authentication & User Management
 * Author: Misawo Nathaniel
 * File: modules/auth/sso_auth.php
 * Description: Strathmore Single Sign-On (SSO) RESTful Authentication API & Token Helper.
 *              Validates Student/Staff ID or @strathmore.edu email against bcrypt hashes
 *              and issues HMAC-SHA256 signed SSO assertion tokens.
 */

declare(strict_types=1);

// Shared signing secret for simulated Strathmore Identity Provider (IdP) assertions
const SSO_SECRET_KEY = 'SU_SMART_LOAN_SSO_IDP_SECRET_2026';
const SSO_TOKEN_TTL  = 300; // Assertion token valid for 5 minutes

/**
 * Generates an HMAC-SHA256 signed SSO assertion token for a verified user.
 */
function generateSsoToken(array $user): string
{
    $payload = [
        'iss'        => 'https://sso.strathmore.edu/idp',
        'sub'        => (string) $user['user_id'],
        'student_id' => (string) $user['student_id'],
        'email'      => (string) $user['email'],
        'role'       => (string) $user['role'],
        'iat'        => time(),
        'exp'        => time() + SSO_TOKEN_TTL,
        'nonce'      => bin2hex(random_bytes(8))
    ];

    $encodedPayload = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
    $signature      = hash_hmac('sha256', $encodedPayload, SSO_SECRET_KEY);

    return $encodedPayload . '.' . $signature;
}

/**
 * Verifies an HMAC-SHA256 signed SSO assertion token and returns decoded claims, or null if invalid/expired.
 */
function verifySsoToken(string $token): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 2) {
        return null;
    }

    [$encodedPayload, $signature] = $parts;
    $expectedSignature = hash_hmac('sha256', $encodedPayload, SSO_SECRET_KEY);

    if (!hash_equals($expectedSignature, $signature)) {
        return null;
    }

    $padded  = str_pad(strtr($encodedPayload, '-_', '+/'), strlen($encodedPayload) % 4 === 0 ? strlen($encodedPayload) : strlen($encodedPayload) + 4 - (strlen($encodedPayload) % 4), '=');
    $payload = json_decode((string) base64_decode($padded), true);

    if (!is_array($payload) || empty($payload['exp']) || time() > (int) $payload['exp']) {
        return null;
    }

    return $payload;
}

/**
 * Authenticates a user by Student/Staff ID or Email + Password and returns SSO result.
 */
function authenticateSsoCredentials(PDO $pdo, string $identifier, string $password): array
{
    $identifier = trim($identifier);

    if ($identifier === '' || $password === '') {
        return ['success' => false, 'code' => 400, 'message' => 'Student/Staff ID (or Email) and Password are required.'];
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE student_id = ? OR email = ? LIMIT 1");
    $stmt->execute([$identifier, $identifier]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $storedHash = (string) ($user['pass'] ?? $user['password'] ?? '');

    if (!$user || $storedHash === '' || !password_verify($password, $storedHash)) {
        return ['success' => false, 'code' => 401, 'message' => 'Invalid Strathmore SSO credentials.'];
    }

    if ($user['account_status'] === 'SUSPENDED') {
        return ['success' => false, 'code' => 403, 'message' => 'Account suspended. Contact the Strathmore Bursar Office.'];
    }

    $token = generateSsoToken($user);

    return [
        'success'   => true,
        'code'      => 200,
        'sso_token' => $token,
        'expires_in'=> SSO_TOKEN_TTL,
        'user'      => [
            'user_id'        => (int) $user['user_id'],
            'student_id'     => $user['student_id'],
            'full_name'      => $user['full_name'],
            'email'          => $user['email'],
            'role'           => $user['role'],
            'account_status' => $user['account_status'],
            'credit_limit'   => (float) $user['credit_limit'],
            'current_balance'=> (float) $user['current_balance']
        ]
    ];
}

// Execute REST JSON endpoint ONLY when sso_auth.php is called directly
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    header('Content-Type: application/json');
    require_once __DIR__ . '/../../config/db.php';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed. Use POST.'], JSON_PRETTY_PRINT);
        exit;
    }

    // Support both application/json and standard form-urlencoded POST bodies
    $rawInput = file_get_contents('php://input');
    $jsonBody = json_decode((string) $rawInput, true);

    $identifier = is_array($jsonBody)
        ? (string) ($jsonBody['identifier'] ?? $jsonBody['student_id'] ?? $jsonBody['user_id'] ?? '')
        : (string) ($_POST['identifier'] ?? $_POST['student_id'] ?? $_POST['user_id'] ?? '');

    $password = is_array($jsonBody)
        ? (string) ($jsonBody['password'] ?? $jsonBody['pass'] ?? '')
        : (string) ($_POST['password'] ?? $_POST['pass'] ?? '');

    $result = authenticateSsoCredentials($pdo, $identifier, $password);

    http_response_code($result['code']);
    if ($result['success']) {
        echo json_encode([
            'status'     => 'success',
            'provider'   => 'Strathmore University SSO IdP',
            'sso_token'  => $result['sso_token'],
            'expires_in' => $result['expires_in'],
            'user'       => $result['user']
        ], JSON_PRETTY_PRINT);
    } else {
        echo json_encode([
            'status'  => 'error',
            'message' => $result['message']
        ], JSON_PRETTY_PRINT);
    }
    exit;
}