<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_role(['administrator','caretaker']);

$db = Database::getConnection();
$userId = $_SESSION['user_id'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $action = $_POST['form_action'] ?? '';

    if ($action === 'update_profile') {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        if ($firstName === '' || $lastName === '') $errors[] = 'Name fields are required.';
        if (empty($errors)) {
            $db->prepare("UPDATE users SET first_name=?, last_name=?, phone=? WHERE id=?")->execute([$firstName, $lastName, $phone, $userId]);
            flash('success', 'Profile updated.');
            redirect(APP_URL . '/admin/profile.php');
        }
    }

    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $hash = $stmt->fetchColumn();

        if (!password_verify($current, $hash)) $errors[] = 'Current password is incorrect.';
        elseif (strlen($new) < 8 || !preg_match('/[A-Z]/', $new) || !preg_match('/[0-9]/', $new)) $errors[] = 'New password must be 8+ chars with an uppercase letter and a number.';
        elseif ($new !== $confirm) $errors[] = 'New passwords do not match.';

        if (empty($errors)) {
            $newHash = password_hash($new, PASSWORD_DEFAULT);
            $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$newHash, $userId]);
            log_activity('password_changed', 'User changed their own password');
            flash('success', 'Password changed successfully.');
            redirect(APP_URL . '/admin/profile.php');
        }
    }
}

$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$me = $stmt->fetch();

$pageTitle = 'My Profile';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="breadcrumbs"><a href="dashboard.php">Dashboard</a> / My Profile</div>
<div class="page-header"><div><h1 class="page-title">My Profile</h1></div></div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><div><?php foreach ($errors as $err) echo e($err) . '<br>'; ?></div></div>
<?php endif; ?>

<div class="grid-2">
    <div class="card">
        <h3>Profile Details</h3>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="update_profile">
            <div class="form-group d-flex gap-12">
                <div style="flex:1"><label class="form-label">First Name</label><input type="text" name="first_name" class="form-control" value="<?= e($me['first_name']) ?>" required></div>
                <div style="flex:1"><label class="form-label">Last Name</label><input type="text" name="last_name" class="form-control" value="<?= e($me['last_name']) ?>" required></div>
            </div>
            <div class="form-group"><label class="form-label">Email</label><input type="email" class="form-control" value="<?= e($me['email']) ?>" disabled></div>
            <div class="form-group"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" value="<?= e($me['phone'] ?? '') ?>"></div>
            <button type="submit" class="btn btn-primary">Save Changes</button>
        </form>
    </div>
    <div class="card">
        <h3>Change Password</h3>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="change_password">
            <div class="form-group"><label class="form-label">Current Password</label><input type="password" name="current_password" class="form-control" required></div>
            <div class="form-group"><label class="form-label">New Password</label><input type="password" name="new_password" class="form-control" required></div>
            <div class="form-group"><label class="form-label">Confirm New Password</label><input type="password" name="confirm_password" class="form-control" required></div>
            <button type="submit" class="btn btn-primary">Change Password</button>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
