<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_role(['administrator','caretaker']);

$db = Database::getConnection();
$userId = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'mark_read') {
    csrf_verify_or_die();
    $db->prepare("UPDATE notifications SET is_read = 1 WHERE recipient_user_id = ?")->execute([$userId]);
    redirect(APP_URL . '/admin/notifications.php');
}

$stmt = $db->prepare("SELECT * FROM notifications WHERE recipient_user_id = ? ORDER BY created_at DESC LIMIT 50");
$stmt->execute([$userId]);
$notifications = $stmt->fetchAll();

$pageTitle = 'Notifications';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="breadcrumbs"><a href="dashboard.php">Dashboard</a> / Notifications</div>
<div class="page-header">
    <div><h1 class="page-title">Notifications</h1><p class="page-subtitle">Stay on top of rent, leases, and maintenance.</p></div>
    <form method="POST"><?= csrf_field() ?><input type="hidden" name="form_action" value="mark_read">
        <button type="submit" class="btn btn-outline">Mark all as read</button>
    </form>
</div>

<div class="card">
    <?php if (empty($notifications)): ?>
        <div class="empty-state"><i class="fa-solid fa-bell"></i><h3>No notifications</h3><p>You're all caught up.</p></div>
    <?php else: ?>
        <?php foreach ($notifications as $n): ?>
        <div style="padding:14px 0;border-bottom:1px solid var(--border-color);display:flex;gap:14px;<?= $n['is_read'] ? 'opacity:.6' : '' ?>">
            <div class="stat-icon" style="width:36px;height:36px;font-size:14px;flex-shrink:0;"><i class="fa-solid fa-bell"></i></div>
            <div>
                <strong><?= e($n['title']) ?></strong>
                <p style="margin:4px 0 0;"><?= e($n['message']) ?></p>
                <span class="text-secondary" style="font-size:12px;"><?= format_date($n['created_at'], 'M j, Y g:i A') ?></span>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
