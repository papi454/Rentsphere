<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_role(['caretaker']);

$db = Database::getConnection();
$user = current_user();
$errors = [];

// Properties this caretaker is assigned to
$stmt = $db->prepare("SELECT id, name FROM properties WHERE caretaker_id = ?");
$stmt->execute([$user['id']]);
$myProperties = $stmt->fetchAll();
$myPropertyIds = array_column($myProperties, 'id');

if (empty($myPropertyIds)) {
    $pageTitle = 'My Tenants';
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Tenants — <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"></head>
<body><div class="page-body" style="max-width:900px;margin:0 auto;">
    <div class="page-header"><div><h1 class="page-title">My Tenants</h1></div><a href="dashboard.php" class="btn btn-outline">Back to Dashboard</a></div>
    <div class="card"><div class="empty-state"><i class="fa-solid fa-building"></i><h3>No properties assigned to you yet</h3><p>Ask your administrator to assign you to a property first.</p></div></div>
</div></body></html>
<?php
    exit;
}

$placeholders = implode(',', array_fill(0, count($myPropertyIds), '?'));

// ---------------- Handle POST actions ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $action = $_POST['form_action'] ?? '';

    if ($action === 'approve') {
        $tenantId = (int) $_POST['tenant_id'];
        $unitId = (int) $_POST['unit_id'];
        $moveIn = $_POST['move_in_date'] ?? '';
        $leaseEnd = $_POST['lease_end'] ?? '';

        $stmt = $db->prepare("SELECT * FROM tenants WHERE id = ? AND company_id = ? AND status = 'pending'");
        $stmt->execute([$tenantId, $user['company_id']]);
        $tenant = $stmt->fetch();

        $stmt = $db->prepare("SELECT * FROM units WHERE id = ? AND property_id IN ($placeholders) AND occupancy_status != 'occupied'");
        $stmt->execute(array_merge([$unitId], $myPropertyIds));
        $unit = $stmt->fetch();

        if (!$tenant || !$unit || $moveIn === '' || $leaseEnd === '' || strtotime($leaseEnd) <= strtotime($moveIn)) {
            flash('error', 'Please select a valid tenant, vacant unit, and dates.');
            redirect(APP_URL . '/caretaker/tenants.php');
        }

        $db->beginTransaction();
        $stmt = $db->prepare("INSERT INTO leases (tenant_id, unit_id, rent_amount, deposit_amount, move_in_date, lease_start, lease_end, status)
                               VALUES (?,?,?,?,?,?,?, 'active')");
        $stmt->execute([$tenantId, $unitId, $unit['rent_amount'], $unit['deposit_amount'], $moveIn, $moveIn, $leaseEnd]);
        $db->prepare("UPDATE units SET occupancy_status='occupied' WHERE id = ?")->execute([$unitId]);
        $db->prepare("UPDATE tenants SET status='active' WHERE id = ?")->execute([$tenantId]);
        if ($tenant['user_id']) $db->prepare("UPDATE users SET is_active = 1 WHERE id = ?")->execute([$tenant['user_id']]);
        $db->commit();

       log_activity('tenant_approved', "Caretaker approved tenant #$tenantId, assigned unit #$unitId", $user['id']);
if ($tenant['user_id']) {
    create_notification($user['company_id'], 'tenant_approved', 'Your Tenant Account is Approved!',
        'You have been assigned to your unit. You can now log in to your tenant portal.', $tenant['user_id']);
}
if ($tenant['email']) {
    $emailBody = "<p>Hi {$tenant['first_name']},</p>
        <p>Good news — your tenant account has been approved and you've been assigned to your unit.</p>
        <p>You can now log in and complete the rest of your profile from your tenant portal.</p>
        <p><a href='" . APP_URL . "/auth/login.php' style='background:#2563EB;color:#fff;padding:12px 20px;border-radius:8px;text-decoration:none;'>Log In Now</a></p>";
    @send_email($tenant['email'], $tenant['first_name'], 'Your ' . APP_NAME . ' account is approved', $emailBody);
}
flash('success', 'Tenant approved and moved in.');
        redirect(APP_URL . '/caretaker/tenants.php');
    }

    if ($action === 'move_out') {
        $leaseId = (int) $_POST['id'];
        // Verify this lease belongs to one of the caretaker's properties
        $stmt = $db->prepare("SELECT l.*, u.id AS unit_id FROM leases l JOIN units u ON u.id = l.unit_id
                               WHERE l.id = ? AND u.property_id IN ($placeholders)");
        $stmt->execute(array_merge([$leaseId], $myPropertyIds));
        $lease = $stmt->fetch();

        if ($lease) {
            $db->beginTransaction();
            $db->prepare("UPDATE leases SET status='ended', move_out_date = CURDATE() WHERE id = ?")->execute([$leaseId]);
            $db->prepare("UPDATE units SET occupancy_status='vacant' WHERE id = ?")->execute([$lease['unit_id']]);
            $db->commit();
            log_activity('lease_ended', "Caretaker ended lease #$leaseId (move-out)", $user['id']);
            flash('success', 'Tenant moved out. Unit marked as vacant.');
        } else {
            flash('error', 'You are not authorized to modify this lease.');
        }
        redirect(APP_URL . '/caretaker/tenants.php');
    }

    if ($action === 'save') {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $unitId = (int) $_POST['unit_id'];
        $moveIn = $_POST['move_in_date'] ?? '';
        $leaseEnd = $_POST['lease_end'] ?? '';

        if ($firstName === '' || $lastName === '') $errors[] = 'First and last name are required.';
        if (!$unitId) $errors[] = 'Please select a unit.';
        if ($moveIn === '' || $leaseEnd === '') $errors[] = 'Move-in date and lease end date are required.';
        if ($moveIn !== '' && $leaseEnd !== '' && strtotime($leaseEnd) <= strtotime($moveIn)) $errors[] = 'Lease end must be after move-in date.';

        // Verify the unit actually belongs to one of this caretaker's properties and is vacant
        $unit = null;
        if ($unitId) {
            $stmt = $db->prepare("SELECT * FROM units WHERE id = ? AND property_id IN ($placeholders)");
            $stmt->execute(array_merge([$unitId], $myPropertyIds));
            $unit = $stmt->fetch();
            if (!$unit) $errors[] = 'Invalid unit selected.';
            elseif ($unit['occupancy_status'] === 'occupied') $errors[] = 'This unit is already occupied.';
        }

        if (empty($errors)) {
            $db->beginTransaction();
            $stmt = $db->prepare("INSERT INTO tenants (company_id, first_name, last_name, email, phone) VALUES (?,?,?,?,?)");
            $stmt->execute([$user['company_id'], $firstName, $lastName, $email, $phone]);
            $tenantId = $db->lastInsertId();

            $stmt = $db->prepare("INSERT INTO leases (tenant_id, unit_id, rent_amount, deposit_amount, move_in_date, lease_start, lease_end, status)
                                   VALUES (?,?,?,?,?,?,?, 'active')");
            $stmt->execute([$tenantId, $unitId, $unit['rent_amount'], $unit['deposit_amount'], $moveIn, $moveIn, $leaseEnd]);

            $db->prepare("UPDATE units SET occupancy_status='occupied' WHERE id = ?")->execute([$unitId]);
            $db->commit();

            log_activity('tenant_created', "Caretaker registered tenant #$tenantId and moved them into unit #$unitId", $user['id']);
            create_notification($user['company_id'], 'new_tenant', 'New Tenant Added by Caretaker',
                "$firstName $lastName was added by " . $user['first_name'] . ' ' . $user['last_name'] . ' and moved into a unit.');
            flash('success', 'Tenant added and moved in successfully.');
            redirect(APP_URL . '/caretaker/tenants.php');
        }
    }
}

// ---------------- Data for display ----------------
$stmt = $db->prepare("SELECT u.id, u.unit_number, u.rent_amount, u.deposit_amount, p.name AS property_name
                       FROM units u JOIN properties p ON p.id = u.property_id
                       WHERE u.property_id IN ($placeholders) AND u.occupancy_status != 'occupied' ORDER BY p.name, u.unit_number");
$stmt->execute($myPropertyIds);
$availableUnits = $stmt->fetchAll();

$stmt = $db->prepare("SELECT l.id AS lease_id, t.id AS tenant_id, t.first_name, t.last_name, t.email, t.phone,
                       u.unit_number, p.name AS property_name, l.lease_end
                       FROM leases l JOIN tenants t ON t.id = l.tenant_id JOIN units u ON u.id = l.unit_id JOIN properties p ON p.id = u.property_id
                       WHERE u.property_id IN ($placeholders) AND l.status = 'active' ORDER BY p.name, u.unit_number");
$stmt->execute($myPropertyIds);
$myTenants = $stmt->fetchAll();

$stmt = $db->prepare("SELECT * FROM tenants WHERE company_id = ? AND status = 'pending' ORDER BY created_at ASC");
$stmt->execute([$user['company_id']]);
$pendingTenants = $stmt->fetchAll();

$action = $_GET['action'] ?? 'list';
$pageTitle = 'My Tenants';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Tenants — <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<div class="page-body" style="max-width:1000px;margin:0 auto;">
    <div class="page-header">
        <div><h1 class="page-title">My Tenants</h1><p class="page-subtitle">Manage tenants in your assigned properties.</p></div>
        <div class="d-flex gap-8">
            <a href="dashboard.php" class="btn btn-outline">Dashboard</a>
            <?php if ($action === 'list'): ?><a href="?action=add" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add Tenant</a>
            <?php else: ?><a href="tenants.php" class="btn btn-outline">Back to list</a><?php endif; ?>
        </div>
    </div>

    <?php if ($msg = get_flash('success')): ?><div class="alert alert-success" data-autohide><?= e($msg) ?></div><?php endif; ?>
    <?php if ($msg = get_flash('error')): ?><div class="alert alert-error"><?= e($msg) ?></div><?php endif; ?>

    <?php if ($action === 'add'): ?>
        <div class="card" style="max-width:600px;">
            <?php if (!empty($errors)): ?>
                <div class="alert alert-error"><div><?php foreach ($errors as $err) echo e($err) . '<br>'; ?></div></div>
            <?php endif; ?>
            <?php if (empty($availableUnits)): ?>
                <div class="empty-state"><i class="fa-solid fa-door-open"></i><h3>No vacant units</h3><p>All units in your assigned properties are currently occupied.</p></div>
            <?php else: ?>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="save">
                <div class="form-group d-flex gap-12">
                    <div style="flex:1"><label class="form-label">First Name</label><input type="text" name="first_name" class="form-control" required></div>
                    <div style="flex:1"><label class="form-label">Last Name</label><input type="text" name="last_name" class="form-control" required></div>
                </div>
                <div class="form-group d-flex gap-12">
                    <div style="flex:1"><label class="form-label">Email</label><input type="email" name="email" class="form-control"></div>
                    <div style="flex:1"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control"></div>
                </div>
                <div class="form-group">
                    <label class="form-label">Unit</label>
                    <select name="unit_id" class="form-control" required>
                        <option value="">Select vacant unit...</option>
                        <?php foreach ($availableUnits as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= e($u['property_name'] . ' — ' . $u['unit_number'] . ' ($' . money($u['rent_amount']) . '/mo)') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group d-flex gap-12">
                    <div style="flex:1"><label class="form-label">Move-In Date</label><input type="date" name="move_in_date" class="form-control" required></div>
                    <div style="flex:1"><label class="form-label">Lease End Date</label><input type="date" name="lease_end" class="form-control" required></div>
                </div>
                <button type="submit" class="btn btn-primary">Add Tenant &amp; Move In</button>
            </form>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <?php if (!empty($pendingTenants)): ?>
        <div class="card" style="margin-bottom:18px;border-color:var(--color-warning);">
            <h3><i class="fa-solid fa-user-clock" style="color:var(--color-warning);"></i> Pending Applications</h3>
            <?php foreach ($pendingTenants as $pt): ?>
            <div style="padding:14px 0;border-bottom:1px solid var(--border-color);">
                <strong><?= e($pt['first_name'] . ' ' . $pt['last_name']) ?></strong> — <span class="text-secondary"><?= e($pt['email']) ?></span>
                <?php if (empty($availableUnits)): ?>
                    <p class="form-hint">No vacant units in your properties right now.</p>
                <?php else: ?>
                <form method="POST" class="d-flex gap-8" style="flex-wrap:wrap;margin-top:10px;align-items:end;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="form_action" value="approve">
                    <input type="hidden" name="tenant_id" value="<?= $pt['id'] ?>">
                    <select name="unit_id" class="form-control btn-sm" required>
                        <option value="">Assign unit...</option>
                        <?php foreach ($availableUnits as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= e($u['property_name'] . ' — ' . $u['unit_number']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="date" name="move_in_date" class="form-control btn-sm" required placeholder="Move-in">
                    <input type="date" name="lease_end" class="form-control btn-sm" required placeholder="Lease end">
                    <button type="submit" class="btn btn-primary btn-sm">Approve</button>
                </form>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="card">
            <?php if (empty($myTenants)): ?>
                <div class="empty-state"><i class="fa-solid fa-users"></i><h3>No tenants yet</h3><p>Add a tenant to one of your properties to get started.</p></div>
            <?php else: ?>
            <div class="table-wrap"><table class="data-table">
                <thead><tr><th>Name</th><th>Contact</th><th>Property/Unit</th><th>Lease Ends</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($myTenants as $t): ?>
                    <tr>
                        <td><strong><?= e($t['first_name'] . ' ' . $t['last_name']) ?></strong></td>
                        <td><?= e($t['email'] ?: '—') ?><br><span class="text-secondary"><?= e($t['phone'] ?: '') ?></span></td>
                        <td><?= e($t['property_name'] . ' — ' . $t['unit_number']) ?></td>
                        <td><?= format_date($t['lease_end']) ?></td>
                        <td>
                            <form method="POST" onsubmit="event.preventDefault(); confirmDelete(this, 'Move this tenant out and free up the unit?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="form_action" value="move_out">
                                <input type="hidden" name="id" value="<?= $t['lease_id'] ?>">
                                <button type="submit" class="btn btn-outline btn-sm" style="color:var(--color-danger);"><i class="fa-solid fa-right-from-bracket"></i> Move Out</button>
                            </form>
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
