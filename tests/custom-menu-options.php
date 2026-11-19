<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
ob_start();
$env=parse_ini_file(dirname(__DIR__).'/.env');
if(!in_array($env['DB_HOST']??'',['localhost','127.0.0.1'],true))throw new RuntimeException('Local database only.');
$base=rtrim($env['APP_URL'],'/');
if(parse_url($base,PHP_URL_HOST)!=='localhost')throw new RuntimeException('Localhost only.');
$db=new PDO('mysql:host='.$env['DB_HOST'].';dbname='.$env['DB_DATABASE'].';charset=utf8mb4',$env['DB_USERNAME'],$env['DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$q=$db->query("SELECT u.id user_id,u.restaurant_id,r.slug,c.id category_id,t.id table_id,t.token_hash FROM users u JOIN restaurants r ON r.id=u.restaurant_id JOIN restaurant_pos_settings p ON p.restaurant_id=u.restaurant_id AND p.pos_enabled=1 JOIN menu_categories c ON c.restaurant_id=u.restaurant_id JOIN restaurant_tables t ON t.restaurant_id=u.restaurant_id AND t.is_active=1 WHERE u.role='RESTAURANT_ADMIN' AND u.is_active=1 ORDER BY u.id LIMIT 1");
$fixture=$q->fetch();if(!$fixture)throw new RuntimeException('Local manager/category fixture required.');
session_name('EE_R_OWNER');session_save_path('C:/wamp64/tmp');session_id(bin2hex(random_bytes(16)));session_start();
$_SESSION['user']=['id'=>$fixture['user_id'],'restaurant_id'=>$fixture['restaurant_id'],'role'=>'RESTAURANT_ADMIN'];$_SESSION['csrf']=bin2hex(random_bytes(32));
$cookie='EE_R_OWNER='.session_id();$csrf=$_SESSION['csrf'];session_write_close();
$id=0;$orderId=0;$optionalOrderId=0;$qrOrderId=0;$sessionId=0;$customerId=0;$qaPhone='';$name='QA Custom Sizes '.bin2hex(random_bytes(4));
function call_local(string $url,?array $fields=null,?string $cookie=null,array $headers=[]):array{
    $handle=curl_init($url);curl_setopt_array($handle,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20]);
    if($cookie)curl_setopt($handle,CURLOPT_COOKIE,$cookie);
    if($headers)curl_setopt($handle,CURLOPT_HTTPHEADER,$headers);
    if($fields!==null){curl_setopt($handle,CURLOPT_POST,true);curl_setopt($handle,CURLOPT_POSTFIELDS,$fields);}
    $body=(string)curl_exec($handle);$status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);curl_close($handle);
    return [$status,json_decode($body,true)??$body];
}
function call_json(string $url,array $data):array{
    $handle=curl_init($url);curl_setopt_array($handle,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode($data,JSON_THROW_ON_ERROR)]);
    $body=(string)curl_exec($handle);$status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);curl_close($handle);return [$status,json_decode($body,true)??$body];
}
try{
    $options=[['name'=>'Small','price'=>'350.00','default'=>false],['name'=>'Medium','price'=>'490.00','default'=>true],['name'=>'Large','price'=>'650.00','default'=>false]];
    [$status,$created]=call_local($base.'/r-owner/api/admin/item',['csrf'=>$csrf,'category_id'=>$fixture['category_id'],'name'=>$name,'description'=>'Local test item','price'=>'490.00','options'=>json_encode($options,JSON_THROW_ON_ERROR)],$cookie);
    if($status!==200||empty($created['id']))throw new RuntimeException('Save failed: '.$status.' '.json_encode($created));
    $id=(int)$created['id'];
    $q=$db->prepare('SELECT name,price,is_default FROM menu_item_options WHERE restaurant_id=? AND menu_item_id=? ORDER BY sort_order');$q->execute([$fixture['restaurant_id'],$id]);$saved=$q->fetchAll();
    if(array_column($saved,'name')!==['Small','Medium','Large']||array_column($saved,'price')!==['350.00','490.00','650.00']||(int)$saved[1]['is_default']!==1)throw new RuntimeException('Saved options mismatch.');
    require dirname(__DIR__).'/app/menu-options.php';
    $q=$db->prepare('SELECT * FROM menu_items WHERE id=? AND restaurant_id=?');$q->execute([$id,$fixture['restaurant_id']]);$item=$q->fetch();
    if(menu_option_price($db,(int)$fixture['restaurant_id'],$item,'Large')!=='650.00')throw new RuntimeException('Server price mismatch.');
    try{menu_option_price($db,(int)$fixture['restaurant_id'],$item,'Invalid');throw new RuntimeException('Invalid size accepted.');}catch(InvalidArgumentException $expected){}
    [$status,$bootstrap]=call_local($base.'/r-owner/api/pos/bootstrap',null,$cookie);
    if($status!==200)throw new RuntimeException('POS bootstrap unavailable: '.$status.' '.json_encode($bootstrap));
    [$status,$posHtml]=call_local($base.'/r-owner/pos?embed=1',null,$cookie);
    if($status!==200||!is_string($posHtml)||!str_contains($posHtml,'id="pos-payment-dialog"')||!str_contains($posHtml,'>Receive Payment</button>'))throw new RuntimeException('Receive Payment popup is missing from POS.');
    $posItems=$bootstrap['items']??[];$match=array_values(array_filter($posItems,fn($row)=>(int)$row['id']===$id));
    if(count($match)!==1||count($match[0]['options']??[])!==3)throw new RuntimeException('POS did not receive all sizes.');
    require dirname(__DIR__).'/app/token-crypto.php';
    $token=table_public_hash_from_digest($fixture['token_hash'],$env);
    [$status,$menu]=call_local($base.'/api/menu?slug='.rawurlencode($fixture['slug']).'&token='.rawurlencode($token));
    $menuItems=$menu['items']??[];$match=array_values(array_filter($menuItems,fn($row)=>(int)$row['id']===$id));
    if($status!==200||count($match)!==1||count($match[0]['options']??[])!==3)throw new RuntimeException('Customer menu did not receive all sizes.');
    [$status,$customerHtml]=call_local($base.'/'.rawurlencode($fixture['slug']).'/'.rawurlencode($token));
    if($status!==200||!is_string($customerHtml)||!str_contains($customerHtml,'class="food food-has-options"')||!str_contains($customerHtml,'Select Size / Portion')||!str_contains($customerHtml,'data-portion="Small"')||!str_contains($customerHtml,'data-portion="Medium"')||!str_contains($customerHtml,'data-portion="Large"'))throw new RuntimeException('Customer menu portion cards are missing.');
    $phoneCheck=$db->prepare('SELECT id FROM restaurant_customers WHERE restaurant_id=? AND phone=?');
    do{$qaPhone='07'.str_pad((string)random_int(0,99999999),8,'0',STR_PAD_LEFT);$phoneCheck->execute([$fixture['restaurant_id'],$qaPhone]);}while($phoneCheck->fetchColumn());
    $orderFields=['order_type'=>'DINE_IN','table_id'=>(string)$fixture['table_id'],'guest_count'=>'1','customer_name'=>$name,'customer_phone'=>$qaPhone,'delivery_address'=>'','delivery_notes'=>'','delivery_charge'=>'0.00','special_request'=>'','idempotency_key'=>bin2hex(random_bytes(20)),'items'=>json_encode([['id'=>$id,'qty'=>2,'portion'=>'Large','note'=>'']],JSON_THROW_ON_ERROR)];
    [$invalidStatus,$invalidPhone]=call_local($base.'/r-owner/api/pos/order',array_replace($orderFields,['customer_phone'=>'077123456']),$cookie,['X-CSRF-Token: '.$csrf]);
    if($invalidStatus<400||!str_contains((string)($invalidPhone['error']??''),'10-digit'))throw new RuntimeException('Nine-digit phone was accepted.');
    [$status,$order]=call_local($base.'/r-owner/api/pos/order',$orderFields,$cookie,['X-CSRF-Token: '.$csrf]);
    if($status!==200||empty($order['order_id']))throw new RuntimeException('POS custom-size order failed: '.$status.' '.json_encode($order));
    $orderId=(int)$order['order_id'];
    $q=$db->prepare('SELECT customer_id FROM orders WHERE id=? AND restaurant_id=?');$q->execute([$orderId,$fixture['restaurant_id']]);$customerId=(int)$q->fetchColumn();
    $q=$db->prepare('SELECT portion,unit_price,subtotal FROM order_items WHERE order_id=? AND menu_item_id=?');$q->execute([$orderId,$id]);$line=$q->fetch();
    if(!$line||$line['portion']!=='Large'||$line['unit_price']!=='650.00'||$line['subtotal']!=='1300.00')throw new RuntimeException('POS order price or size mismatch.');
    [$status,$firstKot]=call_local($base.'/r-owner/api/pos/kot',['order_id'=>(string)$orderId],$cookie,['X-CSRF-Token: '.$csrf]);
    if($status!==200||!empty($firstKot['kot_id'])||(int)($firstKot['print_kot_id']??0)!==(int)($order['kot_id']??0))throw new RuntimeException('Automatically created KOT was not offered for reprint.');
    [$status,$repeatKot]=call_local($base.'/r-owner/api/pos/kot',['order_id'=>(string)$orderId],$cookie,['X-CSRF-Token: '.$csrf]);
    if($status!==200||!empty($repeatKot['kot_id'])||(int)($repeatKot['print_kot_id']??0)!==(int)($firstKot['print_kot_id']??0))throw new RuntimeException('Existing KOT was not offered for reprint.');
    [$status,$optionalOrder]=call_local($base.'/r-owner/api/pos/order',array_replace($orderFields,['customer_name'=>'','customer_phone'=>'','idempotency_key'=>bin2hex(random_bytes(20))]),$cookie,['X-CSRF-Token: '.$csrf]);
    if($status!==200||empty($optionalOrder['order_id']))throw new RuntimeException('Dine-in order without optional phone failed: '.$status.' '.json_encode($optionalOrder));
    $optionalOrderId=(int)$optionalOrder['order_id'];
    [$status,$bill]=call_local($base.'/r-owner/api/pos/bill',['order_id'=>(string)$optionalOrderId,'discount'=>'0.00','tax'=>'0.00'],$cookie,['X-CSRF-Token: '.$csrf]);
    if($status!==200||empty($bill['invoice']['id']))throw new RuntimeException('Test bill creation failed: '.$status.' '.json_encode($bill));
    $invoiceId=(int)$bill['invoice']['id'];$remaining=(int)round((float)$bill['invoice']['total']*100)-50000;
    $tooMuch=['invoice_id'=>(string)$invoiceId,'idempotency_key'=>bin2hex(random_bytes(20)),'payments'=>json_encode([['method'=>'CASH','amount'=>'500.00','amount_received'=>'500.00'],['method'=>'CARD','amount'=>$bill['invoice']['total']]],JSON_THROW_ON_ERROR)];
    [$invalidStatus]=call_local($base.'/r-owner/api/pos/payment/batch',$tooMuch,$cookie,['X-CSRF-Token: '.$csrf]);
    $q=$db->prepare('SELECT COUNT(*) FROM pos_payments WHERE invoice_id=? AND restaurant_id=?');$q->execute([$invoiceId,$fixture['restaurant_id']]);
    if($invalidStatus<400||(int)$q->fetchColumn()!==0)throw new RuntimeException('Overpayment was not rejected atomically.');
    $batchKey=bin2hex(random_bytes(20));$batchFields=['invoice_id'=>(string)$invoiceId,'idempotency_key'=>$batchKey,'payments'=>json_encode([['method'=>'CASH','amount'=>'500.00','amount_received'=>'600.00','reference'=>''],['method'=>'CARD','amount'=>number_format($remaining/100,2,'.',''),'amount_received'=>'','reference'=>'QA split']],JSON_THROW_ON_ERROR)];
    [$status,$split]=call_local($base.'/r-owner/api/pos/payment/batch',$batchFields,$cookie,['X-CSRF-Token: '.$csrf]);
    if($status!==200||($split['payment_status']??'')!=='PAID'||($split['change']??'')!=='100.00')throw new RuntimeException('Atomic split payment failed: '.$status.' '.json_encode($split));
    [$status,$retry]=call_local($base.'/r-owner/api/pos/payment/batch',$batchFields,$cookie,['X-CSRF-Token: '.$csrf]);
    if($status!==200||empty($retry['already_recorded']))throw new RuntimeException('Split payment retry was not idempotent.');
    $q=$db->prepare('SELECT COUNT(*) FROM pos_payments WHERE invoice_id=? AND restaurant_id=?');$q->execute([$invoiceId,$fixture['restaurant_id']]);if((int)$q->fetchColumn()!==2)throw new RuntimeException('Split payment created duplicate records.');
    [$status,$receipt]=call_local($base.'/r-owner/api/pos/receipt?id='.$invoiceId,null,$cookie);
    if($status!==200||!is_string($receipt)||!str_contains($receipt,'Outstanding balance</span><strong>LKR 0.00')||!str_contains($receipt,'Change due</span><strong>LKR 100.00')||!str_contains($receipt,'Cash received</span><strong>LKR 600.00')||str_contains($receipt,'<span>Cash</span>'))throw new RuntimeException('Receipt balance/change or cash payment display is incorrect.');
    $device=bin2hex(random_bytes(24));
    [$status,$session]=call_json($base.'/api/table-session',['slug'=>$fixture['slug'],'token'=>$token,'client_token'=>$device]);
    if($status!==200||empty($session['session']['id']))throw new RuntimeException('QR table session failed: '.$status.' '.json_encode($session));
    $sessionId=(int)$session['session']['id'];
    [$status,$qrOrder]=call_json($base.'/api/order',['slug'=>$fixture['slug'],'token'=>$token,'dining_session_id'=>$sessionId,'dining_session_proof'=>$session['session']['proof'],'client_token'=>$device,'items'=>[['id'=>$id,'qty'=>1,'portion'=>'Small']],'special_request'=>'','idempotency_key'=>bin2hex(random_bytes(20))]);
    if($status!==200||empty($qrOrder['order']['id']))throw new RuntimeException('QR custom-size order failed: '.$status.' '.json_encode($qrOrder));
    $qrOrderId=(int)$qrOrder['order']['id'];
    $q=$db->prepare('SELECT portion,unit_price FROM order_items WHERE order_id=? AND menu_item_id=?');$q->execute([$qrOrderId,$id]);$qrLine=$q->fetch();
    if(!$qrLine||$qrLine['portion']!=='Small'||$qrLine['unit_price']!=='350.00')throw new RuntimeException('QR order price or size mismatch.');
    echo "PASS custom options, customer portion cards, optional phone validation, KOT reprint, atomic split payment, receipt balance/change, POS and QR pricing.\n";
}finally{
    if($qrOrderId){$q=$db->prepare('DELETE FROM orders WHERE id=? AND restaurant_id=?');$q->execute([$qrOrderId,$fixture['restaurant_id']]);}
    if($orderId){$q=$db->prepare('DELETE FROM orders WHERE id=? AND restaurant_id=?');$q->execute([$orderId,$fixture['restaurant_id']]);}
    if($optionalOrderId){$q=$db->prepare('DELETE FROM orders WHERE id=? AND restaurant_id=?');$q->execute([$optionalOrderId,$fixture['restaurant_id']]);}
    if($customerId){$q=$db->prepare('DELETE FROM restaurant_customers WHERE id=? AND restaurant_id=? AND phone=? AND name=?');$q->execute([$customerId,$fixture['restaurant_id'],$qaPhone,$name]);}
    if($sessionId){$q=$db->prepare('DELETE FROM dining_sessions WHERE id=? AND restaurant_id=?');$q->execute([$sessionId,$fixture['restaurant_id']]);}
    if($id){$q=$db->prepare('DELETE FROM menu_items WHERE id=? AND restaurant_id=?');$q->execute([$id,$fixture['restaurant_id']]);}
    session_id(substr($cookie,strlen('EE_R_OWNER=')));session_start();session_destroy();ob_end_flush();
}
