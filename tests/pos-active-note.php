<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=dirname(__DIR__);$env=[];
foreach(file($root.'/.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){if(str_starts_with(trim($line),'#'))continue;[$key,$value]=array_pad(explode('=',$line,2),2,'');$env[trim($key)]=trim($value,' "');}
if(!in_array($env['DB_HOST']??'', ['localhost','127.0.0.1'],true))throw new RuntimeException('Local database only.');
$db=new PDO('mysql:host='.$env['DB_HOST'].';dbname='.$env['DB_DATABASE'].';charset=utf8mb4',$env['DB_USERNAME'],$env['DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$db->exec("SET time_zone = '+00:00'");
$q=$db->query("SELECT u.restaurant_id FROM users u JOIN restaurants r ON r.id=u.restaurant_id WHERE u.email='demo@admin.com' AND u.is_active=1 AND r.is_active=1 LIMIT 1");$manager=$q->fetch();if(!$manager)throw new RuntimeException('Demo manager unavailable.');$rid=(int)$manager['restaurant_id'];
$q=$db->prepare('SELECT * FROM restaurant_pos_settings WHERE restaurant_id=?');$q->execute([$rid]);$original=$q->fetch();
$q=$db->prepare('SELECT id,name,price FROM menu_items WHERE restaurant_id=? AND is_active=1 AND is_available=1 AND is_portioned=0 LIMIT 2');$q->execute([$rid]);$items=$q->fetchAll();$q=$db->prepare('SELECT id FROM restaurant_tables WHERE restaurant_id=? AND is_active=1 LIMIT 1');$q->execute([$rid]);$table=$q->fetchColumn();if(count($items)<2||!$table)throw new RuntimeException('Two active menu items and a table are required.');
$base=rtrim($env['APP_URL'],'/');$curl=curl_init();curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false]);
function postNote(string $path,?array $fields=null,array $headers=[]):array{global $curl,$base;curl_setopt($curl,CURLOPT_URL,$base.$path);curl_setopt($curl,CURLOPT_HTTPHEADER,$headers);if($fields===null)curl_setopt($curl,CURLOPT_HTTPGET,true);else{curl_setopt($curl,CURLOPT_POST,true);curl_setopt($curl,CURLOPT_POSTFIELDS,$fields);} $body=curl_exec($curl);if($body===false)throw new RuntimeException(curl_error($curl));return [(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),(string)$body];}
$orderId=null;
try{
    $q=$db->prepare('INSERT INTO restaurant_pos_settings(restaurant_id,pos_enabled,manager_bill_edit_enabled) VALUES(?,1,0) ON DUPLICATE KEY UPDATE pos_enabled=1');$q->execute([$rid]);
    $q=$db->prepare('INSERT INTO orders(restaurant_id,table_id,order_number,total,status,idempotency_key,source,order_type,created_at,updated_at) VALUES(?,?,?, ?,"ACCEPTED",?,"POS","DINE_IN",UTC_TIMESTAMP(),UTC_TIMESTAMP())');$q->execute([$rid,$table,'QA-NOTE-'.strtoupper(bin2hex(random_bytes(4))),$items[0]['price'],hash('sha256',random_bytes(32))]);$orderId=(int)$db->lastInsertId();
    $q=$db->prepare('INSERT INTO order_items(order_id,menu_item_id,item_name,unit_price,quantity,subtotal) VALUES(?,?,?,?,1,?)');$q->execute([$orderId,$items[0]['id'],$items[0]['name'],$items[0]['price'],$items[0]['price']]);
    [$code]=postNote('/r-owner/login',['email'=>'demo@admin.com','password'=>'demo']);if($code!==302)throw new RuntimeException('Manager login failed.');
    [$code,$page]=postNote('/r-owner/pos');preg_match('~<script type="application/json" id="pos-config">(.*?)</script>~s',$page,$match);$config=json_decode($match[1]??'{}',true);if($code!==200||empty($config['csrf']))throw new RuntimeException('POS CSRF unavailable.');
    $instruction='Extra spicy; no onion';
    [$code,$body]=postNote('/r-owner/api/pos/order/item',['order_id'=>$orderId,'action'=>'ADD','menu_item_id'=>$items[1]['id'],'portion'=>'','quantity'=>2,'special_instruction'=>$instruction],['X-CSRF-Token: '.$config['csrf']]);if($code!==200)throw new RuntimeException('Adding item failed: '.$body);
    $q=$db->prepare('SELECT quantity,special_instruction FROM order_items WHERE order_id=? AND menu_item_id=?');$q->execute([$orderId,$items[1]['id']]);$line=$q->fetch();if(!$line||(int)$line['quantity']!==2||$line['special_instruction']!==$instruction)throw new RuntimeException('Active-order instruction was not saved.');
    echo "PASS active-order item quantity and special instruction saved\n";
}finally{
    if($orderId){$q=$db->prepare('DELETE FROM orders WHERE id=? AND restaurant_id=?');$q->execute([$orderId,$rid]);}
    if($original){$q=$db->prepare('UPDATE restaurant_pos_settings SET pos_enabled=?,manager_bill_edit_enabled=?,updated_by=? WHERE restaurant_id=?');$q->execute([$original['pos_enabled'],$original['manager_bill_edit_enabled'],$original['updated_by'],$rid]);}
    else{$q=$db->prepare('DELETE FROM restaurant_pos_settings WHERE restaurant_id=?');$q->execute([$rid]);}
    curl_close($curl);echo "Temporary active order removed; settings restored.\n";
}
