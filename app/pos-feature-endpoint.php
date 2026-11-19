<?php
declare(strict_types=1);

if (!in_array($path, ['/api/owner/pos-features','/api/owner/pos-audit'], true)) return;
$userId = (int)($_SESSION['user']['id'] ?? 0);
$account = $db->prepare("SELECT id FROM users WHERE id=? AND role='SUPER_ADMIN' AND is_active=1");
$account->execute([$userId]);
if (!$account->fetchColumn()) order_fail('Super Admin access required.', 403);
if ($path === '/api/owner/pos-audit') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') order_fail('GET required.', 405);
    $restaurantId = filter_var($_GET['restaurant_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($restaurantId === false || $restaurantId === null) order_fail('Select a restaurant.');
    $q = $db->prepare('SELECT a.created_at,a.action_type,a.item_id,a.previous_value,a.new_value,a.reason,a.user_name,a.user_role,a.ip_address,i.invoice_number,o.order_number FROM pos_bill_edit_audit a JOIN pos_invoices i ON i.id=a.invoice_id AND i.restaurant_id=a.restaurant_id JOIN orders o ON o.id=a.order_id AND o.restaurant_id=a.restaurant_id WHERE a.restaurant_id=? ORDER BY a.id DESC LIMIT 100');
    $q->execute([$restaurantId]);
    json_out(['edits'=>$q->fetchAll()]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') order_fail('POST required.', 405);
if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
    order_fail('Refresh your security token.', 403);
}
$restaurantId = filter_var($_POST['restaurant_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$pos = (string)($_POST['pos_enabled'] ?? '');
$edit = (string)($_POST['manager_bill_edit_enabled'] ?? '');
if ($restaurantId === false || !in_array($pos, ['0', '1'], true) || !in_array($edit, ['0', '1'], true)) {
    order_fail('Invalid POS feature settings.');
}
if ($pos === '0') $edit = '0';
try {
    $db->beginTransaction();
    $restaurant = $db->prepare('SELECT id FROM restaurants WHERE id=? FOR UPDATE');
    $restaurant->execute([$restaurantId]);
    if (!$restaurant->fetchColumn()) {
        $db->rollBack();
        order_fail('Restaurant not found.', 404);
    }
    $save = $db->prepare('INSERT INTO restaurant_pos_settings (restaurant_id,pos_enabled,manager_bill_edit_enabled,updated_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE pos_enabled=VALUES(pos_enabled),manager_bill_edit_enabled=VALUES(manager_bill_edit_enabled),updated_by=VALUES(updated_by)');
    $save->execute([$restaurantId, (int)$pos, (int)$edit, $userId]);
    $db->commit();
    json_out(['ok' => true, 'pos_enabled' => (int)$pos, 'manager_bill_edit_enabled' => (int)$edit]);
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('POS feature update failed: '.$error->getMessage());
    order_fail('Unable to update POS features. Confirm the POS migration is installed.', 500);
}
