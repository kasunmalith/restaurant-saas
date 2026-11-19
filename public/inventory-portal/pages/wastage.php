<?php
require_once __DIR__ . '/../app/kot-service.php';
$restaurantId = (int)$context['restaurant_id'];
$units = inventory_unit_list($db, $restaurantId);
$materialsQuery = $db->prepare(
    'SELECT m.id, m.name, m.base_unit_id, u.symbol AS base_symbol, COALESCE(b.quantity_base, 0) AS quantity_base
     FROM inventory_materials m JOIN inventory_units u ON u.id = m.base_unit_id AND u.restaurant_id = m.restaurant_id
     LEFT JOIN inventory_stock_balances b ON b.material_id = m.id AND b.restaurant_id = m.restaurant_id
     WHERE m.restaurant_id = ? AND m.is_active = 1 ORDER BY m.name'
);
$materialsQuery->execute([$restaurantId]);
$materials = $materialsQuery->fetchAll();
$pendingQuery = $db->prepare(
    'SELECT r.id, r.cancel_kot_item_id, r.order_id, r.cancelled_quantity, r.unmatched_quantity,
            r.created_at, e.item_name, e.portion_label,
            COALESCE((SELECT SUM(a.portion_quantity) FROM inventory_kot_review_allocations a WHERE a.restaurant_id = r.restaurant_id AND a.review_id = r.id), 0) AS allocated_quantity
     FROM inventory_kot_cancellation_reviews r
     JOIN inventory_kot_events e ON e.restaurant_id = r.restaurant_id AND e.kot_item_id = r.cancel_kot_item_id
     WHERE r.restaurant_id = ? AND r.status = ? ORDER BY r.created_at'
);
$pendingQuery->execute([$restaurantId, 'PENDING']);
$pending = $pendingQuery->fetchAll();
$addedReviewQuery = $db->prepare(
    'SELECT id, kot_item_id, order_id, menu_item_id, item_name, portion_label, quantity, event_at, review_reason
     FROM inventory_kot_events
     WHERE restaurant_id = ? AND event_action = ? AND processing_status = ?
     ORDER BY event_at DESC, id DESC LIMIT 100'
);
$addedReviewQuery->execute([$restaurantId, 'ADDED', 'REVIEW']);
$addedReviews = $addedReviewQuery->fetchAll();
$historyQuery = $db->prepare(
    'SELECT w.id, w.record_type, w.reason_code, w.notes, w.total_cost, w.unmatched_quantity, w.created_at,
            COUNT(i.id) AS line_count
     FROM inventory_wastage w LEFT JOIN inventory_wastage_items i ON i.wastage_id = w.id AND i.restaurant_id = w.restaurant_id
     WHERE w.restaurant_id = ? GROUP BY w.id ORDER BY w.created_at DESC LIMIT 50'
);
$historyQuery->execute([$restaurantId]);
$wastageHistory = $historyQuery->fetchAll();
$defaultKey = bin2hex(random_bytes(32));
?>
<div class="page-heading"><div><p class="eyebrow">STOCK LOSS CONTROL</p><h2>Wastage &amp; KOT Review</h2><p>Manual waste posts a stock deduction. KOT cancellations stay pending until staff choose whether prepared items were wasted or unused ingredients can be returned.</p></div></div>
<?php if (inventory_role_can((string)$context['portal_role'], 'operate')): ?>
<section class="panel-card form-panel"><div class="panel-title"><div><p class="eyebrow">MANUAL STOCK LOSS</p><h3>Record wastage</h3></div><span class="metric-icon">↘</span></div>
    <?php if (!$materials || !$units): ?><p class="empty-state">Add active materials and units before recording wastage.</p><?php else: ?><form method="post" action="<?= inv_e(inv_route('wastage')) ?>" class="form-grid wastage-form">
        <input type="hidden" name="action" value="record-wastage"><input type="hidden" name="return_page" value="wastage"><input type="hidden" name="csrf" value="<?= inv_e($csrf) ?>"><input type="hidden" name="idempotency_key" value="<?= inv_e($defaultKey) ?>">
        <label>Material<select name="material_id" data-wastage-material required><option value="">Select material</option><?php foreach ($materials as $material): ?><option value="<?= (int)$material['id'] ?>" data-base-unit="<?= (int)$material['base_unit_id'] ?>"><?= inv_e($material['name'].' · on hand '.inv_decimal($material['quantity_base'], 4).' '.$material['base_symbol']) ?></option><?php endforeach; ?></select></label>
        <label>Unit<select name="unit_id" data-wastage-unit required><option value="">Select unit</option><?php foreach ($units as $unit): $baseId=(int)($unit['is_base'] ? $unit['id'] : $unit['base_unit_id']); ?><option value="<?= (int)$unit['id'] ?>" data-base-unit="<?= $baseId ?>"><?= inv_e($unit['name'].' ('.$unit['symbol'].')') ?></option><?php endforeach; ?></select></label>
        <label>Quantity<input type="number" name="quantity" min="0.000001" step="0.000001" required></label>
        <label>Reason<select name="reason_code" required><option value="">Select reason</option><option value="PREPARATION_WASTE">Preparation Waste</option><option value="EXPIRED">Expired</option><option value="DAMAGED">Damaged</option><option value="SPOILED">Spoiled</option><option value="OTHER">Other</option></select></label>
        <label class="span-all">Notes<input name="notes" maxlength="1000" placeholder="Optional details"></label><button class="button button-primary" type="submit">Record Wastage</button>
    </form><?php endif; ?>
