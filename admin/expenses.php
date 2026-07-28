<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_role(['administrator']);

$db = Database::getConnection();
$companyId = $_SESSION['company_id'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $action = $_POST['form_action'] ?? '';

    if ($action === 'delete') {
        $id = (int) $_POST['id'];
        $db->prepare("DELETE FROM expenses WHERE id = ? AND company_id = ?")->execute([$id, $companyId]);
        flash('success', 'Expense deleted.');
        redirect(APP_URL . '/admin/expenses.php');
    }

    if ($action === 'save') {
        $propertyId = !empty($_POST['property_id']) ? (int) $_POST['property_id'] : null;
        $category = $_POST['category'] ?? 'other';
        $description = trim($_POST['description'] ?? '');
        $amount = (float) $_POST['amount'];
        $date = $_POST['expense_date'] ?? date('Y-m-d');

        if ($amount <= 0) $errors[] = 'Amount must be greater than zero.';

        if (empty($errors)) {
            $stmt = $db->prepare("INSERT INTO expenses (company_id, property_id, category, description, amount, expense_date, recorded_by)
                                   VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$companyId, $propertyId, $category, $description, $amount, $date, $_SESSION['user_id']]);
            log_activity('expense_recorded', "Recorded $category expense: $$amount");
            flash('success', 'Expense recorded.');
            redirect(APP_URL . '/admin/expenses.php');
        }
    }
}

$stmt = $db->prepare("SELECT id, name FROM properties WHERE company_id = ? ORDER BY name");
$stmt->execute([$companyId]);
$allProperties = $stmt->fetchAll();

$action = $_GET['action'] ?? 'list';

$stmt = $db->prepare("SELECT e.*, p.name AS property_name FROM expenses e LEFT JOIN properties p ON p.id = e.property_id
                       WHERE e.company_id = ? ORDER BY e.expense_date DESC LIMIT 100");
$stmt->execute([$companyId]);
$expenses = $stmt->fetchAll();

$stmt = $db->prepare("SELECT category, SUM(amount) AS total FROM expenses WHERE company_id = ? AND MONTH(expense_date)=MONTH(CURDATE()) AND YEAR(expense_date)=YEAR(CURDATE()) GROUP BY category");
$stmt->execute([$companyId]);
$monthlyByCategory = $stmt->fetchAll();
$monthTotal = array_sum(array_column($monthlyByCategory, 'total'));

$pageTitle = 'Expenses';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="breadcrumbs"><a href="dashboard.php">Dashboard</a> / Expenses</div>
<div class="page-header">
    <div><h1 class="page-title">Expenses</h1><p class="page-subtitle">Track repairs, utilities, salaries, and other costs.</p></div>
    <?php if ($action === 'list'): ?>
        <a href="?action=add" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add Expense</a>
    <?php else: ?>
        <a href="expenses.php" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> Back</a>
    <?php endif; ?>
</div>

<?php if ($action === 'add'): ?>
    <div class="card" style="max-width:640px;">
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><div><?php foreach ($errors as $err) echo e($err) . '<br>'; ?></div></div>
        <?php endif; ?>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="save">
            <div class="form-group">
                <label class="form-label">Property (optional)</label>
                <select name="property_id" class="form-control">
                    <option value="">General / Company-wide</option>
                    <?php foreach ($allProperties as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group d-flex gap-12">
                <div style="flex:1"><label class="form-label">Category</label>
                    <select name="category" class="form-control">
                        <?php foreach (['repairs','utilities','salaries','cleaning','security','other'] as $c): ?>
                            <option value="<?= $c ?>"><?= ucfirst($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="flex:1"><label class="form-label">Amount</label><input type="number" step="0.01" name="amount" class="form-control" required></div>
            </div>
            <div class="form-group">
                <label class="form-label">Date</label>
                <input type="date" name="expense_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-control" rows="3"></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Save Expense</button>
        </form>
    </div>
<?php else: ?>
    <div class="stat-grid">
        <div class="card stat-card"><div class="stat-icon"><i class="fa-solid fa-receipt"></i></div><div class="stat-value">$<?= money($monthTotal) ?></div><div class="stat-label">This Month's Total</div></div>
        <?php foreach ($monthlyByCategory as $c): ?>
        <div class="card stat-card"><div class="stat-value">$<?= money($c['total']) ?></div><div class="stat-label"><?= ucfirst($c['category']) ?></div></div>
        <?php endforeach; ?>
    </div>
    <div class="card">
        <?php if (empty($expenses)): ?>
            <div class="empty-state"><i class="fa-solid fa-receipt"></i><h3>No expenses recorded yet</h3></div>
        <?php else: ?>
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th>Date</th><th>Category</th><th>Property</th><th>Description</th><th>Amount</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($expenses as $ex): ?>
                <tr>
                    <td><?= format_date($ex['expense_date']) ?></td>
                    <td><span class="badge badge-neutral"><?= ucfirst($ex['category']) ?></span></td>
                    <td><?= e($ex['property_name'] ?? 'General') ?></td>
                    <td><?= e($ex['description'] ?: '—') ?></td>
                    <td>$<?= money($ex['amount']) ?></td>
                    <td>
                        <form method="POST" onsubmit="event.preventDefault(); confirmDelete(this);">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form_action" value="delete">
                            <input type="hidden" name="id" value="<?= $ex['id'] ?>">
                            <button type="submit" class="btn btn-outline btn-sm" style="color:var(--color-danger);"><i class="fa-solid fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
