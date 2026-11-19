<?php
declare(strict_types=1);

require_once __DIR__ . '/recipes-service.php';

function inventory_kot_worker_bootstrap(PDO $db): int
{
    $existing = $db->query('SELECT bootstrap_max_kot_item_id FROM inventory_kot_worker_state WHERE singleton_id = 1')->fetchColumn();
    if ($existing !== false) {
        throw new RuntimeException('The KOT worker is already bootstrapped at item ID ' . (int)$existing . '.');
    }
    $max = (int)$db->query('SELECT COALESCE(MAX(ki.id), 0) FROM pos_kot_items ki JOIN pos_kots k ON k.id = ki.kot_id JOIN orders o ON o.id = k.order_id AND o.restaurant_id = k.restaurant_id')->fetchColumn();
    $insert = $db->prepare('INSERT INTO inventory_kot_worker_state (singleton_id, bootstrap_max_kot_item_id, bootstrapped_at, bootstrapped_by) VALUES (1, ?, UTC_TIMESTAMP(), ?)');
    $insert->execute([$max, 'CLI']);
    return $max;
}

function inventory_kot_worker_state(PDO $db): ?array
{
    $row = $db->query('SELECT bootstrap_max_kot_item_id, bootstrapped_at FROM inventory_kot_worker_state WHERE singleton_id = 1')->fetch();
    return $row ?: null;
}

