<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_role(['administrator']);

$db = Database::getConnection();
$companyId = $_SESSION['company_id'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'save') {
    csrf_verify_or_die();

    $leaseId = (int) $_POST['lease_id'];
    $amount = (float) $_POST['amount'];
    $type = $_POST['payment_type'] ?? 'rent';
    $method = $_POST['payment_method'] ?? 'cash';
    $paymentDate = $_POST['payment_date'] ?? date('Y-m-d');
    $forMonth = $_POST['for_month'] ?? null;
    $notes = trim($_POST['notes'] ?? '');

    $stmt = $db->prepare("SELECT l.*, u.property_id FROM leases l JOIN units u ON u.id = l.unit_id
                           JOIN properties p ON p.id = u.property_id WHERE l.id = ? AND p.company_id = ?");
    $stmt->execute([$leaseId, $companyId]);
    $lease = $stmt->fetch();

    if (!$lease) $errors[] = 'Invalid lease selected.';
    if ($amount <= 0) $errors[] = 'Payment amount must be greater than zero.';

    if (empty($errors)) {
        $db->beginTransaction();
        $stmt = $db->prepare("INSERT INTO payments (lease_id, tenant_id, amount, payment_type, payment_method, payment_date, for_month, recorded_by, notes)
                               VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$leaseId, $lease['tenant_id'], $amount, $type, $method, $paymentDate, $forMonth ?: null, $_SESSION['user_id'], $notes]);
        $paymentId = $db->lastInsertId();

        $receiptNumber = 'RCPT-' . date('Ymd') . '-' . str_pad((string)$paymentId, 5, '0', STR_PAD_LEFT);
        $db->prepare("INSERT INTO receipts (payment_id, receipt_number) VALUES (?, ?)")->execute([$paymentId, $receiptNumber]);
        $db->commit();

        log_activity('payment_recorded', "Recorded payment #$paymentId ($receiptNumber)");
        flash('success', "Payment recorded successfully. Receipt: $receiptNumber");
        redirect(APP_URL . '/admin/payments.php');
    }
}

