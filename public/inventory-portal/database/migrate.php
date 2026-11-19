<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/database.php';
$db = inventory_database();
$db->exec(
    'CREATE TABLE IF NOT EXISTS inventory_schema_migrations (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        migration VARCHAR(191) NOT NULL,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_inventory_migration (migration)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$files = glob(__DIR__ . '/migrations/*.sql') ?: [];
sort($files, SORT_STRING);
$applied = $db->prepare('SELECT 1 FROM inventory_schema_migrations WHERE migration = ? LIMIT 1');
$record = $db->prepare('INSERT INTO inventory_schema_migrations (migration) VALUES (?)');
foreach ($files as $file) {
    $name = basename($file);
    $applied->execute([$name]);
    if ($applied->fetchColumn()) {
        echo "SKIP $name (already applied)\n";
        continue;
    }
    $sql = file_get_contents($file);
    if (!is_string($sql) || trim($sql) === '') {
        throw new RuntimeException("Migration is empty: $name");
    }
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        $db->exec($statement);
    }
    $record->execute([$name]);
    echo "APPLIED $name\n";
}
