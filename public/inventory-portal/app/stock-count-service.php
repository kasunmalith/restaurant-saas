<?php
declare(strict_types=1);

require_once __DIR__ . '/inventory-service.php';

function inventory_create_stock_count(PDO $db, int $restaurantId, int $actorId, string $notes): int
{
    if (mb_strlen($notes) > 1000) throw new InvalidArgumentException('Count notes cannot exceed 1,000 characters.');
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) $db->beginTransaction();
    try {
        $materials = $db->prepare('SELECT id, base_unit_id FROM inventory_materials WHERE restaurant_id = ? AND is_active = 1 ORDER BY id');
        $materials->execute([$restaurantId]);
        $materialRows = $materials->fetchAll();
        if (!$materialRows) throw new InvalidArgumentException('Add at least one active material before starting a count.');
        $ensureBalance = $db->prepare(
            'INSERT INTO inventory_stock_balances (restaurant_id, material_id, quantity_base, inventory_value, average_cost)
             VALUES (?, ?, 0, 0, 0) ON DUPLICATE KEY UPDATE material_id = VALUES(material_id)'
        );
        foreach ($materialRows as $material) $ensureBalance->execute([$restaurantId, (int)$material['id']]);
        $snapshotQuery = $db->prepare(
            'SELECT m.id, m.base_unit_id, b.quantity_base, b.average_cost,
                    COALESCE((SELECT MAX(sm.id) FROM inventory_stock_movements sm
                              WHERE sm.restaurant_id = m.restaurant_id AND sm.material_id = m.id), 0) AS snapshot_movement_id
             FROM inventory_materials m
             JOIN inventory_stock_balances b ON b.material_id = m.id AND b.restaurant_id = m.restaurant_id
             WHERE m.restaurant_id = ? AND m.is_active = 1 ORDER BY m.id FOR UPDATE'
        );
        $snapshotQuery->execute([$restaurantId]);
        $snapshots = $snapshotQuery->fetchAll();
        $countAt = (string)$db->query('SELECT UTC_TIMESTAMP()')->fetchColumn();
        $db->prepare('INSERT INTO inventory_stock_counts (restaurant_id, count_at, notes, created_by) VALUES (?, ?, ?, ?)')
            ->execute([$restaurantId, $countAt, $notes === '' ? null : $notes, $actorId]);
        $countId = (int)$db->lastInsertId();
        $insertLine = $db->prepare(
            'INSERT INTO inventory_stock_count_items
             (restaurant_id, count_id, material_id, unit_id, book_quantity_base, book_average_cost, snapshot_movement_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($snapshots as $snapshot) {
            $insertLine->execute([$restaurantId, $countId, (int)$snapshot['id'], (int)$snapshot['base_unit_id'], $snapshot['quantity_base'], $snapshot['average_cost'], $snapshot['snapshot_movement_id']]);
        }
        if ($ownsTransaction) $db->commit();
        return $countId;
    } catch (Throwable $exception) {
        if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
        throw $exception;
    }
}

function inventory_save_stock_count_values(PDO $db, int $restaurantId, int $countId, array $lineIds, array $physicalValues): void
{
    if (!$lineIds || count($lineIds) !== count($physicalValues) || count($lineIds) > 1000) {
        throw new InvalidArgumentException('Submit a physical quantity for each stock-count line.');
    }
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) $db->beginTransaction();
    try {
        $header = $db->prepare('SELECT status FROM inventory_stock_counts WHERE id = ? AND restaurant_id = ? FOR UPDATE');
        $header->execute([$countId, $restaurantId]);
        if ($header->fetchColumn() !== 'DRAFT') throw new InvalidArgumentException('Only a draft stock count can be edited.');
        $linesQuery = $db->prepare('SELECT id, book_quantity_base FROM inventory_stock_count_items WHERE count_id = ? AND restaurant_id = ? ORDER BY id FOR UPDATE');
        $linesQuery->execute([$countId, $restaurantId]);
        $stored = $linesQuery->fetchAll();
        if (count($stored) !== count($lineIds)) throw new InvalidArgumentException('The stock-count lines changed. Refresh and enter the quantities again.');
        $submitted = [];
        foreach ($lineIds as $index => $lineId) {
            $id = filter_var($lineId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$id || isset($submitted[$id]) || !is_numeric($physicalValues[$index]) || !is_finite((float)$physicalValues[$index]) || (float)$physicalValues[$index] < 0) {
                throw new InvalidArgumentException('Physical quantities must be valid non-negative numbers.');
            }
            $submitted[$id] = round((float)$physicalValues[$index], 6);
        }
        $update = $db->prepare('UPDATE inventory_stock_count_items SET physical_quantity_base = ?, variance_base = ? - book_quantity_base WHERE id = ? AND count_id = ? AND restaurant_id = ?');
        foreach ($stored as $line) {
            $id = (int)$line['id'];
            if (!array_key_exists($id, $submitted)) throw new InvalidArgumentException('A stock-count line was omitted.');
            $update->execute([$submitted[$id], $submitted[$id], $id, $countId, $restaurantId]);
        }
        if ($ownsTransaction) $db->commit();
    } catch (Throwable $exception) {
        if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
        throw $exception;
    }
}

