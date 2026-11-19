<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=dirname(__DIR__);$env=[];
foreach(file($root.'/.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line){if(str_starts_with(trim($line),'#'))continue;[$key,$value]=array_pad(explode('=',$line,2),2,'');$env[trim($key)]=trim($value,' "');}
if(!in_array($env['DB_HOST']??'127.0.0.1',['127.0.0.1','localhost'],true))throw new RuntimeException('Local database only.');
$db=new PDO('mysql:host='.($env['DB_HOST']??'127.0.0.1').';dbname='.$env['DB_DATABASE'].';charset=utf8mb4',$env['DB_USERNAME'],$env['DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$before=[];$columns=[];
foreach(['restaurants','menu_categories','menu_items','orders','order_items'] as $table){
    $columns[$table]=array_column($db->query('SHOW COLUMNS FROM '.$table)->fetchAll(),'Field');
    $before[$table]=hash('sha256',json_encode($db->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll()));
}
foreach(['restaurants'=>['menu_layout'=>'TINYINT UNSIGNED NOT NULL DEFAULT 1','menu_background_path'=>'VARCHAR(255) NULL'],'menu_categories'=>['image_path'=>'VARCHAR(255) NULL','bottom_image_path'=>'VARCHAR(255) NULL']] as $table=>$fields){
    foreach($fields as $field=>$definition)if(!in_array($field,$columns[$table],true))$db->exec('ALTER TABLE '.$table.' ADD COLUMN '.$field.' '.$definition);
}
foreach($columns as $table=>$fields){
    $after=hash('sha256',json_encode($db->query('SELECT `'.implode('`,`',$fields).'` FROM '.$table.' ORDER BY id')->fetchAll()));
    if($after!==$before[$table])throw new RuntimeException('Existing values changed: '.$table);
}
echo "Local migration ready. Existing restaurant, category, item and order values unchanged.\n";
