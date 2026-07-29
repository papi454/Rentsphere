<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$db = Database::getConnection();
$me = current_user();
$paymentId = (int) ($_GET['payment_id'] ?? 0);

$stmt = $db->prepare("SELECT pay.*, r.receipt_number, t.first_name, t.last_name, t.id AS tenant_id, t.user_id AS tenant_user_id,
                       u.unit_number, p.name AS property_name, p.caretaker_id, p.company_id, c.name AS company_name, c.address AS company_address
                       FROM payments pay
                       JOIN receipts r ON r.payment_id = pay.id
                       JOIN tenants t ON t.id = pay.tenant_id
                       JOIN leases l ON l.id = pay.lease_id
                       JOIN units u ON u.id = l.unit_id
                       JOIN properties p ON p.id = u.property_id
                       JOIN companies c ON c.id = p.company_id
                       WHERE pay.id = ?");
$stmt->execute([$paymentId]);
$r = $stmt->fetch();

if (!$r) { http_response_code(404); die('Receipt not found.'); }

// Access control: tenant can only see their own; caretaker only their property; admin only their company
$allowed = false;
if ($me['role'] === 'tenant' && (int) $r['tenant_user_id'] === (int) $me['id']) $allowed = true;
if ($me['role'] === 'caretaker' && (int) $r['caretaker_id'] === (int) $me['id']) $allowed = true;
if ($me['role'] === 'administrator' && (int) $r['company_id'] === (int) $me['company_id']) $allowed = true;

if (!$allowed) { http_response_code(403); die('You do not have permission to view this receipt.'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Receipt <?= e($r['receipt_number']) ?> — <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
    body { background: var(--bg-body); }
    .receipt-wrap { max-width: 560px; margin: 40px auto; padding: 0 20px; }
    .receipt-card { background: #fff; border-radius: 16px; overflow: hidden; box-shadow: var(--shadow-lg); }
    .receipt-header { background: var(--gradient-brand); color: #fff; padding: 28px; text-align: center; }
    .receipt-header .logo-mark { width: 52px; height: 52px; background: #fff; color: var(--color-primary); border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; font-weight: 800; font-size: 22px; margin-bottom: 10px; }
    .receipt-body { padding: 28px; color: #111827; }
    .receipt-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px dashed #E5E9F0; font-size: 14px; }
    .receipt-row:last-child { border-bottom: none; }
    .receipt-row .label { color: #6B7280; }
    .receipt-amount { text-align: center; padding: 20px 0; }
    .receipt-amount .value { font-family: var(--font-display); font-size: 36px; font-weight: 800; color: var(--color-primary); }
    .receipt-actions { text-align: center; margin-top: 20px; }
    @media print {
        .no-print { display: none !important; }
        body { background: #fff; }
        .receipt-card { box-shadow: none; }
    }
</style>
</head>
<body>
<div class="receipt-wrap">
    <div class="receipt-card">
        <div class="receipt-header">
            <div class="logo-mark">R</div>
            <h2 style="margin:4px 0 0;color:#fff;">Payment Receipt</h2>
            <p style="color:rgba(255,255,255,0.85);margin:2px 0 0;font-size:13px;"><?= e($r['company_name']) ?></p>
        </div>
        <div class="receipt-body">
            <div class="receipt-amount">
                <div class="value">$<?= money($r['amount']) ?></div>
                <span class="badge badge-success" style="margin-top:6px;">Paid</span>
            </div>
            <div class="receipt-row"><span class="label">Receipt Number</span><strong><?= e($r['receipt_number']) ?></strong></div>
            <div class="receipt-row"><span class="label">Tenant</span><strong><?= e($r['first_name'] . ' ' . $r['last_name']) ?></strong></div>
            <div class="receipt-row"><span class="label">Property / Unit</span><strong><?= e($r['property_name'] . ' — ' . $r['unit_number']) ?></strong></div>
            <div class="receipt-row"><span class="label">Payment Type</span><strong><?= ucfirst(str_replace('_',' ',$r['payment_type'])) ?></strong></div>
            <div class="receipt-row"><span class="label">Payment Method</span><strong><?= ucfirst(str_replace('_',' ',$r['payment_method'])) ?></strong></div>
            <div class="receipt-row"><span class="label">Date Paid</span><strong><?= format_date($r['payment_date']) ?></strong></div>
            <?php if (!empty($r['notes'])): ?>
            <div class="receipt-row"><span class="label">Notes</span><strong><?= e($r['notes']) ?></strong></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="receipt-actions no-print">
        <button onclick="window.print()" class="btn btn-primary"><i class="fa-solid fa-print"></i> Print / Save as PDF</button>
    </div>
</div>
</body>
</html>
