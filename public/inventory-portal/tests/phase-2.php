<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/database.php';
require_once __DIR__ . '/../app/inventory-service.php';

function phase2_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$db = inventory_database();
$restaurantIds = $db->query('SELECT id FROM restaurants WHERE is_active = 1 ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
if (count($restaurantIds) < 2) {
    throw new RuntimeException('Phase 2 cross-tenant tests require two active restaurants.');
}
$restaurantId = (int)$restaurantIds[0];
$otherRestaurantId = (int)$restaurantIds[1];
$nonce = bin2hex(random_bytes(4));
$db->beginTransaction();

try {
    $unitInsert = $db->prepare(
        'INSERT INTO inventory_units (restaurant_id, name, symbol, dimension, conversion_to_base, is_base, decimal_places)
         VALUES (?, ?, ?, ?, 1, 1, 3)'
    );
    $unitInsert->execute([$restaurantId, 'Test mass base', 'tm' . $nonce, 'test_mass']);
    $baseUnitId = (int)$db->lastInsertId();

    $db->prepare(
        'INSERT INTO inventory_units (restaurant_id, name, symbol, dimension, base_unit_id, conversion_to_base, is_base, decimal_places)
         VALUES (?, ?, ?, ?, ?, 0.001, 0, 3)'
    )->execute([$restaurantId, 'Test gram', 'tg' . $nonce, 'test_mass', $baseUnitId]);
    $gramUnitId = (int)$db->lastInsertId();

    $factor = inventory_conversion_to($db, $restaurantId, $gramUnitId, $baseUnitId);
    phase2_assert(abs($factor - 0.001) < 0.0000001, 'Unit conversion factor was incorrect.');

    $db->prepare(
        'INSERT INTO inventory_materials (restaurant_id, name, base_unit_id, minimum_quantity)
         VALUES (?, ?, ?, 2)'
    )->execute([$restaurantId, 'Phase 2 test material ' . $nonce, $baseUnitId]);
    $materialId = (int)$db->lastInsertId();

    $openingKey = hash('sha256', 'phase2-opening-' . $nonce);
    $firstOpening = inventory_apply_movement($db, $restaurantId, $materialId, 'OPENING_STOCK', 10, 100, 'TEST_OPENING', null, null, $openingKey, null, 'Transactional phase 2 test');
    $retryOpening = inventory_apply_movement($db, $restaurantId, $materialId, 'OPENING_STOCK', 10, 100, 'TEST_OPENING', null, null, $openingKey, null, 'Duplicate retry');
    phase2_assert(!$firstOpening['duplicate'] && $retryOpening['duplicate'], 'Opening stock idempotency did not prevent duplicate posting.');

    $db->prepare('INSERT INTO inventory_suppliers (restaurant_id, name) VALUES (?, ?)')->execute([$restaurantId, 'Phase 2 supplier ' . $nonce]);
    $supplierId = (int)$db->lastInsertId();
    $db->prepare(
        'INSERT INTO inventory_purchases (restaurant_id, supplier_id, purchase_date, status)
         VALUES (?, ?, UTC_DATE(), ?)'
    )->execute([$restaurantId, $supplierId, 'DRAFT']);
    $purchaseId = (int)$db->lastInsertId();
    $db->prepare(
        'INSERT INTO inventory_purchase_items (restaurant_id, purchase_id, material_id, unit_id, quantity, quantity_base, unit_cost, line_total)
         VALUES (?, ?, ?, ?, 10, 10, 200, 2000)'
    )->execute([$restaurantId, $purchaseId, $materialId, $baseUnitId]);

    $beforeReceipt = $db->prepare('SELECT quantity_base, inventory_value, average_cost FROM inventory_stock_balances WHERE restaurant_id = ? AND material_id = ?');
    $beforeReceipt->execute([$restaurantId, $materialId]);
    $balance = $beforeReceipt->fetch();
    phase2_assert(abs((float)$balance['quantity_base'] - 10) < 0.000001 && abs((float)$balance['average_cost'] - 100) < 0.000001, 'Draft purchase changed stock or weighted-average cost.');

    phase2_assert(inventory_receive_purchase($db, $restaurantId, $purchaseId, 0) === 1, 'Purchase was not received.');
    $beforeRetry = $db->prepare('SELECT quantity_base, inventory_value, average_cost FROM inventory_stock_balances WHERE restaurant_id = ? AND material_id = ?');
    $beforeRetry->execute([$restaurantId, $materialId]);
    $balance = $beforeRetry->fetch();
    phase2_assert(abs((float)$balance['quantity_base'] - 20) < 0.000001, 'Received purchase quantity was incorrect.');
    phase2_assert(abs((float)$balance['average_cost'] - 150) < 0.000001, 'Weighted-average cost calculation was incorrect.');

    $secondReceiveRejected = false;
    try {
        inventory_receive_purchase($db, $restaurantId, $purchaseId, 0);
    } catch (InvalidArgumentException) {
        $secondReceiveRejected = true;
    }
    phase2_assert($secondReceiveRejected, 'A received purchase could be posted a second time.');

    inventory_apply_movement(
        $db, $restaurantId, $materialId, 'CONSUMPTION', -2, 0, 'TEST_CONSUMPTION', 12, 55,
        hash('sha256', 'phase2-consumption-' . $nonce), null, 'Transactional consumption test'
    );
    $afterConsumption = $db->prepare('SELECT quantity_base, inventory_value, average_cost FROM inventory_stock_balances WHERE restaurant_id = ? AND material_id = ?');
    $afterConsumption->execute([$restaurantId, $materialId]);
    $balance = $afterConsumption->fetch();
    phase2_assert(abs((float)$balance['quantity_base'] - 18) < 0.000001, 'Consumption quantity was incorrect.');
    phase2_assert(abs((float)$balance['inventory_value'] - 2700) < 0.00001 && abs((float)$balance['average_cost'] - 150) < 0.000001, 'Consumption did not use the current weighted-average cost.');

    $db->prepare('INSERT INTO inventory_materials (restaurant_id, name, base_unit_id) VALUES (?, ?, ?)')
        ->execute([$restaurantId, 'Phase 2 shortage-cost test ' . $nonce, $baseUnitId]);
    $shortageMaterialId = (int)$db->lastInsertId();
    inventory_apply_movement($db, $restaurantId, $shortageMaterialId, 'OPENING_STOCK', 1, 10, 'TEST_SHORTAGE', null, null, hash('sha256', 'phase2-shortage-open-' . $nonce), null, 'Shortage average test');
    inventory_apply_movement($db, $restaurantId, $shortageMaterialId, 'CONSUMPTION', -2, 0, 'TEST_SHORTAGE', null, null, hash('sha256', 'phase2-shortage-consume-' . $nonce), null, 'Shortage average test');
    inventory_apply_movement($db, $restaurantId, $shortageMaterialId, 'PURCHASE', 1, 15, 'TEST_SHORTAGE', null, null, hash('sha256', 'phase2-shortage-receipt-' . $nonce), null, 'Exact shortage restoration');
    $shortageBalance = $db->prepare('SELECT quantity_base, inventory_value, average_cost FROM inventory_stock_balances WHERE restaurant_id = ? AND material_id = ?');
    $shortageBalance->execute([$restaurantId, $shortageMaterialId]);
    $balance = $shortageBalance->fetch();
    phase2_assert(abs((float)$balance['quantity_base']) < 0.000001 && abs((float)$balance['inventory_value']) < 0.000001 && abs((float)$balance['average_cost'] - 10) < 0.000001, 'Exact shortage restoration left an incorrect stock value or lost last known average.');
    inventory_apply_movement($db, $restaurantId, $shortageMaterialId, 'PURCHASE', 1, 20, 'TEST_SHORTAGE', null, null, hash('sha256', 'phase2-shortage-next-receipt-' . $nonce), null, 'Receipt after shortage restoration');
    $shortageBalance->execute([$restaurantId, $shortageMaterialId]);
    $balance = $shortageBalance->fetch();
    phase2_assert(abs((float)$balance['quantity_base'] - 1) < 0.000001 && abs((float)$balance['inventory_value'] - 20) < 0.000001 && abs((float)$balance['average_cost'] - 20) < 0.000001, 'New stock after a fully covered shortage had an incorrect valuation.');

    $crossTenantRejected = false;
    try {
        inventory_conversion_to($db, $otherRestaurantId, $baseUnitId, $baseUnitId);
    } catch (InvalidArgumentException) {
        $crossTenantRejected = true;
    }
    phase2_assert($crossTenantRejected, 'A unit from another restaurant was accepted.');

    $foreignKeyRejected = false;
    try {
        $db->prepare('INSERT INTO inventory_materials (restaurant_id, name, base_unit_id) VALUES (?, ?, ?)')
            ->execute([$otherRestaurantId, 'Cross-tenant test material ' . $nonce, $baseUnitId]);
    } catch (PDOException) {
        $foreignKeyRejected = true;
    }
    phase2_assert($foreignKeyRejected, 'The inventory schema allowed a cross-tenant unit reference.');

    $db->rollBack();
    echo "PASS: unit conversion, opening-stock retry, draft purchase isolation, one-time receipt, weighted-average costing, shortage recovery, consumption costing, and cross-tenant rejection.\n";
    echo "PASS: all temporary test records were rolled back; existing Restaurant SaaS rows were not modified.\n";
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $exception;
}
