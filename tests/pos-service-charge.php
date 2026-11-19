<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=dirname(__DIR__);$env=[];
foreach(file($root.'/.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){if(str_starts_with(trim($line),'#'))continue;[$key,$value]=array_pad(explode('=',$line,2),2,'');$env[trim($key)]=trim($value,' "');}
if(!in_array($env['DB_HOST']??'', ['localhost','127.0.0.1'],true))throw new RuntimeException('Local database only.');
$db=new PDO('mysql:host='.$env['DB_HOST'].';dbname='.$env['DB_DATABASE'].';charset=utf8mb4',$env['DB_USERNAME'],$env['DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$db->exec("SET time_zone = '+00:00'");
$q=$db->query("SELECT u.restaurant_id,r.contact_email,r.contact_phone,r.address,r.timezone,r.is_accepting_orders,r.service_charge_percent FROM users u JOIN restaurants r ON r.id=u.restaurant_id WHERE u.email='demo@admin.com' AND u.is_active=1 LIMIT 1");$restaurant=$q->fetch();if(!$restaurant)throw new RuntimeException('Demo manager unavailable.');
$rid=(int)$restaurant['restaurant_id'];$q=$db->prepare('SELECT * FROM restaurant_pos_settings WHERE restaurant_id=?');$q->execute([$rid]);$originalFeatures=$q->fetch();
$q=$db->prepare('SELECT id,name,price FROM menu_items WHERE restaurant_id=? AND is_active=1 AND is_available=1 AND is_portioned=0 LIMIT 1');$q->execute([$rid]);$item=$q->fetch();if(!$item)throw new RuntimeException('Available non-portioned menu item fixture unavailable.');
$base=rtrim($env['APP_URL'],'/');$curl=curl_init();curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false]);
function httpService(string $path,?array $data=null,array $headers=[]):array{global $curl,$base;curl_setopt($curl,CURLOPT_URL,$base.$path);curl_setopt($curl,CURLOPT_HTTPHEADER,$headers);if($data===null)curl_setopt($curl,CURLOPT_HTTPGET,true);else{curl_setopt($curl,CURLOPT_POST,true);curl_setopt($curl,CURLOPT_POSTFIELDS,$data);} $body=curl_exec($curl);if($body===false)throw new RuntimeException(curl_error($curl));return [(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),(string)$body];}
function checkService(bool $okay,string $label):void{if(!$okay)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
$orderId=null;$packageOrderIds=[];$createdOrderIds=[];
try{
    $default=$db->query("SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='restaurants' AND COLUMN_NAME='service_charge_percent'")->fetchColumn();
    checkService((string)$default==='10.00','new restaurants default to 10% service charge');
    $q=$db->prepare('INSERT INTO restaurant_pos_settings(restaurant_id,pos_enabled,manager_bill_edit_enabled) VALUES(?,1,0) ON DUPLICATE KEY UPDATE pos_enabled=1');$q->execute([$rid]);
    [$code]=httpService('/r-owner/login',['email'=>'demo@admin.com','password'=>'demo']);checkService($code===302,'service charge test manager session');
    [$code,$dashboard]=httpService('/r-owner/dashboard');preg_match('~id="settings-form"[^>]*><input[^>]*name="csrf" value="([^"]+)"~',$dashboard,$match);$csrf=$match[1]??'';
    checkService($code===200&&$csrf!==''&&str_contains($dashboard,'name="service_charge_percent"'),'Restaurant Profile & Settings shows service percentage');
    $settings=['csrf'=>$csrf,'contact_email'=>$restaurant['contact_email']??'','contact_phone'=>$restaurant['contact_phone']??'','address'=>$restaurant['address']??'','timezone'=>$restaurant['timezone'],'is_accepting_orders'=>$restaurant['is_accepting_orders'],'service_charge_percent'=>'12.50'];
    [$code,$body]=httpService('/r-owner/api/admin/settings',$settings);checkService($code===200&&str_contains($body,'"ok":true'),'manager can save service percentage');
    $q=$db->prepare('SELECT service_charge_percent FROM restaurants WHERE id=?');$q->execute([$rid]);checkService($q->fetchColumn()==='12.50','service percentage stored per restaurant');
    $q=$db->prepare('INSERT INTO orders(restaurant_id,order_number,total,status,idempotency_key,source,order_type,created_at,updated_at) VALUES(?, ?,"100.00","ACCEPTED",?,"POS","DINE_IN",UTC_TIMESTAMP(),UTC_TIMESTAMP())');$q->execute([$rid,'QA-SVC-'.strtoupper(bin2hex(random_bytes(4))),hash('sha256',random_bytes(32))]);$orderId=(int)$db->lastInsertId();
    $q=$db->prepare('SELECT packaging_charge,delivery_charge FROM orders WHERE id=?');$q->execute([$orderId]);$defaultCharges=$q->fetch();checkService($defaultCharges['packaging_charge']==='0.00'&&$defaultCharges['delivery_charge']==='0.00','new orders default packaging and delivery charges to zero');
    $q=$db->prepare('INSERT INTO order_items(order_id,menu_item_id,item_name,unit_price,quantity,subtotal) VALUES(?,?,?,"100.00",1,"100.00")');$q->execute([$orderId,$item['id'],$item['name']]);
    [$code,$preview]=httpService('/r-owner/api/pos/bill-preview?order_id='.$orderId.'&discount=0&tax=0');$preview=json_decode($preview,true);checkService($code===200&&($preview['service_charge']??null)==='12.50'&&($preview['total']??null)==='112.50','bill preview uses saved 12.5% rate');
    [$code,$page]=httpService('/r-owner/pos');preg_match('~<script type="application/json" id="pos-config">(.*?)</script>~s',$page,$match);$config=json_decode($match[1]??'{}',true);checkService($code===200&&($config['serviceChargePercent']??null)==='12.50','POS screen receives saved rate');
    checkService(substr_count($page,'id="pos-packaging-charge"')===1&&str_contains($page,'id="pos-packaging-charge" inputmode="decimal" value="0.00"')&&substr_count($page,'id="pos-delivery-charge"')===1&&str_contains($page,'id="pos-delivery-charge" inputmode="decimal" value="0.00"')&&str_contains($page,'</details><div class="pos-customer pos-order-charges"><label id="pos-package-charge">'),'POS shows separate zero-default charge fields outside customer details');
    foreach(['TAKEAWAY'=>['3.00','0.00'],'DELIVERY'=>['3.00','5.00']] as $type=>[$packageCost,$deliveryCost]){
        $request=['order_type'=>$type,'packaging_charge'=>$packageCost,'delivery_charge'=>$deliveryCost,'items'=>json_encode([['id'=>(int)$item['id'],'qty'=>1]],JSON_THROW_ON_ERROR),'idempotency_key'=>bin2hex(random_bytes(16))];
        [$code,$body]=httpService('/r-owner/api/pos/order',$request,['X-CSRF-Token: '.$config['csrf']]);$created=json_decode($body,true);$createdId=(int)($created['order_id']??0);if($createdId)$createdOrderIds[]=$createdId;
        $q=$db->prepare('SELECT order_type,packaging_charge,delivery_charge,customer_name,customer_phone,delivery_address FROM orders WHERE id=? AND restaurant_id=?');$q->execute([$createdId,$rid]);$createdOrder=$q->fetch();
        checkService($code===200&&$createdOrder&&$createdOrder['order_type']===$type&&$createdOrder['packaging_charge']===$packageCost&&$createdOrder['delivery_charge']===$deliveryCost&&$createdOrder['customer_name']===null&&$createdOrder['customer_phone']===null&&$createdOrder['delivery_address']===null,$type.' order accepts omitted customer details and stores separate charges');
        checkService(($created['total']??null)===number_format((float)$item['price']+(float)$packageCost+(float)$deliveryCost,2,'.',''),$type.' order defaults charge fields and calculates total correctly');
    }
    [$code,$body]=httpService('/r-owner/api/pos/bill',['order_id'=>$orderId,'discount'=>'0','service_charge'=>'0','tax'=>'0'],['X-CSRF-Token: '.$config['csrf']]);$bill=json_decode($body,true);checkService($code===200&&($bill['invoice']['total']??null)==='112.50','server enforces configured percentage despite client amount');
    foreach(['TAKEAWAY'=>['3.00','0.00'],'DELIVERY'=>['3.00','5.00']] as $type=>[$packageCost,$deliveryCost]){
        $q=$db->prepare('INSERT INTO orders(restaurant_id,order_number,total,status,idempotency_key,source,order_type,packaging_charge,delivery_charge,created_at,updated_at) VALUES(?, ?,?,"ACCEPTED",?,"POS",?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');$q->execute([$rid,'QA-PKG-'.strtoupper(bin2hex(random_bytes(4))),number_format(100+(float)$packageCost+(float)$deliveryCost,2,'.',''),hash('sha256',random_bytes(32)),$type,$packageCost,$deliveryCost]);$packageOrderIds[]=(int)$db->lastInsertId();$packageOrderId=end($packageOrderIds);
        $q=$db->prepare('INSERT INTO order_items(order_id,menu_item_id,item_name,unit_price,quantity,subtotal) VALUES(?,?,?,"100.00",1,"100.00")');$q->execute([$packageOrderId,$item['id'],$item['name']]);
        [$code,$preview]=httpService('/r-owner/api/pos/bill-preview?order_id='.$packageOrderId.'&discount=0&tax=0');$preview=json_decode($preview,true);$expected=number_format(100+(float)$packageCost+(float)$deliveryCost,2,'.','');checkService($code===200&&($preview['service_charge']??null)==='0.00'&&($preview['packaging_charge']??null)===$packageCost&&($preview['delivery_charge']??null)===$deliveryCost&&($preview['total']??null)===$expected,$type.' excludes service charge and separately includes packaging/delivery charge');
        [$code,$body]=httpService('/r-owner/api/pos/bill',['order_id'=>$packageOrderId,'discount'=>'0','service_charge'=>'99.99','tax'=>'0'],['X-CSRF-Token: '.$config['csrf']]);$bill=json_decode($body,true);checkService($code===200&&($bill['invoice']['total']??null)===$expected,$type.' bill server ignores submitted service charge');
    }
}finally{
    if($orderId){$q=$db->prepare('DELETE FROM orders WHERE id=? AND restaurant_id=?');$q->execute([$orderId,$rid]);}
    if($packageOrderIds){$q=$db->prepare('DELETE FROM orders WHERE id=? AND restaurant_id=?');foreach($packageOrderIds as $packageOrderId)$q->execute([$packageOrderId,$rid]);}
    if($createdOrderIds){$q=$db->prepare('DELETE FROM orders WHERE id=? AND restaurant_id=?');foreach($createdOrderIds as $createdOrderId)$q->execute([$createdOrderId,$rid]);}
    $q=$db->prepare('UPDATE restaurants SET service_charge_percent=? WHERE id=?');$q->execute([$restaurant['service_charge_percent'],$rid]);
    if($originalFeatures){$q=$db->prepare('UPDATE restaurant_pos_settings SET pos_enabled=?,manager_bill_edit_enabled=?,updated_by=? WHERE restaurant_id=?');$q->execute([$originalFeatures['pos_enabled'],$originalFeatures['manager_bill_edit_enabled'],$originalFeatures['updated_by'],$rid]);}
    else{$q=$db->prepare('DELETE FROM restaurant_pos_settings WHERE restaurant_id=?');$q->execute([$rid]);}
    curl_close($curl);echo "Temporary service-charge fixture removed; original settings restored.\n";
}
