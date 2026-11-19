<?php
declare(strict_types=1);

require_once __DIR__ . '/recipes-service.php';

function inventory_report_period(array $input): array
{
    $zone = new DateTimeZone('Asia/Colombo');
    $now = new DateTimeImmutable('now', $zone);
    $period = (string)($input['period'] ?? 'month');
    if ($period === 'today') {
        $start = $now->setTime(0, 0);
        $end = $start->modify('+1 day');
        $label = 'Today';
    } elseif ($period === 'yesterday') {
        $end = $now->setTime(0, 0);
        $start = $end->modify('-1 day');
        $label = 'Yesterday';
    } elseif ($period === 'week') {
        $start = $now->modify('monday this week')->setTime(0, 0);
        $end = $start->modify('+1 week');
        $label = 'This Week';
    } elseif ($period === 'month') {
        $start = $now->modify('first day of this month')->setTime(0, 0);
        $end = $start->modify('+1 month');
        $label = 'This Month';
    } elseif ($period === 'custom') {
        $fromText = (string)($input['from'] ?? '');
        $toText = (string)($input['to'] ?? '');
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $fromText, $zone);
        $to = DateTimeImmutable::createFromFormat('!Y-m-d', $toText, $zone);
        if (!$start || !$to || $start->format('Y-m-d') !== $fromText || $to->format('Y-m-d') !== $toText || $to < $start || $start->diff($to)->days > 365) {
            throw new InvalidArgumentException('Choose a valid custom range of no more than 366 days.');
        }
        $start = $start->setTime(0, 0);
        $end = $to->modify('+1 day')->setTime(0, 0);
        $label = $fromText . ' to ' . $toText;
    } else {
        throw new InvalidArgumentException('Choose a supported report period.');
    }
    $utc = new DateTimeZone('UTC');
    return [
        'period' => $period,
        'from_local' => $start->format('Y-m-d'),
        'to_local' => $end->modify('-1 day')->format('Y-m-d'),
        'from_utc' => $start->setTimezone($utc)->format('Y-m-d H:i:s'),
        'to_utc' => $end->setTimezone($utc)->format('Y-m-d H:i:s'),
        'label' => $label,
    ];
}

