<?php
declare(strict_types=1);

require_once __DIR__ . '/inventory-service.php';
require_once __DIR__ . '/recipes-service.php';
require_once __DIR__ . '/kot-service.php';
require_once __DIR__ . '/stock-count-service.php';

function inventory_handle_action(PDO $db, array $context): never
{
    $page = (string)($_POST['return_page'] ?? 'dashboard');
    $allowedPages = ['materials', 'suppliers', 'purchases', 'stock', 'recipes', 'food-costing', 'wastage', 'stock-count'];
    if (!in_array($page, $allowedPages, true)) {
        $page = 'dashboard';
    }
    try {
        inv_require_csrf();
        inventory_require_capability($context, 'operate');
        $restaurantId = (int)$context['restaurant_id'];
        $actorId = (int)$context['user_id'];
        $action = (string)($_POST['action'] ?? '');

        switch ($action) {
            case 'create-unit': {
                $name = trim((string)($_POST['name'] ?? ''));
                $symbol = trim((string)($_POST['symbol'] ?? ''));
                $dimension = trim((string)($_POST['dimension'] ?? ''));
                $isBase = ($_POST['is_base'] ?? '') === '1';
                if ($name === '' || mb_strlen($name) > 80 || $symbol === '' || mb_strlen($symbol) > 24 || !preg_match('/^[a-zA-Z0-9 _-]{1,32}$/', $dimension)) {
                    throw new InvalidArgumentException('Enter a valid unit name, symbol, and measurement type.');
                }
                $places = filter_var($_POST['decimal_places'] ?? 3, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 6]]);
                if ($places === false) {
                    throw new InvalidArgumentException('Decimal places must be between 0 and 6.');
                }
                if ($isBase) {
                    $existingBase = $db->prepare('SELECT id FROM inventory_units WHERE restaurant_id = ? AND dimension = ? AND is_base = 1 LIMIT 1');
                    $existingBase->execute([$restaurantId, $dimension]);
                    if ($existingBase->fetchColumn()) {
                        throw new InvalidArgumentException('This measurement type already has a base unit. Add a conversion unit instead.');
                    }
                    $baseUnitId = null;
                    $factor = 1.0;
                } else {
                    $baseUnitId = (int)($_POST['base_unit_id'] ?? 0);
                    $base = inventory_unit($db, $restaurantId, $baseUnitId);
                    if (!$base || !(int)$base['is_base'] || $base['dimension'] !== $dimension) {
                        throw new InvalidArgumentException('Choose a base unit with the same measurement type.');
                    }
                    $factor = inv_positive_decimal($_POST['conversion_to_base'] ?? null, 'Conversion factor');
                }
                $insert = $db->prepare(
                    'INSERT INTO inventory_units (restaurant_id, name, symbol, dimension, base_unit_id, conversion_to_base, is_base, decimal_places)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $insert->execute([$restaurantId, $name, $symbol, $dimension, $baseUnitId, $factor, $isBase ? 1 : 0, $places]);
                inv_flash('Unit saved.');
                $page = 'materials';
                break;
            }
            case 'toggle-unit': {
                $id = (int)($_POST['id'] ?? 0);
                $active = ($_POST['active'] ?? '') === '1' ? 1 : 0;
                if (!$active) {
                    $used = $db->prepare(
                        'SELECT (SELECT COUNT(*) FROM inventory_materials WHERE restaurant_id = ? AND base_unit_id = ?)
                              + (SELECT COUNT(*) FROM inventory_purchase_items WHERE restaurant_id = ? AND unit_id = ?)
                              + (SELECT COUNT(*) FROM inventory_units WHERE restaurant_id = ? AND base_unit_id = ?)'
                    );
                    $used->execute([$restaurantId, $id, $restaurantId, $id, $restaurantId, $id]);
                    if ((int)$used->fetchColumn() > 0) {
                        throw new InvalidArgumentException('This unit is already used by a material or purchase and cannot be deactivated.');
                    }
                }
                $update = $db->prepare('UPDATE inventory_units SET is_active = ? WHERE id = ? AND restaurant_id = ?');
                $update->execute([$active, $id, $restaurantId]);
                if ($update->rowCount() !== 1) {
                    throw new InvalidArgumentException('Unit not found or status unchanged.');
                }
                inv_flash($active ? 'Unit activated.' : 'Unit deactivated.');
                $page = 'materials';
                break;
            }
            case 'create-material': {
                $name = trim((string)($_POST['name'] ?? ''));
                $sku = trim((string)($_POST['sku'] ?? ''));
                $unitId = (int)($_POST['base_unit_id'] ?? 0);
                $minimum = inv_positive_decimal($_POST['minimum_quantity'] ?? 0, 'Reorder level', true);
                $notes = trim((string)($_POST['notes'] ?? ''));
                $unit = inventory_unit($db, $restaurantId, $unitId);
                if ($name === '' || mb_strlen($name) > 150 || !$unit || !(int)$unit['is_base']) {
                    throw new InvalidArgumentException('Enter a material name and select an active base unit from this restaurant.');
                }
                if (mb_strlen($sku) > 80 || mb_strlen($notes) > 1000) {
                    throw new InvalidArgumentException('The SKU or notes exceed the allowed length.');
                }
                $insert = $db->prepare(
                    'INSERT INTO inventory_materials (restaurant_id, name, sku, base_unit_id, minimum_quantity, notes, created_by, updated_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $insert->execute([$restaurantId, $name, $sku === '' ? null : $sku, $unitId, $minimum, $notes === '' ? null : $notes, $actorId, $actorId]);
                inv_flash('Raw material added.');
                $page = 'materials';
                break;
            }
            case 'toggle-material': {
                $id = (int)($_POST['id'] ?? 0);
                $active = ($_POST['active'] ?? '') === '1' ? 1 : 0;
                $update = $db->prepare('UPDATE inventory_materials SET is_active = ?, updated_by = ? WHERE id = ? AND restaurant_id = ?');
                $update->execute([$active, $actorId, $id, $restaurantId]);
                if ($update->rowCount() !== 1) {
                    throw new InvalidArgumentException('Material not found.');
                }
                inv_flash($active ? 'Material activated.' : 'Material deactivated.');
                $page = 'materials';
                break;
            }
            case 'update-material': {
                $id = (int)($_POST['id'] ?? 0);
                $name = trim((string)($_POST['name'] ?? ''));
                $sku = trim((string)($_POST['sku'] ?? ''));
                $minimum = inv_positive_decimal($_POST['minimum_quantity'] ?? 0, 'Reorder level', true);
                $notes = trim((string)($_POST['notes'] ?? ''));
                if ($name === '' || mb_strlen($name) > 150 || mb_strlen($sku) > 80 || mb_strlen($notes) > 1000) {
                    throw new InvalidArgumentException('Check the material details and length limits.');
                }
                $update = $db->prepare('UPDATE inventory_materials SET name = ?, sku = ?, minimum_quantity = ?, notes = ?, updated_by = ? WHERE id = ? AND restaurant_id = ?');
                $update->execute([$name, $sku ?: null, $minimum, $notes ?: null, $actorId, $id, $restaurantId]);
                if ($update->rowCount() < 0) {
                    throw new InvalidArgumentException('Material not found.');
                }
                inv_flash('Material details saved.');
                $page = 'materials';
                break;
            }
            case 'create-supplier': {
                $name = trim((string)($_POST['name'] ?? ''));
                $contact = trim((string)($_POST['contact_person'] ?? ''));
                $phone = trim((string)($_POST['phone'] ?? ''));
                $email = trim((string)($_POST['email'] ?? ''));
                $address = trim((string)($_POST['address'] ?? ''));
                $notes = trim((string)($_POST['notes'] ?? ''));
                if ($name === '' || mb_strlen($name) > 150 || mb_strlen($contact) > 120 || mb_strlen($phone) > 40 || mb_strlen($address) > 700 || mb_strlen($notes) > 1000) {
                    throw new InvalidArgumentException('Check the supplier details and length limits.');
                }
                if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190)) {
                    throw new InvalidArgumentException('Enter a valid supplier email address.');
                }
                $db->prepare(
                    'INSERT INTO inventory_suppliers (restaurant_id, name, contact_person, phone, email, address, notes, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([$restaurantId, $name, $contact ?: null, $phone ?: null, $email ?: null, $address ?: null, $notes ?: null, $actorId]);
                inv_flash('Supplier added.');
                $page = 'suppliers';
                break;
            }
            case 'toggle-supplier': {
                $id = (int)($_POST['id'] ?? 0);
                $active = ($_POST['active'] ?? '') === '1' ? 1 : 0;
                $update = $db->prepare('UPDATE inventory_suppliers SET is_active = ? WHERE id = ? AND restaurant_id = ?');
                $update->execute([$active, $id, $restaurantId]);
                if ($update->rowCount() !== 1) {
                    throw new InvalidArgumentException('Supplier not found.');
                }
                inv_flash($active ? 'Supplier activated.' : 'Supplier deactivated.');
                $page = 'suppliers';
                break;
            }
            case 'update-supplier': {
                $id = (int)($_POST['id'] ?? 0);
                $name = trim((string)($_POST['name'] ?? ''));
                $contact = trim((string)($_POST['contact_person'] ?? ''));
                $phone = trim((string)($_POST['phone'] ?? ''));
                $email = trim((string)($_POST['email'] ?? ''));
                $address = trim((string)($_POST['address'] ?? ''));
                $notes = trim((string)($_POST['notes'] ?? ''));
                if ($name === '' || mb_strlen($name) > 150 || mb_strlen($contact) > 120 || mb_strlen($phone) > 40 || mb_strlen($address) > 700 || mb_strlen($notes) > 1000 || ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190))) {
                    throw new InvalidArgumentException('Check the supplier details and length limits.');
                }
                $update = $db->prepare('UPDATE inventory_suppliers SET name = ?, contact_person = ?, phone = ?, email = ?, address = ?, notes = ? WHERE id = ? AND restaurant_id = ?');
                $update->execute([$name, $contact ?: null, $phone ?: null, $email ?: null, $address ?: null, $notes ?: null, $id, $restaurantId]);
                if ($update->rowCount() < 0) {
                    throw new InvalidArgumentException('Supplier not found.');
                }
                inv_flash('Supplier details saved.');
                $page = 'suppliers';
                break;
            }
            case 'create-purchase': {
                $supplierId = (int)($_POST['supplier_id'] ?? 0);
                $reference = trim((string)($_POST['reference_no'] ?? ''));
                $date = (string)($_POST['purchase_date'] ?? '');
                $notes = trim((string)($_POST['notes'] ?? ''));
                if ($supplierId > 0) {
                    $supplier = $db->prepare('SELECT id FROM inventory_suppliers WHERE id = ? AND restaurant_id = ? AND is_active = 1');
                    $supplier->execute([$supplierId, $restaurantId]);
                    if (!$supplier->fetchColumn()) {
                        throw new InvalidArgumentException('Select an active supplier belonging to this restaurant.');
                    }
                }
                $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date || $parsedDate > new DateTimeImmutable('tomorrow', new DateTimeZone('Asia/Colombo'))) {
                    throw new InvalidArgumentException('Enter a valid purchase date.');
                }
                if (mb_strlen($reference) > 120 || mb_strlen($notes) > 1000) {
                    throw new InvalidArgumentException('The reference or notes exceed the allowed length.');
                }
                $db->prepare(
                    'INSERT INTO inventory_purchases (restaurant_id, supplier_id, reference_no, purchase_date, notes, created_by)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([$restaurantId, $supplierId ?: null, $reference ?: null, $date, $notes ?: null, $actorId]);
                $_SESSION['purchase_focus'] = (int)$db->lastInsertId();
                inv_flash('Draft purchase created. It will not affect stock until received.');
                $page = 'purchases';
                break;
            }
            case 'add-purchase-item': {
                $purchaseId = (int)($_POST['purchase_id'] ?? 0);
                $purchaseQuery = $db->prepare('SELECT id FROM inventory_purchases WHERE id = ? AND restaurant_id = ? AND status = ?');
                $purchaseQuery->execute([$purchaseId, $restaurantId, 'DRAFT']);
                if (!$purchaseQuery->fetchColumn()) {
                    throw new InvalidArgumentException('Only a draft purchase belonging to this restaurant can be edited.');
                }
                $materialId = (int)($_POST['material_id'] ?? 0);
                $materialQuery = $db->prepare('SELECT id, base_unit_id, is_active FROM inventory_materials WHERE id = ? AND restaurant_id = ?');
                $materialQuery->execute([$materialId, $restaurantId]);
                $material = $materialQuery->fetch();
                if (!$material || !(int)$material['is_active']) {
                    throw new InvalidArgumentException('Select an active material from this restaurant.');
                }
                $unitId = (int)($_POST['unit_id'] ?? 0);
                $factor = inventory_conversion_to($db, $restaurantId, $unitId, (int)$material['base_unit_id']);
                $quantity = inv_positive_decimal($_POST['quantity'] ?? null, 'Purchase quantity');
                $unitCost = inv_positive_decimal($_POST['unit_cost'] ?? null, 'Unit cost', true);
                $baseQuantity = round($quantity * $factor, 6);
                if ($baseQuantity <= 0) {
                    throw new InvalidArgumentException('Converted purchase quantity is too small.');
                }
                $db->prepare(
                    'INSERT INTO inventory_purchase_items (restaurant_id, purchase_id, material_id, unit_id, quantity, quantity_base, unit_cost, line_total)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([$restaurantId, $purchaseId, $materialId, $unitId, $quantity, $baseQuantity, $unitCost, round($quantity * $unitCost, 6)]);
                inv_flash('Purchase line added. Stock remains unchanged until the purchase is received.');
                $page = 'purchases';
                $_SESSION['purchase_focus'] = $purchaseId;
                break;
            }
            case 'remove-purchase-item': {
                $purchaseId = (int)($_POST['purchase_id'] ?? 0);
                $lineId = (int)($_POST['line_id'] ?? 0);
                $check = $db->prepare('SELECT id FROM inventory_purchases WHERE id = ? AND restaurant_id = ? AND status = ?');
                $check->execute([$purchaseId, $restaurantId, 'DRAFT']);
                if (!$check->fetchColumn()) {
                    throw new InvalidArgumentException('Only a draft purchase can be changed.');
                }
                $delete = $db->prepare('DELETE FROM inventory_purchase_items WHERE id = ? AND purchase_id = ? AND restaurant_id = ?');
                $delete->execute([$lineId, $purchaseId, $restaurantId]);
                if ($delete->rowCount() !== 1) {
                    throw new InvalidArgumentException('Purchase line not found.');
                }
                inv_flash('Draft purchase line removed.');
                $page = 'purchases';
                $_SESSION['purchase_focus'] = $purchaseId;
                break;
            }
            case 'receive-purchase': {
                $purchaseId = (int)($_POST['purchase_id'] ?? 0);
                $count = inventory_receive_purchase($db, $restaurantId, $purchaseId, $actorId);
                inv_flash('Purchase received. ' . $count . ' stock movement(s) were posted.');
                $page = 'purchases';
                $_SESSION['purchase_focus'] = $purchaseId;
                break;
            }
            case 'cancel-purchase': {
                $purchaseId = (int)($_POST['purchase_id'] ?? 0);
                $cancel = $db->prepare('UPDATE inventory_purchases SET status = ? WHERE id = ? AND restaurant_id = ? AND status = ?');
                $cancel->execute(['CANCELLED', $purchaseId, $restaurantId, 'DRAFT']);
                if ($cancel->rowCount() !== 1) {
                    throw new InvalidArgumentException('Only a draft purchase can be cancelled.');
                }
                inv_flash('Draft purchase cancelled. No stock movement was posted.');
                $page = 'purchases';
                break;
            }
            case 'opening-stock': {
                $materialId = (int)($_POST['material_id'] ?? 0);
                $materialQuery = $db->prepare('SELECT id, base_unit_id FROM inventory_materials WHERE id = ? AND restaurant_id = ? AND is_active = 1');
                $materialQuery->execute([$materialId, $restaurantId]);
                $material = $materialQuery->fetch();
                if (!$material) {
                    throw new InvalidArgumentException('Select an active material from this restaurant.');
                }
                $unitId = (int)($_POST['unit_id'] ?? 0);
                $factor = inventory_conversion_to($db, $restaurantId, $unitId, (int)$material['base_unit_id']);
                $quantity = inv_positive_decimal($_POST['quantity'] ?? null, 'Opening quantity');
                $unitCost = inv_positive_decimal($_POST['unit_cost'] ?? null, 'Unit cost', true);
                $key = (string)($_POST['idempotency_key'] ?? '');
                $baseQuantity = round($quantity * $factor, 6);
                inventory_apply_movement(
                    $db, $restaurantId, $materialId, 'OPENING_STOCK', $baseQuantity,
                    $unitCost / $factor, 'OPENING', null, null, $key, $actorId,
                    trim((string)($_POST['notes'] ?? 'Opening stock'))
                );
                inv_flash('Opening stock posted to the append-only ledger.');
                $page = 'stock';
                break;
            }
            case 'save-recipe': {
                $selection = explode('|', (string)($_POST['menu_mapping'] ?? ''), 2);
                if (count($selection) !== 2 || !ctype_digit($selection[0]) || $selection[1] === '') {
                    throw new InvalidArgumentException('Select a menu item and its size or option.');
                }
                $yield = inv_positive_decimal($_POST['yield_quantity'] ?? null, 'Recipe yield');
                $yieldUnit = trim((string)($_POST['yield_unit_label'] ?? 'servings'));
                $notes = trim((string)($_POST['notes'] ?? ''));
                $effectiveUtc = inventory_local_datetime_to_utc((string)($_POST['effective_from'] ?? ''));
                $materialIds = is_array($_POST['material_id'] ?? null) ? $_POST['material_id'] : [];
                $unitIds = is_array($_POST['unit_id'] ?? null) ? $_POST['unit_id'] : [];
                $quantities = is_array($_POST['quantity'] ?? null) ? $_POST['quantity'] : [];
                if (count($materialIds) !== count($unitIds) || count($materialIds) !== count($quantities) || count($materialIds) > 100) {
                    throw new InvalidArgumentException('The ingredient list is invalid.');
                }
                $ingredients = [];
                foreach ($materialIds as $index => $materialId) {
                    $ingredients[] = [
                        'material_id' => (int)$materialId,
                        'unit_id' => (int)$unitIds[$index],
                        'quantity' => inv_positive_decimal($quantities[$index], 'Ingredient quantity'),
                    ];
                }
                inventory_save_recipe($db, $restaurantId, $actorId, (int)$selection[0], $selection[1], $effectiveUtc, $yield, $yieldUnit, $notes, $ingredients);
                inv_flash('Recipe version saved. Previous versions remain available in history.');
                $page = 'recipes';
                break;
            }
            case 'record-wastage': {
                $materialId = (int)($_POST['material_id'] ?? 0);
                $unitId = (int)($_POST['unit_id'] ?? 0);
                $quantity = inv_positive_decimal($_POST['quantity'] ?? null, 'Wastage quantity');
                $reason = (string)($_POST['reason_code'] ?? '');
                $notes = trim((string)($_POST['notes'] ?? ''));
                $key = (string)($_POST['idempotency_key'] ?? '');
                inventory_record_manual_wastage($db, $restaurantId, $actorId, $materialId, $unitId, $quantity, $reason, $notes, $key);
                inv_flash('Wastage recorded and deducted through the stock ledger.');
                $page = 'wastage';
                break;
            }
            case 'resolve-kot-cancellation': {
                inventory_resolve_kot_cancellation(
                    $db, $restaurantId, $actorId, (int)($_POST['review_id'] ?? 0),
                    (string)($_POST['resolution'] ?? ''), trim((string)($_POST['resolution_reason'] ?? ''))
                );
                inv_flash('KOT cancellation review resolved.');
                $page = 'wastage';
                break;
            }
            case 'retry-kot-event': {
                $status = inventory_retry_kot_event($db, $restaurantId, (int)($_POST['event_id'] ?? 0));
                inv_flash($status === 'PROCESSED'
                    ? 'KOT stock deduction was processed successfully.'
                    : 'The KOT line still needs review. Check its reason and complete the missing setup before retrying.', $status === 'PROCESSED' ? 'success' : 'warning');
                $page = 'wastage';
                break;
            }
            case 'save-kot-mapping-override': {
                $status = inventory_save_kot_mapping_override_and_retry(
                    $db, $restaurantId, $actorId, (int)($_POST['event_id'] ?? 0), trim((string)($_POST['mapping_key'] ?? ''))
                );
                inv_flash($status === 'PROCESSED'
                    ? 'KOT label mapping saved and stock was processed.'
                    : 'Mapping saved. The KOT line still needs a matching effective recipe; add or correct that recipe, then retry it.', $status === 'PROCESSED' ? 'success' : 'warning');
                $page = 'wastage';
                break;
            }
            case 'create-stock-count': {
                $countId = inventory_create_stock_count($db, $restaurantId, $actorId, trim((string)($_POST['notes'] ?? '')));
                $_SESSION['stock_count_focus'] = $countId;
                inv_flash('Draft stock count created with a locked book-quantity snapshot.');
                $page = 'stock-count';
                break;
            }
            case 'save-stock-count': {
                $countId = (int)($_POST['count_id'] ?? 0);
                $lineIds = is_array($_POST['line_id'] ?? null) ? $_POST['line_id'] : [];
                $physical = is_array($_POST['physical_quantity'] ?? null) ? $_POST['physical_quantity'] : [];
                inventory_save_stock_count_values($db, $restaurantId, $countId, $lineIds, $physical);
                $_SESSION['stock_count_focus'] = $countId;
                inv_flash('Physical stock counts saved. Review the differences before posting.');
                $page = 'stock-count';
                break;
            }
            case 'post-stock-count': {
                $countId = (int)($_POST['count_id'] ?? 0);
                $lineCount = inventory_post_stock_count($db, $restaurantId, $actorId, $countId);
                $_SESSION['stock_count_focus'] = $countId;
                inv_flash('Stock count posted. ' . $lineCount . ' material line(s) were reconciled through ledger movements.');
                $page = 'stock-count';
                break;
            }
            default:
                throw new InvalidArgumentException('Unknown inventory action.');
        }
    } catch (InvalidArgumentException $exception) {
        inv_flash($exception->getMessage(), 'error');
    } catch (Throwable $exception) {
        error_log('[inventory-portal action] ' . $exception->getMessage());
        inv_flash('The change could not be saved. Check the values and try again.', 'error');
    }
    inv_redirect($page);
}
