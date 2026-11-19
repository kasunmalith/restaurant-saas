<?php
declare(strict_types=1);
// Run on the local database only, after a backup. Existing item prices and historical order lines are preserved.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
$root=dirname(__DIR__);$env=[];
foreach (file($root.'/.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line) {
    if (str_starts_with(trim($line),'#')) continue;
    [$key,$value]=array_pad(explode('=',$line,2),2,'');$env[trim($key)]=trim($value,' "');
}
if (!in_array($env['DB_HOST']??'',['localhost','127.0.0.1'],true)) throw new RuntimeException('Local database only.');
$db=new PDO('mysql:host='.$env['DB_HOST'].';dbname='.$env['DB_DATABASE'].';charset=utf8mb4',$env['DB_USERNAME'],$env['DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE IF NOT EXISTS menu_item_options (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,restaurant_id BIGINT UNSIGNED NOT NULL,menu_item_id BIGINT UNSIGNED NOT NULL,name VARCHAR(40) NOT NULL,price DECIMAL(12,2) NOT NULL,sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,is_default TINYINT(1) NOT NULL DEFAULT 0,UNIQUE KEY uq_menu_option_name (menu_item_id,name),KEY ix_menu_option_restaurant (restaurant_id,menu_item_id),FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE,FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE) ENGINE=InnoDB');
foreach (['order_items','pos_invoice_items','pos_kot_items'] as $table) {
    $q=$db->prepare('SELECT DATA_TYPE,CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME="portion"');$q->execute([$table]);$column=$q->fetch(PDO::FETCH_ASSOC);
    if (!$column) continue;
    if ($column['DATA_TYPE']==='varchar' && (int)$column['CHARACTER_MAXIMUM_LENGTH']>=50) continue;
    $db->exec('ALTER TABLE `'.$table.'` MODIFY COLUMN portion VARCHAR(50) NULL');
}
echo "Custom menu options schema ready.\n";
