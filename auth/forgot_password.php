<?php
require_once __DIR__ . '/../includes/bootstrap.php';
if (is_logged_in()) redirect(APP_URL . '/admin/dashboard.php');

$db = Database::getConnection();
$errors = [];
$sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $email = trim($_POST['email'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    } else {
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Always show success message even if user not found (prevents email enumeration)
        if ($user) {
            $otp = generate_otp();
            $expiresAt = date('Y-m-d H:i:s', time() + PASSWORD_RESET_EXPIRY_SECONDS);
            $stmt = $db->prepare("INSERT INTO otp_codes (user_id, code, purpose, expires_at) VALUES (?, ?, 'password_reset', ?)");
            $stmt->execute([$user['id'], $otp, $expiresAt]);

            $emailBody = "<p>Hi {$user['first_name']},</p>
                <p>Your " . APP_NAME . " password reset code is:</p>
                <h2 style='letter-spacing:6px;'>{$otp}</h2>
                <p>This code expires in " . (PASSWORD_RESET_EXPIRY_SECONDS / 60) . " minutes. If you didn't request this, you can ignore this email.</p>";
            @send_email($user['email'], $user['first_name'], 'Reset your ' . APP_NAME . ' password', $emailBody);

            $_SESSION['pending_reset_user_id'] = $user['id'];
            log_activity('password_reset_requested', 'Password reset requested', $user['id']);
        }
        $sent = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password — <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body>
<div class="auth-page">
    <div class="card-glass auth-card">
        <div class="auth-logo"><div class="auth-logo-mark">R</div><div class="auth-logo-text"><?= e(APP_NAME) ?></div></div>
        <h1 class="auth-title">Forgot your password?</h1>
        <p class="auth-subtitle">Enter your email and we'll send you a reset code.</p>

        <?php if ($sent): ?>
            <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> If that email exists in our system, a reset code has been sent.</div>
            <a href="reset_password.php" class="btn btn-primary w-full">Enter Reset Code</a>
        <?php else: ?>
            <?php if (!empty($errors)): ?>
                <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i>
                    <div><?php foreach ($errors as $err) echo e($err) . '<br>'; ?></div>
                </div>
            <?php endif; ?>
            <form method="POST" novalidate>
                <?= csrf_field() ?>
                <div class="form-group">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email" class="form-control" required autofocus>
                </div>
                <button type="submit" class="btn btn-primary w-full">Send Reset Code</button>
            </form>
        <?php endif; ?>
        <div class="auth-footer-link"><a href="login.php">Back to login</a></div>
    </div>
</div>
</body>
</html>