</section>
<?php endif; ?>
<section class="panel-card data-panel"><div class="panel-title"><div><p class="eyebrow">KITCHEN RECONCILIATION</p><h3>Pending KOT cancellations</h3></div></div>
    <?php if (!$pending): ?><p class="empty-state">No KOT cancellations are awaiting review.</p><?php else: ?><div class="table-scroll"><table class="data-table"><thead><tr><th>Order / KOT line</th><th>Cancelled</th><th>Tracked preparation</th><th>Unmatched</th><th>Decision</th></tr></thead><tbody>
    <?php foreach ($pending as $review): ?><tr><td><strong><?= inv_e($review['item_name']) ?></strong><small class="table-subtext">Order #<?= (int)$review['order_id'] ?> · KOT item #<?= (int)$review['cancel_kot_item_id'] ?><?= $review['portion_label'] ? ' · '.inv_e($review['portion_label']) : '' ?></small></td><td><?= inv_e(inv_decimal($review['cancelled_quantity'], 2)) ?></td><td><?= inv_e(inv_decimal($review['allocated_quantity'], 2)) ?></td><td><?= inv_e(inv_decimal($review['unmatched_quantity'], 2)) ?></td><td><?php if (inventory_role_can((string)$context['portal_role'], 'operate')): ?><form method="post" action="<?= inv_e(inv_route('wastage')) ?>" class="review-form"><input type="hidden" name="action" value="resolve-kot-cancellation"><input type="hidden" name="return_page" value="wastage"><input type="hidden" name="csrf" value="<?= inv_e($csrf) ?>"><input type="hidden" name="review_id" value="<?= (int)$review['id'] ?>"><select name="resolution" aria-label="Cancellation resolution"><?php if ((float)$review['unmatched_quantity'] <= 0.000001 && (float)$review['allocated_quantity'] > 0): ?><option value="RETURN_TO_STOCK">Return tracked ingredients to stock</option><?php endif; ?><option value="WASTAGE">Record as preparation wastage</option></select><input name="resolution_reason" maxlength="1000" placeholder="Reason for decision" required><button class="button button-small" type="submit">Resolve</button></form><?php else: ?><span class="status-pill">Awaiting staff review</span><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
