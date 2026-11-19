<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/database.php';
require_once __DIR__ . '/../app/stock-count-service.php';

function phase5_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$db = inventory_database();
$restaurantIds = array_map('intval', $db->query('SELECT id FROM restaurants WHERE is_active = 1 ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN));
if (count($restaurantIds) < 2) throw new RuntimeException('Phase 5 tests require two active restaurants.');
$restaurantId = $restaurantIds[0];
$otherRestaurantId = $restaurantIds[1];
$nonce = bin2hex(random_bytes(5));
$db->beginTransaction();

try {
    $dimension = 'phase5_unit_' . $nonce;
    $db->prepare('INSERT INTO inventory_units (restaurant_id, name, symbol, dimension, conversion_to_base, is_base) VALUES (?, ?, ?, ?, 1, 1)')
        ->execute([$restaurantId, 'Count test unit ' . $nonce, 'c' . $nonce, $dimension]);
    $unitId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO inventory_materials (restaurant_id, name, base_unit_id, minimum_quantity) VALUES (?, ?, ?, 20)')
        ->execute([$restaurantId, 'Count test material ' . $nonce, $unitId]);
    $materialId = (int)$db->lastInsertId();
    inventory_apply_movement($db, $restaurantId, $materialId, 'OPENING_STOCK', 10, 10, 'COUNT_TEST', null, null, hash('sha256', 'phase5-opening-' . $nonce), null, 'Phase 5 opening');

    $countId = inventory_create_stock_count($db, $restaurantId, 0, 'Phase 5 positive variance');
    $lineQuery = $db->prepare('SELECT i.id, i.material_id, i.book_quantity_base FROM inventory_stock_count_items i WHERE i.count_id = ? AND i.restaurant_id = ? ORDER BY i.id');
    $lineQuery->execute([$countId, $restaurantId]);
    $lineRows = $lineQuery->fetchAll();
    $lineIds = [];
    $physical = [];
    $targetBook = null;
    foreach ($lineRows as $line) {
        $lineIds[] = (int)$line['id'];
        $value = (float)$line['book_quantity_base'];
        if ((int)$line['material_id'] === $materialId) {
            $targetBook = $value;
            $value += 2;
        }
        $physical[] = $value;
    }
    phase5_assert($targetBook !== null && abs($targetBook - 10) < 0.000001, 'The count did not capture the correct book quantity.');
    inventory_save_stock_count_values($db, $restaurantId, $countId, $lineIds, $physical);

    inventory_apply_movement($db, $restaurantId, $materialId, 'PURCHASE', 3, 20, 'COUNT_TEST_RECEIPT', null, null, hash('sha256', 'phase5-receipt-' . $nonce), null, 'Receipt after count snapshot');
    $staleRejected = false;
    try { inventory_post_stock_count($db, $restaurantId, 0, $countId); } catch (InvalidArgumentException) { $staleRejected = true; }
    phase5_assert($staleRejected, 'A count with a later purchase must be blocked.');
    $freshCountId = inventory_create_stock_count($db, $restaurantId, 0, 'Fresh count after receipt');
    $lineQuery->execute([$freshCountId, $restaurantId]);
    $freshRows = $lineQuery->fetchAll();
    $freshIds = [];
    $freshPhysical = [];
    foreach ($freshRows as $line) {
        $freshIds[] = (int)$line['id'];
        $freshPhysical[] = (float)$line['book_quantity_base'] + ((int)$line['material_id'] === $materialId ? 2 : 0);
    }
    inventory_save_stock_count_values($db, $restaurantId, $freshCountId, $freshIds, $freshPhysical);
    inventory_post_stock_count($db, $restaurantId, 0, $freshCountId);
    $balanceQuery = $db->prepare('SELECT quantity_base, inventory_value, average_cost FROM inventory_stock_balances WHERE restaurant_id = ? AND material_id = ?');
    $balanceQuery->execute([$restaurantId, $materialId]);
    $balance = $balanceQuery->fetch();
    phase5_assert(abs((float)$balance['quantity_base'] - 15) < 0.000001, 'Posting did not preserve a receipt that occurred after the count snapshot.');

    $retryRejected = false;
    try { inventory_post_stock_count($db, $restaurantId, 0, $freshCountId); } catch (InvalidArgumentException) { $retryRejected = true; }
    phase5_assert($retryRejected, 'A posted count could be posted twice.');
    $movementCount = $db->prepare('SELECT COUNT(*) FROM inventory_stock_movements WHERE restaurant_id = ? AND source_type = ? AND source_id = ?');
    $movementCount->execute([$restaurantId, 'STOCK_COUNT', $freshCountId]);
    phase5_assert((int)$movementCount->fetchColumn() === 1, 'The positive count variance did not create exactly one adjustment movement.');

    $negativeCountId = inventory_create_stock_count($db, $restaurantId, 0, 'Phase 5 negative variance');
    $lineQuery->execute([$negativeCountId, $restaurantId]);
    $negativeRows = $lineQuery->fetchAll();
    $negativeIds = [];
    $negativePhysical = [];
    foreach ($negativeRows as $line) {
        $negativeIds[] = (int)$line['id'];
        $value = (float)$line['book_quantity_base'];
        if ((int)$line['material_id'] === $materialId) $value = 14.25;
        $negativePhysical[] = $value;
    }
    inventory_save_stock_count_values($db, $restaurantId, $negativeCountId, $negativeIds, $negativePhysical);
    inventory_post_stock_count($db, $restaurantId, 0, $negativeCountId);
    $balanceQuery->execute([$restaurantId, $materialId]);
    phase5_assert(abs((float)$balanceQuery->fetchColumn() - 14.25) < 0.000001, 'Negative count variance did not reduce stock to the verified physical quantity.');

    foreach (['CONSUMPTION', 'WASTAGE'] as $movementType) {
        $staleCountId = inventory_create_stock_count($db, $restaurantId, 0, 'Stale after '.$movementType);
        $lineQuery->execute([$staleCountId, $restaurantId]);
        $staleRows = $lineQuery->fetchAll();
        $staleIds = array_map(static fn(array $row): int => (int)$row['id'], $staleRows);
        $stalePhysical = array_map(static fn(array $row): float => (float)$row['book_quantity_base'], $staleRows);
        inventory_save_stock_count_values($db, $restaurantId, $staleCountId, $staleIds, $stalePhysical);
        inventory_apply_movement($db, $restaurantId, $materialId, $movementType, -0.1, 0, 'COUNT_TEST_LATE', null, null, hash('sha256', $nonce.$movementType), null);
        $blocked = false;
        try { inventory_post_stock_count($db, $restaurantId, 0, $staleCountId); } catch (InvalidArgumentException) { $blocked = true; }
        phase5_assert($blocked, 'A count with later '.$movementType.' was posted using stale book stock.');
    }
    $roundTripCountId = inventory_create_stock_count($db, $restaurantId, 0, 'Quantity restored after movements');
    $lineQuery->execute([$roundTripCountId, $restaurantId]);
    $roundTripRows = $lineQuery->fetchAll();
    inventory_save_stock_count_values($db, $restaurantId, $roundTripCountId,
        array_map(static fn(array $row): int => (int)$row['id'], $roundTripRows),
        array_map(static fn(array $row): float => (float)$row['book_quantity_base'], $roundTripRows));
    $balanceQuery->execute([$restaurantId, $materialId]);
    $beforeRoundTrip = $balanceQuery->fetch();
    inventory_apply_movement($db, $restaurantId, $materialId, 'CONSUMPTION', -0.1, 0, 'COUNT_TEST_LATE', null, null, hash('sha256', $nonce.'round-down'), null);
    inventory_apply_movement($db, $restaurantId, $materialId, 'RETURN', 0.1, (float)$beforeRoundTrip['average_cost'], 'COUNT_TEST_LATE', null, null, hash('sha256', $nonce.'round-up'), null);
    $roundTripBlocked = false;
    try { inventory_post_stock_count($db, $restaurantId, 0, $roundTripCountId); } catch (InvalidArgumentException) { $roundTripBlocked = true; }
    phase5_assert($roundTripBlocked, 'A count was posted after intervening movements that restored the original quantity.');

    $snapshot = inventory_stock_snapshot($db, $restaurantId);
    $testMaterial = array_values(array_filter($snapshot, static fn(array $row): bool => (int)$row['id'] === $materialId))[0] ?? null;
    phase5_assert($testMaterial && (float)$testMaterial['quantity_base'] <= (float)$testMaterial['minimum_quantity'], 'Low-stock threshold did not identify the count-test material.');
    $crossTenantRejected = false;
    try { inventory_save_stock_count_values($db, $otherRestaurantId, $countId, $lineIds, $physical); } catch (InvalidArgumentException) { $crossTenantRejected = true; }
    phase5_assert($crossTenantRejected, 'A stock count from another restaurant was edited.');

    $db->rollBack();
    echo "PASS: snapshot capture, stale purchase/consumption/wastage and round-trip movement rejection, fresh positive and negative variances, one-time posting, low-stock detection, and tenant isolation.\n";
    echo "PASS: all inventory test records and ledger entries were rolled back; no existing SaaS tables were changed.\n";
} catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack();
    throw $exception;
}
