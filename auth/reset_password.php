<?php
require_once __DIR__ . '/../includes/bootstrap.php';
if (is_logged_in()) redirect(APP_URL . '/admin/dashboard.php');

if (empty($_SESSION['pending_reset_user_id'])) {
    redirect(APP_URL . '/auth/forgot_password.php');
}

$db = Database::getConnection();
$userId = $_SESSION['pending_reset_user_id'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();

    $code = trim($_POST['code'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($code === '') $errors[] = 'Please enter the reset code.';
    if (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must be at least 8 characters with an uppercase letter and a number.';
    }
    if ($password !== $confirm) $errors[] = 'Passwords do not match.';

    if (empty($errors)) {
        $stmt = $db->prepare("SELECT * FROM otp_codes WHERE user_id = ? AND purpose = 'password_reset'
                               ORDER BY id DESC LIMIT 1");
        $stmt->execute([$userId]);
        $otpRecord = $stmt->fetch();

        if (!$otpRecord || $otpRecord['used']) {
            $errors[] = 'No active reset request found. Please start over.';
        } elseif (strtotime($otpRecord['expires_at']) < time()) {
            $errors[] = 'This reset code has expired. Please request a new one.';
        } elseif (!hash_equals($otpRecord['code'], $code)) {
            $db->prepare("UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?")->execute([$otpRecord['id']]);
            $errors[] = 'Incorrect reset code.';
        } else {
            $db->prepare("UPDATE otp_codes SET used = 1 WHERE id = ?")->execute([$otpRecord['id']]);
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $db->prepare("UPDATE users SET password_hash = ?, remember_token = NULL WHERE id = ?")->execute([$hash, $userId]);

            log_activity('password_reset', 'Password reset successfully', $userId);
            unset($_SESSION['pending_reset_user_id']);

            flash('success', 'Your password has been reset. Please log in with your new password.');
            redirect(APP_URL . '/auth/login.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Password — <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body>
<div class="auth-page">
    <div class="card-glass auth-card">
        <div class="auth-logo"><div class="auth-logo-mark">R</div><div class="auth-logo-text"><?= e(APP_NAME) ?></div></div>
        <h1 class="auth-title">Reset your password</h1>
        <p class="auth-subtitle">Enter the code we emailed you and choose a new password.</p>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i>
                <div><?php foreach ($errors as $err) echo e($err) . '<br>'; ?></div>
            </div>
        <?php endif; ?>

        <form method="POST" novalidate>
            <?= csrf_field() ?>
            <div class="form-group">
                <label class="form-label">Reset Code</label>
                <input type="text" name="code" class="form-control" required autofocus>
            </div>
            <div class="form-group">
                <label class="form-label">New Password</label>
                <input type="password" name="password" class="form-control" required>
                <div class="form-hint">At least 8 characters, one uppercase letter, one number.</div>
            </div>
            <div class="form-group">
                <label class="form-label">Confirm New Password</label>
                <input type="password" name="confirm_password" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary w-full">Reset Password</button>
        </form>
        <div class="auth-footer-link"><a href="forgot_password.php">Request a new code</a> · <a href="login.php">Back to login</a></div>
    </div>
</div>
</body>
</html>