</section>
<section class="panel-card data-panel"><div class="panel-title"><div><p class="eyebrow">KOT STOCK DEDUCTION</p><h3>Items needing mapping or recipe review</h3></div><span class="status-pill"><?= count($addedReviews) ?> pending</span></div>
    <?php if (!$addedReviews): ?><p class="empty-state">No KOT additions are waiting for mapping or recipe setup.</p><?php else: ?><div class="table-scroll"><table class="data-table"><thead><tr><th>Order / item</th><th>Quantity</th><th>Event time (UTC)</th><th>Review reason</th><th>Next action</th></tr></thead><tbody>
    <?php foreach ($addedReviews as $review): ?><?php $reviewMenuItem = !empty($review['menu_item_id']) ? inventory_menu_item($db, $restaurantId, (int)$review['menu_item_id']) : null; $reviewMappings = $reviewMenuItem ? inventory_menu_mappings($db, $restaurantId, $reviewMenuItem) : []; ?>
        <tr><td><strong><?= inv_e($review['item_name']) ?></strong><small class="table-subtext">Order #<?= (int)$review['order_id'] ?> · KOT item #<?= (int)$review['kot_item_id'] ?><?= $review['portion_label'] !== null && $review['portion_label'] !== '' ? ' · '.inv_e($review['portion_label']) : '' ?></small></td><td><?= inv_e(inv_decimal($review['quantity'], 2)) ?></td><td><?= inv_e($review['event_at']) ?></td><td><?= inv_e($review['review_reason'] ?? 'Review required') ?></td><td>
        <?php if (inventory_role_can((string)$context['portal_role'], 'operate')): ?>
            <?php if (!empty($review['menu_item_id']) && trim((string)($review['portion_label'] ?? '')) !== '' && $reviewMappings): ?>
                <form method="post" action="<?= inv_e(inv_route('wastage')) ?>" class="review-form"><input type="hidden" name="action" value="save-kot-mapping-override"><input type="hidden" name="return_page" value="wastage"><input type="hidden" name="csrf" value="<?= inv_e($csrf) ?>"><input type="hidden" name="event_id" value="<?= (int)$review['id'] ?>"><select name="mapping_key" aria-label="Map saved KOT option to a menu size" required><?php foreach ($reviewMappings as $mapping): ?><option value="<?= inv_e($mapping['key']) ?>"><?= inv_e($mapping['label'].' · '.number_format((float)$mapping['price'], 2)) ?></option><?php endforeach; ?></select><button class="button button-small" type="submit">Save mapping &amp; retry</button></form>
            <?php endif; ?>
            <form method="post" action="<?= inv_e(inv_route('wastage')) ?>" class="review-form review-retry-form"><input type="hidden" name="action" value="retry-kot-event"><input type="hidden" name="return_page" value="wastage"><input type="hidden" name="csrf" value="<?= inv_e($csrf) ?>"><input type="hidden" name="event_id" value="<?= (int)$review['id'] ?>"><button class="button button-small button-secondary" type="submit">Retry after setup</button></form>
        <?php else: ?><span class="status-pill">Staff action required</span><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
</section>
<section class="panel-card data-panel"><div class="panel-title"><div><p class="eyebrow">POSTED HISTORY</p><h3>Recent wastage records</h3></div></div>
    <?php if (!$wastageHistory): ?><p class="empty-state">No wastage records have been posted.</p><?php else: ?><div class="table-scroll"><table class="data-table"><thead><tr><th>Date (UTC)</th><th>Type / reason</th><th>Notes</th><th>Lines</th><th>Recorded cost</th><th>Unmatched KOT quantity</th></tr></thead><tbody><?php foreach ($wastageHistory as $entry): ?><tr><td><?= inv_e($entry['created_at']) ?></td><td><?= inv_e(str_replace('_', ' ', $entry['record_type'].' · '.$entry['reason_code'])) ?></td><td><?= inv_e($entry['notes'] ?? '—') ?></td><td><?= (int)$entry['line_count'] ?></td><td><?= inv_e(inv_money($entry['total_cost'])) ?></td><td><?= inv_e(inv_decimal($entry['unmatched_quantity'], 2)) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>
