<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=dirname(__DIR__);$env=[];
foreach(file($root.'/.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){if(str_starts_with(trim($line),'#'))continue;[$key,$value]=array_pad(explode('=',$line,2),2,'');$env[trim($key)]=trim($value,' "');}
if(!in_array($env['DB_HOST']??'', ['localhost','127.0.0.1'],true))throw new RuntimeException('Local database only.');
$db=new PDO('mysql:host='.$env['DB_HOST'].';dbname='.$env['DB_DATABASE'].';charset=utf8mb4',$env['DB_USERNAME'],$env['DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$db->exec("SET time_zone = '+00:00'");
$q=$db->query("SELECT u.id,u.restaurant_id FROM users u JOIN restaurants r ON r.id=u.restaurant_id WHERE u.email='demo@admin.com' AND u.is_active=1 AND r.is_active=1 LIMIT 1");$manager=$q->fetch();if(!$manager)throw new RuntimeException('Local demo manager unavailable.');
$rid=(int)$manager['restaurant_id'];$userId=(int)$manager['id'];$q=$db->prepare('SELECT * FROM restaurant_pos_settings WHERE restaurant_id=?');$q->execute([$rid]);$original=$q->fetch();
$q=$db->prepare('SELECT id,name FROM menu_items WHERE restaurant_id=? AND is_active=1 LIMIT 1');$q->execute([$rid]);$item=$q->fetch();$q=$db->prepare('SELECT id FROM restaurant_tables WHERE restaurant_id=? AND is_active=1 LIMIT 1');$q->execute([$rid]);$tableId=$q->fetchColumn();if(!$item||!$tableId)throw new RuntimeException('Demo menu/table fixture unavailable.');
$base=rtrim($env['APP_URL'],'/');$curl=curl_init();curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false]);
function callPayment(string $path,?array $fields=null,array $headers=[]):array{global $curl,$base;curl_setopt($curl,CURLOPT_URL,$base.$path);curl_setopt($curl,CURLOPT_HTTPHEADER,$headers);if($fields===null)curl_setopt($curl,CURLOPT_HTTPGET,true);else{curl_setopt($curl,CURLOPT_POST,true);curl_setopt($curl,CURLOPT_POSTFIELDS,$fields);} $body=curl_exec($curl);if($body===false)throw new RuntimeException(curl_error($curl));return [(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),(string)$body];}
function ensure(bool $okay,string $label):void{if(!$okay)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
$orderId=null;
try{
    $q=$db->prepare('INSERT INTO restaurant_pos_settings(restaurant_id,pos_enabled,manager_bill_edit_enabled) VALUES(?,1,0) ON DUPLICATE KEY UPDATE pos_enabled=1');$q->execute([$rid]);
    $number='QA-PAY-'.strtoupper(bin2hex(random_bytes(4)));$key=hash('sha256',random_bytes(32));
    $q=$db->prepare('INSERT INTO orders(restaurant_id,table_id,order_number,total,status,idempotency_key,source,order_type,created_at,updated_at) VALUES(?,?,?,"100.00","ACCEPTED",?,"POS","DINE_IN",UTC_TIMESTAMP(),UTC_TIMESTAMP())');$q->execute([$rid,$tableId,$number,$key]);$orderId=(int)$db->lastInsertId();
    $q=$db->prepare('INSERT INTO order_items(order_id,menu_item_id,item_name,unit_price,quantity,subtotal) VALUES(?,?,?,"100.00",1,"100.00")');$q->execute([$orderId,$item['id'],$item['name']]);
    $invoiceNumber='QA-INV-'.strtoupper(bin2hex(random_bytes(4)));$q=$db->prepare('INSERT INTO pos_invoices(restaurant_id,order_id,invoice_number,subtotal,total,payment_status,generated_by,created_at,updated_at) VALUES(?,?,?,"100.00","100.00","UNPAID",?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');$q->execute([$rid,$orderId,$invoiceNumber,$userId]);$invoiceId=(int)$db->lastInsertId();
    [$code]=callPayment('/r-owner/login',['email'=>'demo@admin.com','password'=>'demo']);ensure($code===302,'temporary manager session');
    [$code,$page]=callPayment('/r-owner/pos');preg_match('~<script type="application/json" id="pos-config">(.*?)</script>~s',$page,$matches);$config=json_decode($matches[1]??'{}',true);ensure($code===200&&!empty($config['csrf']),'payment CSRF available');ensure(str_contains($page,'id="pos-payment-status" class="pos-payment-status"'),'rendered payment modal contains the status selector');ensure(str_contains($page,'id="pos-payment-split" class="pos-payment-split"'),'rendered payment modal contains the split-payment selector');
    $url='/r-owner/api/pos/payment';$header=['X-CSRF-Token: '.$config['csrf']];$requestKey=bin2hex(random_bytes(20));
    [$code,$body]=callPayment($url,['invoice_id'=>$invoiceId,'method'=>'CASH','amount'=>'40.00','amount_received'=>'39.00','idempotency_key'=>bin2hex(random_bytes(20))],$header);$insufficient=json_decode($body,true);ensure($code===422&&($insufficient['error']??'')==='Insufficient cash received.','cash payment rejects insufficient tender with clear message');
    $payload=['invoice_id'=>$invoiceId,'method'=>'CARD','amount'=>'40.00','reference'=>'RETRY-QA','idempotency_key'=>$requestKey];
    [$code,$body]=callPayment($url,$payload,$header);$first=json_decode($body,true);ensure($code===200&&($first['paid_total']??null)==='40.00','first partial payment recorded');
    [$code,$body]=callPayment($url,$payload,$header);$retry=json_decode($body,true);ensure($code===200&&($retry['already_recorded']??false)&&($retry['paid_total']??null)==='40.00','identical retry returns existing payment');
    $changed=$payload;$changed['amount']='41.00';[$code]=callPayment($url,$changed,$header);ensure($code===422,'same retry key with changed amount is rejected');
    $q=$db->prepare('SELECT COUNT(*) FROM pos_payments WHERE invoice_id=?');$q->execute([$invoiceId]);ensure((int)$q->fetchColumn()===1,'retry did not create a second payment row');
    $split=[['method'=>'CASH','amount'=>'20.00','amount_received'=>'25.00','reference'=>'SPLIT-CASH'],['method'=>'CARD','amount'=>'40.00','amount_received'=>'','reference'=>'SPLIT-CARD']];
    [$code,$body]=callPayment('/r-owner/api/pos/payment/batch',['invoice_id'=>$invoiceId,'payments'=>json_encode($split),'idempotency_key'=>bin2hex(random_bytes(20))],$header);$final=json_decode($body,true);ensure($code===200&&($final['payment_status']??null)==='PAID'&&($final['order_status']??null)==='ACCEPTED'&&($final['change']??null)==='5.00','split payment settles invoice, reports cash change, and preserves order status');
    $q=$db->prepare('SELECT status FROM orders WHERE id=? AND restaurant_id=?');$q->execute([$orderId,$rid]);ensure($q->fetchColumn()==='ACCEPTED','order status remains independent after full payment');
    $q=$db->prepare('SELECT COUNT(*) FROM pos_payments WHERE invoice_id=?');$q->execute([$invoiceId]);ensure((int)$q->fetchColumn()===3,'partial payment and both split tenders are retained exactly once');
    $script=file_get_contents($root.'/public/assets/pos.js');$markup=file_get_contents($root.'/app/pos-endpoints.php');
    ensure(str_contains($markup,'Total Bill')&&str_contains($markup,'Amount Due')&&str_contains($markup,'Remaining after payment'),'payment summary uses clear POS labels');
    ensure(str_contains($markup,'id="pos-payment-status" class="pos-payment-status"')&&str_contains($script,"$('pos-payment-status').classList.remove"),'payment status markup provides the selector required by the modal');
    ensure(str_contains($markup,'id="pos-payment-split" class="pos-payment-split"')&&str_contains($script,"$('pos-payment-split').hidden=false"),'payment split markup provides the selector required by the modal');
    ensure(str_contains($script,'Insufficient cash received.')&&str_contains($script,'Ready to record full payment.')&&str_contains($script,'Partial payment selected.'),'payment status and cash validation messages are clear');
    ensure(str_contains($script,'async function retrySavedPaymentReceipt()')&&str_contains($script,"if(paymentDialogSaved){\n    if(printAfter)await retrySavedPaymentReceipt();"),'retry print does not submit payment again');
}finally{
    if($orderId){$q=$db->prepare('DELETE FROM orders WHERE id=? AND restaurant_id=?');$q->execute([$orderId,$rid]);}
    if($original){$q=$db->prepare('UPDATE restaurant_pos_settings SET pos_enabled=?,manager_bill_edit_enabled=?,updated_by=? WHERE restaurant_id=?');$q->execute([$original['pos_enabled'],$original['manager_bill_edit_enabled'],$original['updated_by'],$rid]);}
    else{$q=$db->prepare('DELETE FROM restaurant_pos_settings WHERE restaurant_id=?');$q->execute([$rid]);}
    curl_close($curl);echo "Temporary payment fixture removed; settings restored.\n";
}
