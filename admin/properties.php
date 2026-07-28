<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_role(['administrator']);

$db = Database::getConnection();
$companyId = $_SESSION['company_id'];
$errors = [];

// ---------------- Handle POST actions ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $action = $_POST['form_action'] ?? '';

    if ($action === 'delete') {
        $id = (int) $_POST['id'];
        $stmt = $db->prepare("DELETE FROM properties WHERE id = ? AND company_id = ?");
        $stmt->execute([$id, $companyId]);
        log_activity('property_deleted', "Deleted property #$id");
        flash('success', 'Property deleted successfully.');
        redirect(APP_URL . '/admin/properties.php');
    }

    if ($action === 'save') {
        $id = (int) ($_POST['id'] ?? 0);
        $caretakerId = !empty($_POST['caretaker_id']) ? (int) $_POST['caretaker_id'] : null;
        $name = trim($_POST['name'] ?? '');
        $category = $_POST['category'] ?? 'apartment';
        $status = $_POST['status'] ?? 'active';
        $address = trim($_POST['address'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($name === '' || $address === '') {
            $errors[] = 'Property name and address are required.';
        }

        if (empty($errors)) {
          if ($id > 0) {
    $stmt = $db->prepare("UPDATE properties SET name=?, category=?, status=?, address=?, city=?, description=?, caretaker_id=? WHERE id=? AND company_id=?");
    $stmt->execute([$name, $category, $status, $address, $city, $description, $caretakerId, $id, $companyId]);
    log_activity('property_updated', "Updated property #$id");
    flash('success', 'Property updated successfully.');
} else {
    $stmt = $db->prepare("INSERT INTO properties (company_id, name, category, status, address, city, description, caretaker_id) VALUES (?,?,?,?,?,?,?,?)");
    $stmt->execute([$companyId, $name, $category, $status, $address, $city, $description, $caretakerId]);
    $newId = $db->lastInsertId();
    log_activity('property_created', "Created property #$newId");
    flash('success', 'Property added successfully.');
}
            redirect(APP_URL . '/admin/properties.php');
        }
    }
}
$stmt =  $db->prepare("SELECT id, first_name, last_name FROM users WHERE company_id = ? AND role = 'caretaker' AND is_active = 1 ORDER BY first_name");
$stmt->execute([$companyId]);
$caretakers = $stmt->fetchAll();
$action = $_GET['action'] ?? 'list';
$editRecord = null;
if ($action === 'edit' && !empty($_GET['id'])) {
    $stmt = $db->prepare("SELECT * FROM properties WHERE id = ? AND company_id = ?");
    $stmt->execute([(int) $_GET['id'], $companyId]);
    $editRecord = $stmt->fetch();
    if (!$editRecord) { flash('error', 'Property not found.'); redirect(APP_URL . '/admin/properties.php'); }
}

// ---------------- List data ----------------
$search = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$sql = "SELECT p.*, (SELECT COUNT(*) FROM units u WHERE u.property_id = p.id) AS unit_count
        FROM properties p WHERE p.company_id = ?";
$params = [$companyId];
if ($search !== '') { $sql .= " AND (p.name LIKE ? OR p.address LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($statusFilter !== '') { $sql .= " AND p.status = ?"; $params[] = $statusFilter; }
$sql .= " ORDER BY p.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$properties = $stmt->fetchAll();

$pageTitle = 'Properties';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="breadcrumbs"><a href="dashboard.php">Dashboard</a> / Properties</div>
<div class="page-header">
    <div><h1 class="page-title">Properties</h1><p class="page-subtitle">Manage all your properties in one place.</p></div>
    <?php if ($action === 'list'): ?>
        <a href="?action=add" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add Property</a>
    <?php else: ?>
        <a href="properties.php" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> Back to list</a>
    <?php endif; ?>
</div>

<?php if ($action === 'add' || $action === 'edit'): ?>
    <div class="card" style="max-width:640px;">
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><div><?php foreach ($errors as $err) echo e($err) . '<br>'; ?></div></div>
        <?php endif; ?>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="save">
            <input type="hidden" name="id" value="<?= e((string)($editRecord['id'] ?? '')) ?>">
            <div class="form-group">
                <label class="form-label">Property Name</label>
                <input type="text" name="name" class="form-control" value="<?= e($editRecord['name'] ?? '') ?>" required>
            </div>
            <div class="form-group d-flex gap-12">
                <div style="flex:1">
                    <label class="form-label">Category</label>
                    <select name="category" class="form-control">
                        <?php foreach (['apartment','house','commercial','hostel','other'] as $c): ?>
                            <option value="<?= $c ?>" <?= (($editRecord['category'] ?? '') === $c) ? 'selected' : '' ?>><?= ucfirst($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="flex:1">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-control">
                        <?php foreach (['active','inactive','under_renovation'] as $s): ?>
                            <option value="<?= $s ?>" <?= (($editRecord['status'] ?? 'active') === $s) ? 'selected' : '' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Address</label>
                <input type="text" name="address" class="form-control" value="<?= e($editRecord['address'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Assigned Caretaker</label>
                <select name="caretaker_id" class="form-control">
                    <option value="">— Unassigned —</option>
                    <?php foreach ($caretakers as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= (($editRecord['caretaker_id'] ?? '') == $c['id']) ? 'selected' : '' ?>><?= e($c['first_name'] . ' ' . $c['last_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($caretakers)): ?><div class="form-hint">No caretakers yet — create one in Users &amp; Roles first.</div><?php endif; ?>
            </div>
            <div class="form-group">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-control" rows="4"><?= e($editRecord['description'] ?? '') ?></textarea>
            </div>
            <button type="submit" class="btn btn-primary"><?= $editRecord ? 'Update Property' : 'Add Property' ?></button>
            <div class="form-group">
                <label class="form-label">City</label>
                <input type="text" name="city" class="form-control" value="<?= e($editRecord['city'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-control" rows="4"><?= e($editRecord['description'] ?? '') ?></textarea>
            </div>
            <button type="submit" class="btn btn-primary"><?= $editRecord ? 'Update Property' : 'Add Property' ?></button>
        </form>
    </div>
<?php else: ?>
    <div class="card" style="margin-bottom:18px;">
        <form method="GET" class="d-flex gap-12" style="flex-wrap:wrap;">
            <input type="text" name="q" class="form-control" placeholder="Search by name or address..." value="<?= e($search) ?>" style="max-width:280px;">
            <select name="status" class="form-control" style="max-width:180px;">
                <option value="">All Statuses</option>
                <?php foreach (['active','inactive','under_renovation'] as $s): ?>
                    <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-outline"><i class="fa-solid fa-filter"></i> Filter</button>
        </form>
    </div>

    <div class="card">
        <?php if (empty($properties)): ?>
            <div class="empty-state"><i class="fa-solid fa-building"></i><h3>No properties yet</h3><p>Add your first property to get started.</p></div>
        <?php else: ?>
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th>Name</th><th>Category</th><th>Address</th><th>Units</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($properties as $p): ?>
                <tr>
                    <td><strong><?= e($p['name']) ?></strong></td>
                    <td><?= e(ucfirst($p['category'])) ?></td>
                    <td><?= e($p['address']) ?><?= $p['city'] ? ', ' . e($p['city']) : '' ?></td>
                    <td><?= (int) $p['unit_count'] ?></td>
                    <td><span class="badge badge-<?= $p['status'] === 'active' ? 'success' : ($p['status'] === 'inactive' ? 'neutral' : 'warning') ?>"><?= e(ucfirst(str_replace('_',' ',$p['status']))) ?></span></td>
                    <td>
                        <div class="d-flex gap-8">
                            <a href="?action=edit&id=<?= $p['id'] ?>" class="btn btn-outline btn-sm"><i class="fa-solid fa-pen"></i></a>
                            <form method="POST" style="display:inline" onsubmit="event.preventDefault(); confirmDelete(this, 'This will remove the property and all its units.');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="form_action" value="delete">
                                <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                <button type="submit" class="btn btn-outline btn-sm" style="color:var(--color-danger);"><i class="fa-solid fa-trash"></i></button>
                            </form>
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
