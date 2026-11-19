<?php
declare(strict_types=1);

require_once __DIR__ . '/inventory-service.php';

function inventory_recipe_consumption_quantity(float $ingredientQuantityBase, float $yieldQuantity, float $orderedQuantity): float
{
    if (!is_finite($ingredientQuantityBase) || !is_finite($yieldQuantity) || !is_finite($orderedQuantity)
        || $ingredientQuantityBase <= 0 || $yieldQuantity <= 0 || $orderedQuantity <= 0) {
        throw new InvalidArgumentException('Recipe yield, ingredient quantity, and order quantity must be positive.');
    }
    return round(($ingredientQuantityBase / $yieldQuantity) * $orderedQuantity, 6);
}

function inventory_menu_item(PDO $db, int $restaurantId, int $menuItemId): ?array
{
    $query = $db->prepare(
        'SELECT id, restaurant_id, category_id, name, price, is_portioned, half_price, full_price, is_active, is_available
         FROM menu_items WHERE id = ? AND restaurant_id = ? LIMIT 1'
    );
    $query->execute([$menuItemId, $restaurantId]);
    $item = $query->fetch();
    return $item ?: null;
}

function inventory_menu_mappings(PDO $db, int $restaurantId, array $menuItem): array
{
    $itemId = (int)$menuItem['id'];
    $mappings = [[
        'key' => 'BASE',
        'type' => 'BASE',
        'option_id' => null,
        'label' => 'Standard',
        'price' => (float)$menuItem['price'],
    ]];

    if ((int)$menuItem['is_portioned'] === 1) {
        if ($menuItem['half_price'] !== null) {
            $mappings[] = ['key' => 'PORTION:HALF', 'type' => 'PORTION', 'option_id' => null, 'label' => 'Half', 'price' => (float)$menuItem['half_price']];
        }
        if ($menuItem['full_price'] !== null) {
            $mappings[] = ['key' => 'PORTION:FULL', 'type' => 'PORTION', 'option_id' => null, 'label' => 'Full', 'price' => (float)$menuItem['full_price']];
        }
    }

    $options = $db->prepare(
        'SELECT id, name, price FROM menu_item_options WHERE restaurant_id = ? AND menu_item_id = ? ORDER BY sort_order, id'
    );
    $options->execute([$restaurantId, $itemId]);
    foreach ($options as $option) {
        $mappings[] = [
            'key' => 'OPTION:' . (int)$option['id'],
            'type' => 'OPTION',
            'option_id' => (int)$option['id'],
            'label' => (string)$option['name'],
            'price' => (float)$option['price'],
        ];
    }
    return $mappings;
}

function inventory_resolve_menu_mapping(PDO $db, int $restaurantId, int $menuItemId, ?string $portion): ?array
{
    $item = inventory_menu_item($db, $restaurantId, $menuItemId);
    if (!$item) {
        return null;
    }
    $mappings = inventory_menu_mappings($db, $restaurantId, $item);
    $portionText = trim((string)$portion);
    if ($portionText !== '') {
        $overrideQuery = $db->prepare(
            'SELECT target_mapping_key, target_mapping_type, menu_item_option_id
             FROM inventory_menu_mapping_overrides
             WHERE restaurant_id = ? AND menu_item_id = ? AND normalized_label = ? LIMIT 1'
        );
        $overrideQuery->execute([$restaurantId, $menuItemId, inventory_normalize_mapping_label($portionText)]);
        $override = $overrideQuery->fetch();
        if ($override) {
            foreach ($mappings as $mapping) {
                if ((string)$mapping['key'] === (string)$override['target_mapping_key']
                    && (string)$mapping['type'] === (string)$override['target_mapping_type']
                    && (($mapping['option_id'] === null && $override['menu_item_option_id'] === null)
                        || (int)$mapping['option_id'] === (int)$override['menu_item_option_id'])) {
                    return $mapping;
                }
            }
        }
    }
    return inventory_resolve_mapping_label($mappings, $portion);
}

function inventory_normalize_mapping_label(string $label): string
{
    $normalized = preg_replace('/\s+/', ' ', trim($label)) ?? trim($label);
    return mb_strtolower($normalized);
}

