<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=dirname(__DIR__);$env=[];
foreach(file($root.'/.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){if(str_starts_with(trim($line),'#'))continue;[$key,$value]=array_pad(explode('=', $line,2),2,'');$env[trim($key)]=trim($value,' "');}
if(!in_array($env['DB_HOST']??'', ['localhost','127.0.0.1'],true))throw new RuntimeException('This migration runner is restricted to localhost.');
$db=new PDO('mysql:host='.$env['DB_HOST'].';dbname='.$env['DB_DATABASE'].';charset=utf8mb4',$env['DB_USERNAME'],$env['DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$exists=$db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$exists->execute(['users','menu_item_delete_enabled']);
if($exists->fetchColumn())echo "menu_item_delete_enabled already exists.".PHP_EOL;else{$db->exec('ALTER TABLE users ADD COLUMN menu_item_delete_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER menu_item_edit_enabled');echo "Added menu_item_delete_enabled (enabled for existing accounts).".PHP_EOL;}
