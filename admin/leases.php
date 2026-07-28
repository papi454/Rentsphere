<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_role(['administrator']);

$db = Database::getConnection();
$companyId = $_SESSION['company_id'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $action = $_POST['form_action'] ?? '';

    if ($action === 'move_out') {
        $leaseId = (int) $_POST['id'];
        $db->beginTransaction();
        $stmt = $db->prepare("SELECT * FROM leases WHERE id = ?");
        $stmt->execute([$leaseId]);
        $lease = $stmt->fetch();
        if ($lease) {
            $db->prepare("UPDATE leases SET status='ended', move_out_date = CURDATE() WHERE id = ?")->execute([$leaseId]);
            $db->prepare("UPDATE units SET occupancy_status='vacant' WHERE id = ?")->execute([$lease['unit_id']]);
            log_activity('lease_ended', "Ended lease #$leaseId (move-out)");
        }
        $db->commit();
        flash('success', 'Tenant moved out. Unit marked as vacant.');
        redirect(APP_URL . '/admin/leases.php');
    }

    if ($action === 'save') {
        $tenantId = (int) $_POST['tenant_id'];
        $unitId = (int) $_POST['unit_id'];
        $rent = (float) $_POST['rent_amount'];
        $deposit = (float) ($_POST['deposit_amount'] ?? 0);
        $moveIn = $_POST['move_in_date'] ?? '';
        $leaseStart = $_POST['lease_start'] ?? $moveIn;
        $leaseEnd = $_POST['lease_end'] ?? '';

        if (!$tenantId || !$unitId) $errors[] = 'Please select both a tenant and a unit.';
        if ($moveIn === '' || $leaseEnd === '') $errors[] = 'Move-in date and lease end date are required.';
        if ($leaseEnd !== '' && $moveIn !== '' && strtotime($leaseEnd) <= strtotime($moveIn)) $errors[] = 'Lease end date must be after the move-in date.';

        // check unit isn't already occupied by an active lease
        if (empty($errors)) {
            $stmt = $db->prepare("SELECT id FROM leases WHERE unit_id = ? AND status = 'active'");
            $stmt->execute([$unitId]);
            if ($stmt->fetch()) $errors[] = 'This unit already has an active lease. End the current lease before assigning a new tenant.';
        }

        if (empty($errors)) {
            $db->beginTransaction();
            $stmt = $db->prepare("INSERT INTO leases (tenant_id, unit_id, rent_amount, deposit_amount, move_in_date, lease_start, lease_end, status)
                                   VALUES (?,?,?,?,?,?,?, 'active')");
            $stmt->execute([$tenantId, $unitId, $rent, $deposit, $moveIn, $leaseStart, $leaseEnd]);
            $db->prepare("UPDATE units SET occupancy_status='occupied' WHERE id = ?")->execute([$unitId]);
            $db->commit();

            log_activity('lease_created', "Created lease for tenant #$tenantId, unit #$unitId");
            flash('success', 'Lease created and tenant moved in successfully.');
            redirect(APP_URL . '/admin/leases.php');
        }
    }
}

$stmt = $db->prepare("SELECT id, first_name, last_name FROM tenants WHERE company_id = ? AND status='active' ORDER BY first_name");
$stmt->execute([$companyId]);
$allTenants = $stmt->fetchAll();

$stmt = $db->prepare("SELECT u.id, u.unit_number, u.rent_amount, u.deposit_amount, p.name AS property_name FROM units u
                       JOIN properties p ON p.id = u.property_id WHERE p.company_id = ? AND u.occupancy_status != 'occupied' ORDER BY p.name, u.unit_number");
$stmt->execute([$companyId]);
$availableUnits = $stmt->fetchAll();

$action = $_GET['action'] ?? 'list';

$statusFilter = $_GET['status'] ?? 'active';
$sql = "SELECT l.*, t.first_name, t.last_name, u.unit_number, p.name AS property_name FROM leases l
        JOIN tenants t ON t.id = l.tenant_id JOIN units u ON u.id = l.unit_id JOIN properties p ON p.id = u.property_id
        WHERE p.company_id = ?";
$params = [$companyId];
if ($statusFilter !== '') { $sql .= " AND l.status = ?"; $params[] = $statusFilter; }
$sql .= " ORDER BY l.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$leases = $stmt->fetchAll();

$pageTitle = 'Leases';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="breadcrumbs"><a href="dashboard.php">Dashboard</a> / Leases</div>
<div class="page-header">
    <div><h1 class="page-title">Leases</h1><p class="page-subtitle">Move tenants in, track lease terms, and manage move-outs.</p></div>
    <?php if ($action === 'list'): ?>
        <a href="?action=add" class="btn btn-primary"><i class="fa-solid fa-plus"></i> New Lease (Move-In)</a>
    <?php else: ?>
        <a href="leases.php" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> Back to list</a>
    <?php endif; ?>
</div>

<?php if ($action === 'add'): ?>
    <div class="card" style="max-width:640px;">
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><div><?php foreach ($errors as $err) echo e($err) . '<br>'; ?></div></div>
        <?php endif; ?>
        <?php if (empty($allTenants) || empty($availableUnits)): ?>
            <div class="empty-state"><i class="fa-solid fa-triangle-exclamation"></i><h3>Missing data</h3>
            <p><?= empty($allTenants) ? 'Register a tenant first. ' : '' ?><?= empty($availableUnits) ? 'You need at least one vacant unit.' : '' ?></p></div>
        <?php else: ?>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="save">
            <div class="form-group">
                <label class="form-label">Tenant</label>
                <select name="tenant_id" class="form-control" required>
                    <option value="">Select tenant...</option>
                    <?php foreach ($allTenants as $t): ?><option value="<?= $t['id'] ?>"><?= e($t['first_name'] . ' ' . $t['last_name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Unit</label>
                <select name="unit_id" id="unitSelect" class="form-control" required onchange="fillDefaults()">
                    <option value="">Select unit...</option>
                    <?php foreach ($availableUnits as $u): ?><option value="<?= $u['id'] ?>" data-rent="<?= $u['rent_amount'] ?>" data-deposit="<?= $u['deposit_amount'] ?>"><?= e($u['property_name'] . ' — ' . $u['unit_number']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group d-flex gap-12">
                <div style="flex:1"><label class="form-label">Monthly Rent</label><input type="number" step="0.01" name="rent_amount" id="rentInput" class="form-control" required></div>
                <div style="flex:1"><label class="form-label">Deposit</label><input type="number" step="0.01" name="deposit_amount" id="depositInput" class="form-control"></div>
            </div>
            <div class="form-group d-flex gap-12">
                <div style="flex:1"><label class="form-label">Move-In Date</label><input type="date" name="move_in_date" class="form-control" required></div>
                <div style="flex:1"><label class="form-label">Lease End Date</label><input type="date" name="lease_end" class="form-control" required></div>
            </div>
            <button type="submit" class="btn btn-primary">Create Lease &amp; Move In</button>
        </form>
        <script>
        function fillDefaults() {
            const sel = document.getElementById('unitSelect');
            const opt = sel.options[sel.selectedIndex];
            document.getElementById('rentInput').value = opt.dataset.rent || '';
            document.getElementById('depositInput').value = opt.dataset.deposit || '';
        }
        </script>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="card" style="margin-bottom:18px;">
        <form method="GET" class="d-flex gap-12">
            <select name="status" class="form-control" style="max-width:200px;" onchange="this.form.submit()">
                <option value="active" <?= $statusFilter==='active'?'selected':'' ?>>Active</option>
                <option value="ended" <?= $statusFilter==='ended'?'selected':'' ?>>Ended</option>
                <option value="" <?= $statusFilter===''?'selected':'' ?>>All</option>
            </select>
        </form>
    </div>
    <div class="card">
        <?php if (empty($leases)): ?>
            <div class="empty-state"><i class="fa-solid fa-file-signature"></i><h3>No leases found</h3></div>
        <?php else: ?>
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th>Tenant</th><th>Unit</th><th>Rent</th><th>Lease Term</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($leases as $l): ?>
                <tr>
                    <td><?= e($l['first_name'] . ' ' . $l['last_name']) ?></td>
                    <td><?= e($l['property_name'] . ' — ' . $l['unit_number']) ?></td>
                    <td>$<?= money($l['rent_amount']) ?>/mo</td>
                    <td><?= format_date($l['lease_start']) ?> – <?= format_date($l['lease_end']) ?></td>
                    <td><span class="badge badge-<?= $l['status']==='active'?'success':'neutral' ?>"><?= ucfirst($l['status']) ?></span></td>
                    <td>
                        <?php if ($l['status'] === 'active'): ?>
                        <form method="POST" style="display:inline" onsubmit="event.preventDefault(); confirmDelete(this, 'Move this tenant out and free up the unit?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form_action" value="move_out">
                            <input type="hidden" name="id" value="<?= $l['id'] ?>">
                            <button type="submit" class="btn btn-outline btn-sm">Move Out</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
