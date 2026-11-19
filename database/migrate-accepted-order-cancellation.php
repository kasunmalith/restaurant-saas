<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$env = [];
foreach (file($root.'/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (str_starts_with(trim($line), '#')) continue;
    [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
    $env[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
}
if (!in_array($env['DB_HOST'] ?? '', ['localhost', '127.0.0.1'], true)) {
    throw new RuntimeException('This migration runner is restricted to localhost.');
}
$db = new PDO(
    'mysql:host='.$env['DB_HOST'].';port='.($env['DB_PORT'] ?? '3306').';dbname='.$env['DB_DATABASE'].';charset=utf8mb4',
    $env['DB_USERNAME'], $env['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$column = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
$column->execute(['restaurant_pos_settings', 'accepted_order_cancellation_enabled']);
if ($column->fetchColumn()) {
    echo "Accepted-order cancellation setting already exists.\n";
    exit;
}
$db->exec(file_get_contents(__DIR__.'/028_accepted_order_cancellation.sql'));
echo "Added restaurant_pos_settings.accepted_order_cancellation_enabled; existing restaurants remain disabled.\n";