function inventory_profit_report(PDO $db, int $restaurantId, string $fromUtc, string $toUtc): array
{
    $invoicesQuery = $db->prepare(
        "SELECT i.id, i.order_id, i.created_at, o.status AS order_status,
                i.subtotal, i.discount, i.service_charge, i.tax, i.packaging_charge, i.delivery_charge,
                i.total, i.paid_total, COALESCE(SUM(ii.line_total), 0) AS items_total,
                COUNT(ii.id) AS invoice_item_count
         FROM pos_invoices i
         JOIN orders o ON o.id = i.order_id AND o.restaurant_id = i.restaurant_id
         LEFT JOIN pos_invoice_items ii ON ii.invoice_id = i.id
         WHERE i.restaurant_id = ? AND i.payment_status = 'PAID' AND i.paid_total >= i.total
           AND i.created_at >= ? AND i.created_at < ? AND o.status NOT IN ('REJECTED','CANCELLED')
         GROUP BY i.id, i.order_id, i.created_at, o.status, i.subtotal, i.discount, i.service_charge, i.tax,
                  i.packaging_charge, i.delivery_charge, i.total, i.paid_total
         ORDER BY i.created_at"
    );
    $invoicesQuery->execute([$restaurantId, $fromUtc, $toUtc]);
    $invoiceRows = $invoicesQuery->fetchAll();
    $cogsQuery = $db->prepare(
        "SELECT
            COALESCE((SELECT SUM(sm.cost_amount) FROM inventory_stock_movements sm
                      JOIN inventory_kot_events e ON e.restaurant_id = sm.restaurant_id AND e.kot_item_id = sm.source_id
                      WHERE sm.restaurant_id = ? AND e.order_id = ? AND e.event_action = 'ADDED'
                        AND sm.movement_type = 'CONSUMPTION' AND sm.source_type = 'KOT_ITEM'), 0)
            - COALESCE((SELECT SUM(sm.cost_amount) FROM inventory_stock_movements sm
                      JOIN inventory_kot_events e ON e.restaurant_id = sm.restaurant_id AND e.kot_item_id = sm.source_id
                      WHERE sm.restaurant_id = ? AND e.order_id = ? AND e.event_action = 'CANCELLED'
                        AND sm.movement_type = 'RETURN' AND sm.source_type = 'KOT_RETURN'), 0)"
    );
    $completenessQuery = $db->prepare(
        "SELECT
            (SELECT COUNT(*) FROM pos_kot_items ki JOIN pos_kots k ON k.id = ki.kot_id
             WHERE k.restaurant_id = ? AND k.order_id = ?
               AND NOT EXISTS (SELECT 1 FROM inventory_kot_events e WHERE e.restaurant_id = k.restaurant_id AND e.kot_item_id = ki.id)) AS unprocessed,
            (SELECT COUNT(*) FROM inventory_kot_events e WHERE e.restaurant_id = ? AND e.order_id = ?
               AND e.processing_status IN ('REVIEW','IGNORED')) AS unresolved,
            (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = ? AND oi.quantity <> COALESCE(
                (SELECT SUM(CASE WHEN ki.action = 'ADDED' THEN ki.quantity ELSE -ki.quantity END)
                 FROM pos_kot_items ki JOIN pos_kots k ON k.id = ki.kot_id
                 WHERE k.restaurant_id = ? AND k.order_id = oi.order_id AND ki.order_item_id = oi.id), 0)) AS unsent_delta,
            (SELECT COUNT(*) FROM inventory_kot_events e WHERE e.restaurant_id = ? AND e.order_id = ?
               AND e.event_action = 'ADDED' AND e.processing_status = 'PROCESSED' AND e.recipe_id IS NOT NULL) AS processed_additions"
            . ", (SELECT COUNT(*) FROM inventory_stock_movements sm
                 JOIN inventory_kot_events e ON e.restaurant_id = sm.restaurant_id AND e.kot_item_id = sm.source_id
                 WHERE sm.restaurant_id = ? AND e.order_id = ? AND e.event_action = 'ADDED'
                   AND sm.source_type = 'KOT_ITEM' AND sm.movement_type = 'CONSUMPTION'
                   AND (sm.unit_cost <= 0 OR sm.cost_amount <= 0)) AS uncosted_consumption"
    );
    $sales = 0.0;
    $service = 0.0;
    $tax = 0.0;
    $delivery = 0.0;
    $packaging = 0.0;
    $trackedCogs = 0.0;
    $incomplete = 0;
    $invoiceDetails = [];
    foreach ($invoiceRows as $invoice) {
        $revenue = max(0.0, (float)$invoice['subtotal'] - (float)$invoice['discount']);
        $sales += $revenue;
        $service += (float)$invoice['service_charge'];
        $tax += (float)$invoice['tax'];
        $delivery += (float)$invoice['delivery_charge'];
        $packaging += (float)$invoice['packaging_charge'];
        $cogsQuery->execute([$restaurantId, (int)$invoice['order_id'], $restaurantId, (int)$invoice['order_id']]);
        $cogs = max(0.0, (float)$cogsQuery->fetchColumn());
        $completenessQuery->execute([$restaurantId, (int)$invoice['order_id'], $restaurantId, (int)$invoice['order_id'], (int)$invoice['order_id'], $restaurantId, $restaurantId, (int)$invoice['order_id'], $restaurantId, (int)$invoice['order_id']]);
        $checks = $completenessQuery->fetch();
        $isComplete = (int)$invoice['invoice_item_count'] > 0
            && (int)$checks['unprocessed'] === 0
            && (int)$checks['unresolved'] === 0
            && (int)$checks['unsent_delta'] === 0
            && (int)$checks['processed_additions'] > 0
            && (int)$checks['uncosted_consumption'] === 0;
        if (!$isComplete) $incomplete++;
        else $trackedCogs += $cogs;
        $invoiceDetails[] = [
            'invoice_id' => (int)$invoice['id'], 'order_id' => (int)$invoice['order_id'], 'created_at' => $invoice['created_at'],
            'revenue' => $revenue, 'cogs' => $isComplete ? $cogs : null, 'complete' => $isComplete,
            'service_charge' => (float)$invoice['service_charge'], 'tax' => (float)$invoice['tax'],
            'delivery_charge' => (float)$invoice['delivery_charge'], 'packaging_charge' => (float)$invoice['packaging_charge'],
        ];
    }

    $paymentsQuery = $db->prepare(
        'SELECT p.method, SUM(p.amount) AS amount, COUNT(*) AS payment_count
         FROM pos_payments p JOIN pos_invoices i ON i.id = p.invoice_id AND i.restaurant_id = p.restaurant_id
         WHERE p.restaurant_id = ? AND p.created_at >= ? AND p.created_at < ?
         GROUP BY p.method ORDER BY amount DESC'
    );
    $paymentsQuery->execute([$restaurantId, $fromUtc, $toUtc]);
    $paymentRows = $paymentsQuery->fetchAll();

    $purchaseQuery = $db->prepare(
        "SELECT COALESCE(s.name, 'Supplier not recorded') AS supplier_name, m.name AS material_name,
                SUM(sm.cost_amount) AS amount, SUM(ABS(sm.quantity_base)) AS quantity, u.symbol AS base_unit
         FROM inventory_stock_movements sm
         JOIN inventory_materials m ON m.id = sm.material_id AND m.restaurant_id = sm.restaurant_id
         JOIN inventory_units u ON u.id = m.base_unit_id AND u.restaurant_id = m.restaurant_id
         LEFT JOIN inventory_purchases p ON p.id = sm.source_id AND p.restaurant_id = sm.restaurant_id
         LEFT JOIN inventory_suppliers s ON s.id = p.supplier_id AND s.restaurant_id = p.restaurant_id
         WHERE sm.restaurant_id = ? AND sm.movement_type = 'PURCHASE' AND sm.effective_at >= ? AND sm.effective_at < ?
         GROUP BY s.name, m.id, m.name, u.symbol ORDER BY amount DESC"
    );
    $purchaseQuery->execute([$restaurantId, $fromUtc, $toUtc]);
    $purchaseRows = $purchaseQuery->fetchAll();

    $costTrendQuery = $db->prepare(
        "SELECT x.id, x.material_name, x.base_unit, x.effective_at, x.movement_type,
                x.quantity_base, x.unit_cost, x.average_cost_after
         FROM (
             SELECT sm.id, sm.effective_at, sm.movement_type, sm.quantity_base, sm.unit_cost,
                    m.name AS material_name, u.symbol AS base_unit,
                    SUM(sm.quantity_base) OVER (PARTITION BY sm.material_id ORDER BY sm.id ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS quantity_after,
                    SUM(sm.inventory_value_delta) OVER (PARTITION BY sm.material_id ORDER BY sm.id ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS value_after,
                    CASE WHEN SUM(sm.quantity_base) OVER (PARTITION BY sm.material_id ORDER BY sm.id ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) > 0
                         THEN SUM(sm.inventory_value_delta) OVER (PARTITION BY sm.material_id ORDER BY sm.id ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW)
                              / SUM(sm.quantity_base) OVER (PARTITION BY sm.material_id ORDER BY sm.id ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW)
                         ELSE NULL END AS average_cost_after
             FROM inventory_stock_movements sm
             JOIN inventory_materials m ON m.id = sm.material_id AND m.restaurant_id = sm.restaurant_id
             JOIN inventory_units u ON u.id = m.base_unit_id AND u.restaurant_id = m.restaurant_id
             WHERE sm.restaurant_id = ?
         ) x
         WHERE x.effective_at >= ? AND x.effective_at < ?
         ORDER BY x.id DESC LIMIT 100"
    );
    $costTrendQuery->execute([$restaurantId, $fromUtc, $toUtc]);
    $costTrendRows = $costTrendQuery->fetchAll();

    $wastageReasonsQuery = $db->prepare(
        'SELECT reason_code, SUM(total_cost) AS amount, SUM(unmatched_quantity) AS unmatched_quantity, COUNT(*) AS record_count
         FROM inventory_wastage WHERE restaurant_id = ? AND created_at >= ? AND created_at < ?
         GROUP BY reason_code ORDER BY amount DESC'
    );
    $wastageReasonsQuery->execute([$restaurantId, $fromUtc, $toUtc]);
    $wastageReasons = $wastageReasonsQuery->fetchAll();
    $wastageMaterialsQuery = $db->prepare(
        'SELECT m.name, SUM(i.cost_amount) AS amount, SUM(i.quantity_base) AS quantity, u.symbol AS base_unit
         FROM inventory_wastage_items i
         JOIN inventory_wastage w ON w.id = i.wastage_id AND w.restaurant_id = i.restaurant_id
         JOIN inventory_materials m ON m.id = i.material_id AND m.restaurant_id = i.restaurant_id
         JOIN inventory_units u ON u.id = m.base_unit_id AND u.restaurant_id = m.restaurant_id
         WHERE i.restaurant_id = ? AND w.created_at >= ? AND w.created_at < ?
         GROUP BY m.id, m.name, u.symbol ORDER BY amount DESC LIMIT 10'
    );
    $wastageMaterialsQuery->execute([$restaurantId, $fromUtc, $toUtc]);
    $wastageMaterials = $wastageMaterialsQuery->fetchAll();

    $stockRows = inventory_stock_snapshot($db, $restaurantId);
    $stockValue = array_sum(array_map(static fn(array $row): float => (float)$row['inventory_value'], $stockRows));
    $consumptionQuery = $db->prepare(
        "SELECT COALESCE(SUM(CASE WHEN movement_type = 'CONSUMPTION' THEN cost_amount
                                  WHEN movement_type = 'RETURN' AND source_type = 'KOT_RETURN' THEN -cost_amount ELSE 0 END), 0)
         FROM inventory_stock_movements WHERE restaurant_id = ? AND effective_at >= ? AND effective_at < ?"
    );
    $consumptionQuery->execute([$restaurantId, $fromUtc, $toUtc]);
    $trackedConsumption = (float)$consumptionQuery->fetchColumn();

    $workerInitialized = $db->query('SELECT 1 FROM inventory_kot_worker_state WHERE singleton_id = 1')->fetchColumn() !== false;
    $periodComplete = $workerInitialized && count($invoiceRows) > 0 && $incomplete === 0;
    return [
        'sales_revenue' => $sales,
        'tracked_cogs' => $trackedCogs,
        'worker_initialized' => $workerInitialized,
        'gross_profit' => $periodComplete ? $sales - $trackedCogs : null,
        'gross_margin_percent' => $periodComplete && $sales > 0 ? (($sales - $trackedCogs) / $sales) * 100 : null,
        'incomplete_invoice_count' => $incomplete,
        'paid_invoice_count' => count($invoiceRows),
        'service_charge' => $service, 'tax' => $tax, 'delivery_charge' => $delivery, 'packaging_charge' => $packaging,
        'payment_rows' => $paymentRows, 'purchase_rows' => $purchaseRows,
        'cost_trend_rows' => $costTrendRows,
        'wastage_reason_rows' => $wastageReasons, 'wastage_material_rows' => $wastageMaterials,
        'wastage_total' => array_sum(array_map(static fn(array $row): float => (float)$row['amount'], $wastageReasons)),
        'stock_value' => $stockValue, 'tracked_consumption' => $trackedConsumption,
        'invoice_rows' => $invoiceDetails,
    ];
}

function inventory_current_food_cost_rankings(PDO $db, int $restaurantId): array
{
    $items = $db->prepare('SELECT id, name, price, is_portioned, half_price, full_price, is_active FROM menu_items WHERE restaurant_id = ? AND is_active = 1 ORDER BY name');
    $items->execute([$restaurantId]);
    $rows = [];
    foreach ($items->fetchAll() as $item) {
        foreach (inventory_menu_mappings($db, $restaurantId, $item) as $mapping) {
            $recipe = inventory_current_recipe($db, $restaurantId, (int)$item['id'], (string)$mapping['key']);
            if (!$recipe) continue;
            $cost = inventory_recipe_cost($db, $restaurantId, (int)$recipe['id']);
            if (!$cost['complete'] || $cost['cost_per_yield'] === null) continue;
            $metrics = inventory_profit_metrics((float)$mapping['price'], (float)$cost['cost_per_yield']);
            $rows[] = ['name' => $item['name'], 'mapping' => $mapping['label'], 'selling_price' => (float)$mapping['price'], 'ingredient_cost' => (float)$cost['cost_per_yield'], ...$metrics];
        }
    }
    return $rows;
}
