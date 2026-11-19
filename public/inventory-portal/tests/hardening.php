<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/database.php';
require_once __DIR__ . '/../app/automation-service.php';
require_once __DIR__ . '/../app/stock-count-service.php';

function hardening_assert(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

$db = inventory_database();
$menu = $db->query('SELECT mi.id, mi.restaurant_id, mo.id AS option_id, mo.name AS option_name FROM menu_items mi JOIN menu_item_options mo ON mo.menu_item_id = mi.id AND mo.restaurant_id = mi.restaurant_id JOIN restaurants r ON r.id = mi.restaurant_id WHERE mi.is_active = 1 AND r.is_active = 1 ORDER BY mi.id LIMIT 1')->fetch();
if (!$menu) throw new RuntimeException('A pre-existing active menu option is needed for this rollback-only test.');
$restaurantId = (int)$menu['restaurant_id'];
$menuItemId = (int)$menu['id'];
$nonce = bin2hex(random_bytes(6));
$db->beginTransaction();
try {
    $db->prepare('INSERT INTO inventory_units (restaurant_id, name, symbol, dimension, conversion_to_base, is_base) VALUES (?, ?, ?, ?, 1, 1)')
        ->execute([$restaurantId, 'Hardening kg '.$nonce, 'hk'.$nonce, 'hardening_mass_'.$nonce]);
    $kg = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO inventory_units (restaurant_id, name, symbol, dimension, base_unit_id, conversion_to_base, is_base) VALUES (?, ?, ?, ?, ?, 0.001, 0)')
        ->execute([$restaurantId, 'Hardening g '.$nonce, 'hg'.$nonce, 'hardening_mass_'.$nonce, $kg]);
    $gram = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO inventory_units (restaurant_id, name, symbol, dimension, conversion_to_base, is_base) VALUES (?, ?, ?, ?, 1, 1)')
        ->execute([$restaurantId, 'Hardening pcs '.$nonce, 'hp'.$nonce, 'hardening_count_'.$nonce]);
    $pcs = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO inventory_materials (restaurant_id, name, base_unit_id) VALUES (?, ?, ?)')
        ->execute([$restaurantId, 'Hardening rice '.$nonce, $kg]);
    $rice = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO inventory_materials (restaurant_id, name, base_unit_id) VALUES (?, ?, ?)')
        ->execute([$restaurantId, 'Hardening egg '.$nonce, $pcs]);
    $egg = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO inventory_stock_balances (restaurant_id, material_id, quantity_base, inventory_value, average_cost) VALUES (?, ?, 0, 0, 0)')
        ->execute([$restaurantId, $rice]);
    hardening_assert(!inventory_material_has_known_cost($db, $restaurantId, $rice), 'An empty balance was treated as known zero cost.');
    inventory_apply_movement($db, $restaurantId, $rice, 'OPENING_STOCK', 10, 300, 'HARDENING', null, null, hash('sha256', $nonce.'rice'), null);
    inventory_apply_movement($db, $restaurantId, $egg, 'OPENING_STOCK', 10, 20, 'HARDENING', null, null, hash('sha256', $nonce.'egg'), null);
    hardening_assert(inventory_material_has_known_cost($db, $restaurantId, $rice), 'Priced opening stock was not recognized.');

    $t0 = gmdate('Y-m-d H:i:s', time() - 180);
    $t1 = gmdate('Y-m-d H:i:s', time() - 120);
    $t2 = gmdate('Y-m-d H:i:s', time() - 60);
    $recipe1 = inventory_save_recipe($db, $restaurantId, 0, $menuItemId, 'BASE', $t0, 1, 'serving', '', [['material_id'=>$rice, 'unit_id'=>$gram, 'quantity'=>200]]);
    $recipe2 = inventory_save_recipe($db, $restaurantId, 0, $menuItemId, 'BASE', $t1, 2, 'servings', '', [['material_id'=>$rice, 'unit_id'=>$gram, 'quantity'=>400]]);
    $recipe4 = inventory_save_recipe($db, $restaurantId, 0, $menuItemId, 'BASE', $t2, 4, 'servings', '', [
        ['material_id'=>$rice, 'unit_id'=>$gram, 'quantity'=>800],
        ['material_id'=>$egg, 'unit_id'=>$pcs, 'quantity'=>4],
    ]);
    hardening_assert(inventory_recipe_cost($db, $restaurantId, $recipe4)['complete'], 'Priced multiple-ingredient recipe is incomplete.');
    hardening_assert(abs((float)inventory_recipe_cost($db, $restaurantId, $recipe4)['cost_per_yield'] - 80) < 0.00001, 'Per-serving food cost is wrong.');
    $baseEvent = ['restaurant_id'=>$restaurantId, 'kot_item_id'=>9000000000 + random_int(1,100000), 'order_id'=>9100000000 + random_int(1,100000),
        'order_item_id'=>9200000000 + random_int(1,100000), 'menu_item_id'=>$menuItemId, 'event_action'=>'ADDED',
        'item_name'=>'Hardening fixture', 'portion_label'=>null, 'quantity'=>2, 'event_at'=>gmdate('Y-m-d H:i:s')];
    $beforeEvents = (int)$db->query('SELECT COUNT(*) FROM inventory_kot_events')->fetchColumn();
    $preview = inventory_kot_dry_run_event($db, $baseEvent);
    hardening_assert($preview['status'] === 'WOULD_CONSUME' && abs($preview['ingredients'][0]['consumption_base'] - 0.4) < 0.000001, 'Dry-run quantity is wrong.');
    hardening_assert((int)$db->query('SELECT COUNT(*) FROM inventory_kot_events')->fetchColumn() === $beforeEvents, 'Dry-run wrote an event.');
    hardening_assert(inventory_process_kot_snapshot($db, $baseEvent) === 'PROCESSED', 'Yield-four KOT was not processed.');
    hardening_assert(inventory_process_kot_snapshot($db, $baseEvent) === 'DUPLICATE', 'Worker rerun was not idempotent.');
    $balance = $db->prepare('SELECT quantity_base FROM inventory_stock_balances WHERE restaurant_id = ? AND material_id = ?');
    $balance->execute([$restaurantId, $rice]);
    hardening_assert(abs((float)$balance->fetchColumn() - 9.6) < 0.000001, 'Two servings of yield-four rice did not leave 9.6 kg.');
    $cost = $db->prepare('SELECT SUM(cost_amount) FROM inventory_stock_movements WHERE restaurant_id = ? AND source_type = ? AND source_id = ?');
    $cost->execute([$restaurantId, 'KOT_ITEM', $baseEvent['kot_item_id']]);
    hardening_assert(abs((float)$cost->fetchColumn() - 160) < 0.00001, 'Multiple-ingredient KOT cost is wrong.');

    foreach ([[$recipe1, $t0], [$recipe2, $t1]] as $index => [$recipeId, $time]) {
        $event = $baseEvent;
        $event['kot_item_id'] += $index + 1;
        $event['event_at'] = $time;
        hardening_assert(inventory_process_kot_snapshot($db, $event) === 'PROCESSED', 'Yield-one/two KOT failed.');
        $movement = $db->prepare('SELECT ABS(quantity_base) FROM inventory_stock_movements WHERE restaurant_id = ? AND source_type = ? AND source_id = ? LIMIT 1');
        $movement->execute([$restaurantId, 'KOT_ITEM', $event['kot_item_id']]);
        hardening_assert(abs((float)$movement->fetchColumn() - 0.4) < 0.000001, 'Yield-one/two consumption is wrong.');
    }
    $optionKey = 'OPTION:'.(int)$menu['option_id'];
    inventory_save_recipe($db, $restaurantId, 0, $menuItemId, $optionKey, $t2, 4, 'servings', '', [['material_id'=>$rice, 'unit_id'=>$gram, 'quantity'=>800]]);
    $optionEvent = $baseEvent;
    $optionEvent['kot_item_id'] += 3;
    $optionEvent['portion_label'] = $menu['option_name'];
    hardening_assert(inventory_process_kot_snapshot($db, $optionEvent) === 'PROCESSED', 'Option recipe was not resolved.');

    $cancel = $baseEvent;
    $cancel['kot_item_id'] += 4;
    $cancel['event_action'] = 'CANCELLED';
    $cancel['quantity'] = 1;
    hardening_assert(inventory_process_kot_snapshot($db, $cancel) === 'REVIEW', 'Cancellation did not enter review.');
    $missing = $baseEvent;
    $missing['kot_item_id'] += 5;
    $missing['portion_label'] = 'Invalid option '.$nonce;
    hardening_assert(inventory_process_kot_snapshot($db, $missing) === 'REVIEW', 'Invalid mapping was not reviewed.');
    $unknownMaterial = $db->prepare('INSERT INTO inventory_materials (restaurant_id, name, base_unit_id) VALUES (?, ?, ?)');
    $unknownMaterial->execute([$restaurantId, 'Uncosted test ingredient '.$nonce, $kg]);
    $uncostedId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO inventory_stock_balances (restaurant_id, material_id, quantity_base, inventory_value, average_cost) VALUES (?, ?, 0, 0, 0)')
        ->execute([$restaurantId, $uncostedId]);
    $uncostedTime = gmdate('Y-m-d H:i:s', time() + 60);
    $uncostedRecipe = inventory_save_recipe($db, $restaurantId, 0, $menuItemId, $optionKey, $uncostedTime, 1, 'serving', '', [['material_id'=>$uncostedId, 'unit_id'=>$kg, 'quantity'=>0.2]]);
    hardening_assert(!inventory_recipe_cost($db, $restaurantId, $uncostedRecipe)['complete'], 'Unknown zero cost appeared complete.');
    $uncostedEvent = $optionEvent;
    $uncostedEvent['kot_item_id'] += 6;
    $uncostedEvent['event_at'] = $uncostedTime;
    hardening_assert(inventory_process_kot_snapshot($db, $uncostedEvent) === 'REVIEW', 'Unknown-cost KOT was consumed.');
    $shortageEvent = $baseEvent;
    $shortageEvent['kot_item_id'] += 7;
    $shortageEvent['quantity'] = 100;
    hardening_assert(inventory_process_kot_snapshot($db, $shortageEvent) === 'PROCESSED', 'Existing negative-stock policy changed unexpectedly.');
    $balance->execute([$restaurantId, $rice]);
    hardening_assert((float)$balance->fetchColumn() < 0, 'Negative stock did not occur as documented.');
    $db->rollBack();
    echo "PASS: yield 1/2/4, quantity two, gram conversion, multiple ingredients, option recipe, dry-run no-write, idempotency, cancellation review, invalid mapping, unknown cost, and negative stock. All fixtures rolled back.\n";
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    throw $error;
}
