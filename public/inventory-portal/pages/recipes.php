<?php
require_once __DIR__ . '/../app/recipes-service.php';
$restaurantId = (int)$context['restaurant_id'];
$units = inventory_unit_list($db, $restaurantId);
$materialsQuery = $db->prepare('SELECT m.id, m.name, m.sku, m.base_unit_id, COALESCE(b.average_cost, 0) AS average_cost, (b.material_id IS NOT NULL) AS cost_known FROM inventory_materials m LEFT JOIN inventory_stock_balances b ON b.material_id = m.id AND b.restaurant_id = m.restaurant_id WHERE m.restaurant_id = ? AND m.is_active = 1 ORDER BY m.name');
$materialsQuery->execute([$restaurantId]);
$materials = $materialsQuery->fetchAll();
$menuQuery = $db->prepare('SELECT id, name, price, is_portioned, half_price, full_price, is_active FROM menu_items WHERE restaurant_id = ? AND is_active = 1 ORDER BY name');
$menuQuery->execute([$restaurantId]);
$menuMappings = [];
foreach ($menuQuery->fetchAll() as $menuItem) {
    foreach (inventory_menu_mappings($db, $restaurantId, $menuItem) as $mapping) {
        $menuMappings[] = ['item' => $menuItem, 'mapping' => $mapping];
    }
}
$historyQuery = $db->prepare(
    'SELECT r.menu_item_id, r.mapping_label, r.version, r.effective_from, r.effective_to,
            r.yield_quantity, r.yield_unit_label, mi.name AS menu_name
     FROM inventory_recipes r
     LEFT JOIN menu_items mi ON mi.id = r.menu_item_id AND mi.restaurant_id = r.restaurant_id
     WHERE r.restaurant_id = ? ORDER BY r.menu_item_id, r.mapping_key, r.version DESC'
);
$historyQuery->execute([$restaurantId]);
$history = $historyQuery->fetchAll();
$defaultEffective = (new DateTimeImmutable('+1 minute', new DateTimeZone('Asia/Colombo')))->format('Y-m-d\\TH:i');
?>
<div class="page-heading"><div><p class="eyebrow">MENU COST MODEL</p><h2>Recipes</h2><p>Map each menu item and option to ingredients. A change creates a new effective recipe version.</p></div></div>
<section class="panel-card form-panel">
    <div class="panel-title"><div><p class="eyebrow">NEW VERSION</p><h3>Build a recipe</h3></div><span class="metric-icon">≋</span></div>
    <?php if (!inventory_role_can((string)$context['portal_role'], 'operate')): ?>
        <p class="empty-state">Your role can view recipe history but cannot change recipes.</p>
    <?php elseif (!$menuMappings || !$materials || !$units): ?>
        <p class="empty-state">Add active menu items, raw materials, and units before building a recipe.</p>
    <?php else: ?>
    <form method="post" action="<?= inv_e(inv_route('recipes')) ?>" class="recipe-form">
        <input type="hidden" name="action" value="save-recipe"><input type="hidden" name="return_page" value="recipes"><input type="hidden" name="csrf" value="<?= inv_e($csrf) ?>">
        <div class="form-grid">
            <label class="span-all">Menu item and size / option<select name="menu_mapping" data-menu-mapping required><option value="">Select menu mapping</option><?php foreach ($menuMappings as $entry): $mi=$entry['item']; $map=$entry['mapping']; ?><option value="<?= (int)$mi['id'] ?>|<?= inv_e($map['key']) ?>" data-selling-price="<?= inv_e($map['price']) ?>"><?= inv_e($mi['name'].' · '.$map['label'].' · '.inv_money($map['price'])) ?></option><?php endforeach; ?></select></label>
            <label>Recipe yield quantity<input name="yield_quantity" type="number" min="0.000001" step="0.000001" value="1" required></label>
            <label>Yield unit label<input name="yield_unit_label" maxlength="40" value="servings" required></label>
            <label>Effective from (restaurant time)<input name="effective_from" type="datetime-local" value="<?= inv_e($defaultEffective) ?>" required></label>
            <label>Notes<input name="notes" maxlength="1000" placeholder="Optional preparation notes"></label>
        </div>
        <div class="ingredient-builder"><div class="panel-title"><div><p class="eyebrow">INGREDIENTS</p><h3>Recipe lines</h3></div><button class="button button-small button-quiet" type="button" data-add-ingredient>Add ingredient</button></div>
            <div class="ingredient-list" data-ingredient-list><div class="ingredient-row" data-ingredient-row>
                <label>Material<select name="material_id[]" data-recipe-material required><option value="">Select material</option><?php foreach ($materials as $material): ?><option value="<?= (int)$material['id'] ?>" data-base-unit="<?= (int)$material['base_unit_id'] ?>" data-average-cost="<?= inv_e($material['average_cost']) ?>" data-cost-known="<?= (int)$material['cost_known'] ?>"><?= inv_e($material['name'].($material['sku'] ? ' · '.$material['sku'] : '')) ?></option><?php endforeach; ?></select></label>
                <label>Quantity<input name="quantity[]" type="number" min="0.000001" step="0.000001" required></label>
                <label>Unit<select name="unit_id[]" data-recipe-unit required><option value="">Select unit</option><?php foreach ($units as $unit): $baseId=(int)($unit['is_base'] ? $unit['id'] : $unit['base_unit_id']); $factor=(float)($unit['is_base'] ? 1 : $unit['conversion_to_base']); ?><option value="<?= (int)$unit['id'] ?>" data-base-unit="<?= $baseId ?>" data-factor="<?= inv_e($factor) ?>"><?= inv_e($unit['name'].' ('.$unit['symbol'].')') ?></option><?php endforeach; ?></select></label>
                <button class="button button-small button-danger" type="button" data-remove-ingredient aria-label="Remove ingredient">Remove</button>
            </div></div>
        </div>
        <div class="recipe-preview" data-recipe-preview aria-live="polite">Estimated ingredient cost: <strong data-preview-cost>—</strong><span data-preview-profit>Choose a menu mapping and enter ingredient quantities to preview current food cost.</span></div>
        <button class="button button-primary" type="submit">Save Recipe Version</button>
    </form>
    <?php endif; ?>
</section>
<section class="panel-card data-panel"><div class="panel-title panel-title-wrap"><div><p class="eyebrow">VERSION HISTORY</p><h3>Saved recipes</h3></div><label class="search-field">Search<input type="search" placeholder="Menu item or option" data-table-search="recipe-history-table"></label></div>
    <?php if (!$history): ?><p class="empty-state">No recipes have been configured yet.</p><?php else: ?><div class="table-scroll"><table class="data-table" id="recipe-history-table"><thead><tr><th>Menu item</th><th>Mapping</th><th>Version</th><th>Effective from (UTC)</th><th>Effective to (UTC)</th><th>Yield</th></tr></thead><tbody><?php foreach ($history as $recipe): ?><tr data-search-row="<?= inv_e(mb_strtolower(($recipe['menu_name'] ?? 'deleted menu item').' '.$recipe['mapping_label'])) ?>"><td><?= inv_e($recipe['menu_name'] ?? ('Deleted menu item #'.$recipe['menu_item_id'])) ?></td><td><?= inv_e($recipe['mapping_label']) ?></td><td>v<?= (int)$recipe['version'] ?></td><td><?= inv_e($recipe['effective_from']) ?></td><td><?= inv_e($recipe['effective_to'] ?? 'Current') ?></td><td><?= inv_e(inv_decimal($recipe['yield_quantity'], 4).' '.$recipe['yield_unit_label']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>
