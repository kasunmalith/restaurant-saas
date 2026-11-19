<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
$env = [];
foreach (file($root.'/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (str_starts_with(trim($line), '#')) continue;
    [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
    $env[trim($key)] = trim($value, ' "');
}
if (!in_array($env['DB_HOST'] ?? '', ['localhost', '127.0.0.1'], true)) throw new RuntimeException('Local database only.');
$db = new PDO('mysql:host='.$env['DB_HOST'].';dbname='.$env['DB_DATABASE'].';charset=utf8mb4', $env['DB_USERNAME'], $env['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$candidate = $db->query("SELECT u.id,u.restaurant_id FROM users u JOIN restaurants r ON r.id=u.restaurant_id WHERE u.role='RESTAURANT_ADMIN' AND u.is_active=1 AND r.is_active=1 LIMIT 1")->fetch();
if (!$candidate) throw new RuntimeException('No active local manager available for the POS UI check.');
function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function base(): string { return '/restaurant-saas'; }
function site_base(): string { return '/restaurant-saas'; }
function csrf(): string { return 'local-ui-test-token'; }
function layout(string $title, string $body): never { $GLOBALS['posTestHtml'] = $body; throw new RuntimeException('rendered'); }
function order_fail(string $message, int $code = 422): never { throw new RuntimeException($message.' '.$code); }
function check(bool $condition, string $description): void { if (!$condition) throw new RuntimeException('FAIL '.$description); echo 'PASS '.$description.PHP_EOL; }

$db->beginTransaction();
try {
    $enable = $db->prepare('INSERT INTO restaurant_pos_settings(restaurant_id,pos_enabled,manager_bill_edit_enabled) VALUES(?,1,1) ON DUPLICATE KEY UPDATE pos_enabled=1,manager_bill_edit_enabled=1');
    $enable->execute([(int)$candidate['restaurant_id']]);
    $_SESSION['user'] = ['id' => (int)$candidate['id']];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $path = '/pos';
    try { require $root.'/app/pos-endpoints.php'; } catch (RuntimeException $error) { if ($error->getMessage() !== 'rendered') throw $error; }
    $html = $GLOBALS['posTestHtml'] ?? '';
    foreach (['pos-kot-only','pos-kot','pos-preview','pos-bill','pos-bill-payment','pos-bill-print','pos-receipt','pos-pay','pos-print-width','pos-bill-edit','pos-audit-panel','pos-order-filter','pos-report-load'] as $id) {
        check(str_contains($html, 'id="'.$id.'"'), 'POS screen has '.$id);
    }
    check(str_contains($html, '80 mm') && str_contains($html, '58 mm'), 'Thermal paper sizes are selectable');
    check(str_contains($html, 'Software Development') === false, 'POS body delegates shared footer to layout');
} finally {
    $db->rollBack();
}
echo "Local POS feature flag rolled back.\n";
