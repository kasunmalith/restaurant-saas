<?php
require_once __DIR__ . '/../app/inventory-service.php';
$restaurantId = (int)$context['restaurant_id'];
$allUnits = inventory_unit_list($db, $restaurantId, false);
$units = array_values(array_filter($allUnits, static fn(array $unit): bool => (int)$unit['is_active'] === 1));
$baseUnits = array_values(array_filter($units, static fn(array $unit): bool => (int)$unit['is_base'] === 1));
$materialsQuery = $db->prepare(
    'SELECT m.*, u.name AS base_unit_name, u.symbol AS base_unit_symbol
     FROM inventory_materials m
     INNER JOIN inventory_units u ON u.id = m.base_unit_id AND u.restaurant_id = m.restaurant_id
     WHERE m.restaurant_id = ? ORDER BY m.is_active DESC, m.name'
);
$materialsQuery->execute([$restaurantId]);
$materials = $materialsQuery->fetchAll();
?>
<div class="page-heading"><div><p class="eyebrow">STOCK CATALOG</p><h2>Raw Materials</h2><p>Create restaurant-owned ingredients and define their base measurement units.</p></div></div>
<div class="two-column-grid">
    <section class="panel-card form-panel">
        <div class="panel-title"><div><p class="eyebrow">MASTER DATA</p><h3>Add a unit</h3></div><span class="metric-icon">↔</span></div>
        <form method="post" action="<?= inv_e(inv_route('materials')) ?>" class="form-grid">
            <input type="hidden" name="action" value="create-unit"><input type="hidden" name="return_page" value="materials"><input type="hidden" name="csrf" value="<?= inv_e($csrf) ?>">
            <label>Unit name<input name="name" maxlength="80" placeholder="Kilogram" required></label>
            <label>Symbol / code<input name="symbol" maxlength="24" placeholder="kg" required></label>
            <label>Measurement type<input name="dimension" maxlength="32" placeholder="mass" required></label>
            <label>Unit type<select name="is_base" data-unit-kind><option value="1">Base unit</option><option value="0">Conversion unit</option></select></label>
            <label data-base-unit-field hidden>Base unit<select name="base_unit_id"><option value="">Select base unit</option><?php foreach ($baseUnits as $unit): ?><option value="<?= (int)$unit['id'] ?>"><?= inv_e($unit['name'] . ' (' . $unit['symbol'] . ') · ' . $unit['dimension']) ?></option><?php endforeach; ?></select></label>
            <label data-conversion-field hidden>Multiplier to base unit<input name="conversion_to_base" type="number" min="0.000000001" step="0.000000001" value="1"></label>
            <label>Decimal places<input name="decimal_places" type="number" min="0" max="6" value="3" required></label>
            <button class="button button-primary" type="submit">Save Unit</button>
        </form>
    </section>
    <section class="panel-card form-panel">
        <div class="panel-title"><div><p class="eyebrow">MATERIAL SETUP</p><h3>Add raw material</h3></div><span class="metric-icon">◈</span></div>
        <?php if (!$baseUnits): ?>
            <p class="empty-state">Create a base unit first. For example: kg (mass), L (volume), or pcs (count).</p>
        <?php else: ?>
            <form method="post" action="<?= inv_e(inv_route('materials')) ?>" class="form-grid">
                <input type="hidden" name="action" value="create-material"><input type="hidden" name="return_page" value="materials"><input type="hidden" name="csrf" value="<?= inv_e($csrf) ?>">
                <label>Material name<input name="name" maxlength="150" placeholder="Rice" required></label>
                <label>SKU / code <small>Optional</small><input name="sku" maxlength="80"></label>
                <label>Base unit<select name="base_unit_id" required><option value="">Select base unit</option><?php foreach ($baseUnits as $unit): ?><option value="<?= (int)$unit['id'] ?>"><?= inv_e($unit['name'] . ' (' . $unit['symbol'] . ') · ' . $unit['dimension']) ?></option><?php endforeach; ?></select></label>
                <label>Low-stock reorder level<input name="minimum_quantity" type="number" min="0" step="0.000001" value="0" required></label>
                <label class="span-all">Notes<textarea name="notes" maxlength="1000" rows="2"></textarea></label>
                <button class="button button-primary" type="submit">Add Material</button>
            </form>
        <?php endif; ?>
    </section>
