<?php
session_start();
require_once __DIR__ . '/../../config/db.php';

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

$error = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');
    $password   = $_POST['password'] ?? '';

    if ($identifier === '' || $password === '') {
        $error = 'Please enter both your Strathmore ID/Email and password.';
    } else {
        $stmt = $pdo->prepare("
            SELECT * FROM users 
            WHERE student_id = ? OR email = ? 
            LIMIT 1
        ");
        $stmt->execute([$identifier, $identifier]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['pass'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']        = (int) $user['user_id'];
            $_SESSION['student_id']     = $user['student_id'];
            $_SESSION['full_name']      = $user['full_name'];
            $_SESSION['email']          = $user['email'];
            $_SESSION['role']           = $user['role'];
            $_SESSION['account_status'] = $user['account_status'];

            header("Location: dashboard.php");
            exit;
        } else {
            $error = 'Invalid Strathmore SSO credentials. Try password: admin123';
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<style>
    .login-split-wrapper {
        display: grid;
        grid-template-columns: 1.15fr 0.85fr;
        gap: 3rem;
        align-items: center;
        max-width: 1120px;
        margin: 1.5rem auto 2.5rem;
        padding: 0 1rem;
        min-height: calc(100vh - 190px);
    }

    /* Left Hero Column */
    .login-hero {
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
        padding-right: 1rem;
    }

    .hero-eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        width: fit-content;
        padding: 0.35rem 0.85rem;
        border-radius: 999px;
        font-size: 0.78rem;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: var(--neon-cyan, #38bdf8);
        background: rgba(56, 189, 248, 0.1);
        border: 1px solid rgba(56, 189, 248, 0.25);
    }

    .login-hero h1 {
        font-size: clamp(2rem, 3.2vw, 2.85rem);
        font-weight: 800;
        line-height: 1.12;
        letter-spacing: -0.03em;
        margin: 0;
        color: #f8fafc;
    }

    .login-hero h1 span {
        background: linear-gradient(135deg, #10b981 0%, #38bdf8 100%);
        background-clip: text;
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
    }

    .login-hero-sub {
        font-size: 1rem;
        line-height: 1.6;
        color: #94a3b8;
        margin: 0;
        max-width: 500px;
    }

    /* Floating Glass Virtual Campus Card */
    .virtual-tab-card {
        position: relative;
        max-width: 440px;
        padding: 1.45rem 1.6rem;
        border-radius: 18px;
        background: linear-gradient(135deg, rgba(16, 185, 129, 0.14) 0%, rgba(15, 23, 42, 0.85) 55%, rgba(56, 189, 248, 0.14) 100%);
        border: 1px solid rgba(56, 189, 248, 0.28);
        box-shadow: 0 20px 45px rgba(0, 0, 0, 0.45), inset 0 1px 0 rgba(255, 255, 255, 0.12);
        backdrop-filter: blur(14px);
    }

    .virtual-card-top {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.35rem;
    }

    .virtual-card-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        font-size: 0.78rem;
        font-weight: 700;
        color: #10b981;
        letter-spacing: 0.05em;
        text-transform: uppercase;
    }

    .virtual-card-balance {
        font-size: 1.75rem;
        font-weight: 800;
        color: #ffffff;
        letter-spacing: -0.02em;
        font-variant-numeric: tabular-nums;
    }

    .virtual-card-meta {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 1.15rem;
        padding-top: 0.9rem;
        border-top: 1px solid rgba(148, 163, 184, 0.15);
        font-size: 0.8rem;
        color: #94a3b8;
    }

    /* Feature Bento Pills */
    .hero-metrics {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 0.75rem;
        max-width: 440px;
    }

    .hero-metric-box {
        background: rgba(15, 23, 42, 0.65);
        border: 1px solid rgba(148, 163, 184, 0.14);
        border-radius: 12px;
        padding: 0.75rem 0.85rem;
    }

    .hero-metric-box strong {
        display: block;
        font-size: 0.95rem;
        color: #f8fafc;
        margin-bottom: 0.15rem;
    }

    .hero-metric-box span {
        font-size: 0.74rem;
        color: #64748b;
    }

    /* Right Auth Card */
    .login-vault-card {
        width: 100%;
        max-width: 440px;
        justify-self: end;
        background: rgba(13, 20, 36, 0.88);
        border: 1px solid rgba(16, 185, 129, 0.35);
        border-radius: 20px;
        padding: 2rem;
        box-shadow: 0 24px 60px rgba(0, 0, 0, 0.55), 0 0 35px rgba(16, 185, 129, 0.08);
        backdrop-filter: blur(16px);
    }

    .quick-fill-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 0.5rem;
        margin-top: 0.65rem;
    }

    .quick-fill-btn {
        background: rgba(30, 41, 59, 0.75);
        border: 1px solid rgba(148, 163, 184, 0.2);
        color: #e2e8f0;
        padding: 0.5rem 0.4rem;
        border-radius: 999px;
        font-size: 0.78rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.18s ease;
        text-align: center;
    }

    .quick-fill-btn:hover {
        border-color: #10b981;
        color: #10b981;
        background: rgba(16, 185, 129, 0.1);
        transform: translateY(-1px);
    }

    @media (max-width: 900px) {
        .login-split-wrapper {
            grid-template-columns: 1fr;
            gap: 2rem;
            margin-top: 1rem;
        }
        .login-hero {
            padding-right: 0;
        }
        .login-vault-card {
            justify-self: stretch;
            max-width: 100%;
        }
    }
</style>

<div class="login-split-wrapper">
    <!-- Left Column: Fintech Command Center Showcase -->
    <section class="login-hero">
        <div class="hero-eyebrow">
            <i data-lucide="sparkles" style="width: 14px; height: 14px;"></i>
            Strathmore Smart Credit Infrastructure
        </div>

        <h1>
            Campus essentials now.<br>
            <span>Settle seamlessly via M-Pesa.</span>
        </h1>

        <p class="login-hero-sub">
            Instant zero-interest micro-credit across campus cafeterias, bookstores, and printing hubs—authenticated via Strathmore Single Sign-On.
        </p>

        <!-- Glass Virtual Credit Card Preview -->
        <div class="virtual-tab-card">
            <div class="virtual-card-top">
                <span class="virtual-card-chip">
                    <i data-lucide="cpu" style="width: 16px; height: 16px;"></i>
                    CampusTab Revolving Line
                </span>
                <span class="status-pill status-active">0% APR</span>
            </div>

            <div style="font-size: 0.74rem; text-transform: uppercase; letter-spacing: 0.08em; color: #94a3b8; margin-bottom: 0.2rem;">
                Standard Semester Credit Limit
            </div>
            <div class="virtual-card-balance">KSh 5,000.00</div>

            <div class="virtual-card-meta">
                <span>HMAC-SHA256 SSO</span>
                <span style="color: #38bdf8; font-weight: 600;">Daraja STK Enabled</span>
            </div>
        </div>

        <!-- 3 Mini Architecture Pillars -->
        <div class="hero-metrics">
            <div class="hero-metric-box">
                <strong>&lt; 1.2s</strong>
                <span>POS Tap Auth</span>
            </div>
            <div class="hero-metric-box">
                <strong>M-Pesa</strong>
                <span>Express Clearing</span>
            </div>
            <div class="hero-metric-box">
                <strong>CMS Sync</strong>
                <span>Auto Hold Control</span>
            </div>
        </div>
    </section>

    <!-- Right Column: Strathmore SSO Authentication Vault -->
    <section class="login-vault-card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
            <h2 style="display: flex; align-items: center; gap: 0.55rem; margin: 0; font-size: 1.4rem; color: #ffffff;">
                <i data-lucide="shield-check" style="width: 24px; height: 24px; color: #10b981;"></i>
                Strathmore SSO
            </h2>
            <span class="status-pill status-active" style="font-size: 0.72rem;">IdP v1.0</span>
        </div>

        <p style="margin: 0 0 1.4rem; font-size: 0.88rem; color: #94a3b8; line-height: 1.5;">
            Sign in with your Strathmore Admission/Staff ID or <code style="color: #38bdf8;">@strathmore.edu</code> email.
        </p>

        <?php if ($error): ?>
            <div class="alert alert-error" style="margin-bottom: 1.25rem;">
                <i data-lucide="alert-circle" style="width: 18px; height: 18px; flex-shrink: 0;"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="" autocomplete="off">
            <div class="form-group">
                <label for="identifier">Student / Staff ID or Strathmore Email</label>
                <input
                    id="identifier"
                    type="text"
                    name="identifier"
                    value="<?= htmlspecialchars($_POST['identifier'] ?? '') ?>"
                    placeholder="e.g. 100001 or misawo@strathmore.edu"
                    required
                    autofocus
                >
            </div>

            <div class="form-group">
                <label for="password">University SSO Password</label>
                <input
                    id="password"
                    type="password"
                    name="password"
                    placeholder="Enter your SSO password"
                    required
                >
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 0.4rem; padding: 0.85rem;">
                <i data-lucide="log-in" style="width: 18px; height: 18px;"></i>
                Sign In via Strathmore SSO
            </button>
        </form>

        <div style="margin-top: 1.6rem; padding-top: 1.25rem; border-top: 1px solid rgba(148, 163, 184, 0.14);">
            <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.78rem; color: #94a3b8;">
                <span>Demo Quick-Fill</span>
                <span>Password: <code style="color: #38bdf8;">admin123</code></span>
            </div>

            <div class="quick-fill-grid">
                <button type="button" class="quick-fill-btn" onclick="fillDemo('100001')">
                    Student
                </button>
                <button type="button" class="quick-fill-btn" onclick="fillDemo('VEND01')">
                    Vendor
                </button>
                <button type="button" class="quick-fill-btn" onclick="fillDemo('ADM001')">
                    Admin
                </button>
            </div>
        </div>
    </section>
</div>

<script>
function fillDemo(id) {
    document.getElementById('identifier').value = id;
    document.getElementById('password').value = 'admin123';
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>