<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$db = Database::getConnection();
$token = $_GET['token'] ?? '';
$status = 'invalid';
$message = 'This verification link is invalid.';

if ($token !== '') {
    $stmt = $db->prepare("SELECT ev.*, u.email, u.first_name FROM email_verifications ev
                           JOIN users u ON u.id = ev.user_id
                           WHERE ev.token = ? AND ev.used = 0");
    $stmt->execute([$token]);
    $record = $stmt->fetch();

    if ($record && strtotime($record['expires_at']) > time()) {
        $db->beginTransaction();
        $db->prepare("UPDATE users SET is_email_verified = 1 WHERE id = ?")->execute([$record['user_id']]);
        $db->prepare("UPDATE email_verifications SET used = 1 WHERE id = ?")->execute([$record['id']]);
        $db->commit();

        log_activity('email_verified', 'Email verified', $record['user_id']);
        $status = 'success';
        $message = 'Your email has been verified! You can now log in.';
    } elseif ($record) {
        $status = 'expired';
        $message = 'This verification link has expired. Please request a new one from the login page.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verify Email — <?= e(APP_NAME) ?></title>
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
        <?php if ($status === 'success'): ?>
            <i class="fa-solid fa-circle-check" style="font-size:48px;color:var(--color-success);margin-bottom:16px;"></i>
        <?php else: ?>
            <i class="fa-solid fa-circle-exclamation" style="font-size:48px;color:var(--color-danger);margin-bottom:16px;"></i>
        <?php endif; ?>
        <h1 class="auth-title"><?= $status === 'success' ? 'Email Verified' : 'Verification Failed' ?></h1>
        <p class="auth-subtitle"><?= e($message) ?></p>
        <a href="login.php" class="btn btn-primary w-full">Go to Login</a>
    </div>
</div>
</body>
</html>