</div>
<section class="panel-card data-panel"><div class="panel-title"><div><p class="eyebrow">MEASUREMENT SETUP</p><h3>Units and conversions</h3></div></div>
    <?php if (!$allUnits): ?><p class="empty-state">No units have been created yet.</p><?php else: ?><div class="table-scroll"><table class="data-table"><thead><tr><th>Name</th><th>Symbol</th><th>Dimension</th><th>Conversion</th><th>Status</th><th>Actions</th></tr></thead><tbody>
    <?php foreach ($allUnits as $unit): ?><tr><td><?= inv_e($unit['name']) ?></td><td><?= inv_e($unit['symbol']) ?></td><td><?= inv_e($unit['dimension']) ?></td><td><?= $unit['is_base'] ? 'Base unit' : inv_e(inv_decimal($unit['conversion_to_base'], 6) . ' ' . $unit['base_symbol']) ?></td><td><span class="status-pill<?= $unit['is_active'] ? '' : ' status-off' ?>"><?= $unit['is_active'] ? 'Active' : 'Inactive' ?></span></td><td><form method="post" action="<?= inv_e(inv_route('materials')) ?>"><input type="hidden" name="action" value="toggle-unit"><input type="hidden" name="return_page" value="materials"><input type="hidden" name="csrf" value="<?= inv_e($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$unit['id'] ?>"><input type="hidden" name="active" value="<?= $unit['is_active'] ? '0' : '1' ?>"><button class="button button-small button-quiet" type="submit"><?= $unit['is_active'] ? 'Deactivate' : 'Activate' ?></button></form></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
</section>
<section class="panel-card data-panel">
    <div class="panel-title panel-title-wrap"><div><p class="eyebrow">TENANT CATALOG</p><h3>Materials</h3></div><label class="search-field">Search<input type="search" placeholder="Name or SKU" data-table-search="materials-table"></label></div>
    <?php if (!$materials): ?><p class="empty-state">No raw materials have been added yet.</p><?php else: ?>
    <div class="table-scroll"><table class="data-table" id="materials-table"><thead><tr><th>Material details</th><th>Status</th><th>Actions</th></tr></thead><tbody>
    <?php foreach ($materials as $material): ?><tr data-search-row="<?= inv_e(mb_strtolower($material['name'] . ' ' . ($material['sku'] ?? ''))) ?>">
        <td><form method="post" action="<?= inv_e(inv_route('materials')) ?>" class="material-edit-row"><input type="hidden" name="action" value="update-material"><input type="hidden" name="return_page" value="materials"><input type="hidden" name="csrf" value="<?= inv_e($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$material['id'] ?>">
            <label>Name<input name="name" maxlength="150" value="<?= inv_e($material['name']) ?>" required></label><label>SKU<input name="sku" maxlength="80" value="<?= inv_e($material['sku'] ?? '') ?>"></label><label>Reorder level (<?= inv_e($material['base_unit_symbol']) ?>)<input name="minimum_quantity" type="number" min="0" step="0.000001" value="<?= inv_e($material['minimum_quantity']) ?>" required></label><label>Notes<input name="notes" maxlength="1000" value="<?= inv_e($material['notes'] ?? '') ?>"></label><span class="base-unit-note">Base unit: <?= inv_e($material['base_unit_name'] . ' (' . $material['base_unit_symbol'] . ')') ?></span><button class="button button-small" type="submit">Save</button>
        </form></td>
        <td><span class="status-pill<?= $material['is_active'] ? '' : ' status-off' ?>"><?= $material['is_active'] ? 'Active' : 'Inactive' ?></span></td>
        <td><form method="post" action="<?= inv_e(inv_route('materials')) ?>"><input type="hidden" name="action" value="toggle-material"><input type="hidden" name="return_page" value="materials"><input type="hidden" name="csrf" value="<?= inv_e($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$material['id'] ?>"><input type="hidden" name="active" value="<?= $material['is_active'] ? '0' : '1' ?>"><button class="button button-small button-quiet" type="submit"><?= $material['is_active'] ? 'Deactivate' : 'Activate' ?></button></form></td>
    </tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
</section>

