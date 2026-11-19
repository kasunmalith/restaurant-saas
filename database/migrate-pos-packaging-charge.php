<?php
declare(strict_types=1);

// Idempotent local-only migration for separate packaging and delivery fees.
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
$db = new PDO('mysql:host='.$env['DB_HOST'].';dbname='.$env['DB_DATABASE'].';charset=utf8mb4', $env['DB_USERNAME'], $env['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$column = $db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
foreach (['orders' => 'delivery_notes', 'pos_invoices' => 'tax'] as $table => $after) {
    $column->execute([$table, 'packaging_charge']);
    if ($column->fetchColumn() !== false) continue;
    $db->exec('ALTER TABLE `'.$table.'` ADD COLUMN packaging_charge DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `'.$after.'`');
    echo 'Added '.$table.'.packaging_charge with default 0.00'.PHP_EOL;
}
echo "Packaging charge migration complete.".PHP_EOL;