$stmt = $db->prepare("SELECT l.id, t.first_name, t.last_name, u.unit_number, l.rent_amount FROM leases l
                       JOIN tenants t ON t.id = l.tenant_id JOIN units u ON u.id = l.unit_id JOIN properties p ON p.id = u.property_id
                       WHERE p.company_id = ? AND l.status = 'active' ORDER BY t.first_name");
$stmt->execute([$companyId]);
$activeLeases = $stmt->fetchAll();

$action = $_GET['action'] ?? 'list';

$stmt = $db->prepare("SELECT pay.*, t.first_name, t.last_name, u.unit_number, r.receipt_number FROM payments pay
                       JOIN tenants t ON t.id = pay.tenant_id JOIN leases l ON l.id = pay.lease_id JOIN units u ON u.id = l.unit_id
                       JOIN properties p ON p.id = u.property_id LEFT JOIN receipts r ON r.payment_id = pay.id
                       WHERE p.company_id = ? ORDER BY pay.payment_date DESC, pay.id DESC LIMIT 100");
$stmt->execute([$companyId]);
$payments = $stmt->fetchAll();

// Outstanding balance estimate: active leases where no payment recorded this month
$stmt = $db->prepare("SELECT l.id, t.first_name, t.last_name, u.unit_number, l.rent_amount FROM leases l
                       JOIN tenants t ON t.id = l.tenant_id JOIN units u ON u.id = l.unit_id JOIN properties p ON p.id = u.property_id
                       WHERE p.company_id = ? AND l.status = 'active' AND l.id NOT IN (
                           SELECT lease_id FROM payments WHERE payment_type = 'rent' AND MONTH(payment_date) = MONTH(CURDATE()) AND YEAR(payment_date) = YEAR(CURDATE())
                       )");
$stmt->execute([$companyId]);
$outstanding = $stmt->fetchAll();

$pageTitle = 'Rent Collection';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="breadcrumbs"><a href="dashboard.php">Dashboard</a> / Rent Collection</div>
<div class="page-header">
    <div><h1 class="page-title">Rent Collection</h1><p class="page-subtitle">Record payments and track balances.</p></div>
    <?php if ($action === 'list'): ?>
        <a href="?action=add" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Record Payment</a>
    <?php else: ?>
        <a href="payments.php" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> Back</a>
    <?php endif; ?>
</div>

<?php if ($action === 'add'): ?>
    <div class="card" style="max-width:640px;">
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><div><?php foreach ($errors as $err) echo e($err) . '<br>'; ?></div></div>
        <?php endif; ?>
        <?php if (empty($activeLeases)): ?>
            <div class="empty-state"><i class="fa-solid fa-file-signature"></i><h3>No active leases</h3><p>Create a lease first before recording payments.</p></div>
        <?php else: ?>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="save">
            <div class="form-group">
                <label class="form-label">Lease / Tenant</label>
                <select name="lease_id" id="leaseSelect" class="form-control" required onchange="document.getElementById('amountInput').value = this.options[this.selectedIndex].dataset.rent || '';">
                    <option value="">Select...</option>
                    <?php foreach ($activeLeases as $l): ?>
                        <option value="<?= $l['id'] ?>" data-rent="<?= $l['rent_amount'] ?>"><?= e($l['first_name'] . ' ' . $l['last_name'] . ' — ' . $l['unit_number']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group d-flex gap-12">
                <div style="flex:1"><label class="form-label">Amount</label><input type="number" step="0.01" id="amountInput" name="amount" class="form-control" required></div>
                <div style="flex:1"><label class="form-label">Payment Type</label>
                    <select name="payment_type" class="form-control">
                        <option value="rent">Rent</option><option value="deposit">Deposit</option><option value="late_fee">Late Fee</option><option value="other">Other</option>
                    </select>
                </div>
            </div>
            <div class="form-group d-flex gap-12">
                <div style="flex:1"><label class="form-label">Payment Method</label>
                    <select name="payment_method" class="form-control">
                        <option value="cash">Cash</option><option value="bank_transfer">Bank Transfer</option><option value="mobile_money">Mobile Money</option><option value="card">Card</option><option value="other">Other</option>
                    </select>
                </div>
                <div style="flex:1"><label class="form-label">Payment Date</label><input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
            </div>
            <div class="form-group">
                <label class="form-label">For Month (rent period)</label>
                <input type="month" name="for_month" class="form-control">
            </div>
            <div class="form-group">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2"></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Record Payment</button>
        </form>
        <?php endif; ?>
    </div>
<?php else: ?>
    <?php if (!empty($outstanding)): ?>
    <div class="card" style="margin-bottom:18px;border-color:var(--color-warning);">
        <h3><i class="fa-solid fa-triangle-exclamation" style="color:var(--color-warning);"></i> Outstanding This Month</h3>
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th>Tenant</th><th>Unit</th><th>Rent Due</th></tr></thead>
            <tbody>
            <?php foreach ($outstanding as $o): ?>
                <tr><td><?= e($o['first_name'].' '.$o['last_name']) ?></td><td><?= e($o['unit_number']) ?></td><td>$<?= money($o['rent_amount']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <?php endif; ?>

    <div class="card">
        <h3>Payment History</h3>
        <?php if (empty($payments)): ?>
            <div class="empty-state"><i class="fa-solid fa-hand-holding-dollar"></i><h3>No payments recorded yet</h3></div>
        <?php else: ?>
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th>Receipt #</th><th>Tenant</th><th>Unit</th><th>Type</th><th>Amount</th><th>Date</th><th>Method</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($payments as $p): ?>
                <tr>
                    <td><?= e($p['receipt_number'] ?? '—') ?></td>
                    <td><?= e($p['first_name'].' '.$p['last_name']) ?></td>
                    <td><?= e($p['unit_number']) ?></td>
                    <td><span class="badge badge-info"><?= ucfirst(str_replace('_',' ',$p['payment_type'])) ?></span></td>
                    <td>$<?= money($p['amount']) ?></td>
                    <td><?= format_date($p['payment_date']) ?></td>
                    <td><?= ucfirst(str_replace('_',' ',$p['payment_method'])) ?></td>
                    <td><?php if ($p['receipt_number']): ?><a href="<?= APP_URL ?>/receipt.php?payment_id=<?= $p['id'] ?>" target="_blank" class="btn btn-outline btn-sm"><i class="fa-solid fa-receipt"></i></a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
