<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=dirname(__DIR__);$env=[];
foreach(file($root.'/.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){if(str_starts_with(trim($line),'#'))continue;[$key,$value]=array_pad(explode('=',$line,2),2,'');$env[trim($key)]=trim($value,' "');}
if(!in_array($env['DB_HOST']??'', ['localhost','127.0.0.1'],true))throw new RuntimeException('Local database only.');
$db=new PDO('mysql:host='.$env['DB_HOST'].';dbname='.$env['DB_DATABASE'].';charset=utf8mb4',$env['DB_USERNAME'],$env['DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$manager=$db->query("SELECT u.id,u.restaurant_id FROM users u JOIN restaurants r ON r.id=u.restaurant_id WHERE u.email='demo@admin.com' AND u.is_active=1 AND r.is_active=1 LIMIT 1")->fetch();if(!$manager)throw new RuntimeException('Local demo manager unavailable.');
$rid=(int)$manager['restaurant_id'];$q=$db->prepare('SELECT * FROM restaurant_pos_settings WHERE restaurant_id=?');$q->execute([$rid]);$original=$q->fetch();
$curl=curl_init();curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false]);$base=rtrim($env['APP_URL'],'/');$orderId=null;
try{
    $q=$db->prepare('INSERT INTO restaurant_pos_settings(restaurant_id,pos_enabled,manager_bill_edit_enabled) VALUES(?,1,0) ON DUPLICATE KEY UPDATE pos_enabled=1');$q->execute([$rid]);
    $q=$db->prepare('INSERT INTO orders(restaurant_id,order_number,total,status,idempotency_key,source,order_type,created_at,updated_at) VALUES(?, ?,"123.45","ACCEPTED",?,"POS","TAKEAWAY",UTC_TIMESTAMP(),UTC_TIMESTAMP())');$q->execute([$rid,'QA-MONTH-'.strtoupper(bin2hex(random_bytes(4))),hash('sha256',random_bytes(32))]);$orderId=(int)$db->lastInsertId();
    $q=$db->prepare('INSERT INTO pos_invoices(restaurant_id,order_id,invoice_number,subtotal,total,payment_status,generated_by,created_at,updated_at) VALUES(?,?,?,"123.45","123.45","UNPAID",?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');$q->execute([$rid,$orderId,'QA-MONTH-INV-'.strtoupper(bin2hex(random_bytes(4))),$manager['id']]);
    curl_setopt($curl,CURLOPT_URL,$base.'/r-owner/login');curl_setopt($curl,CURLOPT_POST,true);curl_setopt($curl,CURLOPT_POSTFIELDS,['email'=>'demo@admin.com','password'=>'demo']);curl_exec($curl);
    if(curl_getinfo($curl,CURLINFO_RESPONSE_CODE)!==302)throw new RuntimeException('Manager login failed.');
    $from=date('Y-m-01');$to=date('Y-m-d');curl_setopt($curl,CURLOPT_URL,$base.'/r-owner/api/pos/reports?from='.$from.'&to='.$to);curl_setopt($curl,CURLOPT_HTTPGET,true);$body=curl_exec($curl);
    if(curl_getinfo($curl,CURLINFO_RESPONSE_CODE)!==200)throw new RuntimeException('POS report request failed: '.$body);
    $report=json_decode((string)$body,true,512,JSON_THROW_ON_ERROR);if(!is_array($report['monthly']??null)||!is_array($report['daily']??null))throw new RuntimeException('Monthly or daily report missing.');
    $cents=static function(string $amount):int{[$whole,$fraction]=array_pad(explode('.',$amount,2),2,'0');return (int)$whole*100+(int)str_pad($fraction,2,'0');};
    $dailyTotal=array_sum(array_map(static fn(array $row):int=>$cents($row['total']),$report['daily']));
    $monthlyTotal=array_sum(array_map(static fn(array $row):int=>$cents($row['total']),$report['monthly']));
    if($dailyTotal!==$monthlyTotal||$monthlyTotal<12345)throw new RuntimeException('Monthly report does not include and reconcile the controlled sale.');
    echo "PASS monthly sales reconcile with daily sales\n";
}finally{
    if($orderId){$q=$db->prepare('DELETE FROM orders WHERE id=? AND restaurant_id=?');$q->execute([$orderId,$rid]);}
    if($original){$q=$db->prepare('UPDATE restaurant_pos_settings SET pos_enabled=?,manager_bill_edit_enabled=?,updated_by=? WHERE restaurant_id=?');$q->execute([$original['pos_enabled'],$original['manager_bill_edit_enabled'],$original['updated_by'],$rid]);}
    else{$q=$db->prepare('DELETE FROM restaurant_pos_settings WHERE restaurant_id=?');$q->execute([$rid]);}
    curl_close($curl);echo "Original POS settings restored.\n";
}