function inventory_post_stock_count(PDO $db, int $restaurantId, int $actorId, int $countId): int
{
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) $db->beginTransaction();
    try {
        $headerQuery = $db->prepare('SELECT status, count_at FROM inventory_stock_counts WHERE id = ? AND restaurant_id = ? FOR UPDATE');
        $headerQuery->execute([$countId, $restaurantId]);
        $header = $headerQuery->fetch();
        if (!$header || $header['status'] !== 'DRAFT') throw new InvalidArgumentException('Only a draft stock count can be posted.');
        $linesQuery = $db->prepare('SELECT id, material_id, unit_id, book_quantity_base, book_average_cost, snapshot_movement_id, physical_quantity_base FROM inventory_stock_count_items WHERE count_id = ? AND restaurant_id = ? ORDER BY id FOR UPDATE');
        $linesQuery->execute([$countId, $restaurantId]);
        $lines = $linesQuery->fetchAll();
        if (!$lines) throw new InvalidArgumentException('This stock count has no material lines.');
        $balanceQuery = $db->prepare(
            'SELECT quantity_base, average_cost FROM inventory_stock_balances
             WHERE restaurant_id = ? AND material_id = ? FOR UPDATE'
        );
        $movementQuery = $db->prepare('SELECT COALESCE(MAX(id), 0) FROM inventory_stock_movements WHERE restaurant_id = ? AND material_id = ?');
        // Lock and verify every balance before writing any count movement. A changed
        // book quantity or cost requires a fresh physical count rather than a guess.
        foreach ($lines as $line) {
            $balanceQuery->execute([$restaurantId, (int)$line['material_id']]);
            $current = $balanceQuery->fetch();
            $movementQuery->execute([$restaurantId, (int)$line['material_id']]);
            $latestMovementId = (int)$movementQuery->fetchColumn();
            if (!$current
                || $line['snapshot_movement_id'] === null
                || $latestMovementId !== (int)$line['snapshot_movement_id']
                || abs((float)$current['quantity_base'] - (float)$line['book_quantity_base']) > 0.000001
                || abs((float)$current['average_cost'] - (float)$line['book_average_cost']) > 0.000001) {
                throw new InvalidArgumentException('Stock changed since this count was created. Start a new count and recount before posting.');
            }
        }
        foreach ($lines as $line) {
            if ($line['physical_quantity_base'] === null) throw new InvalidArgumentException('Enter a physical quantity for every material before posting.');
            $variance = round((float)$line['physical_quantity_base'] - (float)$line['book_quantity_base'], 6);
            $db->prepare('UPDATE inventory_stock_count_items SET variance_base = ? WHERE id = ? AND count_id = ? AND restaurant_id = ?')
                ->execute([$variance, (int)$line['id'], $countId, $restaurantId]);
            if ($variance == 0.0) continue;
            inventory_apply_movement(
                $db, $restaurantId, (int)$line['material_id'], 'COUNT_ADJUSTMENT', $variance,
                max(0.0, (float)$line['book_average_cost']), 'STOCK_COUNT', $countId, (int)$line['id'],
                hash('sha256', 'stock-count:' . $restaurantId . ':' . $countId . ':' . (int)$line['id']),
                $actorId, 'Physical count adjustment', (string)$header['count_at']
            );
        }
        $update = $db->prepare('UPDATE inventory_stock_counts SET status = ?, posted_by = ?, posted_at = UTC_TIMESTAMP() WHERE id = ? AND restaurant_id = ? AND status = ?');
        $update->execute(['POSTED', $actorId, $countId, $restaurantId, 'DRAFT']);
        if ($update->rowCount() !== 1) throw new RuntimeException('The stock count could not be posted atomically.');
        if ($ownsTransaction) $db->commit();
        return count($lines);
    } catch (Throwable $exception) {
        if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
        throw $exception;
    }
}
