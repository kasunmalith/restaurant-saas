<?php
require_once __DIR__ . '/../app/inventory-service.php';
require_once __DIR__ . '/../app/report-service.php';
require_once __DIR__ . '/../app/kot-service.php';
$restaurantId = (int)$context['restaurant_id'];
$stockRows = inventory_stock_snapshot($db, $restaurantId);
$activeMaterials = count(array_filter($stockRows, static fn(array $row): bool => (int)$row['is_active'] === 1));
$stockValue = array_sum(array_map(static fn(array $row): float => (float)$row['inventory_value'], $stockRows));
$lowStockCount = count(array_filter($stockRows, static fn(array $row): bool => (int)$row['is_active'] === 1 && (float)$row['minimum_quantity'] > 0 && (float)$row['quantity_base'] <= (float)$row['minimum_quantity']));
$month = inventory_report_period(['period' => 'month']);
$report = inventory_profit_report($db, $restaurantId, $month['from_utc'], $month['to_utc']);
$workerState = inventory_kot_worker_state($db);
$cards = [
    ['Raw Materials', number_format($activeMaterials), '◈'],
    ['Current Stock Value', inv_money($stockValue), '▦'],
    ['Purchases This Month', inv_money(array_sum(array_map(static fn(array $row): float => (float)$row['amount'], $report['purchase_rows']))), '▤'],
    ['Tracked Ingredient Cost', $workerState ? inv_money($report['tracked_consumption']) : 'Not available yet', '◷'],
    ['Gross Profit', $report['gross_profit'] === null ? 'Incomplete' : inv_money($report['gross_profit']), '↗'],
    ['Wastage Cost', inv_money($report['wastage_total']), '↘'],
    ['Low Stock Items', number_format($lowStockCount), '!'],
];
?>
<section class="welcome-panel">
    <div><p class="eyebrow">CONTROL CENTRE</p><h2>Inventory overview</h2><p>Track materials, purchasing, recipes, and gross profit in one tenant-isolated workspace. Values shown here are calculated from this restaurant's inventory records.</p></div>
    <span class="welcome-illustration" aria-hidden="true">▥</span>
</section>
<div class="metric-grid">
    <?php foreach ($cards as [$label, $value, $icon]): ?>
        <article class="metric-card"><span class="metric-icon" aria-hidden="true"><?= inv_e($icon) ?></span><p><?= inv_e($label) ?></p><strong><?= inv_e($value) ?></strong></article>
    <?php endforeach; ?>
</div>
<section class="panel-card phase-note"><div><p class="eyebrow">INVENTORY OPERATIONS</p><h2>Period: <?= inv_e($month['label']) ?></h2><p>Gross profit uses fully paid POS invoices and tracked KOT ingredient movements. <?= $report['incomplete_invoice_count'] > 0 ? inv_e($report['incomplete_invoice_count'].' paid invoice(s) have missing, pending, or unresolved KOT/recipe data; gross profit is withheld until reconciled.') : 'Service charges, tax, delivery, packaging, tips, salaries, rent, and other operating expenses are excluded from food revenue or net profit.' ?></p></div><a class="button button-primary" href="<?= inv_e(inv_route('profit-reports')) ?>">View Reports</a></section>
