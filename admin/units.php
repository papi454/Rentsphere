<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_role(['administrator']);

$db = Database::getConnection();
$companyId = $_SESSION['company_id'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $action = $_POST['form_action'] ?? '';

    if ($action === 'delete') {
        $id = (int) $_POST['id'];
        $stmt = $db->prepare("DELETE FROM units WHERE id = ? AND property_id IN (SELECT id FROM properties WHERE company_id = ?)");
        $stmt->execute([$id, $companyId]);
        log_activity('unit_deleted', "Deleted unit #$id");
        flash('success', 'Unit deleted successfully.');
        redirect(APP_URL . '/admin/units.php');
    }

    if ($action === 'save') {
        $id = (int) ($_POST['id'] ?? 0);
        $propertyId = (int) $_POST['property_id'];
        $unitNumber = trim($_POST['unit_number'] ?? '');
        $floor = trim($_POST['floor'] ?? '');
        $bedrooms = (int) ($_POST['bedrooms'] ?? 0);
        $bathrooms = (int) ($_POST['bathrooms'] ?? 0);
        $rent = (float) ($_POST['rent_amount'] ?? 0);
        $deposit = (float) ($_POST['deposit_amount'] ?? 0);
        $occStatus = $_POST['occupancy_status'] ?? 'vacant';

        // verify property belongs to this company
        $stmt = $db->prepare("SELECT id FROM properties WHERE id = ? AND company_id = ?");
        $stmt->execute([$propertyId, $companyId]);
        if (!$stmt->fetch()) $errors[] = 'Invalid property selected.';
        if ($unitNumber === '') $errors[] = 'Unit number is required.';
        if ($rent <= 0) $errors[] = 'Rent amount must be greater than zero.';

        if (empty($errors)) {
            if ($id > 0) {
                $stmt = $db->prepare("UPDATE units SET property_id=?, unit_number=?, floor=?, bedrooms=?, bathrooms=?, rent_amount=?, deposit_amount=?, occupancy_status=? WHERE id=?");
                $stmt->execute([$propertyId, $unitNumber, $floor, $bedrooms, $bathrooms, $rent, $deposit, $occStatus, $id]);
                log_activity('unit_updated', "Updated unit #$id");
                flash('success', 'Unit updated successfully.');
            } else {
                $stmt = $db->prepare("INSERT INTO units (property_id, unit_number, floor, bedrooms, bathrooms, rent_amount, deposit_amount, occupancy_status) VALUES (?,?,?,?,?,?,?,?)");
                $stmt->execute([$propertyId, $unitNumber, $floor, $bedrooms, $bathrooms, $rent, $deposit, $occStatus]);
                log_activity('unit_created', "Created unit in property #$propertyId");
                flash('success', 'Unit added successfully.');
            }
            redirect(APP_URL . '/admin/units.php');
        }
    }
}

$stmt = $db->prepare("SELECT id, name FROM properties WHERE company_id = ? ORDER BY name");
$stmt->execute([$companyId]);
$allProperties = $stmt->fetchAll();

$action = $_GET['action'] ?? 'list';
$editRecord = null;
if ($action === 'edit' && !empty($_GET['id'])) {
    $stmt = $db->prepare("SELECT u.* FROM units u JOIN properties p ON p.id = u.property_id WHERE u.id = ? AND p.company_id = ?");
    $stmt->execute([(int) $_GET['id'], $companyId]);
    $editRecord = $stmt->fetch();
    if (!$editRecord) { flash('error', 'Unit not found.'); redirect(APP_URL . '/admin/units.php'); }
}

$propertyFilter = (int) ($_GET['property_id'] ?? 0);
$sql = "SELECT u.*, p.name AS property_name FROM units u JOIN properties p ON p.id = u.property_id WHERE p.company_id = ?";
$params = [$companyId];
if ($propertyFilter > 0) { $sql .= " AND u.property_id = ?"; $params[] = $propertyFilter; }
$sql .= " ORDER BY u.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$units = $stmt->fetchAll();

$pageTitle = 'Units';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="breadcrumbs"><a href="dashboard.php">Dashboard</a> / Units</div>
<div class="page-header">
    <div><h1 class="page-title">Units</h1><p class="page-subtitle">Manage rental units across your properties.</p></div>
    <?php if ($action === 'list'): ?>
        <a href="?action=add" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add Unit</a>
    <?php else: ?>
        <a href="units.php" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> Back to list</a>
    <?php endif; ?>
</div>

