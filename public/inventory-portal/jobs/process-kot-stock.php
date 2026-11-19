<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/database.php';
require_once __DIR__ . '/../app/kot-service.php';
require_once __DIR__ . '/../app/automation-service.php';

$db = inventory_database();
$arguments = array_slice($argv, 1);
if (in_array('--dry-run', $arguments, true) && in_array('--bootstrap', $arguments, true)) {
    fwrite(STDERR, "Choose either --dry-run or --bootstrap, not both.\n");
    exit(2);
}
if (in_array('--dry-run', $arguments, true)) {
    $state = inventory_kot_worker_state($db);
    if (!$state) echo "Worker is not initialized. This previews existing unprocessed KOT items; bootstrap would skip current history.\n";
    $cutoff = $state ? (int)$state['bootstrap_max_kot_item_id'] : 0;
    $preview = $db->prepare(
        'SELECT k.restaurant_id, ki.id AS kot_item_id, k.order_id, ki.order_item_id,
                oi.menu_item_id, ki.action AS event_action, ki.item_name, ki.portion AS portion_label,
                ki.quantity, k.created_at AS event_at
         FROM pos_kot_items ki
         JOIN pos_kots k ON k.id = ki.kot_id
         JOIN orders o ON o.id = k.order_id AND o.restaurant_id = k.restaurant_id
         LEFT JOIN order_items oi ON oi.id = ki.order_item_id AND oi.order_id = o.id
         WHERE ki.id > ? AND NOT EXISTS (
             SELECT 1 FROM inventory_kot_events e WHERE e.restaurant_id = k.restaurant_id AND e.kot_item_id = ki.id
         ) ORDER BY ki.id LIMIT 501'
    );
    $preview->execute([$cutoff]);
    $rows = $preview->fetchAll();
    foreach (array_slice($rows, 0, 500) as $event) {
        try {
            echo json_encode(inventory_kot_dry_run_event($db, $event), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
        } catch (Throwable $exception) {
            echo json_encode(['kot_item_id' => (int)$event['kot_item_id'], 'restaurant_id' => (int)$event['restaurant_id'],
                'status' => 'REVIEW', 'reason' => $exception->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
        }
    }
    if (count($rows) > 500) echo "Preview capped at 500 KOT lines; additional lines were not shown.\n";
    echo "Dry-run complete. No database rows were changed.\n";
    exit(0);
}
if (in_array('--bootstrap', $arguments, true)) {
    $cutoff = inventory_kot_worker_bootstrap($db);
    echo "KOT worker initialized at existing KOT item ID {$cutoff}. Older KOT activity will not be replayed.\n";
    exit(0);
}

$state = inventory_kot_worker_state($db);
if (!$state) {
    fwrite(STDERR, "KOT worker is not initialized. Run this command once with --bootstrap before scheduling it.\n");
    exit(2);
}

$lock = (int)$db->query("SELECT GET_LOCK('energetic_edge_inventory_kot_worker', 0)")->fetchColumn();
if ($lock !== 1) {
    echo "Another KOT worker is already running; this invocation made no changes.\n";
    exit(0);
}

$processed = 0;
$review = 0;
$duplicates = 0;
try {
    $query = $db->prepare(
        'SELECT k.restaurant_id, ki.id AS kot_item_id, k.order_id, ki.order_item_id,
                oi.menu_item_id, ki.action AS event_action, ki.item_name, ki.portion AS portion_label,
                ki.quantity, k.created_at AS event_at
         FROM pos_kot_items ki
         JOIN pos_kots k ON k.id = ki.kot_id
         JOIN orders o ON o.id = k.order_id AND o.restaurant_id = k.restaurant_id
         LEFT JOIN order_items oi ON oi.id = ki.order_item_id AND oi.order_id = o.id
         WHERE ki.id > ?
           AND NOT EXISTS (
               SELECT 1 FROM inventory_kot_events e
               WHERE e.restaurant_id = k.restaurant_id AND e.kot_item_id = ki.id
           )
         ORDER BY ki.id LIMIT 100'
    );
    do {
        $query->execute([(int)$state['bootstrap_max_kot_item_id']]);
        $batch = $query->fetchAll();
        foreach ($batch as $event) {
            $result = inventory_process_kot_snapshot($db, $event);
            if ($result === 'PROCESSED') $processed++;
            elseif ($result === 'REVIEW') $review++;
            else $duplicates++;
        }
    } while (count($batch) === 100);
    $db->prepare('INSERT INTO inventory_kot_worker_runs (processed_count, review_count, duplicate_count) VALUES (?, ?, ?)')
        ->execute([$processed, $review, $duplicates]);
    echo "KOT worker complete. Processed: {$processed}; cancellation or mapping review: {$review}; duplicate retries skipped: {$duplicates}.\n";
} catch (Throwable $exception) {
    error_log('[inventory-portal KOT worker] ' . $exception->getMessage());
    fwrite(STDERR, "KOT worker stopped safely. The failed event was not marked complete; see the PHP error log.\n");
    exit(1);
} finally {
    $db->query("SELECT RELEASE_LOCK('energetic_edge_inventory_kot_worker')");
}
