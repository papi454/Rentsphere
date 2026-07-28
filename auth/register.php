<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (is_logged_in()) {
    redirect(APP_URL . '/admin/dashboard.php');
}

$db = Database::getConnection();

// Only allow this page to create the very first administrator.
// After that, additional admins/caretakers must be created from admin/users.php
$existingAdmins = (int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'administrator'")->fetchColumn();

$errors = [];

if ($existingAdmins > 0) {
    flash('error', 'An administrator account already exists. Please log in, or ask your administrator to create your account.');
    redirect(APP_URL . '/auth/login.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();

    $companyName = trim($_POST['company_name'] ?? '');
    $firstName   = trim($_POST['first_name'] ?? '');
    $lastName    = trim($_POST['last_name'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $password    = $_POST['password'] ?? '';
    $confirm     = $_POST['confirm_password'] ?? '';

    if ($companyName === '' || $firstName === '' || $lastName === '' || $email === '') {
        $errors[] = 'All fields are required.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    }
    if (!preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must contain at least one uppercase letter and one number.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }

    if (empty($errors)) {
        // Double-check email uniqueness
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $errors[] = 'An account with this email already exists.';
        }
    }

    if (empty($errors)) {
        try {
            $db->beginTransaction();

            $stmt = $db->prepare("INSERT INTO companies (name) VALUES (?)");
            $stmt->execute([$companyName]);
            $companyId = (int) $db->lastInsertId();

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("INSERT INTO users (company_id, role, first_name, last_name, email, password_hash, is_email_verified)
                                   VALUES (?, 'administrator', ?, ?, ?, ?, 0)");
            $stmt->execute([$companyId, $firstName, $lastName, $email, $hash]);
            $userId = (int) $db->lastInsertId();

            // Create email verification token
            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 hour
            $stmt = $db->prepare("INSERT INTO email_verifications (user_id, token, expires_at) VALUES (?, ?, ?)");
            $stmt->execute([$userId, $token, $expiresAt]);

            $db->commit();

            log_activity('register', 'First administrator account created', $userId, $companyId);

            $verifyLink = APP_URL . '/auth/verify_email.php?token=' . $token;
            $emailBody = $verifyLink = APP_URL . '/auth/verify_email.php?token=' . $token;
            $emailBody = "
<div style='font-family:Arial,sans-serif;max-width:480px;margin:0 auto;'>
    <div style='background:linear-gradient(135deg,#2563EB,#4F46E5,#06B6D4);padding:32px 24px;border-radius:16px 16px 0 0;text-align:center;'>
        <div style='width:56px;height:56px;background:#fff;border-radius:14px;display:inline-flex;align-items:center;justify-content:center;font-size:24px;font-weight:800;color:#2563EB;font-family:Arial,sans-serif;'>R</div>
        <h1 style='color:#fff;margin:16px 0 0;font-size:22px;'>Welcome to RentSphere!</h1>
    </div>
    <div style='background:#ffffff;padding:32px 24px;border-radius:0 0 16px 16px;border:1px solid #E5E9F0;border-top:none;'>
        <p style='font-size:15px;color:#111827;'>Hi {$firstName},</p>
        <p style='font-size:14px;color:#6B7280;line-height:1.6;'>Thanks for creating your RentSphere account. Please verify your email address to activate it:</p>
        <div style='text-align:center;margin:28px 0;'>
            <a href='{$verifyLink}' style='background:linear-gradient(135deg,#2563EB,#4F46E5);color:#fff;padding:14px 32px;border-radius:10px;text-decoration:none;font-weight:600;font-size:14px;display:inline-block;'>Verify My Email</a>
        </div>
        <p style='font-size:12.5px;color:#9CA3AF;'>Or paste this link into your browser:<br><a href='{$verifyLink}' style='color:#2563EB;'>{$verifyLink}</a></p>
        <p style='font-size:12.5px;color:#9CA3AF;margin-top:20px;'>This link expires in 1 hour. If you didn't create this account, you can safely ignore this email.</p>
    </div>
</div>";
            send_email($email, $firstName, 'Verify your ' . APP_NAME . ' account', $emailBody);

            flash('success', 'Account created! Please check your email (' . e($email) . ') to verify your account before logging in.');
            redirect(APP_URL . '/auth/login.php');
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('Registration error: ' . $e->getMessage());
            $errors[] = 'Something went wrong while creating your account. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create Administrator Account — <?= e(APP_NAME) ?></title>
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
        <h1 class="auth-title">Create your administrator account</h1>
        <p class="auth-subtitle">This is the first-time setup — you'll be the system's main administrator.</p>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i>
                <div><?php foreach ($errors as $err) echo e($err) . '<br>'; ?></div>
            </div>
        <?php endif; ?>

        <form method="POST" novalidate>
            <?= csrf_field() ?>
            <div class="form-group">
                <label class="form-label">Company / Business Name</label>
                <input type="text" name="company_name" class="form-control" value="<?= old('company_name') ?>" required>
            </div>
            <div class="form-group d-flex gap-12">
                <div style="flex:1">
                    <label class="form-label">First Name</label>
                    <input type="text" name="first_name" class="form-control" value="<?= old('first_name') ?>" required>
                </div>
                <div style="flex:1">
                    <label class="form-label">Last Name</label>
                    <input type="text" name="last_name" class="form-control" value="<?= old('last_name') ?>" required>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Email Address</label>
                <input type="email" name="email" class="form-control" value="<?= old('email') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control" required>
                <div class="form-hint">At least 8 characters, one uppercase letter, one number.</div>
            </div>
            <div class="form-group">
                <label class="form-label">Confirm Password</label>
                <input type="password" name="confirm_password" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary w-full">Create Account</button>
        </form>
        <div class="auth-footer-link">Already have an account? <a href="login.php">Log in</a></div>
    </div>
</div>
</body>
</html>
