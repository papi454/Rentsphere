<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_role(['administrator']);

$db = Database::getConnection();
$companyId = $_SESSION['company_id'];
$errors = [];

function handle_upload(string $field, string $subfolder): ?string
{
    if (empty($_FILES[$field]['name'])) return null;
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) return null;

    $allowed = ['jpg','jpeg','png','pdf'];
    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) return null;

    $dir = UPLOAD_PATH . '/' . $subfolder;
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $filename = bin2hex(random_bytes(12)) . '.' . $ext;
    $dest = $dir . '/' . $filename;
    if (move_uploaded_file($_FILES[$field]['tmp_name'], $dest)) {
        return 'uploads/' . $subfolder . '/' . $filename;
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $action = $_POST['form_action'] ?? '';

    if ($action === 'delete') {
        $id = (int) $_POST['id'];
        $stmt = $db->prepare("UPDATE tenants SET status = 'former' WHERE id = ? AND company_id = ?");
        $stmt->execute([$id, $companyId]);
        log_activity('tenant_moved_out', "Tenant #$id marked as former");
        flash('success', 'Tenant marked as former (moved out). Historical records are preserved.');
        redirect(APP_URL . '/admin/tenants.php');
    }

    if ($action === 'save') {
        $id = (int) ($_POST['id'] ?? 0);
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $emName = trim($_POST['emergency_contact_name'] ?? '');
        $emPhone = trim($_POST['emergency_contact_phone'] ?? '');

        if ($firstName === '' || $lastName === '') $errors[] = 'First and last name are required.';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email or leave it blank.';

        if (empty($errors)) {
            $idDocPath = handle_upload('id_document', 'tenant_ids');
            $photoPath = handle_upload('photo', 'tenant_photos');

            if ($id > 0) {
                $sql = "UPDATE tenants SET first_name=?, last_name=?, email=?, phone=?, emergency_contact_name=?, emergency_contact_phone=?";
                $params = [$firstName, $lastName, $email, $phone, $emName, $emPhone];
                if ($idDocPath) { $sql .= ", id_document_path=?"; $params[] = $idDocPath; }
                if ($photoPath) { $sql .= ", photo_path=?"; $params[] = $photoPath; }
                $sql .= " WHERE id=? AND company_id=?";
                $params[] = $id; $params[] = $companyId;
                $db->prepare($sql)->execute($params);
                log_activity('tenant_updated', "Updated tenant #$id");
                flash('success', 'Tenant updated successfully.');
            } else {
                $stmt = $db->prepare("INSERT INTO tenants (company_id, first_name, last_name, email, phone, id_document_path, photo_path, emergency_contact_name, emergency_contact_phone)
                                       VALUES (?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$companyId, $firstName, $lastName, $email, $phone, $idDocPath, $photoPath, $emName, $emPhone]);
                $newId = $db->lastInsertId();
                log_activity('tenant_created', "Registered tenant #$newId");
                create_notification($companyId, 'new_tenant', 'New Tenant Registered', "$firstName $lastName was registered as a new tenant.");
                flash('success', 'Tenant registered successfully. Assign them to a unit from the Leases page.');
            }
            redirect(APP_URL . '/admin/tenants.php');
        }
    }
}

$action = $_GET['action'] ?? 'list';
$editRecord = null;
if ($action === 'edit' && !empty($_GET['id'])) {
    $stmt = $db->prepare("SELECT * FROM tenants WHERE id = ? AND company_id = ?");
    $stmt->execute([(int) $_GET['id'], $companyId]);
    $editRecord = $stmt->fetch();
    if (!$editRecord) { flash('error', 'Tenant not found.'); redirect(APP_URL . '/admin/tenants.php'); }
}

$search = trim($_GET['q'] ?? '');
$sql = "SELECT t.*, (SELECT u.unit_number FROM leases l JOIN units u ON u.id = l.unit_id WHERE l.tenant_id = t.id AND l.status='active' LIMIT 1) AS current_unit
        FROM tenants t WHERE t.company_id = ?";
$params = [$companyId];
if ($search !== '') { $sql .= " AND (t.first_name LIKE ? OR t.last_name LIKE ? OR t.email LIKE ?)"; $params = array_merge($params, ["%$search%","%$search%","%$search%"]); }
$sql .= " ORDER BY t.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$tenants = $stmt->fetchAll();

