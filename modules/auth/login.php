<?php
session_start();
require_once '../../config/db.php';
require_once 'sso_auth.php';

// Redirect already-authenticated users straight to their workspace
if (isset($_SESSION['user_id'])) {
    $baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'], 3), '/\\');
    if (($_SESSION['role'] ?? '') === 'VENDOR') {
        header('Location: ' . $baseUrl . '/modules/pos/checkout.php');
    } else {
        header('Location: ' . $baseUrl . '/modules/auth/dashboard.php');
    }
    exit;
}

$error = '';

if (isset($_GET['error'])) {
    $errorMap = [
        'missing_token'       => 'SSO handshake incomplete: missing assertion token.',
        'invalid_token'       => 'SSO session assertion expired or failed signature verification.',
        'account_unavailable' => 'Your account is suspended or unavailable. Contact the Bursar Office.'
    ];
    $error = $errorMap[$_GET['error']] ?? 'Authentication failed. Please sign in again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');
    $password   = $_POST['password'] ?? '';

    $authResult = authenticateSsoCredentials($pdo, $identifier, $password);

    if ($authResult['success']) {
        header('Location: sso_callback.php?token=' . urlencode($authResult['sso_token']));
        exit;
    } else {
        $error = $authResult['message'];
    }
}

require_once '../../includes/header.php';
?>

<div class="container" style="max-width: 460px; margin: 45px auto;">
    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
            <h2 style="margin: 0; color: var(--primary-hover);">Strathmore SSO</h2>
            <span style="font-size: 0.75rem; padding: 3px 8px; border-radius: 999px; background: rgba(16, 185, 129, 0.15); color: var(--primary-hover); border: 1px solid var(--primary);">
                IdP v1.0
            </span>
        </div>
        <p style="color: var(--text-muted); margin-bottom: 20px; font-size: 0.92rem;">
            Sign in with your Strathmore Admission/Staff ID or <code>@strathmore.edu</code> email.
        </p>

        <?php if ($error !== ''): ?>
            <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid var(--danger); color: #fca5a5; padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; font-size: 0.9rem;">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label for="identifier">Student / Staff ID or Strathmore Email</label>
                <input type="text" id="identifier" name="identifier" placeholder="e.g. 100001 or misawo@strathmore.edu"
                       value="<?= htmlspecialchars($_POST['identifier'] ?? '') ?>" required autofocus>
            </div>

            <div class="form-group">
                <label for="password">University SSO Password</label>
                <input type="password" id="password" name="password" placeholder="Enter your SSO password" required>
            </div>

            <button type="submit" class="btn" style="width: 100%; font-weight: 600; margin-top: 6px;">
                Sign In via Strathmore SSO
            </button>
        </form>

        <div style="margin-top: 22px; padding-top: 16px; border-top: 1px solid var(--border);">
            <p style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 8px;">
                <strong>Demo Quick-Fill (Password: <code>admin123</code>):</strong>
            </p>
            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                <button type="button" class="btn" style="padding: 5px 10px; font-size: 0.8rem; background: #334155;"
                        onclick="fillDemo('100001')">Student (100001)</button>
                <button type="button" class="btn" style="padding: 5px 10px; font-size: 0.8rem; background: #334155;"
                        onclick="fillDemo('VEND01')">Vendor (VEND01)</button>
                <button type="button" class="btn" style="padding: 5px 10px; font-size: 0.8rem; background: #334155;"
                        onclick="fillDemo('ADM001')">Admin (ADM001)</button>
            </div>
        </div>
    </div>
</div>

<script>
function fillDemo(id) {
    document.getElementById('identifier').value = id;
    document.getElementById('password').value = 'admin123';
}
</script>

<?php require_once '../../includes/footer.php'; ?>