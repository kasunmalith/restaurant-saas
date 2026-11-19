<?php
declare(strict_types=1);

require_once __DIR__ . '/kot-service.php';

function inventory_automation_status(PDO $db, int $restaurantId): array
{
    $state = inventory_kot_worker_state($db);
    $lastRun = $db->query('SELECT completed_at FROM inventory_kot_worker_runs ORDER BY id DESC LIMIT 1')->fetchColumn();
    $lastEvent = $db->prepare('SELECT kot_item_id, event_at FROM inventory_kot_events WHERE restaurant_id = ? AND processing_status = ? ORDER BY kot_item_id DESC LIMIT 1');
    $lastEvent->execute([$restaurantId, 'PROCESSED']);
    $reviews = $db->prepare('SELECT COUNT(*) FROM inventory_kot_events WHERE restaurant_id = ? AND processing_status = ?');
    $reviews->execute([$restaurantId, 'REVIEW']);
    $mappings = $db->prepare("SELECT COUNT(*) FROM inventory_kot_events WHERE restaurant_id = ? AND processing_status = 'REVIEW' AND event_action = 'ADDED' AND (review_reason LIKE '%option or portion label%' OR review_reason LIKE '%menu-item reference%' OR review_reason LIKE '%mapping%')");
    $mappings->execute([$restaurantId]);
    return [
        'initialized' => $state !== null,
        // The scheduler is outside the portal and cannot be truthfully inferred from a recent run.
        'schedule' => 'Unknown',
        'last_successful_run' => $lastRun === false ? null : (string)$lastRun,
        'last_processed_kot' => $lastEvent->fetch() ?: null,
        'pending_reviews' => (int)$reviews->fetchColumn(),
        'unresolved_mappings' => (int)$mappings->fetchColumn(),
    ];
}

function inventory_automation_readiness(PDO $db, int $restaurantId): array
{
    $issues = [];
    $settings = $db->prepare('SELECT is_enabled FROM inventory_portal_settings WHERE restaurant_id = ?');
    $settings->execute([$restaurantId]);
    if ((int)$settings->fetchColumn() !== 1) $issues[] = 'Inventory Portal is not enabled for this restaurant.';
    $materials = $db->prepare('SELECT id, name FROM inventory_materials WHERE restaurant_id = ? AND is_active = 1 ORDER BY id');
    $materials->execute([$restaurantId]);
    $materialRows = $materials->fetchAll();
    if (!$materialRows) $issues[] = 'Add at least one active raw material.';
    $menu = $db->prepare('SELECT id, name, price, is_portioned, half_price, full_price, is_active FROM menu_items WHERE restaurant_id = ? AND is_active = 1 ORDER BY id');
    $menu->execute([$restaurantId]);
    $menuRows = $menu->fetchAll();
    if (!$menuRows) $issues[] = 'No active menu items are available for recipe mapping.';
    $recipeCount = 0;
    foreach ($menuRows as $item) {
        foreach (inventory_menu_mappings($db, $restaurantId, $item) as $mapping) {
            $resolved = inventory_resolve_menu_mapping($db, $restaurantId, (int)$item['id'], $mapping['key'] === 'BASE' ? null : (string)$mapping['label']);
            if (!$resolved || $resolved['key'] !== $mapping['key']) {
                $issues[] = 'Ambiguous menu mapping: ' . $item['name'] . ' / ' . $mapping['label'] . '.';
                continue;
            }
            $recipe = inventory_current_recipe($db, $restaurantId, (int)$item['id'], (string)$mapping['key']);
            if (!$recipe) {
                $issues[] = 'Missing current recipe: ' . $item['name'] . ' / ' . $mapping['label'] . '.';
                continue;
            }
            $recipeCount++;
            if (!is_finite((float)$recipe['yield_quantity']) || (float)$recipe['yield_quantity'] <= 0) {
                $issues[] = 'Invalid yield in recipe #' . $recipe['id'] . '.';
                continue;
            }
            $lines = $db->prepare('SELECT ri.material_id, ri.unit_id, ri.quantity_base, m.base_unit_id, m.name AS material_name FROM inventory_recipe_ingredients ri LEFT JOIN inventory_materials m ON m.id = ri.material_id AND m.restaurant_id = ri.restaurant_id WHERE ri.restaurant_id = ? AND ri.recipe_id = ?');
            $lines->execute([$restaurantId, (int)$recipe['id']]);
            $ingredients = $lines->fetchAll();
            if (!$ingredients) $issues[] = 'Recipe #' . $recipe['id'] . ' has no ingredients.';
            foreach ($ingredients as $line) {
                if (!$line['base_unit_id'] || (float)$line['quantity_base'] <= 0) {
                    $issues[] = 'Recipe #' . $recipe['id'] . ' has a missing material or invalid ingredient quantity.';
                    continue;
                }
                try {
                    inventory_conversion_to($db, $restaurantId, (int)$line['unit_id'], (int)$line['base_unit_id']);
                } catch (InvalidArgumentException) {
                    $issues[] = 'Invalid unit conversion in recipe #' . $recipe['id'] . ' for ' . $line['material_name'] . '.';
                }
                if (!inventory_material_has_known_cost($db, $restaurantId, (int)$line['material_id'])) {
                    $issues[] = 'Post verified opening stock or a priced purchase for ' . $line['material_name'] . ' in recipe #' . $recipe['id'] . '.';
                }
            }
        }
    }
    if ($recipeCount === 0) $issues[] = 'No active, effective recipes exist.';
    $status = inventory_automation_status($db, $restaurantId);
    if (!$status['initialized']) $issues[] = 'KOT worker is not initialized. Do not initialize it until real stock and recipes are verified.';
    if ($status['pending_reviews'] > 0) $issues[] = $status['pending_reviews'] . ' KOT event(s) require review.';
    return ['ready' => $issues === [], 'issues' => array_values(array_unique($issues)), 'status' => $status];
}