function inventory_resolve_mapping_label(array $mappings, ?string $portion): ?array
{
    if (!$mappings) {
        return null;
    }
    $portionText = trim((string)$portion);
    if ($portionText === '') {
        return $mappings[0];
    }

    $exactMatches = array_values(array_filter($mappings, static fn(array $mapping): bool => mb_strtolower(trim((string)$mapping['label'])) === mb_strtolower($portionText)));
    if (count($exactMatches) === 1) {
        return $exactMatches[0];
    }
    if (count($exactMatches) > 1) {
        return null;
    }
    $normalized = mb_strtolower(preg_replace('/\s+/', ' ', $portionText) ?? $portionText);
    $normalized = preg_replace('/\s+(portion|size)$/', '', $normalized) ?? $normalized;
    if (in_array($normalized, ['half', 'half portion', 'half size'], true)) {
        foreach ($mappings as $mapping) {
            if ($mapping['key'] === 'PORTION:HALF') {
                return $mapping;
            }
        }
    }
    if (in_array($normalized, ['full', 'full portion', 'full size'], true)) {
        foreach ($mappings as $mapping) {
            if ($mapping['key'] === 'PORTION:FULL') {
                return $mapping;
            }
        }
    }
    if (in_array($normalized, ['base', 'standard', 'regular', 'default'], true) && $mappings[0]['key'] === 'BASE') {
        return $mappings[0];
    }
    return null;
}

