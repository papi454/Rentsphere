<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_role(['administrator']);

$db = Database::getConnection();
$companyId = $_SESSION['company_id'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();

    $name = trim($_POST['name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $currency = trim($_POST['currency'] ?? 'USD');
    $timezone = trim($_POST['timezone'] ?? 'UTC');

    if ($name === '') $errors[] = 'Company name is required.';

    // Logo upload
    $logoPath = null;
    if (!empty($_FILES['logo']['name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['png','jpg','jpeg','svg'], true)) {
            $dir = UPLOAD_PATH . '/branding';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $logoPath = 'uploads/branding/logo_' . $companyId . '.' . $ext;
            move_uploaded_file($_FILES['logo']['tmp_name'], ROOT_PATH . '/' . $logoPath);
        }
    }

    if (empty($errors)) {
        $sql = "UPDATE companies SET name=?, address=?, phone=?, email=?, currency=?, timezone=?";
        $params = [$name, $address, $phone, $email, $currency, $timezone];
        if ($logoPath) { $sql .= ", logo_path=?"; $params[] = $logoPath; }
        $sql .= " WHERE id=?";
        $params[] = $companyId;
        $db->prepare($sql)->execute($params);
        log_activity('settings_updated', 'Company settings updated');
        flash('success', 'Settings updated successfully.');
        redirect(APP_URL . '/admin/settings.php');
    }
}

$stmt = $db->prepare("SELECT * FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch();

$pageTitle = 'Settings';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="breadcrumbs"><a href="dashboard.php">Dashboard</a> / Settings</div>
<div class="page-header"><div><h1 class="page-title">Settings</h1><p class="page-subtitle">Company details and system preferences.</p></div></div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><div><?php foreach ($errors as $err) echo e($err) . '<br>'; ?></div></div>
<?php endif; ?>

<div class="card" style="max-width:640px;">
    <h3>Company Details</h3>
    <form method="POST" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="form-group">
            <label class="form-label">Company Name</label>
            <input type="text" name="name" class="form-control" value="<?= e($company['name']) ?>" required>
        </div>
        <div class="form-group">
            <label class="form-label">Address</label>
            <input type="text" name="address" class="form-control" value="<?= e($company['address'] ?? '') ?>">
        </div>
        <div class="form-group d-flex gap-12">
            <div style="flex:1"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" value="<?= e($company['phone'] ?? '') ?>"></div>
            <div style="flex:1"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= e($company['email'] ?? '') ?>"></div>
        </div>
        <div class="form-group d-flex gap-12">
            <div style="flex:1"><label class="form-label">Currency</label><input type="text" name="currency" class="form-control" value="<?= e($company['currency']) ?>"></div>
            <div style="flex:1"><label class="form-label">Timezone</label><input type="text" name="timezone" class="form-control" value="<?= e($company['timezone']) ?>"></div>
        </div>
        <div class="form-group">
            <label class="form-label">Logo</label>
            <input type="file" name="logo" class="form-control">
            <?php if (!empty($company['logo_path'])): ?><div class="form-hint">Current logo uploaded.</div><?php endif; ?>
        </div>
        <button type="submit" class="btn btn-primary">Save Settings</button>
    </form>
</div>

<div class="card" style="max-width:640px;margin-top:20px;">
    <h3><i class="fa-solid fa-link" style="color:var(--color-primary);"></i> Tenant Sign-Up Link</h3>
    <p class="text-secondary">Share this with prospective tenants so they can create their own account under your company. You'll approve each request and assign their unit.</p>
    <div class="d-flex gap-8">
        <input type="text" class="form-control" readonly id="tenantLinkInput" value="<?= e(APP_URL . '/auth/tenant_register.php?company=' . ($company['slug'] ?? '')) ?>">
        <button type="button" class="btn btn-outline" onclick="navigator.clipboard.writeText(document.getElementById('tenantLinkInput').value); this.textContent='Copied!';">Copy</button>
    </div>
</div>

<div class="card" style="max-width:640px;margin-top:20px;">
    <h3>SMTP / Mail Settings</h3>
    <p class="text-secondary">Mail settings are currently configured in <code>config/config.php</code> (MAIL_HOST, MAIL_USERNAME, etc). Move these to the database-backed <code>settings</code> table if you want them editable from here.</p>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
