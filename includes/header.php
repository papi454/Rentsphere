<?php
/**
 * Include AFTER require_role([...]) in every dashboard page.
 * Expects optional $pageTitle and $pageSubtitle variables set before including.
 */
$user = current_user();
$currentPage = basename($_SERVER['SCRIPT_NAME']);
$unreadCount = $user ? unread_notification_count($user['id']) : 0;

function nav_active(string $file, string $current): string {
    return $file === $current ? 'active' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($pageTitle) ? e($pageTitle) . ' — ' : '' ?><?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <div class="auth-logo-mark">R</div>
            <span class="sidebar-brand-text"><?= e(APP_NAME) ?></span>
        </div>

        <div class="nav-section-title">Main</div>
        <ul class="nav-list">
            <li class="nav-item <?= nav_active('dashboard.php', $currentPage) ?>"><a href="<?= APP_URL ?>/admin/dashboard.php"><i class="fa-solid fa-gauge"></i><span class="nav-label">Dashboard</span></a></li>
        </ul>

        <div class="nav-section-title">Property Management</div>
        <ul class="nav-list">
            <li class="nav-item <?= nav_active('properties.php', $currentPage) ?>"><a href="<?= APP_URL ?>/admin/properties.php"><i class="fa-solid fa-building"></i><span class="nav-label">Properties</span></a></li>
            <li class="nav-item <?= nav_active('units.php', $currentPage) ?>"><a href="<?= APP_URL ?>/admin/units.php"><i class="fa-solid fa-door-open"></i><span class="nav-label">Units</span></a></li>
            <li class="nav-item <?= nav_active('tenants.php', $currentPage) ?>"><a href="<?= APP_URL ?>/admin/tenants.php"><i class="fa-solid fa-users"></i><span class="nav-label">Tenants</span></a></li>
            <li class="nav-item <?= nav_active('leases.php', $currentPage) ?>"><a href="<?= APP_URL ?>/admin/leases.php"><i class="fa-solid fa-file-signature"></i><span class="nav-label">Leases</span></a></li>
        </ul>

        <div class="nav-section-title">Finance</div>
        <ul class="nav-list">
            <li class="nav-item <?= nav_active('payments.php', $currentPage) ?>"><a href="<?= APP_URL ?>/admin/payments.php"><i class="fa-solid fa-hand-holding-dollar"></i><span class="nav-label">Rent Collection</span></a></li>
            <li class="nav-item <?= nav_active('expenses.php', $currentPage) ?>"><a href="<?= APP_URL ?>/admin/expenses.php"><i class="fa-solid fa-receipt"></i><span class="nav-label">Expenses</span></a></li>
            <li class="nav-item <?= nav_active('reports.php', $currentPage) ?>"><a href="<?= APP_URL ?>/admin/reports.php"><i class="fa-solid fa-chart-line"></i><span class="nav-label">Reports</span></a></li>
        </ul>

        <div class="nav-section-title">Operations</div>
        <ul class="nav-list">
            <li class="nav-item <?= nav_active('maintenance.php', $currentPage) ?>"><a href="<?= APP_URL ?>/admin/maintenance.php"><i class="fa-solid fa-screwdriver-wrench"></i><span class="nav-label">Maintenance</span></a></li>
            <li class="nav-item <?= nav_active('notifications.php', $currentPage) ?>"><a href="<?= APP_URL ?>/admin/notifications.php"><i class="fa-solid fa-bell"></i><span class="nav-label">Notifications</span></a></li>
        </ul>

        <div class="nav-section-title">Administration</div>
        <ul class="nav-list">
            <li class="nav-item <?= nav_active('users.php', $currentPage) ?>"><a href="<?= APP_URL ?>/admin/users.php"><i class="fa-solid fa-user-shield"></i><span class="nav-label">Users &amp; Roles</span></a></li>
            <li class="nav-item <?= nav_active('activity_logs.php', $currentPage) ?>"><a href="<?= APP_URL ?>/admin/activity_logs.php"><i class="fa-solid fa-clock-rotate-left"></i><span class="nav-label">Activity Logs</span></a></li>
            <li class="nav-item <?= nav_active('settings.php', $currentPage) ?>"><a href="<?= APP_URL ?>/admin/settings.php"><i class="fa-solid fa-gear"></i><span class="nav-label">Settings</span></a></li>
        </ul>
    </aside>

    <div class="main-content">
        <header class="topbar">
            <div class="topbar-left">
                <button class="sidebar-toggle" id="mobileNavToggle"><i class="fa-solid fa-bars"></i></button>
                <button class="sidebar-toggle" id="sidebarToggle"><i class="fa-solid fa-angles-left"></i></button>
                <div class="search-bar">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" placeholder="Search properties, tenants, payments...">
                </div>
            </div>
            <div class="topbar-right">
                <button class="icon-btn" id="themeToggle"><i class="fa-solid fa-moon"></i></button>
                <a href="<?= APP_URL ?>/auth/logout.php" class="icon-btn" style="text-decoration:none;" title="Log Out"><i class="fa-solid fa-right-from-bracket"></i></a>
                <a href="<?= APP_URL ?>/admin/notifications.php" class="icon-btn" style="text-decoration:none;">
                    <i class="fa-solid fa-bell"></i>
                    <?php if ($unreadCount > 0): ?><span class="badge-dot"></span><?php endif; ?>
                </a>
                <div style="position:relative;">
                    <div class="user-menu" id="userMenuTrigger">
                        <div class="avatar"><?= e(strtoupper(substr($user['first_name'] ?? 'U', 0, 1) . substr($user['last_name'] ?? '', 0, 1))) ?></div>
                        <div>
                            <div class="user-menu-name"><?= e(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?></div>
                            <div class="user-menu-role"><?= e(ucfirst($user['role'] ?? '')) ?></div>
                        </div>
                        <i class="fa-solid fa-chevron-down" style="font-size:11px;color:var(--text-secondary);"></i>
                    </div>
                    <div class="card" id="userMenuDropdown" style="display:none;position:absolute;right:0;top:52px;min-width:180px;padding:8px;z-index:50;">
                        <a href="<?= APP_URL ?>/admin/profile.php" class="nav-item" style="display:block;padding:9px 12px;border-radius:8px;color:var(--text-primary);">My Profile</a>
                        <a href="<?= APP_URL ?>/admin/settings.php" style="display:block;padding:9px 12px;border-radius:8px;color:var(--text-primary);">Settings</a>
                        <hr style="border-color:var(--border-color);margin:6px 0;">
                        <a href="<?= APP_URL ?>/auth/logout.php" style="display:block;padding:9px 12px;border-radius:8px;color:var(--color-danger);">Log Out</a>
                    </div>
                </div>
            </div>
        </header>

        <main class="page-body">
            <?php if ($msg = get_flash('success')): ?>
                <div class="alert alert-success" data-autohide><i class="fa-solid fa-circle-check"></i> <?= e($msg) ?></div>
            <?php endif; ?>
            <?php if ($msg = get_flash('error')): ?>
                <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?= e($msg) ?></div>
            <?php endif; ?>
