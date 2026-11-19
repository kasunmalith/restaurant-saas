<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root=dirname(__DIR__);$env=[];
foreach(file($root.'/.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){if(str_starts_with(trim($line),'#'))continue;[$key,$value]=array_pad(explode('=',$line,2),2,'');$env[trim($key)]=trim($value,' "');}
if(!in_array($env['DB_HOST']??'', ['localhost','127.0.0.1'],true))throw new RuntimeException('Local database only.');
$db=new PDO('mysql:host='.$env['DB_HOST'].';dbname='.$env['DB_DATABASE'].';charset=utf8mb4',$env['DB_USERNAME'],$env['DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec("SET time_zone = '+00:00'");
$base=rtrim($env['APP_URL'],'/');$loginEmail='demo@admin.com';$loginPassword='demo';
$manager=$db->prepare('SELECT u.id,u.restaurant_id FROM users u JOIN restaurants r ON r.id=u.restaurant_id WHERE u.email=? AND u.role="RESTAURANT_ADMIN" AND u.is_active=1 AND r.is_active=1');$manager->execute([$loginEmail]);$manager=$manager->fetch();
if(!$manager)throw new RuntimeException('Local demo manager is unavailable.');
$rid=(int)$manager['restaurant_id'];$saved=$db->prepare('SELECT * FROM restaurant_pos_settings WHERE restaurant_id=?');$saved->execute([$rid]);$original=$saved->fetch();
$menu=$db->prepare('SELECT id,name,price FROM menu_items WHERE restaurant_id=? AND is_active=1 AND is_available=1 AND is_portioned=0 AND price>0 LIMIT 1');$menu->execute([$rid]);$menu=$menu->fetch();
$table=$db->prepare('SELECT id FROM restaurant_tables WHERE restaurant_id=? AND is_active=1 LIMIT 1');$table->execute([$rid]);$tableId=$table->fetchColumn();
if(!$menu||!$tableId)throw new RuntimeException('Demo restaurant needs an active table and non-portioned item.');
$http=curl_init();curl_setopt_array($http,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false]);
function request(string $route,?array $fields=null,array $headers=[]):array{global $http,$base;curl_setopt($http,CURLOPT_URL,$base.$route);curl_setopt($http,CURLOPT_HTTPHEADER,$headers);if($fields===null){curl_setopt($http,CURLOPT_HTTPGET,true);}else{curl_setopt($http,CURLOPT_POST,true);curl_setopt($http,CURLOPT_POSTFIELDS,$fields);} $body=curl_exec($http);if($body===false)throw new RuntimeException(curl_error($http));return [(int)curl_getinfo($http,CURLINFO_RESPONSE_CODE),(string)$body];}
function verify(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
$orderId=null;
try{
    $q=$db->prepare('INSERT INTO restaurant_pos_settings(restaurant_id,pos_enabled,manager_bill_edit_enabled) VALUES(?,1,0) ON DUPLICATE KEY UPDATE pos_enabled=1,manager_bill_edit_enabled=0');$q->execute([$rid]);
    $key=hash('sha256',random_bytes(32));$number='QA-QR-'.strtoupper(bin2hex(random_bytes(4)));
    $q=$db->prepare('INSERT INTO orders(restaurant_id,table_id,order_number,special_request,total,status,idempotency_key,source,order_type,created_at,updated_at) VALUES(?,?,?,"Isolated POS gap test",?,"ACCEPTED",?,"QR","DINE_IN",UTC_TIMESTAMP(),UTC_TIMESTAMP())');$q->execute([$rid,$tableId,$number,$menu['price'],$key]);$orderId=(int)$db->lastInsertId();
    $q=$db->prepare('INSERT INTO order_items(order_id,menu_item_id,item_name,unit_price,quantity,subtotal) VALUES(?,?,?,?,1,?)');$q->execute([$orderId,$menu['id'],$menu['name'],$menu['price'],$menu['price']]);
    [$code]=request('/r-owner/login',['email'=>$loginEmail,'password'=>$loginPassword]);verify($code===302,'temporary manager login');
    [$code,$html]=request('/r-owner/pos');verify($code===200,'POS screen opens for enabled manager');
    preg_match('~<script type="application/json" id="pos-config">(.*?)</script>~s',$html,$match);$config=json_decode($match[1]??'{}',true);verify(!empty($config['csrf']),'POS CSRF token available');
    $route='/r-owner/api/pos/';$header=['X-CSRF-Token: '.$config['csrf']];
    [$code,$body]=request($route.'bill',['order_id'=>$orderId,'discount'=>'0','service_charge'=>'0','tax'=>'0'],$header);$bill=json_decode($body,true);verify($code===200&&!empty($bill['invoice']['id']),'existing QR-source order can generate a POS bill');$invoiceId=(int)$bill['invoice']['id'];
    [$code]=request($route.'bill/edit',['invoice_id'=>$invoiceId,'action'=>'DISCOUNT_CHANGED','amount'=>'1.00'],$header);verify($code===403,'manager final-bill edit is denied when Super Admin toggle is OFF');
    [$code,$receipt]=request($route.'receipt?id='.$invoiceId.'&width=58');verify($code===200&&str_contains($receipt,'size:58mm auto')&&str_contains($receipt,'>Unit</th>'),'58 mm receipt includes unit price');
    [$code,$audit]=request($route.'audit?invoice_id='.$invoiceId);$audit=json_decode($audit,true);verify($code===200&&is_array($audit['edits']??null),'manager can view bill audit while editing is OFF');
    [$code,$body]=request($route.'payment',['invoice_id'=>$invoiceId,'method'=>'CARD','amount'=>$bill['invoice']['total'],'reference'=>'QA-LOCAL','idempotency_key'=>bin2hex(random_bytes(20))],$header);$payment=json_decode($body,true);verify($code===200&&($payment['payment_status']??null)==='PAID','QR-source bill can be settled');
    [$code,$body]=request($route.'order/complete',['order_id'=>$orderId],$header);verify($code===200,'paid QR-source order can be completed');
    [$code]=request($route.'kot',['order_id'=>$orderId],$header);verify($code===422,'completed order cannot receive a new KOT');
}finally{
    if($orderId){$q=$db->prepare('DELETE FROM orders WHERE id=? AND restaurant_id=?');$q->execute([$orderId,$rid]);}
    if($original){$q=$db->prepare('UPDATE restaurant_pos_settings SET pos_enabled=?,manager_bill_edit_enabled=?,updated_by=? WHERE restaurant_id=?');$q->execute([$original['pos_enabled'],$original['manager_bill_edit_enabled'],$original['updated_by'],$rid]);}
    else{$q=$db->prepare('DELETE FROM restaurant_pos_settings WHERE restaurant_id=?');$q->execute([$rid]);}
    curl_close($http);
    echo "Temporary QR order removed; original POS settings restored.\n";
}
