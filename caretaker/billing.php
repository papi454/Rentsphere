<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_role(['caretaker']);

$db = Database::getConnection();
$user = current_user();
$errors = [];

$stmt = $db->prepare("SELECT id FROM properties WHERE caretaker_id = ?");
$stmt->execute([$user['id']]);
$myPropertyIds = array_column($stmt->fetchAll(), 'id');

if (empty($myPropertyIds)) { flash('error', 'You have no properties assigned yet.'); redirect(APP_URL . '/caretaker/dashboard.php'); }
$placeholders = implode(',', array_fill(0, count($myPropertyIds), '?'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $action = $_POST['form_action'] ?? '';

    if ($action === 'delete') {
        $id = (int) $_POST['id'];
        $stmt = $db->prepare("DELETE FROM bills WHERE id = ? AND status = 'unpaid' AND lease_id IN
                               (SELECT id FROM leases WHERE unit_id IN (SELECT id FROM units WHERE property_id IN ($placeholders)))");
        $stmt->execute(array_merge([$id], $myPropertyIds));
        flash('success', 'Bill removed.');
        redirect(APP_URL . '/caretaker/billing.php');
    }

    if ($action === 'save') {
        $leaseId = (int) $_POST['lease_id'];
        $billType = $_POST['bill_type'] ?? 'other';
        $description = trim($_POST['description'] ?? '');
        $amount = (float) $_POST['amount'];

        // Verify this lease belongs to the caretaker's properties
        $stmt = $db->prepare("SELECT l.*, u.property_id FROM leases l JOIN units u ON u.id = l.unit_id
                               WHERE l.id = ? AND u.property_id IN ($placeholders) AND l.status = 'active'");
        $stmt->execute(array_merge([$leaseId], $myPropertyIds));
        $lease = $stmt->fetch();

        if (!$lease) $errors[] = 'Invalid tenant/lease selected.';
        if ($amount <= 0) $errors[] = 'Amount must be greater than zero.';

        if (empty($errors)) {
            $stmt = $db->prepare("INSERT INTO bills (lease_id, tenant_id, bill_type, description, amount, created_by) VALUES (?,?,?,?,?,?)");
            $stmt->execute([$leaseId, $lease['tenant_id'], $billType, $description, $amount, $user['id']]);
            $billId = $db->lastInsertId();

            // Notify the tenant if they have a portal login
            $stmt = $db->prepare("SELECT user_id, first_name FROM tenants WHERE id = ?");
            $stmt->execute([$lease['tenant_id']]);
            $tenantRow = $stmt->fetch();
            if ($tenantRow && $tenantRow['user_id']) {
                create_notification($user['company_id'], 'new_bill', 'New Bill Added',
                    ucfirst(str_replace('_',' ',$billType)) . " charge of $" . money($amount) . " has been added to your account.",
                    $tenantRow['user_id']);
            }
            log_activity('bill_created', "Added $billType bill #$billId ($$amount)", $user['id']);
            flash('success', 'Bill added successfully.');
            redirect(APP_URL . '/caretaker/billing.php');
        }
    }
}

$stmt = $db->prepare("SELECT l.id AS lease_id, t.first_name, t.last_name, u.unit_number, p.name AS property_name
                       FROM leases l JOIN tenants t ON t.id = l.tenant_id JOIN units u ON u.id = l.unit_id JOIN properties p ON p.id = u.property_id
                       WHERE u.property_id IN ($placeholders) AND l.status = 'active' ORDER BY t.first_name");
$stmt->execute($myPropertyIds);
$myLeases = $stmt->fetchAll();

$stmt = $db->prepare("SELECT b.*, t.first_name, t.last_name, u.unit_number FROM bills b
                       JOIN leases l ON l.id = b.lease_id JOIN tenants t ON t.id = b.tenant_id JOIN units u ON u.id = l.unit_id
                       WHERE u.property_id IN ($placeholders) ORDER BY b.created_at DESC LIMIT 100");
$stmt->execute($myPropertyIds);
$bills = $stmt->fetchAll();

$action = $_GET['action'] ?? 'list';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Billing — <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<div class="page-body" style="max-width:1000px;margin:0 auto;">
    <div class="page-header">
        <div><h1 class="page-title">Tenant Billing</h1><p class="page-subtitle">Add water, garbage, electricity, or damage charges.</p></div>
        <div class="d-flex gap-8">
            <a href="dashboard.php" class="btn btn-outline">Dashboard</a>
            <?php if ($action === 'list'): ?><a href="?action=add" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add Bill</a>
            <?php else: ?><a href="billing.php" class="btn btn-outline">Back to list</a><?php endif; ?>
        </div>
    </div>

    <?php if ($msg = get_flash('success')): ?><div class="alert alert-success" data-autohide><?= e($msg) ?></div><?php endif; ?>
    <?php if (!empty($errors)): ?><div class="alert alert-error"><div><?php foreach ($errors as $err) echo e($err) . '<br>'; ?></div></div><?php endif; ?>

    <?php if ($action === 'add'): ?>
        <div class="card" style="max-width:560px;">
            <?php if (empty($myLeases)): ?>
                <div class="empty-state"><i class="fa-solid fa-users"></i><h3>No active tenants</h3><p>Add a tenant first before billing them.</p></div>
            <?php else: ?>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="save">
                <div class="form-group">
                    <label class="form-label">Tenant / Unit</label>
                    <select name="lease_id" class="form-control" required>
                        <option value="">Select...</option>
                        <?php foreach ($myLeases as $l): ?>
                            <option value="<?= $l['lease_id'] ?>"><?= e($l['first_name'] . ' ' . $l['last_name'] . ' — ' . $l['property_name'] . ' ' . $l['unit_number']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Bill Type</label>
                    <select name="bill_type" class="form-control">
                        <option value="water">Water</option>
                        <option value="garbage">Garbage</option>
                        <option value="electricity">Electricity</option>
                        <option value="maintenance_damage">Maintenance / Damage</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Amount</label>
                    <input type="number" step="0.01" name="amount" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Description (optional)</label>
                    <textarea name="description" class="form-control" rows="2" placeholder="e.g. Broken window in living room"></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Add Bill</button>
            </form>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="card">
            <?php if (empty($bills)): ?>
                <div class="empty-state"><i class="fa-solid fa-file-invoice-dollar"></i><h3>No bills added yet</h3></div>
            <?php else: ?>
            <div class="table-wrap"><table class="data-table">
                <thead><tr><th>Tenant</th><th>Unit</th><th>Type</th><th>Description</th><th>Amount</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($bills as $b): ?>
                    <tr>
                        <td><?= e($b['first_name'] . ' ' . $b['last_name']) ?></td>
                        <td><?= e($b['unit_number']) ?></td>
                        <td><span class="badge badge-neutral"><?= ucfirst(str_replace('_',' ',$b['bill_type'])) ?></span></td>
                        <td><?= e($b['description'] ?: '—') ?></td>
                        <td>$<?= money($b['amount']) ?></td>
                        <td><span class="badge badge-<?= $b['status']==='paid'?'success':($b['status']==='pending_confirmation'?'warning':'danger') ?>"><?= ucfirst(str_replace('_',' ',$b['status'])) ?></span></td>
                        <td>
                            <?php if ($b['status'] === 'unpaid'): ?>
                            <form method="POST" onsubmit="event.preventDefault(); confirmDelete(this);">
                                <?= csrf_field() ?><input type="hidden" name="form_action" value="delete"><input type="hidden" name="id" value="<?= $b['id'] ?>">
                                <button type="submit" class="btn btn-outline btn-sm" style="color:var(--color-danger);"><i class="fa-solid fa-trash"></i></button>
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
</div>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
</body>
</html>
