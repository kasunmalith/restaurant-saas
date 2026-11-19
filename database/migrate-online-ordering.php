<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli') { http_response_code(404); exit; }
$root=dirname(__DIR__);$env=[];
foreach(file($root.'/.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){
    if(str_starts_with(trim($line),'#'))continue;
    [$key,$value]=array_pad(explode('=',$line,2),2,'');$env[trim($key)]=trim($value,' "');
}
if(!in_array($env['DB_HOST']??'', ['localhost','127.0.0.1'],true))throw new RuntimeException('This migration is restricted to localhost.');
$db=new PDO('mysql:host='.$env['DB_HOST'].';dbname='.$env['DB_DATABASE'].';charset=utf8mb4',$env['DB_USERNAME'],$env['DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$check=$db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
$check->execute(['restaurants','online_ordering_enabled']);
if(!$check->fetchColumn()){$db->exec('ALTER TABLE restaurants ADD COLUMN online_ordering_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER is_accepting_orders');echo "Added restaurants.online_ordering_enabled (default ON).".PHP_EOL;}
else echo "restaurants.online_ordering_enabled already exists; no change made.".PHP_EOL;
