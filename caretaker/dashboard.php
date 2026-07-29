<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_role(['caretaker']);

$db = Database::getConnection();
$user = current_user();

$stmt = $db->prepare("SELECT * FROM properties WHERE caretaker_id = ?");
$stmt->execute([$user['id']]);
$myProperties = $stmt->fetchAll();

$stmt = $db->prepare("SELECT mr.*, p.name AS property_name, u.unit_number FROM maintenance_requests mr
                       JOIN properties p ON p.id = mr.property_id LEFT JOIN units u ON u.id = mr.unit_id
                       WHERE mr.assigned_caretaker_id = ? ORDER BY mr.created_at DESC");
$stmt->execute([$user['id']]);
$myRequests = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'complete') {
    csrf_verify_or_die();
    $id = (int) $_POST['id'];
    $db->prepare("UPDATE maintenance_requests SET status='completed', completed_at=NOW() WHERE id=? AND assigned_caretaker_id=?")
       ->execute([$id, $user['id']]);
    flash('success', 'Request marked as completed.');
    redirect(APP_URL . '/caretaker/dashboard.php');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Caretaker Dashboard — <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<div class="page-body" style="max-width:1000px;margin:0 auto;">
    <div class="page-header">
        <div><h1 class="page-title">Hi, <?= e($user['first_name']) ?> 👋</h1><p class="page-subtitle">Your assigned properties and maintenance tasks.</p></div>
        <div class="d-flex gap-8">
            <a href="tenants.php" class="btn btn-primary"><i class="fa-solid fa-users"></i> My Tenants</a>
            <a href="billing.php" class="btn btn-outline"><i class="fa-solid fa-file-invoice-dollar"></i> Billing</a>
            <a href="payment_confirmations.php" class="btn btn-outline"><i class="fa-solid fa-hand-holding-dollar"></i> Confirm Payments</a>
            <a href="<?= APP_URL ?>/auth/logout.php" class="btn btn-outline">Log Out</a>
        </div>
    </div>

    <?php if ($msg = get_flash('success')): ?><div class="alert alert-success" data-autohide><?= e($msg) ?></div><?php endif; ?>

    <div class="card" style="margin-bottom:20px;">
        <h3>My Properties</h3>
        <?php if (empty($myProperties)): ?>
            <div class="empty-state"><i class="fa-solid fa-building"></i><h3>No properties assigned yet</h3></div>
        <?php else: ?>
            <ul>
            <?php foreach ($myProperties as $p): ?>
                <li><?= e($p['name']) ?> — <?= e($p['address']) ?></li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>My Maintenance Requests</h3>
        <?php if (empty($myRequests)): ?>
            <div class="empty-state"><i class="fa-solid fa-screwdriver-wrench"></i><h3>Nothing assigned to you right now</h3></div>
        <?php else: ?>
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th>Title</th><th>Property/Unit</th><th>Priority</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($myRequests as $r): ?>
                <tr>
                    <td><?= e($r['title']) ?></td>
                    <td><?= e($r['property_name']) ?><?= $r['unit_number'] ? ' — '.e($r['unit_number']) : '' ?></td>
                    <td><span class="badge badge-<?= $r['priority']==='urgent'?'danger':'info' ?>"><?= ucfirst($r['priority']) ?></span></td>
                    <td><span class="badge badge-<?= $r['status']==='completed'?'success':'warning' ?>"><?= ucfirst(str_replace('_',' ',$r['status'])) ?></span></td>
                    <td>
                        <?php if ($r['status'] !== 'completed'): ?>
                        <form method="POST"><?= csrf_field() ?><input type="hidden" name="form_action" value="complete"><input type="hidden" name="id" value="<?= $r['id'] ?>">
                            <button type="submit" class="btn btn-outline btn-sm">Mark Done</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
