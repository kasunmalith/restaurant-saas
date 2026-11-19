<?php
declare(strict_types=1);

function inventory_unit(PDO $db, int $restaurantId, int $unitId, bool $lock = false): ?array
{
    $statement = $db->prepare(
        'SELECT id, restaurant_id, name, symbol, dimension, base_unit_id, conversion_to_base, is_base, decimal_places, is_active
         FROM inventory_units WHERE id = ? AND restaurant_id = ?' . ($lock ? ' FOR UPDATE' : '')
    );
    $statement->execute([$unitId, $restaurantId]);
    $unit = $statement->fetch();
    return $unit ?: null;
}

function inventory_conversion_to(PDO $db, int $restaurantId, int $unitId, int $baseUnitId): float
{
    $unit = inventory_unit($db, $restaurantId, $unitId);
    $base = inventory_unit($db, $restaurantId, $baseUnitId);
    if (!$unit || !$base || !(int)$base['is_base'] || !$base['is_active'] || $unit['dimension'] !== $base['dimension'] || !$unit['is_active']) {
        throw new InvalidArgumentException('Select an active unit that belongs to the same measurement type as this material.');
    }
    if ((int)$unit['is_base']) {
        if ((int)$unit['id'] !== $baseUnitId) {
            throw new InvalidArgumentException('The selected base unit does not match this material.');
        }
        return 1.0;
    }
    if ((int)$unit['base_unit_id'] !== $baseUnitId) {
        throw new InvalidArgumentException('The selected unit converts to a different base unit.');
    }
    return (float)$unit['conversion_to_base'];
}

function inventory_material_has_known_cost(PDO $db, int $restaurantId, int $materialId): bool
{
    // A balance row alone is not proof that a cost was ever established.
    $query = $db->prepare(
        "SELECT b.average_cost,
                EXISTS(SELECT 1 FROM inventory_stock_movements sm
                       WHERE sm.restaurant_id = b.restaurant_id AND sm.material_id = b.material_id
                         AND sm.movement_type IN ('OPENING_STOCK','PURCHASE')
                         AND sm.quantity_base > 0 AND sm.unit_cost > 0) AS has_cost_source
         FROM inventory_stock_balances b
         WHERE b.restaurant_id = ? AND b.material_id = ?"
    );
    $query->execute([$restaurantId, $materialId]);
    $row = $query->fetch();
    return $row !== false && (float)$row['average_cost'] > 0 && (int)$row['has_cost_source'] === 1;
}

