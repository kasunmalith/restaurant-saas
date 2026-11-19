<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ob_start();
require dirname(__DIR__) . '/vendor/autoload.php';
$env = Dotenv\Dotenv::createArrayBacked(dirname(__DIR__))->load();
$localHosts = ['localhost', '127.0.0.1', '::1'];
if (!in_array(strtolower((string)parse_url($env['APP_URL'] ?? '', PHP_URL_HOST)), $localHosts, true)
    || !in_array(strtolower((string)($env['DB_HOST'] ?? '')), $localHosts, true)) {
    throw new RuntimeException('This integration test may only run against a local app and local database.');
}
$db = new PDO(
    'mysql:host=' . $env['DB_HOST'] . ';dbname=' . $env['DB_DATABASE'] . ';charset=utf8mb4',
    $env['DB_USERNAME'],
    $env['DB_PASSWORD'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
ini_set('session.use_cookies', '0');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$_SESSION = [];
$slug = 'qa-inventory-' . bin2hex(random_bytes(8));
$email = $slug . '@example.test';
$managerPassword = bin2hex(random_bytes(16));
$ownerEmail = 'qa-owner-' . $slug . '@example.test';
$ownerPassword = bin2hex(random_bytes(16));
$ownerId = 0;
$restaurantId = 0;
$managerId = 0;
$client = curl_init();
curl_setopt_array($client, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_COOKIEJAR => '', CURLOPT_TIMEOUT => 20]);

function owner_portal_request(string $path, ?array $body = null): array
{
    global $client, $env;
    curl_setopt($client, CURLOPT_URL, rtrim($env['APP_URL'], '/') . $path);
    if ($body === null) {
        curl_setopt($client, CURLOPT_HTTPGET, true);
    } else {
        curl_setopt($client, CURLOPT_POST, true);
        curl_setopt($client, CURLOPT_POSTFIELDS, $body);
    }
    $response = curl_exec($client);
    if ($response === false) throw new RuntimeException(curl_error($client));
    return [(int)curl_getinfo($client, CURLINFO_RESPONSE_CODE), (string)$response];
}

function owner_portal_check(bool $passed, string $label): void
{
    if (!$passed) throw new RuntimeException('FAIL ' . $label);
    echo 'PASS ' . $label . PHP_EOL;
}

try {
    $insert = $db->prepare("INSERT INTO users (restaurant_id,role,name,email,password_hash,is_active) VALUES (NULL,'SUPER_ADMIN','QA Inventory Owner',?,?,1)");
    $insert->execute([$ownerEmail, password_hash($ownerPassword, PASSWORD_DEFAULT)]);
    $ownerId = (int)$db->lastInsertId();

    $insert = $db->prepare('INSERT INTO restaurants (name,slug) VALUES (?,?)');
    $insert->execute(['QA Inventory Restaurant', $slug]);
    $restaurantId = (int)$db->lastInsertId();

    $insert = $db->prepare("INSERT INTO users (restaurant_id,role,name,email,password_hash,is_active) VALUES (?,'RESTAURANT_ADMIN','QA Inventory Manager',?,?,1)");
    $insert->execute([$restaurantId, $email, password_hash($managerPassword, PASSWORD_DEFAULT)]);
    $managerId = (int)$db->lastInsertId();

    [$code] = owner_portal_request('/login', ['email' => $ownerEmail, 'password' => $ownerPassword]);
    owner_portal_check($code === 302, 'SaaS Owner can sign in');
    [, $dashboard] = owner_portal_request('/dashboard');
    owner_portal_check(str_contains($dashboard, 'id="inventory-portal-access"') && str_contains($dashboard, 'owner-inventory-restaurant') && str_contains($dashboard, 'minlength="6"'), 'owner panel renders a custom password field with six-character minimum');
    [$assetCode, $assetBody] = owner_portal_request('/assets/owner-inventory-portal.js?v=2');
    owner_portal_check($assetCode === 200 && str_contains($assetBody, "payload.set('password',requestedPassword)"), 'owner control JavaScript submits the selected password');
    [$assetCode] = owner_portal_request('/assets/owner-inventory-portal.css?v=2');
    owner_portal_check($assetCode === 200, 'owner control stylesheet loads');
    preg_match('/name="csrf" type="hidden" value="([a-f0-9]+)"/', $dashboard, $csrfMatch);
    $csrf = $csrfMatch[1] ?? '';
    owner_portal_check($csrf !== '', 'owner security token is present');

    $chosenPassword = 'six88x';
    [$code, $body] = owner_portal_request('/api/owner/inventory-portal', ['csrf' => $csrf, 'restaurant_id' => $restaurantId, 'action' => 'enable_generate', 'password' => $chosenPassword]);
    $enabled = json_decode($body, true);
    owner_portal_check($code === 200 && !empty($enabled['ok']) && ($enabled['password'] ?? '') === $chosenPassword, 'owner enables selected restaurant using their own six-character password');

    $query = $db->prepare('SELECT is_enabled,password_hash FROM inventory_portal_settings WHERE restaurant_id=?');
    $query->execute([$restaurantId]);
    $setting = $query->fetch();
    owner_portal_check($setting && (int)$setting['is_enabled'] === 1 && password_verify($chosenPassword, $setting['password_hash']), 'custom portal password is stored as a hash and portal is enabled');

    $query = $db->prepare('SELECT portal_role,is_active FROM inventory_portal_access WHERE restaurant_id=? AND user_id=?');
    $query->execute([$restaurantId, $managerId]);
    $grant = $query->fetch();
    owner_portal_check($grant && $grant['portal_role'] === 'OWNER' && (int)$grant['is_active'] === 1, 'active restaurant Manager receives initial portal Owner access');

    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    require_once dirname(__DIR__) . '/public/inventory-portal/app/auth.php';
    owner_portal_check(inventory_login($db, $email, $chosenPassword), 'Manager email signs in with the owner-selected portal password');
    owner_portal_check(inventory_context($db) !== null, 'enabled portal session is tenant-scoped and valid');
    owner_portal_check(!inventory_login($db, $email, $managerPassword), 'regular SaaS password is not used for Inventory Portal login');

    [$code, $body] = owner_portal_request('/api/owner/inventory-portal', ['csrf' => $csrf, 'restaurant_id' => $restaurantId, 'action' => 'regenerate', 'password' => 'short']);
    $tooShort = json_decode($body, true);
    owner_portal_check($code === 409 && str_contains((string)($tooShort['error'] ?? ''), 'at least 6 characters'), 'server rejects passwords shorter than six characters');
    $query = $db->prepare('SELECT password_hash FROM inventory_portal_settings WHERE restaurant_id=?');
    $query->execute([$restaurantId]);
    owner_portal_check(password_verify($chosenPassword, (string)$query->fetchColumn()), 'rejected short password does not replace the existing portal password');

    $changedPassword = 'ownerSet6';
    [$code, $body] = owner_portal_request('/api/owner/inventory-portal', ['csrf' => $csrf, 'restaurant_id' => $restaurantId, 'action' => 'regenerate', 'password' => $changedPassword]);
    $changed = json_decode($body, true);
    owner_portal_check($code === 200 && ($changed['password'] ?? '') === $changedPassword, 'owner can set a chosen password for an already-enabled portal');
    $query = $db->prepare('SELECT password_hash FROM inventory_portal_settings WHERE restaurant_id=?');
    $query->execute([$restaurantId]);
    owner_portal_check(password_verify($changedPassword, (string)$query->fetchColumn()), 'chosen replacement password is stored as a hash');
    owner_portal_check(!inventory_login($db, $email, $chosenPassword), 'previous custom password stops working after change');
    owner_portal_check(inventory_login($db, $email, $changedPassword), 'new custom password signs in successfully');

    [$code, $body] = owner_portal_request('/api/owner/inventory-portal', ['csrf' => $csrf, 'restaurant_id' => $restaurantId, 'action' => 'regenerate']);
    $rotated = json_decode($body, true);
    owner_portal_check($code === 200 && !empty($rotated['password']) && $rotated['password'] !== $changedPassword, 'owner can still generate a random replacement password');
    $query = $db->prepare('SELECT password_hash FROM inventory_portal_settings WHERE restaurant_id=?');
    $query->execute([$restaurantId]);
    owner_portal_check(password_verify($rotated['password'], (string)$query->fetchColumn()), 'rotated password replaces the previous stored hash');
    owner_portal_check(!inventory_login($db, $email, $changedPassword), 'previous portal password stops working after rotation');
    owner_portal_check(inventory_login($db, $email, $rotated['password']), 'rotated password signs in successfully');

    [$code, $body] = owner_portal_request('/api/owner/inventory-portal', ['csrf' => $csrf, 'restaurant_id' => $restaurantId, 'action' => 'disable']);
    owner_portal_check($code === 200 && !empty(json_decode($body, true)['ok']), 'owner disables the selected portal');
    owner_portal_check(inventory_context($db) === null, 'disabling blocks the current portal session');
    $bucket = hash('sha256', $email . "\n127.0.0.1");
    $db->prepare('DELETE FROM inventory_login_attempts WHERE bucket_hash=?')->execute([$bucket]);
} finally {
    if ($restaurantId > 0) {
        $db->prepare('DELETE FROM inventory_portal_access WHERE restaurant_id=?')->execute([$restaurantId]);
        $db->prepare('DELETE FROM inventory_portal_settings WHERE restaurant_id=?')->execute([$restaurantId]);
        $db->prepare('DELETE FROM users WHERE restaurant_id=?')->execute([$restaurantId]);
        $db->prepare('DELETE FROM restaurants WHERE id=?')->execute([$restaurantId]);
    }
    if ($ownerId > 0) $db->prepare("DELETE FROM users WHERE id=? AND role='SUPER_ADMIN'")->execute([$ownerId]);
    if (isset($email)) $db->prepare('DELETE FROM inventory_login_attempts WHERE bucket_hash=?')->execute([hash('sha256', $email . "\n127.0.0.1")]);
    if (is_resource($client) || $client instanceof CurlHandle) curl_close($client);
    if (ob_get_level() > 0) ob_end_flush();
}
