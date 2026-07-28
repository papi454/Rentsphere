<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_role(['administrator']);

$db = Database::getConnection();
$companyId = $_SESSION['company_id'];

$stmt = $db->prepare("SELECT al.*, u.first_name, u.last_name FROM activity_logs al LEFT JOIN users u ON u.id = al.user_id
                       WHERE al.company_id = ? ORDER BY al.created_at DESC LIMIT 200");
$stmt->execute([$companyId]);
$logs = $stmt->fetchAll();

$stmt = $db->prepare("SELECT lh.*, u.first_name, u.last_name FROM login_history lh JOIN users u ON u.id = lh.user_id
                       WHERE u.company_id = ? ORDER BY lh.created_at DESC LIMIT 50");
$stmt->execute([$companyId]);
$logins = $stmt->fetchAll();

$pageTitle = 'Activity Logs';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="breadcrumbs"><a href="dashboard.php">Dashboard</a> / Activity Logs</div>
<div class="page-header"><div><h1 class="page-title">Activity &amp; Login Logs</h1><p class="page-subtitle">Audit trail of actions and sign-ins.</p></div></div>

<div class="grid-2">
    <div class="card">
        <h3>Activity Log</h3>
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th>User</th><th>Action</th><th>Description</th><th>When</th></tr></thead>
            <tbody>
            <?php foreach ($logs as $l): ?>
                <tr>
                    <td><?= $l['first_name'] ? e($l['first_name'].' '.$l['last_name']) : 'System' ?></td>
                    <td><span class="badge badge-neutral"><?= e(str_replace('_',' ',$l['action'])) ?></span></td>
                    <td><?= e($l['description']) ?></td>
                    <td class="text-secondary"><?= format_date($l['created_at'], 'M j, g:i A') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <div class="card">
        <h3>Login History</h3>
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th>User</th><th>IP</th><th>Status</th><th>When</th></tr></thead>
            <tbody>
            <?php foreach ($logins as $l): ?>
                <tr>
                    <td><?= e($l['first_name'].' '.$l['last_name']) ?></td>
                    <td class="text-secondary"><?= e($l['ip_address']) ?></td>
                    <td><span class="badge badge-<?= $l['status']==='success'?'success':'danger' ?>"><?= ucfirst($l['status']) ?></span></td>
                    <td class="text-secondary"><?= format_date($l['created_at'], 'M j, g:i A') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
