<?php
require_once __DIR__ . '/../app/recipes-service.php';
$restaurantId = (int)$context['restaurant_id'];
$menuQuery = $db->prepare('SELECT id, name, price, is_portioned, half_price, full_price, is_active FROM menu_items WHERE restaurant_id = ? AND is_active = 1 ORDER BY name');
$menuQuery->execute([$restaurantId]);
$rows = [];
foreach ($menuQuery->fetchAll() as $menuItem) {
    foreach (inventory_menu_mappings($db, $restaurantId, $menuItem) as $mapping) {
        $recipe = inventory_current_recipe($db, $restaurantId, (int)$menuItem['id'], (string)$mapping['key']);
        $cost = $recipe ? inventory_recipe_cost($db, $restaurantId, (int)$recipe['id']) : null;
        $metrics = $cost && $cost['complete'] && $cost['cost_per_yield'] !== null
            ? inventory_profit_metrics((float)$mapping['price'], (float)$cost['cost_per_yield'])
            : ['gross_profit' => null, 'gross_margin_percent' => null];
        $rows[] = ['item' => $menuItem, 'mapping' => $mapping, 'recipe' => $recipe, 'cost' => $cost, 'metrics' => $metrics];
    }
}
?>
<div class="page-heading"><div><p class="eyebrow">ESTIMATED MENU ECONOMICS</p><h2>Food Costing</h2><p>Ingredient cost uses current weighted-average material costs. Gross profit here excludes labor and operating expenses; it is not net profit.</p></div></div>
<div class="notice notice-info" role="note">Costs are estimates based on the current recipe and current stock weighted-average costs. A missing recipe or unknown ingredient cost is shown as incomplete, not as zero.</div>
<section class="panel-card data-panel"><div class="panel-title panel-title-wrap"><div><p class="eyebrow">ITEM AND OPTION LEVEL</p><h3>Food cost, gross profit and margin</h3></div><label class="search-field">Search<input type="search" placeholder="Menu item or option" data-table-search="food-costing-table"></label></div>
    <?php if (!$rows): ?><p class="empty-state">No active menu items are available for costing.</p><?php else: ?><div class="table-scroll"><table class="data-table" id="food-costing-table"><thead><tr><th>Menu item</th><th>Option / portion</th><th>Selling price</th><th>Ingredient cost / yield</th><th>Gross profit</th><th>Gross margin</th><th>Recipe</th></tr></thead><tbody>
        <?php foreach ($rows as $row): $item=$row['item']; $mapping=$row['mapping']; $recipe=$row['recipe']; $cost=$row['cost']; $complete=(bool)($cost['complete'] ?? false); ?><tr data-search-row="<?= inv_e(mb_strtolower($item['name'].' '.$mapping['label'])) ?>"><td><?= inv_e($item['name']) ?></td><td><?= inv_e($mapping['label']) ?></td><td><?= inv_e(inv_money($mapping['price'])) ?></td><td><?= $complete ? inv_e(inv_money($cost['cost_per_yield'])) : '<span class="status-pill status-off">Incomplete</span>' ?></td><td><?= $complete ? inv_e(inv_money($row['metrics']['gross_profit'])) : '—' ?></td><td><?= $complete && $row['metrics']['gross_margin_percent'] !== null ? inv_e(number_format($row['metrics']['gross_margin_percent'], 2).'%') : '—' ?></td><td><?= $recipe ? 'v'.(int)$recipe['version'] : '<span class="status-pill status-off">Recipe needed</span>' ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
</section>
