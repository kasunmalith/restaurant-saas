<?php
require_once __DIR__ . '/../app/inventory-service.php';
$restaurantId = (int)$context['restaurant_id'];
$suppliersQuery = $db->prepare('SELECT id, name FROM inventory_suppliers WHERE restaurant_id = ? AND is_active = 1 ORDER BY name');
$suppliersQuery->execute([$restaurantId]);
$suppliers = $suppliersQuery->fetchAll();
$materialsQuery = $db->prepare('SELECT m.id, m.name, m.base_unit_id, u.symbol AS base_symbol FROM inventory_materials m JOIN inventory_units u ON u.id = m.base_unit_id AND u.restaurant_id = m.restaurant_id WHERE m.restaurant_id = ? AND m.is_active = 1 ORDER BY m.name');
$materialsQuery->execute([$restaurantId]);
$materials = $materialsQuery->fetchAll();
$units = inventory_unit_list($db, $restaurantId);
$purchaseId = (int)($_GET['id'] ?? ($_SESSION['purchase_focus'] ?? 0));
unset($_SESSION['purchase_focus']);
$purchase = null;
$purchaseLines = [];
if ($purchaseId > 0) {
    $q = $db->prepare('SELECT p.*, s.name AS supplier_name FROM inventory_purchases p LEFT JOIN inventory_suppliers s ON s.id = p.supplier_id AND s.restaurant_id = p.restaurant_id WHERE p.id = ? AND p.restaurant_id = ?');
    $q->execute([$purchaseId, $restaurantId]);
    $purchase = $q->fetch() ?: null;
    if ($purchase) {
        $q = $db->prepare('SELECT l.*, m.name AS material_name, u.symbol AS unit_symbol FROM inventory_purchase_items l JOIN inventory_materials m ON m.id = l.material_id AND m.restaurant_id = l.restaurant_id JOIN inventory_units u ON u.id = l.unit_id AND u.restaurant_id = l.restaurant_id WHERE l.purchase_id = ? AND l.restaurant_id = ? ORDER BY l.id');
        $q->execute([$purchaseId, $restaurantId]);
        $purchaseLines = $q->fetchAll();
    }
}
$list = $db->prepare('SELECT p.id, p.reference_no, p.purchase_date, p.status, s.name AS supplier_name, COALESCE(SUM(l.line_total),0) AS total FROM inventory_purchases p LEFT JOIN inventory_suppliers s ON s.id = p.supplier_id AND s.restaurant_id = p.restaurant_id LEFT JOIN inventory_purchase_items l ON l.purchase_id = p.id AND l.restaurant_id = p.restaurant_id WHERE p.restaurant_id = ? GROUP BY p.id ORDER BY p.id DESC LIMIT 100');
$list->execute([$restaurantId]);
$purchases = $list->fetchAll();
?>
<div class="page-heading"><div><p class="eyebrow">PROCUREMENT</p><h2>Purchases</h2><p>Drafts do not affect stock. Stock and weighted-average cost change only when a purchase is received.</p></div></div>
<div class="two-column-grid">
    <section class="panel-card form-panel"><div class="panel-title"><div><p class="eyebrow">PURCHASE ENTRY</p><h3>Create draft</h3></div><span class="metric-icon">▤</span></div>
        <form method="post" action="<?= inv_e(inv_route('purchases')) ?>" class="form-grid">
            <input type="hidden" name="action" value="create-purchase"><input type="hidden" name="return_page" value="purchases"><input type="hidden" name="csrf" value="<?= inv_e($csrf) ?>">
            <label>Supplier<select name="supplier_id"><option value="0">Choose later / no supplier</option><?php foreach ($suppliers as $supplier): ?><option value="<?= (int)$supplier['id'] ?>"><?= inv_e($supplier['name']) ?></option><?php endforeach; ?></select></label>
            <label>Reference number<input name="reference_no" maxlength="120" placeholder="Invoice or receipt number"></label>
            <label>Purchase date<input name="purchase_date" type="date" value="<?= inv_e((new DateTimeImmutable('today', new DateTimeZone('Asia/Colombo')))->format('Y-m-d')) ?>" required></label>
            <label>Notes<input name="notes" maxlength="1000"></label><button class="button button-primary" type="submit">Create Draft</button>
        </form>
    </section>
    <section class="panel-card form-panel">
        <div class="panel-title"><div><p class="eyebrow">STOCK CONTROL</p><h3>Posting rule</h3></div><span class="metric-icon">✓</span></div>
        <p class="muted">A purchase is posted once, as append-only PURCHASE movements. Received purchases cannot be edited or deleted; corrections require explicit later stock movements.</p>
    </section>
