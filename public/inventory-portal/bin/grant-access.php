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
$role = strtoupper((string)($options['role'] ?? ''));
$roles = ['OWNER', 'INVENTORY_MANAGER', 'ACCOUNTANT'];
if (!$restaurantId || !$userId || !in_array($role, $roles, true)) {
    fwrite(STDERR, "Usage: php bin/grant-access.php --restaurant-id=ID --user-id=ID --role=OWNER|INVENTORY_MANAGER|ACCOUNTANT --confirm=GRANT\n");
    exit(2);
}
if (($options['confirm'] ?? '') !== 'GRANT') {
    fwrite(STDERR, "No access was changed. Add --confirm=GRANT to explicitly provision this account.\n");
    exit(2);
}

$db = inventory_database();
$check = $db->prepare(
    'SELECT u.id FROM users u INNER JOIN restaurants r ON r.id = u.restaurant_id
     WHERE u.id = ? AND u.restaurant_id = ? AND u.is_active = 1 AND r.is_active = 1 LIMIT 1'
);
$check->execute([$userId, $restaurantId]);
if (!$check->fetchColumn()) {
    fwrite(STDERR, "The active user does not belong to the specified active restaurant.\n");
    exit(3);
}
$grant = $db->prepare(
    'INSERT INTO inventory_portal_access (restaurant_id, user_id, portal_role, is_active)
     VALUES (?, ?, ?, 1)
     ON DUPLICATE KEY UPDATE portal_role = VALUES(portal_role), is_active = 1, updated_at = CURRENT_TIMESTAMP'
);
$grant->execute([$restaurantId, $userId, $role]);
echo "Inventory Portal access granted for user ID {$userId} at restaurant ID {$restaurantId} with role {$role}.\n";