function inventory_kot_dry_run_event(PDO $db, array $event): array
{
    $restaurantId = (int)$event['restaurant_id'];
    $result = ['kot_item_id' => (int)$event['kot_item_id'], 'restaurant_id' => $restaurantId,
        'action' => (string)$event['event_action'], 'status' => 'REVIEW', 'reason' => null, 'ingredients' => []];
    if ($result['action'] !== 'ADDED') {
        $result['reason'] = 'KOT cancellation requires staff review; no automatic return is simulated.';
        return $result;
    }
    $menuItemId = (int)($event['menu_item_id'] ?? 0);
    $mapping = inventory_resolve_menu_mapping($db, $restaurantId, $menuItemId, $event['portion_label'] ?? null);
    if (!$mapping) {
        $result['reason'] = 'Menu item, option, or portion mapping is missing or ambiguous.';
        return $result;
    }
    $recipe = inventory_current_recipe($db, $restaurantId, $menuItemId, (string)$mapping['key'], (string)$event['event_at']);
    if (!$recipe || (float)$recipe['yield_quantity'] <= 0) {
        $result['reason'] = 'No valid effective recipe exists at KOT time.';
        return $result;
    }
    $result['recipe_id'] = (int)$recipe['id'];
    $result['recipe_version'] = (int)$recipe['version'];
    $result['mapping'] = (string)$mapping['key'];
    $lines = $db->prepare('SELECT ri.material_id, ri.quantity_base, m.name, u.symbol AS base_unit, b.quantity_base AS stock_quantity, b.average_cost FROM inventory_recipe_ingredients ri JOIN inventory_materials m ON m.id = ri.material_id AND m.restaurant_id = ri.restaurant_id JOIN inventory_units u ON u.id = m.base_unit_id AND u.restaurant_id = m.restaurant_id LEFT JOIN inventory_stock_balances b ON b.restaurant_id = ri.restaurant_id AND b.material_id = ri.material_id WHERE ri.restaurant_id = ? AND ri.recipe_id = ? ORDER BY ri.id');
    $lines->execute([$restaurantId, (int)$recipe['id']]);
    foreach ($lines as $line) {
        $quantity = inventory_recipe_consumption_quantity((float)$line['quantity_base'], (float)$recipe['yield_quantity'], (float)$event['quantity']);
        if ($quantity <= 0) { $result['reason'] = 'Ingredient quantity rounds to zero after yield conversion.'; return $result; }
        $known = inventory_material_has_known_cost($db, $restaurantId, (int)$line['material_id']);
        $result['ingredients'][] = ['material' => $line['name'], 'material_id' => (int)$line['material_id'], 'base_unit' => $line['base_unit'],
            'consumption_base' => $quantity, 'current_stock_base' => (float)($line['stock_quantity'] ?? 0),
            'expected_stock_base' => round((float)($line['stock_quantity'] ?? 0) - $quantity, 6),
            'expected_cost' => $known ? round($quantity * (float)$line['average_cost'], 6) : null];
        if (!$known) $result['reason'] = 'One or more ingredients have unknown cost.';
    }
    if (!$result['ingredients']) $result['reason'] = 'The recipe has no ingredients.';
    if ($result['reason'] === null) $result['status'] = 'WOULD_CONSUME';
    return $result;
}
