<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/database.php';
$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--') && str_contains($argument, '=')) {
        [$key, $value] = explode('=', substr($argument, 2), 2);
        $options[$key] = $value;
    }
}
$restaurantId = filter_var($options['restaurant-id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$userId = filter_var($options['user-id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$restaurantId || !$userId) {
    fwrite(STDERR, "Usage: php bin/revoke-access.php --restaurant-id=ID --user-id=ID --confirm=REVOKE\n");
    exit(2);
}
if (($options['confirm'] ?? '') !== 'REVOKE') {
    fwrite(STDERR, "No access was changed. Add --confirm=REVOKE to explicitly deactivate this grant.\n");
    exit(2);
}
$db = inventory_database();
$update = $db->prepare('UPDATE inventory_portal_access SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE restaurant_id = ? AND user_id = ? AND is_active = 1');
$update->execute([$restaurantId, $userId]);
if ($update->rowCount() !== 1) {
    fwrite(STDERR, "No active Inventory Portal grant matched those IDs.\n");
    exit(3);
}
echo "Inventory Portal access deactivated for user ID {$userId} at restaurant ID {$restaurantId}.\n";
