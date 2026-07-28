<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$roleRedirect = ['administrator' => 'admin/dashboard.php', 'caretaker' => 'caretaker/dashboard.php', 'tenant' => 'tenant/dashboard.php'];

if (is_logged_in()) {
    redirect(APP_URL . '/' . ($roleRedirect[$_SESSION['role']] ?? 'admin/dashboard.php'));
}

$db = Database::getConnection();
$errors = [];

// Handle "remember me" auto-login
if (!is_logged_in() && !empty($_COOKIE['rentsphere_remember'])) {
    $stmt = $db->prepare("SELECT * FROM users WHERE remember_token = ? AND is_active = 1");
    $stmt->execute([$_COOKIE['rentsphere_remember']]);
    $user = $stmt->fetch();
    if ($user) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['company_id'] = $user['company_id'];
        $_SESSION['role'] = $user['role'];
        session_regenerate_id(true);
        redirect(APP_URL . '/' . ($roleRedirect[$user['role']] ?? 'admin/dashboard.php'));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = !empty($_POST['remember_me']);

    if ($email === '' || $password === '') {
        $errors[] = 'Please enter both your email and password.';
    }

    if (empty($errors)) {
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            $errors[] = 'Invalid email or password.';
        } elseif (is_account_locked($user)) {
            $minutesLeft = ceil((strtotime($user['locked_until']) - time()) / 60);
            $errors[] = "Too many failed attempts. Your account is locked for {$minutesLeft} more minute(s).";
        } elseif (!$user['is_active']) {
            $errors[] = 'Your account has been deactivated. Please contact your administrator.';
        } elseif (!password_verify($password, $user['password_hash'])) {
            register_failed_login($user['id']);
            log_login_attempt($user['id'], 'failed');
            $errors[] = 'Invalid email or password.';
        } elseif (!$user['is_email_verified']) {
            $errors[] = 'Please verify your email address before logging in. Check your inbox for the verification link.';
        } else {
            // Successful credential check -> trigger OTP 2FA
            reset_failed_logins($user['id']);
            log_login_attempt($user['id'], 'success');

            $otp = generate_otp();
            $expiresAt = date('Y-m-d H:i:s', time() + OTP_EXPIRY_SECONDS);
            $stmt = $db->prepare("INSERT INTO otp_codes (user_id, code, purpose, expires_at) VALUES (?, ?, 'login_2fa', ?)");
            $stmt->execute([$user['id'], $otp, $expiresAt]);

            $emailBody = "
<div style='font-family:Arial,sans-serif;max-width:480px;margin:0 auto;'>
    <div style='background:linear-gradient(135deg,#2563EB,#4F46E5,#06B6D4);padding:28px 24px;border-radius:16px 16px 0 0;text-align:center;'>
        <div style='width:48px;height:48px;background:#fff;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;font-size:20px;font-weight:800;color:#2563EB;'>R</div>
        <h1 style='color:#fff;margin:14px 0 0;font-size:19px;'>Login Verification</h1>
    </div>
    <div style='background:#ffffff;padding:32px 24px;border-radius:0 0 16px 16px;border:1px solid #E5E9F0;border-top:none;text-align:center;'>
        <p style='font-size:14px;color:#6B7280;margin-bottom:4px;'>Hi {$user['first_name']}, here's your code:</p>
        <div style='background:#F5F7FB;border:1.5px dashed #2563EB;border-radius:12px;padding:18px;margin:16px 0;display:inline-block;'>
            <span style='font-size:34px;font-weight:800;letter-spacing:10px;color:#2563EB;font-family:monospace;'>{$otp}</span>
        </div>
        <p style='font-size:12.5px;color:#9CA3AF;'>Expires in " . (OTP_EXPIRY_SECONDS / 60) . " minutes.</p>
        <p style='font-size:12.5px;color:#9CA3AF;margin-top:16px;'>Didn't try to log in? Secure your account immediately by changing your password.</p>
    </div>
</div>";
            @send_email($user['email'], $user['first_name'], 'Your ' . APP_NAME . ' verification code', $emailBody);

            $_SESSION['pending_2fa_user_id'] = $user['id'];
            $_SESSION['pending_2fa_remember'] = $remember;

            redirect(APP_URL . '/auth/otp_verify.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Log In — <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body>
<div class="auth-page">
    <div class="card-glass auth-card">
        <div class="auth-logo">
            <div class="auth-logo-mark">R</div>
            <div class="auth-logo-text"><?= e(APP_NAME) ?></div>
        </div>
        <h1 class="auth-title">Welcome back</h1>
        <p class="auth-subtitle">Log in to your <?= e(APP_NAME) ?> dashboard.</p>

        <?php if ($msg = get_flash('success')): ?>
            <div class="alert alert-success" data-autohide><i class="fa-solid fa-circle-check"></i> <?= e($msg) ?></div>
        <?php endif; ?>
        <?php if ($msg = get_flash('error')): ?>
            <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?= e($msg) ?></div>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i>
                <div><?php foreach ($errors as $err) echo e($err) . '<br>'; ?></div>
            </div>
        <?php endif; ?>

        <form method="POST" novalidate>
            <?= csrf_field() ?>
            <div class="form-group">
                <label class="form-label">Email Address</label>
                <input type="email" name="email" class="form-control" value="<?= old('email') ?>" required autofocus>
            </div>
            <div class="form-group">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <div class="form-group d-flex justify-between align-center">
                <label class="d-flex align-center gap-8" style="font-size:13.5px;color:var(--text-secondary);">
                    <input type="checkbox" name="remember_me"> Remember me
                </label>
                <a href="forgot_password.php" style="font-size:13.5px;">Forgot password?</a>
            </div>
            <button type="submit" class="btn btn-primary w-full">Log In</button>
        </form>
        <div class="auth-footer-link">Don't have an account? <a href="register.php">Create one</a></div>
    </div>
</div>
</body>
</html>