$pageTitle = 'Tenants';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="breadcrumbs"><a href="dashboard.php">Dashboard</a> / Tenants</div>
<div class="page-header">
    <div><h1 class="page-title">Tenants</h1><p class="page-subtitle">Manage tenant profiles and records.</p></div>
    <?php if ($action === 'list'): ?>
        <a href="?action=add" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Register Tenant</a>
    <?php else: ?>
        <a href="tenants.php" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> Back to list</a>
    <?php endif; ?>
</div>

<?php if ($action === 'add' || $action === 'edit'): ?>
    <div class="card" style="max-width:640px;">
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><div><?php foreach ($errors as $err) echo e($err) . '<br>'; ?></div></div>
        <?php endif; ?>
        <form method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="save">
            <input type="hidden" name="id" value="<?= e((string)($editRecord['id'] ?? '')) ?>">
            <div class="form-group d-flex gap-12">
                <div style="flex:1"><label class="form-label">First Name</label><input type="text" name="first_name" class="form-control" value="<?= e($editRecord['first_name'] ?? '') ?>" required></div>
                <div style="flex:1"><label class="form-label">Last Name</label><input type="text" name="last_name" class="form-control" value="<?= e($editRecord['last_name'] ?? '') ?>" required></div>
            </div>
            <div class="form-group d-flex gap-12">
                <div style="flex:1"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= e($editRecord['email'] ?? '') ?>"></div>
                <div style="flex:1"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" value="<?= e($editRecord['phone'] ?? '') ?>"></div>
            </div>
            <div class="form-group d-flex gap-12">
                <div style="flex:1"><label class="form-label">Emergency Contact Name</label><input type="text" name="emergency_contact_name" class="form-control" value="<?= e($editRecord['emergency_contact_name'] ?? '') ?>"></div>
                <div style="flex:1"><label class="form-label">Emergency Contact Phone</label><input type="text" name="emergency_contact_phone" class="form-control" value="<?= e($editRecord['emergency_contact_phone'] ?? '') ?>"></div>
            </div>
            <div class="form-group d-flex gap-12">
                <div style="flex:1"><label class="form-label">ID Document (jpg/png/pdf)</label><input type="file" name="id_document" class="form-control"></div>
                <div style="flex:1"><label class="form-label">Photo (jpg/png)</label><input type="file" name="photo" class="form-control"></div>
            </div>
            <button type="submit" class="btn btn-primary"><?= $editRecord ? 'Update Tenant' : 'Register Tenant' ?></button>
        </form>
    </div>
<?php else: ?>
    <div class="card" style="margin-bottom:18px;">
        <form method="GET" class="d-flex gap-12">
            <input type="text" name="q" class="form-control" placeholder="Search by name or email..." value="<?= e($search) ?>" style="max-width:300px;">
            <button type="submit" class="btn btn-outline"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
        </form>
    </div>
    <div class="card">
        <?php if (empty($tenants)): ?>
            <div class="empty-state"><i class="fa-solid fa-users"></i><h3>No tenants yet</h3><p>Register your first tenant to get started.</p></div>
        <?php else: ?>
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th>Name</th><th>Contact</th><th>Current Unit</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($tenants as $t): ?>
                <tr>
                    <td class="d-flex align-center gap-8">
                        <div class="avatar" style="width:32px;height:32px;font-size:11px;">
                            <?php if ($t['photo_path']): ?><img src="<?= APP_URL ?>/<?= e($t['photo_path']) ?>"><?php else: ?><?= e(strtoupper(substr($t['first_name'],0,1).substr($t['last_name'],0,1))) ?><?php endif; ?>
                        </div>
                        <strong><?= e($t['first_name'] . ' ' . $t['last_name']) ?></strong>
                    </td>
                    <td><?= e($t['email'] ?: '—') ?><br><span class="text-secondary"><?= e($t['phone'] ?: '') ?></span></td>
                    <td><?= $t['current_unit'] ? e($t['current_unit']) : '<span class="text-secondary">Unassigned</span>' ?></td>
                    <td><span class="badge badge-<?= $t['status'] === 'active' ? 'success' : 'neutral' ?>"><?= ucfirst($t['status']) ?></span></td>
                    <td>
                        <div class="d-flex gap-8">
                            <a href="?action=edit&id=<?= $t['id'] ?>" class="btn btn-outline btn-sm"><i class="fa-solid fa-pen"></i></a>
                            <?php if ($t['status'] === 'active'): ?>
                            <form method="POST" style="display:inline" onsubmit="event.preventDefault(); confirmDelete(this, 'Mark this tenant as moved out (former)?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="form_action" value="delete">
                                <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                <button type="submit" class="btn btn-outline btn-sm" style="color:var(--color-danger);"><i class="fa-solid fa-right-from-bracket"></i></button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