<?php if (empty($allProperties)): ?>
    <div class="card"><div class="empty-state"><i class="fa-solid fa-building"></i><h3>Add a property first</h3><p>You need at least one property before you can add units.</p><a href="properties.php?action=add" class="btn btn-primary" style="margin-top:10px;">Add Property</a></div></div>
<?php elseif ($action === 'add' || $action === 'edit'): ?>
    <div class="card" style="max-width:640px;">
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><div><?php foreach ($errors as $err) echo e($err) . '<br>'; ?></div></div>
        <?php endif; ?>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="save">
            <input type="hidden" name="id" value="<?= e((string)($editRecord['id'] ?? '')) ?>">
            <div class="form-group">
                <label class="form-label">Property</label>
                <select name="property_id" class="form-control" required>
                    <?php foreach ($allProperties as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= (($editRecord['property_id'] ?? '') == $p['id']) ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group d-flex gap-12">
                <div style="flex:1"><label class="form-label">Unit Number</label><input type="text" name="unit_number" class="form-control" value="<?= e($editRecord['unit_number'] ?? '') ?>" required></div>
                <div style="flex:1"><label class="form-label">Floor</label><input type="text" name="floor" class="form-control" value="<?= e($editRecord['floor'] ?? '') ?>"></div>
            </div>
            <div class="form-group d-flex gap-12">
                <div style="flex:1"><label class="form-label">Bedrooms</label><input type="number" min="0" name="bedrooms" class="form-control" value="<?= e((string)($editRecord['bedrooms'] ?? 0)) ?>"></div>
                <div style="flex:1"><label class="form-label">Bathrooms</label><input type="number" min="0" name="bathrooms" class="form-control" value="<?= e((string)($editRecord['bathrooms'] ?? 0)) ?>"></div>
            </div>
            <div class="form-group d-flex gap-12">
                <div style="flex:1"><label class="form-label">Rent Amount</label><input type="number" step="0.01" min="0" name="rent_amount" class="form-control" value="<?= e((string)($editRecord['rent_amount'] ?? '')) ?>" required></div>
                <div style="flex:1"><label class="form-label">Deposit Amount</label><input type="number" step="0.01" min="0" name="deposit_amount" class="form-control" value="<?= e((string)($editRecord['deposit_amount'] ?? '')) ?>"></div>
            </div>
            <div class="form-group">
                <label class="form-label">Occupancy Status</label>
                <select name="occupancy_status" class="form-control">
                    <?php foreach (['vacant','occupied','reserved'] as $s): ?>
                        <option value="<?= $s ?>" <?= (($editRecord['occupancy_status'] ?? 'vacant') === $s) ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary"><?= $editRecord ? 'Update Unit' : 'Add Unit' ?></button>
        </form>
    </div>
<?php else: ?>
    <div class="card" style="margin-bottom:18px;">
        <form method="GET" class="d-flex gap-12">
            <select name="property_id" class="form-control" style="max-width:280px;" onchange="this.form.submit()">
                <option value="0">All Properties</option>
                <?php foreach ($allProperties as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= $propertyFilter === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <div class="card">
        <?php if (empty($units)): ?>
            <div class="empty-state"><i class="fa-solid fa-door-open"></i><h3>No units yet</h3><p>Add a unit to a property to get started.</p></div>
        <?php else: ?>
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th>Unit</th><th>Property</th><th>Beds/Baths</th><th>Rent</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($units as $u): ?>
                <tr>
                    <td><strong><?= e($u['unit_number']) ?></strong><?= $u['floor'] ? ' · Floor ' . e($u['floor']) : '' ?></td>
                    <td><?= e($u['property_name']) ?></td>
                    <td><?= (int)$u['bedrooms'] ?> bd / <?= (int)$u['bathrooms'] ?> ba</td>
                    <td>$<?= money($u['rent_amount']) ?>/mo</td>
                    <td><span class="badge badge-<?= $u['occupancy_status'] === 'occupied' ? 'info' : ($u['occupancy_status'] === 'vacant' ? 'success' : 'warning') ?>"><?= ucfirst($u['occupancy_status']) ?></span></td>
                    <td>
                        <div class="d-flex gap-8">
                            <a href="?action=edit&id=<?= $u['id'] ?>" class="btn btn-outline btn-sm"><i class="fa-solid fa-pen"></i></a>
                            <form method="POST" style="display:inline" onsubmit="event.preventDefault(); confirmDelete(this);">
                                <?= csrf_field() ?>
                                <input type="hidden" name="form_action" value="delete">
                                <input type="hidden" name="id" value="<?= $u['id'] ?>">
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