function inventory_process_kot_snapshot(PDO $db, array $event, bool $retryReview = false): string
{
    $restaurantId = (int)($event['restaurant_id'] ?? 0);
    $kotItemId = (int)($event['kot_item_id'] ?? 0);
    $orderId = (int)($event['order_id'] ?? 0);
    $orderItemId = isset($event['order_item_id']) ? (int)$event['order_item_id'] : null;
    $menuItemId = isset($event['menu_item_id']) ? (int)$event['menu_item_id'] : null;
    $action = (string)($event['event_action'] ?? '');
    $quantity = (float)($event['quantity'] ?? 0);
    $eventAt = (string)($event['event_at'] ?? '');
    if ($restaurantId <= 0 || $kotItemId <= 0 || $orderId <= 0 || !in_array($action, ['ADDED', 'CANCELLED'], true) || !is_finite($quantity) || $quantity <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $eventAt)) {
        throw new InvalidArgumentException('The source KOT event is invalid.');
    }
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) {
        $db->beginTransaction();
    }
    try {
        $existing = $db->prepare('SELECT * FROM inventory_kot_events WHERE restaurant_id = ? AND kot_item_id = ? LIMIT 1 FOR UPDATE');
        $existing->execute([$restaurantId, $kotItemId]);
        $existingEvent = $existing->fetch();
        if ($existingEvent) {
            if (!$retryReview || $existingEvent['processing_status'] !== 'REVIEW' || $existingEvent['event_action'] !== 'ADDED' || $action !== 'ADDED') {
                if ($ownsTransaction) $db->commit();
                return 'DUPLICATE';
            }
            $movementCheck = $db->prepare(
                'SELECT COUNT(*) FROM inventory_stock_movements
                 WHERE restaurant_id = ? AND source_type = ? AND source_id = ? AND movement_type = ?'
            );
            $movementCheck->execute([$restaurantId, 'KOT_ITEM', $kotItemId, 'CONSUMPTION']);
            if ((int)$movementCheck->fetchColumn() > 0) {
                throw new RuntimeException('This reviewed KOT line already has consumption movements; retry was stopped to prevent double deduction.');
            }
            $orderId = (int)$existingEvent['order_id'];
            $orderItemId = $existingEvent['order_item_id'] !== null ? (int)$existingEvent['order_item_id'] : null;
            $menuItemId = $existingEvent['menu_item_id'] !== null ? (int)$existingEvent['menu_item_id'] : null;
            $action = (string)$existingEvent['event_action'];
            $quantity = (float)$existingEvent['quantity'];
            $eventAt = (string)$existingEvent['event_at'];
            $event['item_name'] = $existingEvent['item_name'];
            $event['portion_label'] = $existingEvent['portion_label'];
        }

        $baseEvent = [
            $restaurantId, $kotItemId, $orderId, $orderItemId, $menuItemId, $action,
            mb_substr((string)($event['item_name'] ?? 'Unknown item'), 0, 150),
            ($event['portion_label'] ?? null) !== null ? mb_substr((string)$event['portion_label'], 0, 100) : null,
            $quantity,
        ];
        if ($action === 'CANCELLED') {
            $insertEvent = $db->prepare(
                'INSERT INTO inventory_kot_events
                 (restaurant_id, kot_item_id, order_id, order_item_id, menu_item_id, event_action, item_name, portion_label, quantity, event_at, processing_status, review_reason)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insertEvent->execute([...$baseEvent, $eventAt, 'REVIEW', 'KOT cancellation needs a staff decision.']);
            $eventId = (int)$db->lastInsertId();
            $remaining = $quantity;
            $insertReview = $db->prepare(
                'INSERT INTO inventory_kot_cancellation_reviews (restaurant_id, cancel_kot_item_id, order_id, order_item_id, cancelled_quantity, unmatched_quantity)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $insertReview->execute([$restaurantId, $kotItemId, $orderId, $orderItemId, $quantity, $quantity]);
            $reviewId = (int)$db->lastInsertId();
            if ($orderItemId !== null) {
                $sources = $db->prepare(
                    'SELECT e.kot_item_id, e.quantity, e.recipe_id
                     FROM inventory_kot_events e
                     WHERE e.restaurant_id = ? AND e.order_id = ? AND e.order_item_id = ?
                       AND e.event_action = ? AND e.processing_status = ? AND e.recipe_id IS NOT NULL AND e.kot_item_id < ?
                     ORDER BY e.kot_item_id'
                );
                $sources->execute([$restaurantId, $orderId, $orderItemId, 'ADDED', 'PROCESSED', $kotItemId]);
                $allocations = $db->prepare(
                    'SELECT COALESCE(SUM(a.portion_quantity), 0)
                     FROM inventory_kot_review_allocations a
                     WHERE a.restaurant_id = ? AND a.source_kot_item_id = ?'
                );
                $hasMovements = $db->prepare(
                    'SELECT COUNT(*) FROM inventory_stock_movements
                     WHERE restaurant_id = ? AND source_type = ? AND source_id = ? AND movement_type = ?'
                );
                $insertAllocation = $db->prepare(
                    'INSERT INTO inventory_kot_review_allocations (restaurant_id, review_id, source_kot_item_id, recipe_id, portion_quantity)
                     VALUES (?, ?, ?, ?, ?)'
                );
                foreach ($sources as $source) {
                    if ($remaining <= 0) break;
                    $sourceKotId = (int)$source['kot_item_id'];
                    $hasMovements->execute([$restaurantId, 'KOT_ITEM', $sourceKotId, 'CONSUMPTION']);
                    if (!(int)$hasMovements->fetchColumn()) continue;
                    $allocations->execute([$restaurantId, $sourceKotId]);
                    $alreadyAllocated = (float)$allocations->fetchColumn();
                    $available = max(0.0, (float)$source['quantity'] - $alreadyAllocated);
                    $allocated = min($available, $remaining);
                    if ($allocated <= 0) continue;
                    $insertAllocation->execute([$restaurantId, $reviewId, $sourceKotId, (int)$source['recipe_id'], $allocated]);
                    $remaining = round($remaining - $allocated, 6);
                }
            }
            $db->prepare('UPDATE inventory_kot_cancellation_reviews SET unmatched_quantity = ? WHERE id = ? AND restaurant_id = ?')
                ->execute([max(0, $remaining), $reviewId, $restaurantId]);
            if ($ownsTransaction) $db->commit();
            return 'REVIEW';
        }

        $status = 'REVIEW';
        $reason = null;
        $recipeId = null;
        if (!$menuItemId || !inventory_menu_item($db, $restaurantId, $menuItemId)) {
            $reason = 'The KOT line has no valid restaurant menu-item reference.';
        } else {
            $mapping = inventory_resolve_menu_mapping($db, $restaurantId, $menuItemId, $event['portion_label'] ?? null);
            if (!$mapping) {
                $reason = 'The saved option or portion label is missing or ambiguous.';
            } else {
                $recipe = inventory_current_recipe($db, $restaurantId, $menuItemId, (string)$mapping['key'], $eventAt);
                if (!$recipe) {
                    $reason = 'No effective recipe exists for this menu mapping at the KOT time.';
                } else {
                    $yield = (float)$recipe['yield_quantity'];
                    $lines = $db->prepare('SELECT id, material_id, quantity_base FROM inventory_recipe_ingredients WHERE restaurant_id = ? AND recipe_id = ? ORDER BY id');
                    $lines->execute([$restaurantId, (int)$recipe['id']]);
                    $ingredients = $lines->fetchAll();
                    if (!is_finite($yield) || $yield <= 0) {
                        $reason = 'The effective recipe has an invalid yield.';
                    } elseif (!$ingredients) {
                        $reason = 'The effective recipe has no ingredient lines.';
                    } else {
                        foreach ($ingredients as $ingredient) {
                            if (!inventory_material_has_known_cost($db, $restaurantId, (int)$ingredient['material_id'])) {
                                $reason = 'An ingredient has no verified opening-stock or purchase cost.';
                                break;
                            }
                            if (inventory_recipe_consumption_quantity((float)$ingredient['quantity_base'], $yield, $quantity) <= 0) {
                                $reason = 'An ingredient quantity is too small after recipe-yield conversion.';
                                break;
                            }
                        }
                        if ($reason === null) foreach ($ingredients as $ingredient) {
                            $deduct = inventory_recipe_consumption_quantity((float)$ingredient['quantity_base'], $yield, $quantity);
                            inventory_apply_movement(
                                $db, $restaurantId, (int)$ingredient['material_id'], 'CONSUMPTION', -$deduct, 0,
                                'KOT_ITEM', $kotItemId, (int)$ingredient['id'],
                                hash('sha256', 'kot:add:' . $restaurantId . ':' . $kotItemId . ':' . (int)$recipe['id'] . ':' . (int)$ingredient['id']),
                                null, 'KOT addition; recipe version ' . (int)$recipe['version'], $eventAt
                            );
                        }
                        if ($reason === null) {
                            $status = 'PROCESSED';
                            $recipeId = (int)$recipe['id'];
                        }
                    }
                }
            }
        }
        if ($existingEvent) {
            $previousReason = trim((string)($existingEvent['review_reason'] ?? ''));
            if ($status === 'PROCESSED' && $previousReason !== '') {
                $reason = 'Resolved after review: ' . mb_substr($previousReason, 0, 450);
            }
            $updateEvent = $db->prepare(
                'UPDATE inventory_kot_events SET recipe_id = ?, processing_status = ?, review_reason = ?
                 WHERE id = ? AND restaurant_id = ? AND event_action = ? AND processing_status = ?'
            );
            $updateEvent->execute([$recipeId, $status, $reason, (int)$existingEvent['id'], $restaurantId, 'ADDED', 'REVIEW']);
        } else {
            $insertEvent = $db->prepare(
                'INSERT INTO inventory_kot_events
                 (restaurant_id, kot_item_id, order_id, order_item_id, menu_item_id, event_action, item_name, portion_label, quantity, recipe_id, event_at, processing_status, review_reason)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insertEvent->execute([...$baseEvent, $recipeId, $eventAt, $status, $reason]);
        }
        if ($ownsTransaction) $db->commit();
        return $status;
    } catch (Throwable $exception) {
        if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
        throw $exception;
    }
}

function inventory_retry_kot_event(PDO $db, int $restaurantId, int $eventId): string
{
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) $db->beginTransaction();
    try {
        $query = $db->prepare(
            'SELECT * FROM inventory_kot_events
             WHERE id = ? AND restaurant_id = ? AND event_action = ? AND processing_status = ? FOR UPDATE'
        );
        $query->execute([$eventId, $restaurantId, 'ADDED', 'REVIEW']);
        $event = $query->fetch();
        if (!$event) throw new InvalidArgumentException('This KOT review is no longer pending. Refresh the page.');
        $status = inventory_process_kot_snapshot($db, $event, true);
        if ($ownsTransaction) $db->commit();
        return $status;
    } catch (Throwable $exception) {
        if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
        throw $exception;
    }
}

function inventory_save_kot_mapping_override_and_retry(PDO $db, int $restaurantId, int $actorId, int $eventId, string $mappingKey): string
{
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) $db->beginTransaction();
    try {
        $query = $db->prepare(
            'SELECT * FROM inventory_kot_events
             WHERE id = ? AND restaurant_id = ? AND event_action = ? AND processing_status = ? FOR UPDATE'
        );
        $query->execute([$eventId, $restaurantId, 'ADDED', 'REVIEW']);
        $event = $query->fetch();
        if (!$event || empty($event['menu_item_id']) || trim((string)($event['portion_label'] ?? '')) === '') {
            throw new InvalidArgumentException('This KOT line does not have a menu item and option label that can be mapped.');
        }
        $menuItem = inventory_menu_item($db, $restaurantId, (int)$event['menu_item_id']);
        if (!$menuItem) throw new InvalidArgumentException('The original menu item is no longer available in this restaurant.');
        $mapping = null;
        foreach (inventory_menu_mappings($db, $restaurantId, $menuItem) as $candidate) {
            if (hash_equals((string)$candidate['key'], $mappingKey)) {
                $mapping = $candidate;
                break;
            }
        }
        if (!$mapping) throw new InvalidArgumentException('Choose a current menu size or option for this KOT label.');

        $label = trim((string)$event['portion_label']);
        $save = $db->prepare(
            'INSERT INTO inventory_menu_mapping_overrides
             (restaurant_id, menu_item_id, normalized_label, observed_label, target_mapping_key, target_mapping_type,
              target_mapping_label, menu_item_option_id, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE observed_label = VALUES(observed_label), target_mapping_key = VALUES(target_mapping_key),
               target_mapping_type = VALUES(target_mapping_type), target_mapping_label = VALUES(target_mapping_label),
               menu_item_option_id = VALUES(menu_item_option_id), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP'
        );
        $save->execute([
            $restaurantId, (int)$event['menu_item_id'], inventory_normalize_mapping_label($label), mb_substr($label, 0, 100),
            $mapping['key'], $mapping['type'], $mapping['label'], $mapping['option_id'], $actorId, $actorId,
        ]);
        $status = inventory_process_kot_snapshot($db, $event, true);
        if ($ownsTransaction) $db->commit();
        return $status;
    } catch (Throwable $exception) {
        if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
        throw $exception;
    }
}

function inventory_record_manual_wastage(PDO $db, int $restaurantId, int $actorId, int $materialId, int $unitId, float $quantity, string $reason, string $notes, string $idempotencyKey): int
{
    $reasons = ['PREPARATION_WASTE', 'EXPIRED', 'DAMAGED', 'SPOILED', 'OTHER'];
    if (!in_array($reason, $reasons, true) || !is_finite($quantity) || $quantity <= 0 || mb_strlen($notes) > 1000) {
        throw new InvalidArgumentException('Check the wastage quantity, reason, and notes.');
    }
    if (!preg_match('/^[a-f0-9]{64}$/', $idempotencyKey)) throw new InvalidArgumentException('Invalid wastage request token. Refresh the page and try again.');
    $duplicate = $db->prepare('SELECT source_id FROM inventory_stock_movements WHERE restaurant_id = ? AND idempotency_key = ? AND source_type = ? LIMIT 1');
    $duplicate->execute([$restaurantId, $idempotencyKey, 'WASTAGE']);
    $duplicateWastageId = $duplicate->fetchColumn();
    if ($duplicateWastageId !== false) return (int)$duplicateWastageId;
    $materialQuery = $db->prepare('SELECT base_unit_id FROM inventory_materials WHERE id = ? AND restaurant_id = ? AND is_active = 1');
    $materialQuery->execute([$materialId, $restaurantId]);
    $baseUnitId = $materialQuery->fetchColumn();
    if (!$baseUnitId) throw new InvalidArgumentException('Select an active material from this restaurant.');
    $factor = inventory_conversion_to($db, $restaurantId, $unitId, (int)$baseUnitId);
    $baseQuantity = round($quantity * $factor, 6);
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO inventory_wastage (restaurant_id, record_type, reason_code, notes, created_by) VALUES (?, ?, ?, ?, ?)')
            ->execute([$restaurantId, 'MANUAL', $reason, $notes === '' ? null : $notes, $actorId]);
        $wastageId = (int)$db->lastInsertId();
        $movement = inventory_apply_movement(
            $db, $restaurantId, $materialId, 'WASTAGE', -$baseQuantity, 0, 'WASTAGE', $wastageId, null,
            $idempotencyKey, $actorId, $notes === '' ? $reason : $notes
        );
        $db->prepare(
            'INSERT INTO inventory_wastage_items (restaurant_id, wastage_id, material_id, unit_id, quantity, quantity_base, cost_amount, movement_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$restaurantId, $wastageId, $materialId, $unitId, $quantity, $baseQuantity, $movement['cost_amount'], $movement['id']]);
        $db->prepare('UPDATE inventory_wastage SET total_cost = ? WHERE id = ? AND restaurant_id = ?')
            ->execute([$movement['cost_amount'], $wastageId, $restaurantId]);
        if ($ownsTransaction) $db->commit();
        return $wastageId;
    } catch (Throwable $exception) {
        if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
        throw $exception;
    }
}

function inventory_resolve_kot_cancellation(PDO $db, int $restaurantId, int $actorId, int $reviewId, string $resolution, string $reason): void
{
    if (!in_array($resolution, ['RETURN_TO_STOCK', 'WASTAGE'], true) || trim($reason) === '' || mb_strlen($reason) > 1000) {
        throw new InvalidArgumentException('Choose a resolution and enter a short explanation.');
    }
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) $db->beginTransaction();
    try {
        $query = $db->prepare('SELECT * FROM inventory_kot_cancellation_reviews WHERE id = ? AND restaurant_id = ? FOR UPDATE');
        $query->execute([$reviewId, $restaurantId]);
        $review = $query->fetch();
        if (!$review || $review['status'] !== 'PENDING') throw new InvalidArgumentException('This cancellation review is no longer pending.');
        if ($resolution === 'RETURN_TO_STOCK' && (float)$review['unmatched_quantity'] > 0.000001) {
            throw new InvalidArgumentException('The full cancelled quantity is not linked to tracked recipe consumption. Return is not safe; resolve as wastage instead.');
        }
        $allocationQuery = $db->prepare(
            'SELECT a.source_kot_item_id, a.recipe_id, a.portion_quantity, e.quantity AS source_quantity
             FROM inventory_kot_review_allocations a
             JOIN inventory_kot_events e ON e.restaurant_id = a.restaurant_id AND e.kot_item_id = a.source_kot_item_id
             WHERE a.restaurant_id = ? AND a.review_id = ? ORDER BY a.id'
        );
        $allocationQuery->execute([$restaurantId, $reviewId]);
        $allocations = $allocationQuery->fetchAll();
        if ($resolution === 'RETURN_TO_STOCK' && !$allocations) throw new InvalidArgumentException('There is no tracked stock consumption to return.');

        if ($resolution === 'WASTAGE') {
            $db->prepare(
                'INSERT INTO inventory_wastage (restaurant_id, record_type, reason_code, source_kot_item_id, unmatched_quantity, notes, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$restaurantId, 'KOT_CANCELLATION', 'KOT_CANCELLATION', (int)$review['cancel_kot_item_id'], $review['unmatched_quantity'], $reason, $actorId]);
            $wastageId = (int)$db->lastInsertId();
            $totalCost = 0.0;
            foreach ($allocations as $allocation) {
                $ratio = (float)$allocation['portion_quantity'] / max(0.000001, (float)$allocation['source_quantity']);
                $movements = $db->prepare(
                    'SELECT sm.id, sm.material_id, sm.quantity_base, sm.cost_amount,
                            ri.unit_id, ri.quantity AS ingredient_quantity
                     FROM inventory_stock_movements sm
                     JOIN inventory_recipe_ingredients ri ON ri.id = sm.source_line_id AND ri.restaurant_id = sm.restaurant_id
                     WHERE sm.restaurant_id = ? AND sm.movement_type = ? AND sm.source_type = ? AND sm.source_id = ? ORDER BY sm.id'
                );
                $movements->execute([$restaurantId, 'CONSUMPTION', 'KOT_ITEM', (int)$allocation['source_kot_item_id']]);
                foreach ($movements as $movement) {
                    $baseQuantity = round(abs((float)$movement['quantity_base']) * $ratio, 6);
                    $cost = round((float)$movement['cost_amount'] * $ratio, 6);
                    $totalCost += $cost;
                    $db->prepare(
                        'INSERT INTO inventory_wastage_items
                         (restaurant_id, wastage_id, material_id, unit_id, quantity, quantity_base, cost_amount, source_movement_id)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                    )->execute([
                        $restaurantId, $wastageId, (int)$movement['material_id'], (int)$movement['unit_id'],
                        round((float)$movement['ingredient_quantity'] * (float)$allocation['portion_quantity'], 6),
                        $baseQuantity, $cost, (int)$movement['id'],
                    ]);
                }
            }
            $db->prepare('UPDATE inventory_wastage SET total_cost = ? WHERE id = ? AND restaurant_id = ?')->execute([$totalCost, $wastageId, $restaurantId]);
        } else {
            foreach ($allocations as $allocation) {
                $ratio = (float)$allocation['portion_quantity'] / max(0.000001, (float)$allocation['source_quantity']);
                $movements = $db->prepare(
                    'SELECT id, material_id, source_line_id, quantity_base, cost_amount, inventory_value_delta
                     FROM inventory_stock_movements
                     WHERE restaurant_id = ? AND movement_type = ? AND source_type = ? AND source_id = ? ORDER BY id'
                );
                $movements->execute([$restaurantId, 'CONSUMPTION', 'KOT_ITEM', (int)$allocation['source_kot_item_id']]);
                foreach ($movements as $movement) {
                    $quantityBase = round(abs((float)$movement['quantity_base']) * $ratio, 6);
                    if ($quantityBase <= 0) continue;
                    inventory_apply_movement(
                        $db, $restaurantId, (int)$movement['material_id'], 'RETURN', $quantityBase, 0,
                        'KOT_RETURN', (int)$review['cancel_kot_item_id'], (int)$movement['source_line_id'],
                        hash('sha256', 'kot:return:' . $restaurantId . ':' . (int)$review['cancel_kot_item_id'] . ':' . (int)$movement['id']),
                        $actorId, $reason, null, round((float)$movement['cost_amount'] * $ratio, 6),
                        round(abs((float)$movement['inventory_value_delta']) * $ratio, 6)
                    );
                }
            }
        }
        $update = $db->prepare(
            'UPDATE inventory_kot_cancellation_reviews
             SET status = ?, resolution = ?, resolution_reason = ?, resolved_by = ?, resolved_at = UTC_TIMESTAMP()
             WHERE id = ? AND restaurant_id = ? AND status = ?'
        );
        $update->execute(['RESOLVED', $resolution, trim($reason), $actorId, $reviewId, $restaurantId, 'PENDING']);
        if ($update->rowCount() !== 1) throw new RuntimeException('The cancellation review changed while being resolved.');
        $db->prepare('UPDATE inventory_kot_events SET processing_status = ?, review_reason = ? WHERE restaurant_id = ? AND kot_item_id = ? AND event_action = ?')
            ->execute(['PROCESSED', 'Cancellation resolved as ' . $resolution, $restaurantId, (int)$review['cancel_kot_item_id'], 'CANCELLED']);
        if ($ownsTransaction) $db->commit();
    } catch (Throwable $exception) {
        if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
        throw $exception;
    }
}
