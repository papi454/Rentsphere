<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_role(['caretaker']);

$db = Database::getConnection();
$user = current_user();

$stmt = $db->prepare("SELECT id FROM properties WHERE caretaker_id = ?");
$stmt->execute([$user['id']]);
$myPropertyIds = array_column($stmt->fetchAll(), 'id');

if (empty($myPropertyIds)) { flash('error', 'You have no properties assigned yet.'); redirect(APP_URL . '/caretaker/dashboard.php'); }
$placeholders = implode(',', array_fill(0, count($myPropertyIds), '?'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $action = $_POST['form_action'] ?? '';
    $requestId = (int) ($_POST['id'] ?? 0);

    // Always re-verify this payment request belongs to one of the caretaker's properties
    $stmt = $db->prepare("SELECT pr.*, l.rent_amount, u.property_id, t.first_name, t.user_id AS tenant_user_id
                           FROM payment_requests pr
                           JOIN leases l ON l.id = pr.lease_id JOIN units u ON u.id = l.unit_id
                           JOIN tenants t ON t.id = pr.tenant_id
                           WHERE pr.id = ? AND u.property_id IN ($placeholders) AND pr.status = 'pending'");
    $stmt->execute(array_merge([$requestId], $myPropertyIds));
    $req = $stmt->fetch();

    if (!$req) {
        flash('error', 'That payment request is no longer available.');
        redirect(APP_URL . '/caretaker/payment_confirmations.php');
    }

    if ($action === 'reject') {
        $db->prepare("UPDATE payment_requests SET status='rejected', confirmed_by=?, confirmed_at=NOW() WHERE id=?")
           ->execute([$user['id'], $requestId]);
        if ($req['tenant_user_id']) {
            create_notification($user['company_id'], 'payment_rejected', 'Payment Not Confirmed',
                "Your payment of $" . money($req['amount']) . " could not be confirmed. Please check the reference and try again.",
                $req['tenant_user_id']);
        }
        log_activity('payment_rejected', "Rejected payment request #$requestId", $user['id']);
        flash('success', 'Payment request rejected. The tenant has been notified.');
        redirect(APP_URL . '/caretaker/payment_confirmations.php');
    }

    if ($action === 'confirm') {
        $db->beginTransaction();

        $paymentType = $req['bill_id'] ? 'other' : 'rent';
        $stmt = $db->prepare("INSERT INTO payments (lease_id, tenant_id, amount, payment_type, payment_method, payment_date, recorded_by, notes)
                               VALUES (?,?,?,?,?,CURDATE(),?,?)");
        $method = $req['method'] === 'mpesa' ? 'mobile_money' : 'bank_transfer';
        $notes = 'Tenant self-submitted, confirmed by caretaker. Ref: ' . $req['reference'];
        $stmt->execute([$req['lease_id'], $req['tenant_id'], $req['amount'], $paymentType, $method, $user['id'], $notes]);
        $paymentId = $db->lastInsertId();

        $receiptNumber = 'RCPT-' . date('Ymd') . '-' . str_pad((string) $paymentId, 5, '0', STR_PAD_LEFT);
        $db->prepare("INSERT INTO receipts (payment_id, receipt_number) VALUES (?, ?)")->execute([$paymentId, $receiptNumber]);

        $db->prepare("UPDATE payment_requests SET status='confirmed', confirmed_by=?, confirmed_at=NOW(), resulting_payment_id=? WHERE id=?")
           ->execute([$user['id'], $paymentId, $requestId]);

        if ($req['bill_id']) {
            $db->prepare("UPDATE bills SET status='paid' WHERE id=?")->execute([$req['bill_id']]);
        }

        $db->commit();

        if ($req['tenant_user_id']) {
            create_notification($user['company_id'], 'payment_confirmed', 'Payment Received Successfully',
                "Your payment of $" . money($req['amount']) . " has been confirmed. Receipt: $receiptNumber",
                $req['tenant_user_id']);
        }
        log_activity('payment_confirmed', "Confirmed payment request #$requestId as payment #$paymentId ($receiptNumber)", $user['id']);
        flash('success', "Payment confirmed. Receipt $receiptNumber generated and the tenant has been notified.");
        redirect(APP_URL . '/caretaker/payment_confirmations.php');
    }
}

$stmt = $db->prepare("SELECT pr.*, t.first_name, t.last_name, u.unit_number, b.bill_type
                       FROM payment_requests pr JOIN tenants t ON t.id = pr.tenant_id
                       JOIN leases l ON l.id = pr.lease_id JOIN units u ON u.id = l.unit_id
                       LEFT JOIN bills b ON b.id = pr.bill_id
                       WHERE u.property_id IN ($placeholders) AND pr.status = 'pending' ORDER BY pr.submitted_at ASC");
$stmt->execute($myPropertyIds);
$pending = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Confirm Payments — <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<div class="page-body" style="max-width:1000px;margin:0 auto;">
    <div class="page-header">
        <div><h1 class="page-title">Confirm Payments</h1><p class="page-subtitle">Review tenant-submitted payments before they're recorded.</p></div>
        <a href="dashboard.php" class="btn btn-outline">Dashboard</a>
    </div>
    <?php if ($msg = get_flash('success')): ?><div class="alert alert-success" data-autohide><?= e($msg) ?></div><?php endif; ?>
    <?php if ($msg = get_flash('error')): ?><div class="alert alert-error"><?= e($msg) ?></div><?php endif; ?>

    <div class="card">
        <?php if (empty($pending)): ?>
            <div class="empty-state"><i class="fa-solid fa-hand-holding-dollar"></i><h3>No pending payments</h3><p>Tenant payment submissions will show up here for confirmation.</p></div>
        <?php else: ?>
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th>Tenant</th><th>Unit</th><th>For</th><th>Amount</th><th>Method</th><th>Reference</th><th>Submitted</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($pending as $p): ?>
                <tr>
                    <td><?= e($p['first_name'] . ' ' . $p['last_name']) ?></td>
                    <td><?= e($p['unit_number']) ?></td>
                    <td><?= $p['bill_type'] ? ucfirst(str_replace('_',' ',$p['bill_type'])) : 'Rent' ?></td>
                    <td>$<?= money($p['amount']) ?></td>
                    <td><?= $p['method'] === 'mpesa' ? 'M-Pesa' : 'Bank Transfer' ?></td>
                    <td><?= e($p['reference'] ?: '—') ?></td>
                    <td class="text-secondary"><?= format_date($p['submitted_at'], 'M j, g:i A') ?></td>
                    <td>
                        <div class="d-flex gap-8">
                            <form method="POST"><?= csrf_field() ?><input type="hidden" name="form_action" value="confirm"><input type="hidden" name="id" value="<?= $p['id'] ?>">
                                <button type="submit" class="btn btn-primary btn-sm">Confirm</button>
                            </form>
                            <form method="POST" onsubmit="event.preventDefault(); confirmDelete(this, 'Reject this payment submission?');">
                                <?= csrf_field() ?><input type="hidden" name="form_action" value="reject"><input type="hidden" name="id" value="<?= $p['id'] ?>">
                                <button type="submit" class="btn btn-outline btn-sm" style="color:var(--color-danger);">Reject</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
</body>
</html>
