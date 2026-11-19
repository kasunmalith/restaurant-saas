<?php
declare(strict_types=1);

// Local-only, repeatable first POS migration. Never exposed as a web endpoint.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$env = [];
foreach (file($root.'/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (str_starts_with(trim($line), '#')) continue;
    [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
    $env[trim($key)] = trim($value, ' "');
}
if (!in_array($env['DB_HOST'] ?? '', ['localhost', '127.0.0.1'], true)) {
    throw new RuntimeException('This migration is restricted to a local database host.');
}
$db = new PDO(
    'mysql:host='.$env['DB_HOST'].';dbname='.$env['DB_DATABASE'].';charset=utf8mb4',
    $env['DB_USERNAME'],
    $env['DB_PASSWORD'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$db->exec(file_get_contents(__DIR__.'/014_pos_feature_settings.sql'));
echo "POS feature settings table is ready. All restaurants remain OFF by default.\n";