function inventory_save_recipe(PDO $db, int $restaurantId, int $actorId, int $menuItemId, string $mappingKey, string $effectiveFromUtc, float $yieldQuantity, string $yieldUnitLabel, string $notes, array $ingredientInput): int
{
    $menuItem = inventory_menu_item($db, $restaurantId, $menuItemId);
    if (!$menuItem || !(int)$menuItem['is_active']) {
        throw new InvalidArgumentException('Select an active menu item belonging to this restaurant.');
    }
    $mapping = null;
    foreach (inventory_menu_mappings($db, $restaurantId, $menuItem) as $candidate) {
        if (hash_equals((string)$candidate['key'], $mappingKey)) {
            $mapping = $candidate;
            break;
        }
    }
    if (!$mapping) {
        throw new InvalidArgumentException('The selected menu option no longer exists. Refresh and try again.');
    }
    if ($yieldQuantity <= 0 || !is_finite($yieldQuantity) || $yieldUnitLabel === '' || mb_strlen($yieldUnitLabel) > 40 || mb_strlen($notes) > 1000) {
        throw new InvalidArgumentException('Check the recipe yield and notes.');
    }
    $effective = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $effectiveFromUtc, new DateTimeZone('UTC'));
    if (!$effective || $effective->format('Y-m-d H:i:s') !== $effectiveFromUtc) {
        throw new InvalidArgumentException('Choose a valid recipe effective date and time.');
    }

    $lines = [];
    foreach ($ingredientInput as $line) {
        $materialId = (int)($line['material_id'] ?? 0);
        $unitId = (int)($line['unit_id'] ?? 0);
        $quantity = (float)($line['quantity'] ?? 0);
        if ($materialId <= 0 || $unitId <= 0 || !is_finite($quantity) || $quantity <= 0) {
            throw new InvalidArgumentException('Each recipe ingredient needs a material, unit, and positive quantity.');
        }
        $materialQuery = $db->prepare('SELECT id, base_unit_id, is_active FROM inventory_materials WHERE id = ? AND restaurant_id = ?');
        $materialQuery->execute([$materialId, $restaurantId]);
        $material = $materialQuery->fetch();
        if (!$material || !(int)$material['is_active']) {
            throw new InvalidArgumentException('Recipe ingredients must use active materials from this restaurant.');
        }
        $factor = inventory_conversion_to($db, $restaurantId, $unitId, (int)$material['base_unit_id']);
        $quantityBase = round($quantity * $factor, 6);
        if ($quantityBase <= 0) {
            throw new InvalidArgumentException('An ingredient quantity is too small after unit conversion.');
        }
        $lines[] = [$materialId, $unitId, $quantity, $quantityBase];
    }
    if (!$lines) {
        throw new InvalidArgumentException('Add at least one ingredient to the recipe.');
    }

    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) {
        $db->beginTransaction();
    }
    try {
        $existingQuery = $db->prepare(
            'SELECT id, version, effective_from, effective_to FROM inventory_recipes
             WHERE restaurant_id = ? AND menu_item_id = ? AND mapping_key = ?
             ORDER BY version DESC FOR UPDATE'
        );
        $existingQuery->execute([$restaurantId, $menuItemId, $mappingKey]);
        $versions = $existingQuery->fetchAll();
        $latest = $versions[0] ?? null;
        if ($latest && $effectiveFromUtc <= (string)$latest['effective_from']) {
            throw new InvalidArgumentException('A new recipe version must start after the latest version.');
        }
        $version = $latest ? ((int)$latest['version'] + 1) : 1;
        if ($latest && $latest['effective_to'] === null) {
            $close = $db->prepare('UPDATE inventory_recipes SET effective_to = ? WHERE id = ? AND restaurant_id = ? AND effective_to IS NULL');
            $close->execute([$effectiveFromUtc, (int)$latest['id'], $restaurantId]);
        }
        $db->prepare(
            'INSERT INTO inventory_recipes
             (restaurant_id, menu_item_id, mapping_key, mapping_type, menu_item_option_id, mapping_label, version,
              effective_from, yield_quantity, yield_unit_label, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $restaurantId, $menuItemId, $mappingKey, $mapping['type'], $mapping['option_id'], $mapping['label'],
            $version, $effectiveFromUtc, $yieldQuantity, $yieldUnitLabel, $notes === '' ? null : $notes, $actorId,
        ]);
        $recipeId = (int)$db->lastInsertId();
        $insert = $db->prepare(
            'INSERT INTO inventory_recipe_ingredients (restaurant_id, recipe_id, material_id, unit_id, quantity, quantity_base)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($lines as [$materialId, $unitId, $quantity, $quantityBase]) {
            $insert->execute([$restaurantId, $recipeId, $materialId, $unitId, $quantity, $quantityBase]);
        }
        if ($ownsTransaction) {
            $db->commit();
        }
        return $recipeId;
    } catch (Throwable $exception) {
        if ($ownsTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}

function inventory_current_recipe(PDO $db, int $restaurantId, int $menuItemId, string $mappingKey, ?string $atUtc = null): ?array
{
    $atUtc ??= gmdate('Y-m-d H:i:s');
    $query = $db->prepare(
        'SELECT * FROM inventory_recipes
         WHERE restaurant_id = ? AND menu_item_id = ? AND mapping_key = ? AND is_active = 1
           AND effective_from <= ? AND (effective_to IS NULL OR effective_to > ?)
         ORDER BY version DESC LIMIT 1'
    );
    $query->execute([$restaurantId, $menuItemId, $mappingKey, $atUtc, $atUtc]);
    $recipe = $query->fetch();
    return $recipe ?: null;
}

function inventory_recipe_cost(PDO $db, int $restaurantId, int $recipeId): array
{
    $query = $db->prepare(
        "SELECT ri.id, ri.material_id, ri.quantity, ri.quantity_base, m.name AS material_name,
                m.is_active AS material_active, u.symbol AS entered_unit, bu.symbol AS base_unit,
                COALESCE(b.average_cost, 0) AS average_cost,
                (b.average_cost > 0 AND EXISTS(
                    SELECT 1 FROM inventory_stock_movements sm
                    WHERE sm.restaurant_id = ri.restaurant_id AND sm.material_id = ri.material_id
                      AND sm.movement_type IN ('OPENING_STOCK','PURCHASE')
                      AND sm.quantity_base > 0 AND sm.unit_cost > 0
                )) AS cost_known
         FROM inventory_recipe_ingredients ri
         INNER JOIN inventory_materials m ON m.id = ri.material_id AND m.restaurant_id = ri.restaurant_id
         INNER JOIN inventory_units u ON u.id = ri.unit_id AND u.restaurant_id = ri.restaurant_id
         INNER JOIN inventory_units bu ON bu.id = m.base_unit_id AND bu.restaurant_id = m.restaurant_id
         LEFT JOIN inventory_stock_balances b ON b.material_id = m.id AND b.restaurant_id = m.restaurant_id
         WHERE ri.restaurant_id = ? AND ri.recipe_id = ? ORDER BY ri.id"
    );
    $query->execute([$restaurantId, $recipeId]);
    $lines = $query->fetchAll();
    $total = 0.0;
    $complete = count($lines) > 0;
    foreach ($lines as &$line) {
        $line['line_cost'] = (float)$line['quantity_base'] * (float)$line['average_cost'];
        $total += $line['line_cost'];
        if (!(int)$line['cost_known'] || !(int)$line['material_active']) {
            $complete = false;
        }
    }
    unset($line);

    $recipeQuery = $db->prepare('SELECT yield_quantity FROM inventory_recipes WHERE id = ? AND restaurant_id = ?');
    $recipeQuery->execute([$recipeId, $restaurantId]);
    $yield = (float)$recipeQuery->fetchColumn();
    $perYield = $yield > 0 ? $total / $yield : null;
    return ['lines' => $lines, 'total_cost' => $total, 'yield_quantity' => $yield, 'cost_per_yield' => $perYield, 'complete' => $complete];
}

function inventory_profit_metrics(float $sellingPrice, ?float $ingredientCost): array
{
    if ($ingredientCost === null || !is_finite($sellingPrice) || !is_finite($ingredientCost)) {
        return ['gross_profit' => null, 'gross_margin_percent' => null];
    }
    $profit = $sellingPrice - $ingredientCost;
    return [
        'gross_profit' => $profit,
        'gross_margin_percent' => $sellingPrice > 0 ? ($profit / $sellingPrice) * 100 : null,
    ];
}

function inventory_local_datetime_to_utc(string $value): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone('Asia/Colombo'));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d\TH:i') !== $value) {
        throw new InvalidArgumentException('Choose a valid recipe effective date and time.');
    }
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}
