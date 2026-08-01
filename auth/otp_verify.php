<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (empty($_SESSION['pending_2fa_user_id'])) {
    redirect(APP_URL . '/auth/login.php');
}

$db = Database::getConnection();
$userId = $_SESSION['pending_2fa_user_id'];
$errors = [];
$resent = false;

// Resend OTP
if (isset($_GET['resend'])) {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if ($user) {
        $otp = generate_otp();
        $expiresAt = date('Y-m-d H:i:s', time() + OTP_EXPIRY_SECONDS);
        $stmt = $db->prepare("INSERT INTO otp_codes (user_id, code, purpose, expires_at) VALUES (?, ?, 'login_2fa', ?)");
        $stmt->execute([$userId, $otp, $expiresAt]);

        $emailBody = build_email_html(
            "Your New Verification Code",
            "<p style='margin:0 0 18px;'>Here's your new login code for " . APP_NAME . ":</p>"
            . otp_code_block($otp) .
            "<p style='margin:18px 0 0;color:#9AA4B8;font-size:13px;'>This code expires in " . (OTP_EXPIRY_SECONDS / 60) . " minutes.</p>"
        );
        @send_email($user['email'], $user['first_name'], 'Your new verification code', $emailBody);
        $resent = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();

    $digits = $_POST['otp'] ?? [];
    $code = is_array($digits) ? implode('', $digits) : trim($digits);

    if (strlen($code) !== 6) {
        $errors[] = 'Please enter the complete 6-digit code.';
    } else {
        $stmt = $db->prepare("SELECT * FROM otp_codes WHERE user_id = ? AND purpose = 'login_2fa'
                               ORDER BY id DESC LIMIT 1");
        $stmt->execute([$userId]);
        $otpRecord = $stmt->fetch();

        if (!$otpRecord || $otpRecord['used']) {
            $errors[] = 'No active verification code found. Please request a new one.';
        } elseif (strtotime($otpRecord['expires_at']) < time()) {
            $errors[] = 'This code has expired. Please request a new one.';
        } elseif ($otpRecord['attempts'] >= 5) {
            $errors[] = 'Too many incorrect attempts. Please request a new code.';
        } elseif (!hash_equals($otpRecord['code'], $code)) {
            $db->prepare("UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?")->execute([$otpRecord['id']]);
            $errors[] = 'Incorrect verification code.';
        } else {
            $db->prepare("UPDATE otp_codes SET used = 1 WHERE id = ?")->execute([$otpRecord['id']]);

            $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();

            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['company_id'] = $user['company_id'];
            $_SESSION['role'] = $user['role'];
            unset($_SESSION['pending_2fa_user_id']);

            $db->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")->execute([$user['id']]);
            log_activity('login', 'User logged in successfully', $user['id']);

            // Remember me
            if (!empty($_SESSION['pending_2fa_remember'])) {
                $token = bin2hex(random_bytes(32));
                $db->prepare("UPDATE users SET remember_token = ? WHERE id = ?")->execute([$token, $user['id']]);
                setcookie('rentsphere_remember', $token, time() + REMEMBER_ME_DAYS * 86400, '/', '', false, true);
            }
            unset($_SESSION['pending_2fa_remember']);

            $redirectMap = ['administrator' => 'admin/dashboard.php', 'caretaker' => 'caretaker/dashboard.php', 'tenant' => 'tenant/dashboard.php'];
            redirect(APP_URL . '/' . ($redirectMap[$user['role']] ?? 'admin/dashboard.php'));
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verify Login — <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body>
<div class="auth-page">
    <div class="card-glass auth-card" style="text-align:center;">
        <div class="auth-logo" style="justify-content:center;">
            <div class="auth-logo-mark">R</div>
            <div class="auth-logo-text"><?= e(APP_NAME) ?></div>
        </div>
        <i class="fa-solid fa-shield-halved" style="font-size:36px;color:var(--color-primary);margin-bottom:14px;"></i>
        <h1 class="auth-title">Two-Factor Verification</h1>
        <p class="auth-subtitle">We've sent a 6-digit code to your email. Enter it below to continue.</p>

        <?php if ($resent): ?>
            <div class="alert alert-success" data-autohide><i class="fa-solid fa-circle-check"></i> A new code has been sent.</div>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i>
                <div><?php foreach ($errors as $err) echo e($err) . '<br>'; ?></div>
            </div>
        <?php endif; ?>

        <form method="POST" novalidate>
            <?= csrf_field() ?>
            <div class="form-group otp-inputs" id="otpContainer">
                <?php for ($i = 0; $i < 6; $i++): ?>
                    <input type="text" name="otp[]" inputmode="numeric" maxlength="1" autocomplete="one-time-code" <?= $i === 0 ? 'autofocus' : '' ?>>
                <?php endfor; ?>
            </div>
            <button type="submit" class="btn btn-primary w-full">Verify &amp; Continue</button>
        </form>
        <div class="auth-footer-link">Didn't get a code? <a href="?resend=1">Resend code</a></div>
    </div>
</div>
<script src="../assets/js/app.js"></script>
<script>initOtpInputs('otpContainer');</script>
</body>
</html>
