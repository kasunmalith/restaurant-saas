<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/database.php';
require_once __DIR__ . '/../app/kot-service.php';

function phase4_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$db = inventory_database();
$menu = $db->query('SELECT mi.id AS menu_item_id, mi.restaurant_id FROM menu_items mi JOIN restaurants r ON r.id = mi.restaurant_id WHERE mi.is_active = 1 AND r.is_active = 1 ORDER BY EXISTS (SELECT 1 FROM menu_item_options mo WHERE mo.restaurant_id = mi.restaurant_id AND mo.menu_item_id = mi.id) DESC, mi.restaurant_id, mi.id LIMIT 1')->fetch();
$restaurantIds = array_map('intval', $db->query('SELECT id FROM restaurants WHERE is_active = 1 ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN));
if (!$menu || count($restaurantIds) < 2) throw new RuntimeException('Phase 4 tests require an active menu item and two active restaurants.');
$restaurantId = (int)$menu['restaurant_id'];
$otherRestaurantId = (int)array_values(array_filter($restaurantIds, static fn(int $id): bool => $id !== $restaurantId))[0];
$menuItemId = (int)$menu['menu_item_id'];
$nonce = bin2hex(random_bytes(5));
$db->beginTransaction();

try {
    $bootMax = inventory_kot_worker_bootstrap($db);
    phase4_assert($bootMax >= 0 && inventory_kot_worker_state($db) !== null, 'Worker bootstrap did not record a starting watermark.');

    $dimension = 'phase4_mass_' . $nonce;
    $db->prepare('INSERT INTO inventory_units (restaurant_id, name, symbol, dimension, conversion_to_base, is_base, decimal_places) VALUES (?, ?, ?, ?, 1, 1, 4)')
        ->execute([$restaurantId, 'Phase 4 test base ' . $nonce, 'p4b' . $nonce, $dimension]);
    $unitId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO inventory_materials (restaurant_id, name, base_unit_id) VALUES (?, ?, ?)')
        ->execute([$restaurantId, 'Phase 4 test material ' . $nonce, $unitId]);
    $materialId = (int)$db->lastInsertId();
    inventory_apply_movement($db, $restaurantId, $materialId, 'OPENING_STOCK', 10, 100, 'TEST_KOT', null, null, hash('sha256', 'phase4-opening-' . $nonce), null, 'Phase 4 test');

    $effective = gmdate('Y-m-d H:i:s', time() - 60);
    $recipeId = inventory_save_recipe($db, $restaurantId, 0, $menuItemId, 'BASE', $effective, 1, 'serving', '', [['material_id' => $materialId, 'unit_id' => $unitId, 'quantity' => 0.5]]);
    $orderId = 9000000000 + random_int(1, 1000000);
    $orderItemId = $orderId + 1;
    $kotBase = $orderId + 2;
    $eventAt = gmdate('Y-m-d H:i:s');
    $added = [
        'restaurant_id' => $restaurantId, 'kot_item_id' => $kotBase, 'order_id' => $orderId,
        'order_item_id' => $orderItemId, 'menu_item_id' => $menuItemId, 'event_action' => 'ADDED',
        'item_name' => 'Transactional test menu item', 'portion_label' => null, 'quantity' => 2, 'event_at' => $eventAt,
    ];
    phase4_assert(inventory_process_kot_snapshot($db, $added) === 'PROCESSED', 'A valid KOT addition did not consume stock.');
    phase4_assert(inventory_process_kot_snapshot($db, $added) === 'DUPLICATE', 'Replaying a KOT addition was not idempotent.');
    $balanceQuery = $db->prepare('SELECT quantity_base FROM inventory_stock_balances WHERE restaurant_id = ? AND material_id = ?');
    $balanceQuery->execute([$restaurantId, $materialId]);
    phase4_assert(abs((float)$balanceQuery->fetchColumn() - 9.0) < 0.000001, 'KOT delta deducted an incorrect stock quantity or deducted twice.');

    $cancel1 = $added;
    $cancel1['kot_item_id'] = $kotBase + 1;
    $cancel1['event_action'] = 'CANCELLED';
    $cancel1['quantity'] = 1;
    phase4_assert(inventory_process_kot_snapshot($db, $cancel1) === 'REVIEW', 'KOT cancellation was not sent to review.');
    $reviewQuery = $db->prepare('SELECT id, unmatched_quantity FROM inventory_kot_cancellation_reviews WHERE restaurant_id = ? AND cancel_kot_item_id = ?');
    $reviewQuery->execute([$restaurantId, $cancel1['kot_item_id']]);
    $review = $reviewQuery->fetch();
    phase4_assert((float)$review['unmatched_quantity'] === 0.0, 'The tracked cancellation quantity was not safely allocated to the original KOT addition.');
    inventory_resolve_kot_cancellation($db, $restaurantId, 0, (int)$review['id'], 'RETURN_TO_STOCK', 'Ingredients not used in preparation');
    $balanceQuery->execute([$restaurantId, $materialId]);
    phase4_assert(abs((float)$balanceQuery->fetchColumn() - 9.5) < 0.000001, 'Return-to-stock compensation did not restore only the cancelled portion.');

    $added2 = $added;
    $added2['kot_item_id'] = $kotBase + 2;
    $added2['quantity'] = 1;
    phase4_assert(inventory_process_kot_snapshot($db, $added2) === 'PROCESSED', 'A second KOT addition was not processed as a delta.');
    $cancel2 = $cancel1;
    $cancel2['kot_item_id'] = $kotBase + 3;
    phase4_assert(inventory_process_kot_snapshot($db, $cancel2) === 'REVIEW', 'A second cancellation was not queued for review.');
    $reviewQuery->execute([$restaurantId, $cancel2['kot_item_id']]);
    $review2 = $reviewQuery->fetch();
    $balanceQuery->execute([$restaurantId, $materialId]);
    $beforeWastageDecision = (float)$balanceQuery->fetchColumn();
    inventory_resolve_kot_cancellation($db, $restaurantId, 0, (int)$review2['id'], 'WASTAGE', 'Preparation had already started');
    $balanceQuery->execute([$restaurantId, $materialId]);
    phase4_assert(abs((float)$balanceQuery->fetchColumn() - $beforeWastageDecision) < 0.000001, 'KOT wastage resolution incorrectly deducted already-consumed stock a second time.');

    $wasteKey = hash('sha256', 'phase4-manual-waste-' . $nonce);
    $wasteId = inventory_record_manual_wastage($db, $restaurantId, 0, $materialId, $unitId, 0.25, 'PREPARATION_WASTE', 'Test manual wastage', $wasteKey);
    $retryWasteId = inventory_record_manual_wastage($db, $restaurantId, 0, $materialId, $unitId, 0.25, 'PREPARATION_WASTE', 'Retry same request', $wasteKey);
    phase4_assert($wasteId === $retryWasteId, 'Manual wastage retry created a duplicate record.');

    $unmapped = $added;
    $unmapped['kot_item_id'] = $kotBase + 5;
    $unmapped['portion_label'] = 'Phase 4 unknown portion ' . $nonce;
    $unmapped['quantity'] = 1;
    phase4_assert(inventory_process_kot_snapshot($db, $unmapped) === 'REVIEW', 'An unknown KOT portion was not held for review.');
    $unmappedEventQuery = $db->prepare('SELECT id FROM inventory_kot_events WHERE restaurant_id = ? AND kot_item_id = ?');
    $unmappedEventQuery->execute([$restaurantId, $unmapped['kot_item_id']]);
    $unmappedEventId = (int)$unmappedEventQuery->fetchColumn();
    phase4_assert(inventory_retry_kot_event($db, $restaurantId, $unmappedEventId) === 'REVIEW', 'A still-unmapped review did not remain safely pending.');
    phase4_assert(
        inventory_save_kot_mapping_override_and_retry($db, $restaurantId, 0, $unmappedEventId, 'BASE') === 'PROCESSED',
        'Saving an explicit inventory-side option mapping did not process the reviewed KOT event.'
    );
    $mapped = inventory_resolve_menu_mapping($db, $restaurantId, $menuItemId, $unmapped['portion_label']);
    phase4_assert(($mapped['key'] ?? null) === 'BASE', 'The explicit tenant-scoped KOT mapping override was not honored.');

    $foreignEvent = $added;
    $foreignEvent['restaurant_id'] = $otherRestaurantId;
    $foreignEvent['kot_item_id'] = $kotBase + 4;
    phase4_assert(inventory_process_kot_snapshot($db, $foreignEvent) === 'REVIEW', 'Cross-tenant KOT menu data was consumed.');

    $db->rollBack();
    echo "PASS: bootstrap watermark, KOT delta consumption, worker retry idempotency, cancellation review allocation, proportional return, wastage reclassification, manual wastage retry, controlled KOT mapping override/retry, and cross-tenant rejection.\n";
    echo "PASS: all inventory test records were rolled back; no existing KOT, order, menu, or Restaurant SaaS rows were changed.\n";
} catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack();
    throw $exception;
}
