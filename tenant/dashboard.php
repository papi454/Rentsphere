<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_role(['tenant']);

$db = Database::getConnection();
$user = current_user();

// Requires: users.tenant_id column linking a tenant's login account to their tenants row.
// See the "Tenant Portal Login" step in the setup guide.
$stmt = $db->prepare("SELECT * FROM tenants WHERE user_id = ?");
$stmt->execute([$user['id']]);
$tenant = $stmt->fetch();

$lease = null; $payments = []; $maintenance = [];
if ($tenant) {
    $stmt = $db->prepare("SELECT l.*, u.unit_number, p.name AS property_name FROM leases l
                           JOIN units u ON u.id = l.unit_id JOIN properties p ON p.id = u.property_id
                           WHERE l.tenant_id = ? AND l.status = 'active' LIMIT 1");
    $stmt->execute([$tenant['id']]);
    $lease = $stmt->fetch();

    $stmt = $db->prepare("SELECT pay.*, r.receipt_number FROM payments pay LEFT JOIN receipts r ON r.payment_id = pay.id
                           WHERE pay.tenant_id = ? ORDER BY pay.payment_date DESC LIMIT 20");
    $stmt->execute([$tenant['id']]);
    $payments = $stmt->fetchAll();

    $stmt = $db->prepare("SELECT * FROM maintenance_requests WHERE tenant_id = ? ORDER BY created_at DESC");
    $stmt->execute([$tenant['id']]);
    $maintenance = $stmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'submit_maintenance' && $tenant) {
    csrf_verify_or_die();
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $priority = $_POST['priority'] ?? 'medium';
    if ($title !== '' && $lease) {
        $stmt = $db->prepare("SELECT property_id FROM units WHERE id = ?");
        $stmt->execute([$lease['unit_id']]);
        $propertyId = $stmt->fetchColumn();
        $db->prepare("INSERT INTO maintenance_requests (company_id, property_id, unit_id, tenant_id, title, description, priority)
                       VALUES (?,?,?,?,?,?,?)")
           ->execute([$user['company_id'], $propertyId, $lease['unit_id'], $tenant['id'], $title, $description, $priority]);
        flash('success', 'Maintenance request submitted.');
        redirect(APP_URL . '/tenant/dashboard.php');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Portal — <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body>
<div class="page-body" style="max-width:900px;margin:0 auto;">
    <div class="page-header">
        <div><h1 class="page-title">Welcome, <?= e($user['first_name']) ?></h1><p class="page-subtitle">Your lease, payments, and maintenance requests.</p></div>
        <a href="<?= APP_URL ?>/auth/logout.php" class="btn btn-outline">Log Out</a>
    </div>

    <?php if ($msg = get_flash('success')): ?><div class="alert alert-success" data-autohide><?= e($msg) ?></div><?php endif; ?>

    <?php if (!$tenant): ?>
        <div class="card"><div class="empty-state"><i class="fa-solid fa-triangle-exclamation"></i><h3>Account not linked</h3><p>Your login isn't linked to a tenant record yet. Contact your property administrator.</p></div></div>
    <?php else: ?>
    <div class="grid-2">
        <div class="card">
            <h3>My Lease</h3>
            <?php if ($lease): ?>
                <p><strong><?= e($lease['property_name'] . ' — ' . $lease['unit_number']) ?></strong></p>
                <p>Rent: $<?= money($lease['rent_amount']) ?>/mo</p>
                <p>Lease term: <?= format_date($lease['lease_start']) ?> – <?= format_date($lease['lease_end']) ?></p>
            <?php else: ?>
                <p class="text-secondary">No active lease on file.</p>
            <?php endif; ?>
        </div>
        <div class="card">
            <h3>Submit Maintenance Request</h3>
            <?php if ($lease): ?>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="submit_maintenance">
                <div class="form-group"><input type="text" name="title" class="form-control" placeholder="Issue title" required></div>
                <div class="form-group"><textarea name="description" class="form-control" rows="3" placeholder="Describe the issue..."></textarea></div>
                <div class="form-group">
                    <select name="priority" class="form-control">
                        <option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option><option value="urgent">Urgent</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary w-full">Submit Request</button>
            </form>
            <?php else: ?><p class="text-secondary">You need an active lease to submit requests.</p><?php endif; ?>
        </div>
    </div>

    <div class="card" style="margin-top:20px;">
        <h3>Payment History</h3>
        <?php if (empty($payments)): ?>
            <div class="empty-state"><i class="fa-solid fa-receipt"></i><h3>No payments yet</h3></div>
        <?php else: ?>
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th>Receipt #</th><th>Type</th><th>Amount</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($payments as $p): ?>
                <tr><td><?= e($p['receipt_number'] ?? '—') ?></td><td><?= ucfirst($p['payment_type']) ?></td><td>$<?= money($p['amount']) ?></td><td><?= format_date($p['payment_date']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>

    <div class="card" style="margin-top:20px;">
        <h3>My Maintenance Requests</h3>
        <?php if (empty($maintenance)): ?>
            <div class="empty-state"><i class="fa-solid fa-screwdriver-wrench"></i><h3>No requests submitted</h3></div>
        <?php else: ?>
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th>Title</th><th>Priority</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($maintenance as $m): ?>
                <tr><td><?= e($m['title']) ?></td><td><?= ucfirst($m['priority']) ?></td><td><span class="badge badge-<?= $m['status']==='completed'?'success':'warning' ?>"><?= ucfirst(str_replace('_',' ',$m['status'])) ?></span></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
