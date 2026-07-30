<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (is_logged_in()) {
    redirect(APP_URL . '/admin/dashboard.php');
}

$db = Database::getConnection();
$errors = [];

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

            $slug = generate_unique_company_slug($db, $companyName, $companyId);
            $db->prepare("UPDATE companies SET slug = ? WHERE id = ?")->execute([$slug, $companyId]);

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
            $emailBody = build_email_html(
                "Welcome, {$firstName} 👋",
                "<p style='margin:0 0 12px;'>Thanks for creating your " . APP_NAME . " account. Please verify your email address to activate it and start managing your properties.</p>
                 <p style='margin:0;color:#9AA4B8;font-size:13px;'>This link expires in 1 hour.</p>",
                'Verify Email Address',
                $verifyLink
            );
            @send_email($email, $firstName, 'Verify your ' . APP_NAME . ' account', $emailBody);

            flash('success', 'Account created! Please check your email (' . e($email) . ') to verify your account before logging in. Your tenant sign-up link: ' . APP_URL . '/auth/tenant_register.php?company=' . $slug . ' (also available anytime in Settings).');
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
        <h1 class="auth-title">Create your company account</h1>
        <p class="auth-subtitle">Set up RentSphere for your property business — you'll be the administrator.</p>

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
