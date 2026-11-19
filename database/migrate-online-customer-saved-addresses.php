<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=dirname(__DIR__);$env=[];
foreach(file($root.'/.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){if(str_starts_with(trim($line),'#'))continue;[$key,$value]=array_pad(explode('=',$line,2),2,'');$env[trim($key)]=trim($value,' "');}
if(!in_array($env['DB_HOST']??'', ['localhost','127.0.0.1'],true))throw new RuntimeException('This migration is restricted to localhost.');
$db=new PDO('mysql:host='.$env['DB_HOST'].';dbname='.$env['DB_DATABASE'].';charset=utf8mb4',$env['DB_USERNAME'],$env['DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$exists=$db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$exists->execute(['online_customer_addresses']);
if(!$exists->fetchColumn()){
    $db->exec("CREATE TABLE online_customer_addresses (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, restaurant_id BIGINT UNSIGNED NOT NULL, customer_id BIGINT UNSIGNED NOT NULL, label VARCHAR(80) NULL, address VARCHAR(500) NOT NULL, address_hash CHAR(64) NOT NULL, is_default TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE KEY uq_online_customer_address (restaurant_id,customer_id,address_hash), INDEX idx_online_customer_addresses (restaurant_id,customer_id,is_default,id), FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE, FOREIGN KEY (customer_id) REFERENCES restaurant_customers(id) ON DELETE CASCADE) ENGINE=InnoDB");
    echo "Created online_customer_addresses.".PHP_EOL;
}else echo "online_customer_addresses already exists.".PHP_EOL;
$seed=$db->exec("INSERT IGNORE INTO online_customer_addresses(restaurant_id,customer_id,label,address,address_hash,is_default,created_at,updated_at) SELECT a.restaurant_id,a.customer_id,'Primary',TRIM(c.address),SHA2(TRIM(c.address),256),1,UTC_TIMESTAMP(),UTC_TIMESTAMP() FROM online_customer_accounts a JOIN restaurant_customers c ON c.id=a.customer_id AND c.restaurant_id=a.restaurant_id WHERE c.address IS NOT NULL AND TRIM(c.address)<>''");
echo "Seeded {$seed} existing account address(es).".PHP_EOL;
