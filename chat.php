<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$db = Database::getConnection();
$user = current_user();

// Determine which property chat group(s) this user can access
if ($user['role'] === 'administrator') {
    $stmt = $db->prepare("SELECT id, name FROM properties WHERE company_id = ? ORDER BY name");
    $stmt->execute([$user['company_id']]);
    $accessibleProperties = $stmt->fetchAll();
} elseif ($user['role'] === 'caretaker') {
    $stmt = $db->prepare("SELECT id, name FROM properties WHERE caretaker_id = ? ORDER BY name");
    $stmt->execute([$user['id']]);
    $accessibleProperties = $stmt->fetchAll();
} else { // tenant
    $stmt = $db->prepare("SELECT DISTINCT p.id, p.name FROM properties p
                           JOIN units u ON u.property_id = p.id
                           JOIN leases l ON l.unit_id = u.id
                           JOIN tenants t ON t.id = l.tenant_id
                           WHERE t.user_id = ? AND l.status = 'active'");
    $stmt->execute([$user['id']]);
    $accessibleProperties = $stmt->fetchAll();
}

if (empty($accessibleProperties)) {
    $pageTitle = 'Chat';
    require_once __DIR__ . '/includes/header.php';
    ?>
    <div class="breadcrumbs">Chat</div>
    <div class="page-header"><div><h1 class="page-title">Property Chat</h1></div></div>
    <div class="card"><div class="empty-state"><i class="fa-solid fa-comments"></i><h3>No property chat available yet</h3>
    <p><?= $user['role'] === 'tenant' ? 'You need an active lease before you can join your property chat.' : 'No properties are assigned to you yet.' ?></p></div></div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$accessibleIds = array_column($accessibleProperties, 'id');
$propertyId = (int) ($_GET['property_id'] ?? $accessibleIds[0]);
if (!in_array($propertyId, $accessibleIds, true)) {
    $propertyId = $accessibleIds[0];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $msgPropertyId = (int) $_POST['property_id'];
    $message = trim($_POST['message'] ?? '');
    if (in_array($msgPropertyId, $accessibleIds, true) && $message !== '') {
        $stmt = $db->prepare("INSERT INTO chat_messages (property_id, sender_user_id, message) VALUES (?, ?, ?)");
        $stmt->execute([$msgPropertyId, $user['id'], $message]);
        log_activity('chat_message_sent', "Sent a message in property #$msgPropertyId chat");
    }
    redirect(APP_URL . '/chat.php?property_id=' . $msgPropertyId);
}

$stmt = $db->prepare("SELECT cm.*, u.first_name, u.last_name, u.role FROM chat_messages cm
                       JOIN users u ON u.id = cm.sender_user_id
                       WHERE cm.property_id = ? ORDER BY cm.created_at ASC LIMIT 300");
$stmt->execute([$propertyId]);
$messages = $stmt->fetchAll();

$currentPropertyName = '';
foreach ($accessibleProperties as $p) { if ((int) $p['id'] === $propertyId) $currentPropertyName = $p['name']; }

$pageTitle = 'Chat';
require_once __DIR__ . '/includes/header.php';
?>
<div class="breadcrumbs">Chat</div>
<div class="page-header">
    <div><h1 class="page-title">Property Chat</h1><p class="page-subtitle">Message everyone connected to <?= e($currentPropertyName) ?>.</p></div>
    <?php if (count($accessibleProperties) > 1): ?>
    <form method="GET">
        <select name="property_id" class="form-control" onchange="this.form.submit()">
            <?php foreach ($accessibleProperties as $p): ?>
                <option value="<?= $p['id'] ?>" <?= (int) $p['id'] === $propertyId ? 'selected' : '' ?>><?= e($p['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php endif; ?>
</div>

<div class="card" style="max-width:760px;margin:0 auto;padding:0;overflow:hidden;">
    <div style="height:58vh;overflow-y:auto;padding:20px;display:flex;flex-direction:column;gap:14px;" id="chatScroll">
        <?php if (empty($messages)): ?>
            <div class="empty-state"><i class="fa-solid fa-comments"></i><h3>No messages yet</h3><p>Start the conversation for <?= e($currentPropertyName) ?>.</p></div>
        <?php else: ?>
            <?php foreach ($messages as $m):
                $isMe = (int) $m['sender_user_id'] === (int) $user['id'];
                $roleBadge = $m['role'] === 'administrator' ? 'badge-info' : ($m['role'] === 'caretaker' ? 'badge-warning' : 'badge-neutral');
            ?>
            <div style="display:flex;flex-direction:column;align-items:<?= $isMe ? 'flex-end' : 'flex-start' ?>;">
                <div style="font-size:11.5px;color:var(--text-secondary);margin-bottom:4px;">
                    <?= $isMe ? 'You' : e($m['first_name'] . ' ' . $m['last_name']) ?>
                    <span class="badge <?= $roleBadge ?>" style="margin-left:6px;font-size:10px;padding:2px 7px;"><?= ucfirst($m['role']) ?></span>
                </div>
                <div style="max-width:75%;padding:10px 14px;border-radius:14px;font-size:14px;line-height:1.5;
                    <?= $isMe ? 'background:var(--gradient-brand);color:#fff;border-bottom-right-radius:4px;' : 'background:var(--bg-body);color:var(--text-primary);border-bottom-left-radius:4px;' ?>">
                    <?= nl2br(e($m['message'])) ?>
                </div>
                <div style="font-size:10.5px;color:var(--text-secondary);margin-top:3px;"><?= format_date($m['created_at'], 'M j, g:i A') ?></div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <form method="POST" style="display:flex;gap:10px;padding:16px;border-top:1px solid var(--border-color);background:var(--bg-surface);">
        <?= csrf_field() ?>
        <input type="hidden" name="property_id" value="<?= $propertyId ?>">
        <input type="text" name="message" class="form-control" placeholder="Type a message..." required autocomplete="off">
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-paper-plane"></i></button>
    </form>
</div>

<script>
    var chatScroll = document.getElementById('chatScroll');
    if (chatScroll) chatScroll.scrollTop = chatScroll.scrollHeight;
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
