<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_role(['administrator']);

$db = Database::getConnection();
$companyId = $_SESSION['company_id'];

$stmt = $db->prepare("SELECT COUNT(*) FROM properties WHERE company_id = ?"); $stmt->execute([$companyId]); $totalProperties = (int) $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM units u JOIN properties p ON p.id = u.property_id WHERE p.company_id = ?");
$stmt->execute([$companyId]); $totalUnits = (int) $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM units u JOIN properties p ON p.id = u.property_id WHERE p.company_id = ? AND u.occupancy_status = 'occupied'");
$stmt->execute([$companyId]); $occupiedUnits = (int) $stmt->fetchColumn();
$vacantUnits = $totalUnits - $occupiedUnits;

$stmt = $db->prepare("SELECT COUNT(*) FROM tenants WHERE company_id = ? AND status = 'active'");
$stmt->execute([$companyId]); $totalTenants = (int) $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN leases l ON l.id = p.lease_id JOIN units u ON u.id = l.unit_id
                       JOIN properties pr ON pr.id = u.property_id
                       WHERE pr.company_id = ? AND MONTH(p.payment_date) = MONTH(CURDATE()) AND YEAR(p.payment_date) = YEAR(CURDATE())");
$stmt->execute([$companyId]); $monthlyRevenue = (float) $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM maintenance_requests WHERE company_id = ? AND status IN ('submitted','in_progress')");
$stmt->execute([$companyId]); $pendingMaintenance = (int) $stmt->fetchColumn();

$occupancyRate = $totalUnits > 0 ? round(($occupiedUnits / $totalUnits) * 100) : 0;

// Revenue trend - last 6 months
$revenueLabels = []; $revenueData = [];
for ($i = 5; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $stmt = $db->prepare("SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN leases l ON l.id = p.lease_id JOIN units u ON u.id = l.unit_id
                           JOIN properties pr ON pr.id = u.property_id
                           WHERE pr.company_id = ? AND DATE_FORMAT(p.payment_date, '%Y-%m') = ?");
    $stmt->execute([$companyId, $month]);
    $revenueLabels[] = date('M', strtotime($month . '-01'));
    $revenueData[] = (float) $stmt->fetchColumn();
}

// Recent maintenance requests
$stmt = $db->prepare("SELECT mr.*, pr.name AS property_name FROM maintenance_requests mr
                       JOIN properties pr ON pr.id = mr.property_id
                       WHERE mr.company_id = ? ORDER BY mr.created_at DESC LIMIT 5");
$stmt->execute([$companyId]);
$recentMaintenance = $stmt->fetchAll();

// Upcoming lease expiries (next 30 days)
$stmt = $db->prepare("SELECT l.*, t.first_name, t.last_name, u.unit_number FROM leases l
                       JOIN tenants t ON t.id = l.tenant_id JOIN units u ON u.id = l.unit_id
                       JOIN properties pr ON pr.id = u.property_id
                       WHERE pr.company_id = ? AND l.status = 'active' AND l.lease_end BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                       ORDER BY l.lease_end ASC LIMIT 5");
$stmt->execute([$companyId]);
$expiringLeases = $stmt->fetchAll();

$pageTitle = 'Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Welcome back, <?= e($user['first_name']) ?> 👋</h1>
        <p class="page-subtitle">Here's what's happening across your properties today.</p>
    </div>
    <a href="properties.php?action=add" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add Property</a>
</div>

<div class="stat-grid">
    <div class="card stat-card"><div class="stat-icon"><i class="fa-solid fa-building"></i></div><div class="stat-value"><?= $totalProperties ?></div><div class="stat-label">Total Properties</div></div>
    <div class="card stat-card"><div class="stat-icon"><i class="fa-solid fa-door-open"></i></div><div class="stat-value"><?= $totalUnits ?></div><div class="stat-label">Total Units</div></div>
    <div class="card stat-card"><div class="stat-icon"><i class="fa-solid fa-key"></i></div><div class="stat-value"><?= $occupiedUnits ?></div><div class="stat-label">Occupied Units</div><span class="stat-trend trend-up"><?= $occupancyRate ?>% occupancy</span></div>
    <div class="card stat-card"><div class="stat-icon"><i class="fa-solid fa-house"></i></div><div class="stat-value"><?= $vacantUnits ?></div><div class="stat-label">Vacant Units</div></div>
    <div class="card stat-card"><div class="stat-icon"><i class="fa-solid fa-users"></i></div><div class="stat-value"><?= $totalTenants ?></div><div class="stat-label">Total Tenants</div></div>
    <div class="card stat-card"><div class="stat-icon"><i class="fa-solid fa-sack-dollar"></i></div><div class="stat-value">$<?= money($monthlyRevenue) ?></div><div class="stat-label">Monthly Revenue</div></div>
    <div class="card stat-card"><div class="stat-icon"><i class="fa-solid fa-triangle-exclamation"></i></div><div class="stat-value"><?= $pendingMaintenance ?></div><div class="stat-label">Pending Maintenance</div></div>
</div>

<div class="grid-2">
    <div class="card">
        <h3>Monthly Revenue (Last 6 Months)</h3>
        <canvas id="revenueChart" height="110"></canvas>
    </div>
    <div class="card">
        <h3>Occupancy Rate</h3>
        <canvas id="occupancyChart" height="110"></canvas>
    </div>
</div>

<div class="grid-2" style="margin-top:20px;">
    <div class="card">
        <h3>Recent Maintenance Requests</h3>
        <?php if (empty($recentMaintenance)): ?>
            <div class="empty-state"><i class="fa-solid fa-screwdriver-wrench"></i><h3>No requests yet</h3><p>Maintenance requests will appear here.</p></div>
        <?php else: ?>
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th>Title</th><th>Property</th><th>Priority</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($recentMaintenance as $m): ?>
                <tr>
                    <td><?= e($m['title']) ?></td>
                    <td><?= e($m['property_name']) ?></td>
                    <td><span class="badge badge-<?= $m['priority'] === 'urgent' ? 'danger' : ($m['priority'] === 'high' ? 'warning' : 'info') ?>"><?= e(ucfirst($m['priority'])) ?></span></td>
                    <td><span class="badge badge-<?= $m['status'] === 'completed' ? 'success' : 'neutral' ?>"><?= e(ucfirst(str_replace('_',' ',$m['status']))) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
    <div class="card">
        <h3>Leases Expiring Soon</h3>
        <?php if (empty($expiringLeases)): ?>
            <div class="empty-state"><i class="fa-solid fa-file-signature"></i><h3>All clear</h3><p>No leases expiring in the next 30 days.</p></div>
        <?php else: ?>
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th>Tenant</th><th>Unit</th><th>Lease End</th></tr></thead>
            <tbody>
            <?php foreach ($expiringLeases as $l): ?>
                <tr><td><?= e($l['first_name'] . ' ' . $l['last_name']) ?></td><td><?= e($l['unit_number']) ?></td><td><?= format_date($l['lease_end']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('revenueChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($revenueLabels) ?>,
        datasets: [{ label: 'Revenue', data: <?= json_encode($revenueData) ?>, borderColor: '#2563EB', backgroundColor: 'rgba(37,99,235,0.12)', fill: true, tension: 0.35 }]
    },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
});
new Chart(document.getElementById('occupancyChart'), {
    type: 'doughnut',
    data: {
        labels: ['Occupied', 'Vacant'],
        datasets: [{ data: [<?= $occupiedUnits ?>, <?= $vacantUnits ?>], backgroundColor: ['#2563EB', '#E5E9F0'] }]
    },
    options: { plugins: { legend: { position: 'bottom' } } }
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
