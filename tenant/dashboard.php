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

    $stmt = $db->prepare("SELECT * FROM bills WHERE tenant_id = ? AND status = 'unpaid' ORDER BY created_at DESC");
    $stmt->execute([$tenant['id']]);
    $unpaidBills = $stmt->fetchAll();

    $stmt = $db->prepare("SELECT pr.*, b.bill_type FROM payment_requests pr LEFT JOIN bills b ON b.id = pr.bill_id
                           WHERE pr.tenant_id = ? ORDER BY pr.submitted_at DESC LIMIT 10");
    $stmt->execute([$tenant['id']]);
    $myPaymentRequests = $stmt->fetchAll();

    // Has this month's rent already been paid or submitted?
    $rentAlreadyRequested = false;
    if ($lease) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM payment_requests WHERE lease_id = ? AND bill_id IS NULL AND status IN ('pending','confirmed')
                               AND MONTH(submitted_at) = MONTH(CURDATE()) AND YEAR(submitted_at) = YEAR(CURDATE())");
        $stmt->execute([$lease['id']]);
        $rentAlreadyRequested = (int) $stmt->fetchColumn() > 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'update_profile' && $tenant) {
    csrf_verify_or_die();
    $phone = trim($_POST['phone'] ?? '');
    $emName = trim($_POST['emergency_contact_name'] ?? '');
    $emPhone = trim($_POST['emergency_contact_phone'] ?? '');

    $sql = "UPDATE tenants SET phone=?, emergency_contact_name=?, emergency_contact_phone=?";
    $params = [$phone, $emName, $emPhone];

    foreach (['id_document' => 'tenant_ids', 'photo' => 'tenant_photos'] as $field => $subfolder) {
        if (!empty($_FILES[$field]['name']) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
            $allowed = ['jpg','jpeg','png','pdf'];
            $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
            if (in_array($ext, $allowed, true)) {
                $dir = UPLOAD_PATH . '/' . $subfolder;
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $filename = bin2hex(random_bytes(12)) . '.' . $ext;
                if (move_uploaded_file($_FILES[$field]['tmp_name'], $dir . '/' . $filename)) {
                    $column = $field === 'id_document' ? 'id_document_path' : 'photo_path';
                    $sql .= ", $column=?";
                    $params[] = 'uploads/' . $subfolder . '/' . $filename;
                }
            }
        }
    }
    $sql .= " WHERE id = ?";
    $params[] = $tenant['id'];
    $db->prepare($sql)->execute($params);

    flash('success', 'Your profile has been updated.');
    redirect(APP_URL . '/tenant/dashboard.php');
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'submit_payment' && $tenant && $lease) {
    csrf_verify_or_die();
    $billId = !empty($_POST['bill_id']) ? (int) $_POST['bill_id'] : null;
    $amount = (float) ($_POST['amount'] ?? 0);
    $method = $_POST['method'] ?? 'mpesa';
    $reference = trim($_POST['reference'] ?? '');

    if ($amount > 0 && in_array($method, ['bank','mpesa'], true)) {
        // If paying a specific bill, verify it belongs to this tenant and is still unpaid
        $validBill = true;
        if ($billId) {
            $stmt = $db->prepare("SELECT id FROM bills WHERE id = ? AND tenant_id = ? AND status = 'unpaid'");
            $stmt->execute([$billId, $tenant['id']]);
            $validBill = (bool) $stmt->fetch();
        }

        if ($validBill) {
            $db->prepare("INSERT INTO payment_requests (lease_id, tenant_id, bill_id, amount, method, reference) VALUES (?,?,?,?,?,?)")
               ->execute([$lease['id'], $tenant['id'], $billId, $amount, $method, $reference]);
            if ($billId) {
                $db->prepare("UPDATE bills SET status = 'pending_confirmation' WHERE id = ?")->execute([$billId]);
            }
            flash('success', 'Payment submitted! It will show as confirmed once your caretaker verifies it.');
        } else {
            flash('error', 'This bill is no longer available for payment.');
        }
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
<div class="topbar" style="position:sticky;top:0;">
    <div class="topbar-left">
        <div class="auth-logo-mark" style="width:36px;height:36px;font-size:14px;">R</div>
        <strong style="font-family:var(--font-display);font-size:16px;"><?= e(APP_NAME) ?></strong>
    </div>
    <div class="topbar-right">
        <button class="icon-btn" id="themeToggle"><i class="fa-solid fa-moon"></i></button>
        <a href="<?= APP_URL ?>/auth/logout.php" class="icon-btn" style="text-decoration:none;" title="Log Out"><i class="fa-solid fa-right-from-bracket"></i></a>
    </div>
</div>
<div class="page-body" style="max-width:900px;margin:0 auto;">
    <div class="page-header">
        <div><h1 class="page-title">Welcome, <?= e($user['first_name']) ?> 👋</h1><p class="page-subtitle">Your lease, payments, and maintenance requests.</p></div>
    </div>

    <?php if ($msg = get_flash('success')): ?><div class="alert alert-success" data-autohide><?= e($msg) ?></div><?php endif; ?>

    <?php if (!$tenant): ?>
        <div class="card"><div class="empty-state"><i class="fa-solid fa-triangle-exclamation"></i><h3>Account not linked</h3><p>Your login isn't linked to a tenant record yet. Contact your property administrator.</p></div></div>
    <?php elseif ($tenant['status'] === 'pending'): ?>
        <div class="card"><div class="empty-state"><i class="fa-solid fa-user-clock" style="color:var(--color-warning);"></i><h3>Application under review</h3><p>Your property administrator or caretaker is reviewing your application and will assign you to your unit soon. You'll get an email once you're approved.</p></div></div>
    <?php else: ?>
    <div class="grid-2">
        <div class="card">
            <h3><i class="fa-solid fa-file-signature" style="color:var(--color-primary);"></i> My Lease</h3>
            <?php if ($lease): ?>
                <p><strong><?= e($lease['property_name'] . ' — ' . $lease['unit_number']) ?></strong></p>
                <p>Rent: $<?= money($lease['rent_amount']) ?>/mo</p>
                <p>Lease term: <?= format_date($lease['lease_start']) ?> – <?= format_date($lease['lease_end']) ?></p>
            <?php else: ?>
                <p class="text-secondary">No active lease on file.</p>
            <?php endif; ?>
        </div>
        <div class="card">
            <h3><i class="fa-solid fa-screwdriver-wrench" style="color:var(--color-primary);"></i> Submit Maintenance Request</h3>
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

    <div class="card" style="margin-top:20px;max-width:600px;">
        <h3><i class="fa-solid fa-id-card" style="color:var(--color-primary);"></i> Complete Your Profile</h3>
        <p class="text-secondary">Add your phone, emergency contact, ID, and photo — this is your info to keep up to date.</p>
        <form method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="update_profile">
            <div class="form-group"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" value="<?= e($tenant['phone'] ?? '') ?>"></div>
            <div class="form-group d-flex gap-12">
                <div style="flex:1"><label class="form-label">Emergency Contact Name</label><input type="text" name="emergency_contact_name" class="form-control" value="<?= e($tenant['emergency_contact_name'] ?? '') ?>"></div>
                <div style="flex:1"><label class="form-label">Emergency Contact Phone</label><input type="text" name="emergency_contact_phone" class="form-control" value="<?= e($tenant['emergency_contact_phone'] ?? '') ?>"></div>
            </div>
            <div class="form-group d-flex gap-12">
                <div style="flex:1"><label class="form-label">ID Document</label><input type="file" name="id_document" class="form-control"><?= $tenant['id_document_path'] ? '<div class="form-hint">Already uploaded — choose a file to replace it.</div>' : '' ?></div>
                <div style="flex:1"><label class="form-label">Photo</label><input type="file" name="photo" class="form-control"><?= $tenant['photo_path'] ? '<div class="form-hint">Already uploaded — choose a file to replace it.</div>' : '' ?></div>
            </div>
            <button type="submit" class="btn btn-primary">Save Profile</button>
        </form>
    </div>

    <div class="card" style="margin-top:20px;">
        <h3><i class="fa-solid fa-hand-holding-dollar" style="color:var(--color-warning);"></i> Outstanding &amp; Rent Due</h3>
        <?php if ($lease && !$rentAlreadyRequested): ?>
            <div style="padding:14px 0;border-bottom:1px solid var(--border-color);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
                <div><strong>Monthly Rent</strong><br><span class="text-secondary">$<?= money($lease['rent_amount']) ?> due this month</span></div>
                <button class="btn btn-primary btn-sm" onclick="openPayModal(null, <?= $lease['rent_amount'] ?>, 'Rent')">Pay Now</button>
            </div>
        <?php elseif ($lease && $rentAlreadyRequested): ?>
            <div style="padding:14px 0;border-bottom:1px solid var(--border-color);">
                <strong>Monthly Rent</strong> — <span class="badge badge-warning">Payment submitted, awaiting confirmation</span>
            </div>
        <?php endif; ?>

        <?php if (empty($unpaidBills)): ?>
            <?php if ($lease && $rentAlreadyRequested): ?><p class="text-secondary" style="margin-top:12px;">No other outstanding bills.</p><?php endif; ?>
        <?php else: ?>
            <?php foreach ($unpaidBills as $b): ?>
            <div style="padding:14px 0;border-bottom:1px solid var(--border-color);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
                <div>
                    <strong><?= e(ucfirst(str_replace('_',' ',$b['bill_type']))) ?></strong> — $<?= money($b['amount']) ?>
                    <?php if ($b['description']): ?><br><span class="text-secondary"><?= e($b['description']) ?></span><?php endif; ?>
                </div>
                <button class="btn btn-primary btn-sm" onclick="openPayModal(<?= $b['id'] ?>, <?= $b['amount'] ?>, '<?= e(ucfirst(str_replace('_',' ',$b['bill_type']))) ?>')">Pay Now</button>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!empty($myPaymentRequests)): ?>
        <h3 style="margin-top:24px;">Recent Payment Submissions</h3>
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th>For</th><th>Amount</th><th>Method</th><th>Status</th><th>Submitted</th></tr></thead>
            <tbody>
            <?php foreach ($myPaymentRequests as $pr): ?>
                <tr>
                    <td><?= $pr['bill_type'] ? ucfirst(str_replace('_',' ',$pr['bill_type'])) : 'Rent' ?></td>
                    <td>$<?= money($pr['amount']) ?></td>
                    <td><?= $pr['method'] === 'mpesa' ? 'M-Pesa' : 'Bank Transfer' ?></td>
                    <td>
                        <?php if ($pr['status'] === 'confirmed'): ?><span class="badge badge-success">Payment Received Successfully</span>
                        <?php elseif ($pr['status'] === 'rejected'): ?><span class="badge badge-danger">Not Confirmed — please resubmit</span>
                        <?php else: ?><span class="badge badge-warning">Awaiting Confirmation</span><?php endif; ?>
                    </td>
                    <td class="text-secondary"><?= format_date($pr['submitted_at'], 'M j, g:i A') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>

    <!-- Pay Now Modal -->
    <div id="payModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:100;align-items:center;justify-content:center;">
        <div class="card" style="max-width:420px;width:90%;">
            <h3 id="payModalTitle">Pay Now</h3>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="submit_payment">
                <input type="hidden" name="bill_id" id="payBillId">
                <div class="form-group">
                    <label class="form-label">Amount</label>
                    <input type="number" step="0.01" name="amount" id="payAmount" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Payment Method</label>
                    <select name="method" class="form-control">
                        <option value="mpesa">M-Pesa</option>
                        <option value="bank">Bank Transfer</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Transaction Reference / M-Pesa Code</label>
                    <input type="text" name="reference" class="form-control" placeholder="e.g. QK7XJ2ABCD" required>
                    <div class="form-hint">After paying via M-Pesa or bank, enter the confirmation code here so your caretaker can verify it.</div>
                </div>
                <div class="d-flex gap-8">
                    <button type="submit" class="btn btn-primary w-full">Submit Payment</button>
                    <button type="button" class="btn btn-outline" onclick="closePayModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    <script>
        function openPayModal(billId, amount, label) {
            document.getElementById('payBillId').value = billId || '';
            document.getElementById('payAmount').value = amount;
            document.getElementById('payModalTitle').textContent = 'Pay ' + label;
            document.getElementById('payModal').style.display = 'flex';
        }
        function closePayModal() { document.getElementById('payModal').style.display = 'none'; }
    </script>

    <div class="card" style="margin-top:20px;">
        <h3><i class="fa-solid fa-receipt" style="color:var(--color-primary);"></i> Payment History</h3>
        <?php if (empty($payments)): ?>
            <div class="empty-state"><i class="fa-solid fa-receipt"></i><h3>No payments yet</h3></div>
        <?php else: ?>
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th>Receipt #</th><th>Type</th><th>Amount</th><th>Date</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($payments as $p): ?>
                <tr><td><?= e($p['receipt_number'] ?? '—') ?></td><td><?= ucfirst($p['payment_type']) ?></td><td>$<?= money($p['amount']) ?></td><td><?= format_date($p['payment_date']) ?></td>
                <td><?php if ($p['receipt_number']): ?><a href="<?= APP_URL ?>/receipt.php?payment_id=<?= $p['id'] ?>" target="_blank" class="btn btn-outline btn-sm"><i class="fa-solid fa-receipt"></i> Receipt</a><?php endif; ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>

    <div class="card" style="margin-top:20px;">
        <h3><i class="fa-solid fa-list-check" style="color:var(--color-primary);"></i> My Maintenance Requests</h3>
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