</div>
<?php if ($purchase): ?>
<section class="panel-card data-panel selected-purchase">
    <div class="panel-title panel-title-wrap"><div><p class="eyebrow">PURCHASE #<?= (int)$purchase['id'] ?></p><h3><?= inv_e($purchase['reference_no'] ?: 'Unreferenced purchase') ?></h3><p class="muted"><?= inv_e($purchase['supplier_name'] ?: 'No supplier') ?> · <?= inv_e($purchase['purchase_date']) ?> · <?= inv_e($purchase['status']) ?></p></div><?php if ($purchase['status'] === 'DRAFT'): ?><div class="purchase-header-actions"><form method="post" action="<?= inv_e(inv_route('purchases')) ?>"><input type="hidden" name="action" value="receive-purchase"><input type="hidden" name="return_page" value="purchases"><input type="hidden" name="purchase_id" value="<?= (int)$purchase['id'] ?>"><input type="hidden" name="csrf" value="<?= inv_e($csrf) ?>"><button class="button button-primary" type="submit" <?= !$purchaseLines ? 'disabled' : '' ?>>Receive &amp; post stock</button></form><form method="post" action="<?= inv_e(inv_route('purchases')) ?>"><input type="hidden" name="action" value="cancel-purchase"><input type="hidden" name="return_page" value="purchases"><input type="hidden" name="purchase_id" value="<?= (int)$purchase['id'] ?>"><input type="hidden" name="csrf" value="<?= inv_e($csrf) ?>"><button class="button button-small button-danger" type="submit">Cancel draft</button></form></div><?php endif; ?></div>
    <?php if ($purchase['status'] === 'DRAFT'): ?><form method="post" action="<?= inv_e(inv_route('purchases')) ?>" class="form-grid purchase-line-form">
        <input type="hidden" name="action" value="add-purchase-item"><input type="hidden" name="return_page" value="purchases"><input type="hidden" name="purchase_id" value="<?= (int)$purchase['id'] ?>"><input type="hidden" name="csrf" value="<?= inv_e($csrf) ?>">
        <label>Material<select name="material_id" required><option value="">Select material</option><?php foreach ($materials as $material): ?><option value="<?= (int)$material['id'] ?>"><?= inv_e($material['name'] . ' (' . $material['base_symbol'] . ' base)') ?></option><?php endforeach; ?></select></label>
        <label>Purchase unit<select name="unit_id" required><option value="">Select unit</option><?php foreach ($units as $unit): ?><option value="<?= (int)$unit['id'] ?>"><?= inv_e($unit['name'] . ' (' . $unit['symbol'] . ') · ' . $unit['dimension']) ?></option><?php endforeach; ?></select></label>
        <label>Quantity<input name="quantity" type="number" min="0.000001" step="0.000001" required></label><label>Cost per purchase unit (LKR)<input name="unit_cost" type="number" min="0" step="0.01" required></label>
        <button class="button button-primary" type="submit">Add line</button>
    </form><?php endif; ?>
    <?php if (!$purchaseLines): ?><p class="empty-state">No lines have been added to this draft.</p><?php else: ?><div class="table-scroll"><table class="data-table"><thead><tr><th>Material</th><th>Quantity</th><th>Base quantity</th><th>Unit cost</th><th>Line total</th><?php if ($purchase['status'] === 'DRAFT'): ?><th></th><?php endif; ?></tr></thead><tbody><?php foreach ($purchaseLines as $line): ?><tr><td><?= inv_e($line['material_name']) ?></td><td><?= inv_e(inv_decimal($line['quantity'], 4) . ' ' . $line['unit_symbol']) ?></td><td><?= inv_e(inv_decimal($line['quantity_base'], 4)) ?></td><td><?= inv_e(inv_money($line['unit_cost'])) ?></td><td><?= inv_e(inv_money($line['line_total'])) ?></td><?php if ($purchase['status'] === 'DRAFT'): ?><td><form method="post" action="<?= inv_e(inv_route('purchases')) ?>"><input type="hidden" name="action" value="remove-purchase-item"><input type="hidden" name="return_page" value="purchases"><input type="hidden" name="purchase_id" value="<?= (int)$purchase['id'] ?>"><input type="hidden" name="line_id" value="<?= (int)$line['id'] ?>"><input type="hidden" name="csrf" value="<?= inv_e($csrf) ?>"><button class="button button-small button-danger" type="submit">Remove</button></form></td><?php endif; ?></tr><?php endforeach; ?></tbody></table></div><p class="purchase-total">Draft total: <strong><?= inv_e(inv_money(array_sum(array_column($purchaseLines, 'line_total')))) ?></strong></p><?php endif; ?>
</section>
<?php elseif ($purchaseId > 0): ?><p class="notice notice-error">Purchase not found for this restaurant.</p><?php endif; ?>
<section class="panel-card data-panel"><div class="panel-title panel-title-wrap"><div><p class="eyebrow">PURCHASE REGISTER</p><h3>Recent purchases</h3></div><label class="search-field">Search<input type="search" placeholder="Reference or supplier" data-table-search="purchases-table"></label></div>
<?php if (!$purchases): ?><p class="empty-state">No purchases have been entered yet.</p><?php else: ?><div class="table-scroll"><table class="data-table" id="purchases-table"><thead><tr><th>Date</th><th>Reference</th><th>Supplier</th><th>Total</th><th>Status</th><th></th></tr></thead><tbody><?php foreach ($purchases as $row): ?><tr data-search-row="<?= inv_e(mb_strtolower(($row['reference_no'] ?? '') . ' ' . ($row['supplier_name'] ?? ''))) ?>"><td><?= inv_e($row['purchase_date']) ?></td><td><?= inv_e($row['reference_no'] ?: '—') ?></td><td><?= inv_e($row['supplier_name'] ?: '—') ?></td><td><?= inv_e(inv_money($row['total'])) ?></td><td><span class="status-pill<?= $row['status'] === 'RECEIVED' ? '' : ' status-off' ?>"><?= inv_e($row['status']) ?></span></td><td><a class="button button-small button-quiet" href="<?= inv_e(inv_route('purchases')) ?>&amp;id=<?= (int)$row['id'] ?>">Open</a></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>

