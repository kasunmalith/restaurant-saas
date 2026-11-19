<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/database.php';
require_once __DIR__ . '/../app/recipes-service.php';

function phase3_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$db = inventory_database();
$query = $db->query('SELECT mi.id AS menu_item_id, mi.restaurant_id FROM menu_items mi JOIN restaurants r ON r.id = mi.restaurant_id WHERE mi.is_active = 1 AND r.is_active = 1 ORDER BY EXISTS (SELECT 1 FROM menu_item_options mo WHERE mo.restaurant_id = mi.restaurant_id AND mo.menu_item_id = mi.id) DESC, mi.restaurant_id, mi.id LIMIT 1');
$menu = $query->fetch();
$restaurantIds = $db->query('SELECT id FROM restaurants WHERE is_active = 1 ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
if (!$menu || count($restaurantIds) < 2) {
    throw new RuntimeException('Phase 3 tests require an active menu item and two active restaurants.');
}
$restaurantId = (int)$menu['restaurant_id'];
$otherRestaurantId = (int)array_values(array_filter(array_map('intval', $restaurantIds), static fn(int $id): bool => $id !== $restaurantId))[0];
$menuItemId = (int)$menu['menu_item_id'];
$nonce = bin2hex(random_bytes(5));
$db->beginTransaction();

try {
    $baseInsert = $db->prepare('INSERT INTO inventory_units (restaurant_id, name, symbol, dimension, conversion_to_base, is_base, decimal_places) VALUES (?, ?, ?, ?, 1, 1, 3)');
    $baseInsert->execute([$restaurantId, 'Test recipe base ' . $nonce, 'rb' . $nonce, 'recipe_mass_' . $nonce]);
    $baseUnitId = (int)$db->lastInsertId();
    $conversionInsert = $db->prepare('INSERT INTO inventory_units (restaurant_id, name, symbol, dimension, base_unit_id, conversion_to_base, is_base, decimal_places) VALUES (?, ?, ?, ?, ?, 0.001, 0, 3)');
    $conversionInsert->execute([$restaurantId, 'Test recipe gram ' . $nonce, 'rg' . $nonce, 'recipe_mass_' . $nonce, $baseUnitId]);
    $gramUnitId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO inventory_materials (restaurant_id, name, base_unit_id) VALUES (?, ?, ?)')->execute([$restaurantId, 'Test recipe ingredient ' . $nonce, $baseUnitId]);
    $materialId = (int)$db->lastInsertId();
    inventory_apply_movement($db, $restaurantId, $materialId, 'OPENING_STOCK', 10, 100, 'TEST_RECIPE', null, null, hash('sha256', 'recipe-opening-' . $nonce), null, 'Phase 3 transactional test');

    $t0 = gmdate('Y-m-d H:i:s', time() - 120);
    $t1 = gmdate('Y-m-d H:i:s', time() + 60);
    $recipe1 = inventory_save_recipe($db, $restaurantId, 0, $menuItemId, 'BASE', $t0, 1, 'serving', '', [['material_id' => $materialId, 'unit_id' => $gramUnitId, 'quantity' => 500]]);
    $cost1 = inventory_recipe_cost($db, $restaurantId, $recipe1);
    phase3_assert($cost1['complete'] && abs((float)$cost1['cost_per_yield'] - 50.0) < 0.00001, 'Recipe unit conversion and weighted-average ingredient cost were incorrect.');
    $recipe2 = inventory_save_recipe($db, $restaurantId, 0, $menuItemId, 'BASE', $t1, 1, 'serving', 'New version', [['material_id' => $materialId, 'unit_id' => $baseUnitId, 'quantity' => 0.25]]);
    phase3_assert((int)inventory_current_recipe($db, $restaurantId, $menuItemId, 'BASE', $t0)['id'] === $recipe1, 'Historical recipe version was not retained.');
    phase3_assert((int)inventory_current_recipe($db, $restaurantId, $menuItemId, 'BASE', $t1)['id'] === $recipe2, 'New effective recipe version was not selected.');

    $profit = inventory_profit_metrics(0.0, 50.0);
    phase3_assert($profit['gross_profit'] === -50.0 && $profit['gross_margin_percent'] === null, 'Zero selling price handling was unsafe.');
    $ambiguous = [
        ['key' => 'BASE', 'type' => 'BASE', 'label' => 'Standard'],
        ['key' => 'PORTION:HALF', 'type' => 'PORTION', 'label' => 'Half'],
        ['key' => 'OPTION:3', 'type' => 'OPTION', 'label' => 'Half'],
    ];
    phase3_assert(inventory_resolve_mapping_label($ambiguous, 'Half') === null, 'Ambiguous size/option text was guessed instead of flagged.');
    phase3_assert(inventory_menu_item($db, $otherRestaurantId, $menuItemId) === null, 'A menu item from another restaurant was resolved.');

    $optionQuery = $db->prepare('SELECT id, name FROM menu_item_options WHERE restaurant_id = ? AND menu_item_id = ? ORDER BY id LIMIT 1');
    $optionQuery->execute([$restaurantId, $menuItemId]);
    $option = $optionQuery->fetch();
    if ($option) {
        $optionKey = 'OPTION:' . (int)$option['id'];
        $optionRecipe = inventory_save_recipe($db, $restaurantId, 0, $menuItemId, $optionKey, gmdate('Y-m-d H:i:s', time() + 180), 1, 'serving', '', [['material_id' => $materialId, 'unit_id' => $baseUnitId, 'quantity' => 1]]);
        phase3_assert((int)inventory_current_recipe($db, $restaurantId, $menuItemId, $optionKey, gmdate('Y-m-d H:i:s', time() + 181))['id'] === $optionRecipe, 'An option-specific recipe could not be resolved.');
    }

    $db->prepare('UPDATE inventory_materials SET is_active = 0 WHERE id = ? AND restaurant_id = ?')->execute([$materialId, $restaurantId]);
    $inactiveRejected = false;
    try {
        inventory_save_recipe($db, $restaurantId, 0, $menuItemId, 'BASE', gmdate('Y-m-d H:i:s', time() + 240), 1, 'serving', '', [['material_id' => $materialId, 'unit_id' => $baseUnitId, 'quantity' => 1]]);
    } catch (InvalidArgumentException) {
        $inactiveRejected = true;
    }
    phase3_assert($inactiveRejected, 'An inactive material was accepted for a new recipe.');

    $db->rollBack();
    echo "PASS: base and option mapping, recipe versioning, converted ingredient costing, zero-price margin, ambiguous mapping rejection, inactive-material rejection, and tenant checks.\n";
    echo "PASS: all test inventory rows and recipe versions were rolled back; no existing Restaurant SaaS rows were changed.\n";
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $exception;
}
