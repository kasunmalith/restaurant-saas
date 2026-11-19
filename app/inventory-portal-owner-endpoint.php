<?php
if (($path ?? '') !== '/api/owner/inventory-portal') return;

$ownerCheck = $db->prepare("SELECT id FROM users WHERE id=? AND role='SUPER_ADMIN' AND is_active=1");
$ownerCheck->execute([$_SESSION['user']['id'] ?? 0]);
if (!$ownerCheck->fetchColumn()) order_fail('Owner access required.', 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') order_fail('POST required.', 405);
if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
    order_fail('Refresh your security token.', 403);
}

$restaurantId = (int)($_POST['restaurant_id'] ?? 0);
$action = (string)($_POST['action'] ?? '');
if ($restaurantId < 1 || !in_array($action, ['enable_generate', 'regenerate', 'disable'], true)) {
    order_fail('Select a restaurant and a valid Inventory Portal action.');
}

try {
    $portalPassword = (string)($_POST['password'] ?? '');
    if ($action !== 'disable' && $portalPassword !== '') {
        $passwordLength = preg_match_all('/./us', $portalPassword);
        if ($passwordLength === false) {
            throw new InvalidArgumentException('Use a valid UTF-8 password.');
        }
        if ($passwordLength < 6) {
            throw new InvalidArgumentException('Inventory Portal passwords must be at least 6 characters long.');
        }
        if (strlen($portalPassword) > 72) {
            throw new InvalidArgumentException('Inventory Portal passwords cannot exceed 72 bytes.');
        }
    }

    $db->beginTransaction();
    $restaurantQuery = $db->prepare('SELECT id, name, is_active FROM restaurants WHERE id = ? FOR UPDATE');
    $restaurantQuery->execute([$restaurantId]);
    $restaurant = $restaurantQuery->fetch();
    if (!$restaurant) {
        throw new InvalidArgumentException('Restaurant not found.');
    }

    $actorId = (int)$_SESSION['user']['id'];
    if ($action === 'disable') {
        $save = $db->prepare(
            'INSERT INTO inventory_portal_settings (restaurant_id, is_enabled, updated_by)
             VALUES (?, 0, ?)
             ON DUPLICATE KEY UPDATE is_enabled = 0, updated_by = VALUES(updated_by)'
        );
        $save->execute([$restaurantId, $actorId]);
        $db->commit();
        json_out(['ok' => true, 'enabled' => false]);
    }

    if (!(int)$restaurant['is_active']) {
        throw new InvalidArgumentException('Activate this restaurant account before enabling the Inventory Portal.');
    }

    if ($action === 'regenerate') {
        $enabledQuery = $db->prepare('SELECT is_enabled FROM inventory_portal_settings WHERE restaurant_id = ? FOR UPDATE');
        $enabledQuery->execute([$restaurantId]);
        if (!(int)$enabledQuery->fetchColumn()) {
            throw new InvalidArgumentException('This portal is disabled. Use Enable & Generate Password first.');
        }
    }

    $managerQuery = $db->prepare(
        "SELECT id, email FROM users
         WHERE restaurant_id = ? AND role = 'RESTAURANT_ADMIN' AND is_active = 1
         ORDER BY id LIMIT 1 FOR UPDATE"
    );
    $managerQuery->execute([$restaurantId]);
    $manager = $managerQuery->fetch();
    if (!$manager) {
        throw new InvalidArgumentException('This restaurant has no active Manager account.');
    }

    // Preserve random generation when no owner-selected password was supplied.
    // The tenant password is always hashed before storage.
    if ($portalPassword === '') $portalPassword = bin2hex(random_bytes(16));
    $passwordHash = password_hash($portalPassword, PASSWORD_DEFAULT);
    $save = $db->prepare(
        'INSERT INTO inventory_portal_settings
             (restaurant_id, is_enabled, password_hash, updated_by, password_updated_at)
         VALUES (?, 1, ?, ?, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE is_enabled = 1, password_hash = VALUES(password_hash),
             updated_by = VALUES(updated_by), password_updated_at = UTC_TIMESTAMP()'
    );
    $save->execute([$restaurantId, $passwordHash, $actorId]);

    // The restaurant Manager is the initial portal owner. Other users still need their own active portal grant.
    $grant = $db->prepare(
        "INSERT INTO inventory_portal_access (restaurant_id, user_id, portal_role, is_active)
         VALUES (?, ?, 'OWNER', 1)
         ON DUPLICATE KEY UPDATE portal_role = 'OWNER', is_active = 1"
    );
    $grant->execute([$restaurantId, (int)$manager['id']]);

    $db->commit();
    json_out([
        'ok' => true,
        'enabled' => true,
        'restaurant_name' => (string)$restaurant['name'],
        'manager_email' => (string)$manager['email'],
        'password' => $portalPassword,
    ]);
} catch (InvalidArgumentException $error) {
    if ($db->inTransaction()) $db->rollBack();
    order_fail($error->getMessage(), 409);
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('Inventory Portal owner action: ' . $error->getMessage());
    order_fail('Unable to update Inventory Portal access. Confirm the Inventory Portal migration has been applied.', 500);
}