function inventory_apply_movement(
    PDO $db,
    int $restaurantId,
    int $materialId,
    string $type,
    float $signedQuantity,
    float $inputUnitCost,
    string $sourceType,
    ?int $sourceId,
    ?int $sourceLineId,
    string $idempotencyKey,
    ?int $actorUserId,
    ?string $notes = null,
    ?string $effectiveAtUtc = null,
    ?float $overrideCostAmount = null,
    ?float $overrideValueDelta = null
): array {
    $allowedTypes = ['OPENING_STOCK', 'PURCHASE', 'CONSUMPTION', 'WASTAGE', 'COUNT_ADJUSTMENT', 'REVERSAL', 'RETURN'];
    if (!in_array($type, $allowedTypes, true) || !is_finite($signedQuantity) || !is_finite($inputUnitCost) || $inputUnitCost < 0) {
        throw new InvalidArgumentException('Invalid stock movement.');
    }
    if ($signedQuantity == 0.0 && $overrideCostAmount === null) {
        throw new InvalidArgumentException('A stock movement must change stock quantity.');
    }
    if (!preg_match('/^[a-f0-9]{64}$/', $idempotencyKey)) {
        throw new InvalidArgumentException('Invalid stock movement idempotency key.');
    }

    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) {
        $db->beginTransaction();
    }
    try {
        $material = $db->prepare('SELECT id FROM inventory_materials WHERE id = ? AND restaurant_id = ? LIMIT 1');
        $material->execute([$materialId, $restaurantId]);
        if (!$material->fetchColumn()) {
            throw new InvalidArgumentException('The selected material does not belong to this restaurant.');
        }

        $db->prepare(
            'INSERT INTO inventory_stock_balances (restaurant_id, material_id, quantity_base, inventory_value, average_cost)
             VALUES (?, ?, 0, 0, 0)
             ON DUPLICATE KEY UPDATE material_id = VALUES(material_id)'
        )->execute([$restaurantId, $materialId]);
        $balanceQuery = $db->prepare(
            'SELECT quantity_base, inventory_value, average_cost FROM inventory_stock_balances
             WHERE restaurant_id = ? AND material_id = ? FOR UPDATE'
        );
        $balanceQuery->execute([$restaurantId, $materialId]);
        $balance = $balanceQuery->fetch();

        $duplicate = $db->prepare('SELECT id, quantity_base, cost_amount FROM inventory_stock_movements WHERE restaurant_id = ? AND idempotency_key = ? LIMIT 1');
        $duplicate->execute([$restaurantId, $idempotencyKey]);
        $existing = $duplicate->fetch();
        if ($existing) {
            if ($ownsTransaction) {
                $db->commit();
            }
            return ['id' => (int)$existing['id'], 'duplicate' => true, 'quantity_base' => (float)$existing['quantity_base'], 'cost_amount' => (float)$existing['cost_amount']];
        }

        $quantity = round($signedQuantity, 6);
        if ($overrideCostAmount !== null) {
            $costAmount = round(max(0, $overrideCostAmount), 6);
            $unitCost = abs($quantity) > 0 ? round($costAmount / abs($quantity), 6) : round($inputUnitCost, 6);
            $valueDelta = $overrideValueDelta === null
                ? ($quantity > 0 ? $costAmount : ($quantity < 0 ? -$costAmount : 0.0))
                : round($overrideValueDelta, 6);
        } elseif ($quantity > 0) {
            $unitCost = round($inputUnitCost, 6);
            $costAmount = round($quantity * $unitCost, 6);
            $valueDelta = $costAmount;
        } else {
            $available = (float)$balance['quantity_base'];
            $value = (float)$balance['inventory_value'];
            $unitCost = $available > 0 ? max(0.0, $value / $available) : max(0.0, (float)$balance['average_cost']);
            $costAmount = round(abs($quantity) * $unitCost, 6);
            $valueDelta = -$costAmount;
        }

        $priorQuantity = (float)$balance['quantity_base'];
        if ($quantity > 0 && $overrideCostAmount === null && $priorQuantity <= 0 && ($priorQuantity + $quantity) <= 0) {
            // A receipt that only covers an existing shortage must not leave
            // phantom positive/negative inventory value when quantity is zero.
            $targetValue = round(($priorQuantity + $quantity) * max(0.0, (float)$balance['average_cost']), 6);
            $valueDelta = round($targetValue - (float)$balance['inventory_value'], 6);
        }

        $effectiveAtUtc ??= gmdate('Y-m-d H:i:s');
        $insert = $db->prepare(
            'INSERT INTO inventory_stock_movements
             (restaurant_id, material_id, movement_type, quantity_base, unit_cost, cost_amount, inventory_value_delta,
              source_type, source_id, source_line_id, idempotency_key, actor_user_id, notes, effective_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $restaurantId, $materialId, $type, $quantity, $unitCost, $costAmount, $valueDelta,
            substr($sourceType, 0, 40), $sourceId, $sourceLineId, $idempotencyKey, $actorUserId,
            $notes !== null ? mb_substr($notes, 0, 1000) : null, $effectiveAtUtc,
        ]);
        $movementId = (int)$db->lastInsertId();

        $newQuantity = round((float)$balance['quantity_base'] + $quantity, 6);
        $newValue = round((float)$balance['inventory_value'] + $valueDelta, 6);
        // Keep the last weighted average when stock reaches or falls below zero.
        // This provides a defensible cost for shortages until receipts restore stock.
        $average = $newQuantity > 0 ? round($newValue / $newQuantity, 6) : max(0.0, (float)$balance['average_cost']);
        $db->prepare(
            'UPDATE inventory_stock_balances SET quantity_base = ?, inventory_value = ?, average_cost = ?
             WHERE restaurant_id = ? AND material_id = ?'
        )->execute([$newQuantity, $newValue, $average, $restaurantId, $materialId]);

        if ($ownsTransaction) {
            $db->commit();
        }
        return ['id' => $movementId, 'duplicate' => false, 'quantity_base' => $quantity, 'cost_amount' => $costAmount];
    } catch (Throwable $exception) {
        if ($ownsTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}

function inventory_receive_purchase(PDO $db, int $restaurantId, int $purchaseId, int $actorUserId): int
{
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) {
        $db->beginTransaction();
    }
    try {
        $query = $db->prepare('SELECT id, status FROM inventory_purchases WHERE id = ? AND restaurant_id = ? FOR UPDATE');
        $query->execute([$purchaseId, $restaurantId]);
        $purchase = $query->fetch();
        if (!$purchase) {
            throw new InvalidArgumentException('Purchase not found for this restaurant.');
        }
        if ($purchase['status'] !== 'DRAFT') {
            throw new InvalidArgumentException('Only a draft purchase can be received.');
        }
        $lines = $db->prepare(
            'SELECT id, material_id, quantity_base, line_total FROM inventory_purchase_items
             WHERE purchase_id = ? AND restaurant_id = ? ORDER BY id'
        );
        $lines->execute([$purchaseId, $restaurantId]);
        $items = $lines->fetchAll();
        if (!$items) {
            throw new InvalidArgumentException('Add at least one purchase line before receiving this purchase.');
        }
        foreach ($items as $item) {
            $baseQuantity = (float)$item['quantity_base'];
            $totalCost = (float)$item['line_total'];
            $key = hash('sha256', 'purchase:' . $restaurantId . ':' . $purchaseId . ':' . $item['id']);
            inventory_apply_movement(
                $db, $restaurantId, (int)$item['material_id'], 'PURCHASE', $baseQuantity,
                $baseQuantity > 0 ? $totalCost / $baseQuantity : 0.0, 'PURCHASE', $purchaseId,
                (int)$item['id'], $key, $actorUserId, 'Purchase received'
            );
        }
        $db->prepare('UPDATE inventory_purchases SET status = ?, received_at = UTC_TIMESTAMP(), received_by = ? WHERE id = ? AND restaurant_id = ?')
            ->execute(['RECEIVED', $actorUserId, $purchaseId, $restaurantId]);
        if ($ownsTransaction) {
            $db->commit();
        }
        return count($items);
    } catch (Throwable $exception) {
        if ($ownsTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}

function inventory_stock_snapshot(PDO $db, int $restaurantId): array
{
    $query = $db->prepare(
        'SELECT m.id, m.name, m.sku, m.minimum_quantity, m.is_active, u.symbol AS base_unit_symbol,
                COALESCE(b.quantity_base, 0) AS quantity_base, COALESCE(b.average_cost, 0) AS average_cost,
                COALESCE(b.inventory_value, 0) AS inventory_value
         FROM inventory_materials m
         INNER JOIN inventory_units u ON u.id = m.base_unit_id AND u.restaurant_id = m.restaurant_id
         LEFT JOIN inventory_stock_balances b ON b.material_id = m.id AND b.restaurant_id = m.restaurant_id
         WHERE m.restaurant_id = ?
         ORDER BY m.is_active DESC, m.name'
    );
    $query->execute([$restaurantId]);
    return $query->fetchAll();
}

function inventory_unit_list(PDO $db, int $restaurantId, bool $activeOnly = true): array
{
    $query = $db->prepare(
        'SELECT u.id, u.name, u.symbol, u.dimension, u.base_unit_id, u.conversion_to_base, u.is_base,
                u.decimal_places, u.is_active, b.symbol AS base_symbol
         FROM inventory_units u
         LEFT JOIN inventory_units b ON b.id = u.base_unit_id AND b.restaurant_id = u.restaurant_id
         WHERE u.restaurant_id = ?' . ($activeOnly ? ' AND u.is_active = 1' : '') . '
         ORDER BY u.dimension, u.is_base DESC, u.name'
    );
    $query->execute([$restaurantId]);
    return $query->fetchAll();
}
