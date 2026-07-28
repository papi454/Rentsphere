<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_role(['administrator']);

$db = Database::getConnection();
$companyId = $_SESSION['company_id'];
$report = $_GET['report'] ?? 'revenue';
$export = $_GET['export'] ?? '';

function csv_export(string $filename, array $headers, array $rows): void
{
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    foreach ($rows as $row) fputcsv($out, $row);
    fclose($out);
    exit;
}

$data = [];
$headers = [];

switch ($report) {
    case 'revenue':
        $stmt = $db->prepare("SELECT pay.payment_date, t.first_name, t.last_name, u.unit_number, pay.payment_type, pay.amount
                               FROM payments pay JOIN tenants t ON t.id=pay.tenant_id JOIN leases l ON l.id=pay.lease_id
                               JOIN units u ON u.id=l.unit_id JOIN properties p ON p.id=u.property_id
                               WHERE p.company_id=? ORDER BY pay.payment_date DESC");
        $stmt->execute([$companyId]);
        $data = $stmt->fetchAll();
        $headers = ['Date','Tenant First','Tenant Last','Unit','Type','Amount'];
        break;
    case 'expenses':
        $stmt = $db->prepare("SELECT e.expense_date, e.category, p.name AS property_name, e.description, e.amount
                               FROM expenses e LEFT JOIN properties p ON p.id=e.property_id WHERE e.company_id=? ORDER BY e.expense_date DESC");
        $stmt->execute([$companyId]);
        $data = $stmt->fetchAll();
        $headers = ['Date','Category','Property','Description','Amount'];
        break;
    case 'occupancy':
        $stmt = $db->prepare("SELECT p.name AS property_name, u.unit_number, u.occupancy_status, u.rent_amount
                               FROM units u JOIN properties p ON p.id=u.property_id WHERE p.company_id=? ORDER BY p.name, u.unit_number");
        $stmt->execute([$companyId]);
        $data = $stmt->fetchAll();
        $headers = ['Property','Unit','Status','Rent'];
        break;
    case 'tenants':
        $stmt = $db->prepare("SELECT first_name, last_name, email, phone, status FROM tenants WHERE company_id=? ORDER BY first_name");
        $stmt->execute([$companyId]);
        $data = $stmt->fetchAll();
        $headers = ['First Name','Last Name','Email','Phone','Status'];
        break;
}

if ($export === 'csv' && !empty($headers)) {
    $rows = array_map('array_values', $data);
    csv_export($report . '_report_' . date('Ymd') . '.csv', $headers, $rows);
}

$pageTitle = 'Reports';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="breadcrumbs"><a href="dashboard.php">Dashboard</a> / Reports</div>
<div class="page-header">
    <div><h1 class="page-title">Reports</h1><p class="page-subtitle">Revenue, expenses, occupancy, and tenant reports.</p></div>
</div>

<div class="card" style="margin-bottom:18px;">
    <div class="d-flex gap-8" style="flex-wrap:wrap;">
        <a href="?report=revenue" class="btn <?= $report==='revenue'?'btn-primary':'btn-outline' ?> btn-sm">Revenue</a>
        <a href="?report=expenses" class="btn <?= $report==='expenses'?'btn-primary':'btn-outline' ?> btn-sm">Expenses</a>
        <a href="?report=occupancy" class="btn <?= $report==='occupancy'?'btn-primary':'btn-outline' ?> btn-sm">Occupancy</a>
        <a href="?report=tenants" class="btn <?= $report==='tenants'?'btn-primary':'btn-outline' ?> btn-sm">Tenant List</a>
        <span style="flex:1"></span>
        <a href="?report=<?= $report ?>&export=csv" class="btn btn-outline btn-sm"><i class="fa-solid fa-file-csv"></i> Export CSV</a>
    </div>
</div>

<div class="card">
    <?php if (empty($data)): ?>
        <div class="empty-state"><i class="fa-solid fa-chart-line"></i><h3>No data available</h3><p>Nothing to show for this report yet.</p></div>
    <?php else: ?>
    <div class="table-wrap"><table class="data-table">
        <thead><tr><?php foreach ($headers as $h) echo '<th>' . e($h) . '</th>'; ?></tr></thead>
        <tbody>
        <?php foreach ($data as $row): ?>
            <tr><?php foreach ($row as $col) echo '<td>' . e((string)$col) . '</td>'; ?></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <p class="form-hint" style="margin-top:12px;">Need PDF or Excel export? Install <code>tecnickcom/tcpdf</code> or <code>phpoffice/phpspreadsheet</code> via Composer — CSV export works out of the box.</p>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
