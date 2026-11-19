<?php
require_once __DIR__ . '/../app/stock-count-service.php';
$restaurantId = (int)$context['restaurant_id'];
$focusId = (int)($_GET['count'] ?? $_SESSION['stock_count_focus'] ?? 0);
unset($_SESSION['stock_count_focus']);
$selectedCount = null;
$countLines = [];
if ($focusId > 0) {
    $countQuery = $db->prepare('SELECT * FROM inventory_stock_counts WHERE id = ? AND restaurant_id = ?');
    $countQuery->execute([$focusId, $restaurantId]);
    $selectedCount = $countQuery->fetch() ?: null;
    if ($selectedCount) {
        $linesQuery = $db->prepare(
            'SELECT i.id, i.material_id, i.book_quantity_base, i.physical_quantity_base, i.variance_base,
                    m.name AS material_name, u.symbol AS unit_symbol
             FROM inventory_stock_count_items i
             JOIN inventory_materials m ON m.id = i.material_id AND m.restaurant_id = i.restaurant_id
             JOIN inventory_units u ON u.id = i.unit_id AND u.restaurant_id = i.restaurant_id
             WHERE i.count_id = ? AND i.restaurant_id = ? ORDER BY m.name'
        );
        $linesQuery->execute([$focusId, $restaurantId]);
        $countLines = $linesQuery->fetchAll();
    }
}
$countsQuery = $db->prepare('SELECT id, count_at, status, notes, created_at, posted_at FROM inventory_stock_counts WHERE restaurant_id = ? ORDER BY id DESC LIMIT 50');
$countsQuery->execute([$restaurantId]);
$counts = $countsQuery->fetchAll();
?>
<div class="page-heading"><div><p class="eyebrow">PHYSICAL RECONCILIATION</p><h2>Stock Count</h2><p>Start a draft to capture the book quantity, enter physical counts, review variances, and post explicit adjustment movements.</p></div></div>
<?php if (inventory_role_can((string)$context['portal_role'], 'operate')): ?>
<section class="panel-card form-panel"><div class="panel-title"><div><p class="eyebrow">NEW COUNT SESSION</p><h3>Start a stock count</h3></div><span class="metric-icon">☷</span></div>
    <form method="post" action="<?= inv_e(inv_route('stock-count')) ?>" class="form-grid">
        <input type="hidden" name="action" value="create-stock-count"><input type="hidden" name="return_page" value="stock-count"><input type="hidden" name="csrf" value="<?= inv_e($csrf) ?>">
        <label class="span-all">Notes<input name="notes" maxlength="1000" placeholder="Optional count notes"></label><button class="button button-primary" type="submit">Create Draft Count</button>
    </form>
</section>
<?php endif; ?>
<?php if ($focusId > 0 && !$selectedCount): ?><div class="notice notice-error" role="alert">That stock count does not exist in this restaurant.</div><?php endif; ?>
<?php if ($selectedCount): ?>
<section class="panel-card data-panel"><div class="panel-title"><div><p class="eyebrow">COUNT #<?= (int)$selectedCount['id'] ?> · <?= inv_e($selectedCount['status']) ?></p><h3>Physical quantities</h3><p>Snapshot taken at <?= inv_e($selectedCount['count_at']) ?> UTC. Enter each physical quantity using the displayed base unit.</p></div></div>
    <?php if ($selectedCount['status'] === 'DRAFT' && inventory_role_can((string)$context['portal_role'], 'operate')): ?>
    <form method="post" action="<?= inv_e(inv_route('stock-count')) ?>" class="count-form">
        <input type="hidden" name="action" value="save-stock-count"><input type="hidden" name="return_page" value="stock-count"><input type="hidden" name="count_id" value="<?= (int)$selectedCount['id'] ?>"><input type="hidden" name="csrf" value="<?= inv_e($csrf) ?>">
        <div class="table-scroll"><table class="data-table"><thead><tr><th>Material</th><th>Book quantity</th><th>Physical quantity</th><th>Variance</th></tr></thead><tbody>
            <?php foreach ($countLines as $line): $variance=$line['physical_quantity_base']===null?null:(float)$line['physical_quantity_base']-(float)$line['book_quantity_base']; ?><tr><td><?= inv_e($line['material_name']) ?><small class="table-subtext">Base unit: <?= inv_e($line['unit_symbol']) ?></small><input type="hidden" name="line_id[]" value="<?= (int)$line['id'] ?>"></td><td><?= inv_e(inv_decimal($line['book_quantity_base'], 4).' '.$line['unit_symbol']) ?></td><td><input class="count-quantity" type="number" name="physical_quantity[]" min="0" step="0.000001" value="<?= $line['physical_quantity_base']===null?'':inv_e($line['physical_quantity_base']) ?>" required aria-label="Physical count for <?= inv_e($line['material_name']) ?>"></td><td><?= $variance===null?'Not entered':inv_e(($variance>0?'+':'').inv_decimal($variance,4).' '.$line['unit_symbol']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <button class="button button-primary" type="submit">Save Physical Counts</button>
    </form>
    <form method="post" action="<?= inv_e(inv_route('stock-count')) ?>" class="post-count-form" data-confirm="Post this stock count? This creates permanent stock adjustment entries.">
        <input type="hidden" name="action" value="post-stock-count"><input type="hidden" name="return_page" value="stock-count"><input type="hidden" name="count_id" value="<?= (int)$selectedCount['id'] ?>"><input type="hidden" name="csrf" value="<?= inv_e($csrf) ?>"><button class="button button-secondary" type="submit">Post Stock Count</button><small>Save all physical quantities before posting. A posted count cannot be edited or posted again.</small>
    </form>
    <?php else: ?>
    <?php if (!$countLines): ?><p class="empty-state">No material lines were recorded.</p><?php else: ?><div class="table-scroll"><table class="data-table"><thead><tr><th>Material</th><th>Book quantity</th><th>Physical quantity</th><th>Variance</th></tr></thead><tbody><?php foreach ($countLines as $line): ?><tr><td><?= inv_e($line['material_name']) ?></td><td><?= inv_e(inv_decimal($line['book_quantity_base'],4).' '.$line['unit_symbol']) ?></td><td><?= $line['physical_quantity_base']===null?'Not entered':inv_e(inv_decimal($line['physical_quantity_base'],4).' '.$line['unit_symbol']) ?></td><td><?= $line['variance_base']===null?'—':inv_e(((float)$line['variance_base']>0?'+':'').inv_decimal($line['variance_base'],4).' '.$line['unit_symbol']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    <?php endif; ?>
</section>
<?php endif; ?>
<section class="panel-card data-panel"><div class="panel-title"><div><p class="eyebrow">COUNT HISTORY</p><h3>Recent stock counts</h3></div></div>
    <?php if (!$counts): ?><p class="empty-state">No stock counts have been created.</p><?php else: ?><div class="table-scroll"><table class="data-table"><thead><tr><th>Count</th><th>Count time (UTC)</th><th>Status</th><th>Notes</th><th>Posted at (UTC)</th><th>Details</th></tr></thead><tbody><?php foreach ($counts as $count): ?><tr><td>#<?= (int)$count['id'] ?></td><td><?= inv_e($count['count_at']) ?></td><td><span class="status-pill<?= $count['status']==='DRAFT'?' status-off':'' ?>"><?= inv_e($count['status']) ?></span></td><td><?= inv_e($count['notes'] ?? '—') ?></td><td><?= inv_e($count['posted_at'] ?? '—') ?></td><td><a class="button button-small button-quiet" href="<?= inv_e(inv_route('stock-count').'&count='.(int)$count['id']) ?>">View</a></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>
