<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_role(['administrator']);

$db = Database::getConnection();
$companyId = $_SESSION['company_id'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $action = $_POST['form_action'] ?? '';

    if ($action === 'assign') {
        $id = (int) $_POST['id'];
        $caretakerId = (int) $_POST['caretaker_id'];
        $db->prepare("UPDATE maintenance_requests SET assigned_caretaker_id = ?, status = 'in_progress' WHERE id = ? AND company_id = ?")
           ->execute([$caretakerId, $id, $companyId]);
        create_notification($companyId, 'maintenance_update', 'Maintenance Assigned', 'A maintenance request has been assigned to you.', $caretakerId);
        log_activity('maintenance_assigned', "Assigned request #$id to caretaker #$caretakerId");
        flash('success', 'Request assigned to caretaker.');
        redirect(APP_URL . '/admin/maintenance.php');
    }

    if ($action === 'complete') {
        $id = (int) $_POST['id'];
        $db->prepare("UPDATE maintenance_requests SET status='completed', completed_at=NOW() WHERE id=? AND company_id=?")->execute([$id, $companyId]);
        log_activity('maintenance_completed', "Marked request #$id completed");
        flash('success', 'Request marked as completed.');
        redirect(APP_URL . '/admin/maintenance.php');
    }

    if ($action === 'save') {
        $propertyId = (int) $_POST['property_id'];
        $unitId = !empty($_POST['unit_id']) ? (int) $_POST['unit_id'] : null;
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $priority = $_POST['priority'] ?? 'medium';

        if ($title === '' || !$propertyId) $errors[] = 'Title and property are required.';

        if (empty($errors)) {
            $stmt = $db->prepare("INSERT INTO maintenance_requests (company_id, property_id, unit_id, title, description, priority)
                                   VALUES (?,?,?,?,?,?)");
            $stmt->execute([$companyId, $propertyId, $unitId, $title, $description, $priority]);
            log_activity('maintenance_created', "Created maintenance request: $title");
            flash('success', 'Maintenance request submitted.');
            redirect(APP_URL . '/admin/maintenance.php');
        }
    }
}

$stmt = $db->prepare("SELECT id, name FROM properties WHERE company_id = ? ORDER BY name");
$stmt->execute([$companyId]);
$allProperties = $stmt->fetchAll();

$stmt = $db->prepare("SELECT id, first_name, last_name FROM users WHERE company_id = ? AND role='caretaker' AND is_active=1 ORDER BY first_name");
$stmt->execute([$companyId]);
$caretakers = $stmt->fetchAll();

$action = $_GET['action'] ?? 'list';
$statusFilter = $_GET['status'] ?? '';
$sql = "SELECT mr.*, p.name AS property_name, u.unit_number, ca.first_name AS ct_first, ca.last_name AS ct_last
        FROM maintenance_requests mr JOIN properties p ON p.id = mr.property_id
        LEFT JOIN units u ON u.id = mr.unit_id LEFT JOIN users ca ON ca.id = mr.assigned_caretaker_id
        WHERE mr.company_id = ?";
$params = [$companyId];
if ($statusFilter !== '') { $sql .= " AND mr.status = ?"; $params[] = $statusFilter; }
$sql .= " ORDER BY FIELD(mr.priority,'urgent','high','medium','low'), mr.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

$pageTitle = 'Maintenance';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="breadcrumbs"><a href="dashboard.php">Dashboard</a> / Maintenance</div>
<div class="page-header">
    <div><h1 class="page-title">Maintenance Requests</h1><p class="page-subtitle">Track and assign maintenance work.</p></div>
    <?php if ($action === 'list'): ?>
        <a href="?action=add" class="btn btn-primary"><i class="fa-solid fa-plus"></i> New Request</a>
    <?php else: ?>
        <a href="maintenance.php" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> Back</a>
    <?php endif; ?>
</div>

<?php if ($action === 'add'): ?>
    <div class="card" style="max-width:640px;">
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><div><?php foreach ($errors as $err) echo e($err) . '<br>'; ?></div></div>
        <?php endif; ?>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="save">
            <div class="form-group">
                <label class="form-label">Property</label>
                <select name="property_id" class="form-control" required>
                    <option value="">Select property...</option>
                    <?php foreach ($allProperties as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Title</label>
                <input type="text" name="title" class="form-control" required>
            </div>
            <div class="form-group">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-control" rows="4"></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Priority</label>
                <select name="priority" class="form-control">
                    <option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option><option value="urgent">Urgent</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Submit Request</button>
        </form>
    </div>
<?php else: ?>
    <div class="card" style="margin-bottom:18px;">
        <form method="GET" class="d-flex gap-12">
            <select name="status" class="form-control" style="max-width:220px;" onchange="this.form.submit()">
                <option value="">All Statuses</option>
                <?php foreach (['submitted','in_progress','completed','cancelled'] as $s): ?>
                    <option value="<?= $s ?>" <?= $statusFilter===$s?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <div class="card">
        <?php if (empty($requests)): ?>
            <div class="empty-state"><i class="fa-solid fa-screwdriver-wrench"></i><h3>No maintenance requests</h3></div>
        <?php else: ?>
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th>Title</th><th>Property/Unit</th><th>Priority</th><th>Assigned</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($requests as $r): ?>
                <tr>
                    <td><strong><?= e($r['title']) ?></strong></td>
                    <td><?= e($r['property_name']) ?><?= $r['unit_number'] ? ' — ' . e($r['unit_number']) : '' ?></td>
                    <td><span class="badge badge-<?= $r['priority']==='urgent'?'danger':($r['priority']==='high'?'warning':'info') ?>"><?= ucfirst($r['priority']) ?></span></td>
                    <td><?= $r['ct_first'] ? e($r['ct_first'].' '.$r['ct_last']) : '<span class="text-secondary">Unassigned</span>' ?></td>
                    <td><span class="badge badge-<?= $r['status']==='completed'?'success':($r['status']==='cancelled'?'neutral':'warning') ?>"><?= ucfirst(str_replace('_',' ',$r['status'])) ?></span></td>
                    <td>
                        <div class="d-flex gap-8">
                        <?php if ($r['status'] !== 'completed' && $r['status'] !== 'cancelled'): ?>
                            <?php if (!$r['assigned_caretaker_id'] && !empty($caretakers)): ?>
                            <form method="POST" class="d-flex gap-8">
                                <?= csrf_field() ?>
                                <input type="hidden" name="form_action" value="assign">
                                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                <select name="caretaker_id" class="form-control btn-sm" required>
                                    <option value="">Assign to...</option>
                                    <?php foreach ($caretakers as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['first_name'].' '.$c['last_name']) ?></option><?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-outline btn-sm">Assign</button>
                            </form>
                            <?php endif; ?>
                            <form method="POST">
                                <?= csrf_field() ?>
                                <input type="hidden" name="form_action" value="complete">
                                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                <button type="submit" class="btn btn-outline btn-sm" style="color:var(--color-success);">Mark Done</button>
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
