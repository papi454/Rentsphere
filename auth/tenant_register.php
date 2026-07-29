<?php
require_once __DIR__ . '/../includes/bootstrap.php';
if (is_logged_in()) redirect(APP_URL . '/admin/dashboard.php');

$db = Database::getConnection();
$errors = [];

// This install belongs to one company (the first admin's company)
$companyId = (int) $db->query("SELECT id FROM companies ORDER BY id ASC LIMIT 1")->fetchColumn();

if (!$companyId) {
    flash('error', 'This system is not set up yet. Please contact your property administrator.');
    redirect(APP_URL . '/auth/login.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();

    $firstName = trim($_POST['first_name'] ?? '');
    $lastName  = trim($_POST['last_name'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $password  = $_POST['password'] ?? '';
    $confirm   = $_POST['confirm_password'] ?? '';

    if ($firstName === '' || $lastName === '' || $email === '') $errors[] = 'Name and email are required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
    if (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must be at least 8 characters with an uppercase letter and a number.';
    }
    if ($password !== $confirm) $errors[] = 'Passwords do not match.';

    if (empty($errors)) {
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) $errors[] = 'An account with this email already exists.';
    }

    if (empty($errors)) {
        $db->beginTransaction();

        // Account is created inactive — stays that way until an admin/caretaker approves + assigns a unit
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (company_id, role, first_name, last_name, email, phone, password_hash, is_email_verified, is_active)
                               VALUES (?, 'tenant', ?, ?, ?, ?, ?, 0, 0)");
        $stmt->execute([$companyId, $firstName, $lastName, $email, $phone, $hash]);
        $userId = (int) $db->lastInsertId();

        $stmt = $db->prepare("INSERT INTO tenants (company_id, user_id, first_name, last_name, email, phone, status) VALUES (?,?,?,?,?,?, 'pending')");
        $stmt->execute([$companyId, $userId, $firstName, $lastName, $email, $phone]);

        // Email verification (same flow as staff accounts)
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 3600);
        $stmt = $db->prepare("INSERT INTO email_verifications (user_id, token, expires_at) VALUES (?, ?, ?)");
        $stmt->execute([$userId, $token, $expiresAt]);

        $db->commit();

        log_activity('tenant_self_registered', "Tenant self-registered, awaiting approval", $userId, $companyId);
        log_activity('tenant_self_registered', "Tenant self-registered, awaiting approval", $userId, $companyId);

// Notify every admin/caretaker who can actually approve this application
$stmt = $db->prepare("SELECT id FROM users WHERE company_id = ? AND role IN ('administrator','caretaker') AND is_active = 1");
$stmt->execute([$companyId]);
foreach ($stmt->fetchAll() as $staffMember) {
    create_notification($companyId, 'new_tenant_application', 'New Tenant Application',
        "$firstName $lastName has requested a tenant account and is awaiting approval.", $staffMember['id']);
}

        $verifyLink = APP_URL . '/auth/verify_email.php?token=' . $token;
        $emailBody = "<p>Hi {$firstName},</p>
            <p>Thanks for signing up! First, verify your email:</p>
            <p><a href='{$verifyLink}' style='background:#2563EB;color:#fff;padding:12px 20px;border-radius:8px;text-decoration:none;'>Verify Email</a></p>
            <p>After that, your property administrator or caretaker will review your request and assign you to your unit. You'll get another email once you're approved and can log in.</p>";
        @send_email($email, $firstName, 'Verify your ' . APP_NAME . ' tenant account', $emailBody);

        flash('success', 'Account created! Check your email to verify it. Your administrator will review your request and assign you to your unit before you can log in.');
        redirect(APP_URL . '/auth/login.php');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tenant Sign Up — <?= e(APP_NAME) ?></title>
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
        <h1 class="auth-title">Create your tenant account</h1>
        <p class="auth-subtitle">Sign up, then your property administrator will approve you and assign your unit.</p>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i>
                <div><?php foreach ($errors as $err) echo e($err) . '<br>'; ?></div>
            </div>
        <?php endif; ?>

        <form method="POST" novalidate>
            <?= csrf_field() ?>
            <div class="form-group d-flex gap-12">
                <div style="flex:1"><label class="form-label">First Name</label><input type="text" name="first_name" class="form-control" required></div>
                <div style="flex:1"><label class="form-label">Last Name</label><input type="text" name="last_name" class="form-control" required></div>
            </div>
            <div class="form-group">
                <label class="form-label">Email Address</label>
                <input type="email" name="email" class="form-control" required>
            </div>
            <div class="form-group">
                <label class="form-label">Phone</label>
                <input type="text" name="phone" class="form-control">
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
        <div class="auth-footer-link">Already approved? <a href="login.php">Log in</a></div>
    </div>
</div>
</body>
</html>
