<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/database.php';

$first = inventory_database();
$envPath = dirname(__DIR__, 3).'/.env';
$env = [];
foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (str_starts_with(trim($line), '#')) continue;
    [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
    $env[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
}
$second = new PDO(
    'mysql:host='.($env['DB_HOST'] ?? '127.0.0.1').';port='.($env['DB_PORT'] ?? '3306').';dbname='.($env['DB_DATABASE'] ?? 'restaurant_saas').';charset=utf8mb4',
    $env['DB_USERNAME'] ?? 'root', $env['DB_PASSWORD'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);
$lock = 'energetic_edge_inventory_kot_worker';
try {
    if ((int)$first->query("SELECT GET_LOCK('".$lock."', 0)")->fetchColumn() !== 1) throw new RuntimeException('First worker lock could not be acquired.');
    if ((int)$second->query("SELECT GET_LOCK('".$lock."', 0)")->fetchColumn() !== 0) throw new RuntimeException('A concurrent worker could acquire the active lock.');
    $first->query("SELECT RELEASE_LOCK('".$lock."')");
    if ((int)$second->query("SELECT GET_LOCK('".$lock."', 0)")->fetchColumn() !== 1) throw new RuntimeException('Worker lock could not be reacquired after release.');
    echo "PASS: a second worker connection cannot acquire the active named lock; no database rows were changed.\n";
} finally {
    $first->query("SELECT RELEASE_LOCK('".$lock."')");
    $second->query("SELECT RELEASE_LOCK('".$lock."')");
}
