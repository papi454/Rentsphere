<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_role(['administrator']);

$db = Database::getConnection();
$companyId = $_SESSION['company_id'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $action = $_POST['form_action'] ?? '';

    if ($action === 'toggle_active') {
        $id = (int) $_POST['id'];
        if ($id !== (int) $_SESSION['user_id']) {
            $db->prepare("UPDATE users SET is_active = NOT is_active WHERE id = ? AND company_id = ?")->execute([$id, $companyId]);
            log_activity('user_status_toggled', "Toggled active status for user #$id");
        }
        redirect(APP_URL . '/admin/users.php');
    }

    if ($action === 'save') {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? 'caretaker';
        $password = $_POST['password'] ?? '';

        if ($firstName === '' || $lastName === '' || $email === '') $errors[] = 'All fields are required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email.';
        if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
        if (!in_array($role, ['administrator','caretaker'], true)) $errors[] = 'Invalid role.';

        if (empty($errors)) {
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) $errors[] = 'A user with this email already exists.';
        }

        if (empty($errors)) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("INSERT INTO users (company_id, role, first_name, last_name, email, password_hash, is_email_verified)
                                   VALUES (?,?,?,?,?,?,1)"); // admin-created accounts are pre-verified
            $stmt->execute([$companyId, $role, $firstName, $lastName, $email, $hash]);
            $newId = $db->lastInsertId();
            log_activity('user_created', "Created $role account #$newId");
            flash('success', ucfirst($role) . ' account created successfully.');
            redirect(APP_URL . '/admin/users.php');
        }
    }
}

$action = $_GET['action'] ?? 'list';
$stmt = $db->prepare("SELECT * FROM users WHERE company_id = ? ORDER BY created_at DESC");
$stmt->execute([$companyId]);
$users = $stmt->fetchAll();

$pageTitle = 'Users & Roles';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="breadcrumbs"><a href="dashboard.php">Dashboard</a> / Users &amp; Roles</div>
<div class="page-header">
    <div><h1 class="page-title">Users &amp; Roles</h1><p class="page-subtitle">Manage administrators and caretakers.</p></div>
    <?php if ($action === 'list'): ?>
        <a href="?action=add" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add User</a>
    <?php else: ?>
        <a href="users.php" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> Back</a>
    <?php endif; ?>
</div>

<?php if ($action === 'add'): ?>
    <div class="card" style="max-width:560px;">
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><div><?php foreach ($errors as $err) echo e($err) . '<br>'; ?></div></div>
        <?php endif; ?>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="save">
            <div class="form-group d-flex gap-12">
                <div style="flex:1"><label class="form-label">First Name</label><input type="text" name="first_name" class="form-control" required></div>
                <div style="flex:1"><label class="form-label">Last Name</label><input type="text" name="last_name" class="form-control" required></div>
            </div>
            <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" class="form-control" required></div>
            <div class="form-group"><label class="form-label">Temporary Password</label><input type="password" name="password" class="form-control" required></div>
            <div class="form-group">
                <label class="form-label">Role</label>
                <select name="role" class="form-control">
                    <option value="caretaker">Caretaker</option>
                    <option value="administrator">Administrator</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Create User</button>
        </form>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last Login</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= e($u['first_name'].' '.$u['last_name']) ?></td>
                    <td><?= e($u['email']) ?></td>
                    <td><span class="badge badge-info"><?= ucfirst($u['role']) ?></span></td>
                    <td><span class="badge badge-<?= $u['is_active']?'success':'neutral' ?>"><?= $u['is_active'] ? 'Active' : 'Deactivated' ?></span></td>
                    <td><?= $u['last_login_at'] ? format_date($u['last_login_at'],'M j, Y g:i A') : 'Never' ?></td>
                    <td>
                        <?php if ((int)$u['id'] !== (int)$_SESSION['user_id']): ?>
                        <form method="POST"><?= csrf_field() ?><input type="hidden" name="form_action" value="toggle_active"><input type="hidden" name="id" value="<?= $u['id'] ?>">
                            <button type="submit" class="btn btn-outline btn-sm"><?= $u['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                        </form>
                        <?php else: ?><span class="text-secondary">You</span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
