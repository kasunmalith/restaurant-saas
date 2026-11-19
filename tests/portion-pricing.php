<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
ob_start();
require dirname(__DIR__).'/vendor/autoload.php';
$env=Dotenv\Dotenv::createArrayBacked(dirname(__DIR__))->load();
if(parse_url($env['APP_URL']??'',PHP_URL_HOST)!=='localhost')throw new RuntimeException('Localhost only.');
require dirname(__DIR__).'/app/token-crypto.php';
$db=new PDO('mysql:host='.$env['DB_HOST'].';dbname='.$env['DB_DATABASE'].';charset=utf8mb4',$env['DB_USERNAME'],$env['DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$base=rtrim($env['APP_URL'],'/');
function request(string $url,?array $payload=null,bool $json=false,?string $cookie=null):array{
    $handle=curl_init($url);curl_setopt_array($handle,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20]);
    if($cookie!==null)curl_setopt($handle,CURLOPT_COOKIE,$cookie);
    if($payload!==null){curl_setopt($handle,CURLOPT_POST,true);curl_setopt($handle,CURLOPT_POSTFIELDS,$json?json_encode($payload,JSON_THROW_ON_ERROR):http_build_query($payload));if($json)curl_setopt($handle,CURLOPT_HTTPHEADER,['Content-Type: application/json']);}
    $body=(string)curl_exec($handle);$status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);curl_close($handle);return[$status,json_decode($body,true)??$body];
}
function check(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);echo "PASS $message\n";}
$restaurant=$db->query("SELECT r.id,r.slug,r.is_active,r.is_accepting_orders,r.service_charge_percent,t.id table_id,t.token_hash,c.id category_id FROM restaurants r JOIN restaurant_tables t ON t.restaurant_id=r.id AND t.is_active=1 JOIN menu_categories c ON c.restaurant_id=r.id WHERE r.slug='olive-table' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if(!$restaurant||!(int)$restaurant['is_active']||!(int)$restaurant['is_accepting_orders'])throw new RuntimeException('Active local fixture restaurant required.');
$subscription=$db->prepare("SELECT COUNT(*) FROM subscriptions WHERE restaurant_id=? AND status IN ('ACTIVE','TRIAL') AND starts_on<=UTC_DATE() AND expires_on>=UTC_DATE()");$subscription->execute([$restaurant['id']]);if(!(int)$subscription->fetchColumn())throw new RuntimeException('Active local subscription required.');
$token=table_public_hash_from_digest($restaurant['token_hash'],$env);$suffix=bin2hex(random_bytes(6));$name='QA Portion '.$suffix;$itemId=0;$orderId=0;$sessionId=0;
$manager=$db->prepare("SELECT * FROM users WHERE restaurant_id=? AND role='RESTAURANT_ADMIN' AND is_active=1 LIMIT 1");$manager->execute([$restaurant['id']]);$manager=$manager->fetch(PDO::FETCH_ASSOC);if(!$manager)throw new RuntimeException('Local manager fixture required.');
$testSession=bin2hex(random_bytes(16));$csrf=bin2hex(random_bytes(32));session_name('EE_R_OWNER');session_save_path('C:/wamp64/tmp');session_id($testSession);session_start();$_SESSION['user']=$manager;$_SESSION['csrf']=$csrf;session_write_close();$cookie='EE_R_OWNER='.$testSession;
try{
    $insert=$db->prepare('INSERT INTO menu_items(restaurant_id,category_id,name,price,is_portioned,half_price,full_price) VALUES(?,?,?,450,1,250,450)');$insert->execute([$restaurant['id'],$restaurant['category_id'],$name]);$itemId=(int)$db->lastInsertId();
    [$status,$dashboard]=request($base.'/r-owner/dashboard',null,false,$cookie);check($status===200&&is_string($dashboard)&&str_contains($dashboard,'Offer Half and Full portions'),'manager menu shows portion option');
    preg_match('/name="csrf" value="([a-f0-9]+)"/',$dashboard,$match);check(($match[1]??'')===$csrf,'manager CSRF token available');
    $fields=['csrf'=>$csrf,'id'=>$itemId,'category_id'=>$restaurant['category_id'],'name'=>$name,'description'=>'','price'=>'400.00','is_portioned'=>'0'];
    [$status,$saved]=request($base.'/r-owner/api/admin/item',$fields,false,$cookie);check($status===200&&!empty($saved['ok']),'single-price mode saves');
    $single=$db->query('SELECT is_portioned,price,half_price,full_price FROM menu_items WHERE id='.$itemId)->fetch(PDO::FETCH_ASSOC);check((int)$single['is_portioned']===0&&$single['price']==='400.00'&&$single['half_price']===null,'single-price item has no portion prices');
    $fields['is_portioned']='1';$fields['half_price']='250.00';$fields['full_price']='450.00';unset($fields['price']);
    [$status,$saved]=request($base.'/r-owner/api/admin/item',$fields,false,$cookie);check($status===200&&!empty($saved['ok']),'half/full mode saves');
    $split=$db->query('SELECT is_portioned,price,half_price,full_price FROM menu_items WHERE id='.$itemId)->fetch(PDO::FETCH_ASSOC);check((int)$split['is_portioned']===1&&$split['price']==='450.00'&&$split['half_price']==='250.00','portion prices persist');
    [$status,$menu]=request($base.'/api/menu?slug='.$restaurant['slug'].'&token='.$token);$found=array_values(array_filter($menu['items']??[],fn($item)=>(int)$item['id']===$itemId));check($status===200&&count($found)===1&&$found[0]['half_price']==='250.00'&&$found[0]['full_price']==='450.00','menu API returns both prices');
    $device=bin2hex(random_bytes(24));[$status,$session]=request($base.'/api/table-session',['slug'=>$restaurant['slug'],'token'=>$token,'client_token'=>$device],true);check($status===200&&isset($session['session']['proof']),'table session starts');$sessionId=(int)$session['session']['id'];
    $payload=['slug'=>$restaurant['slug'],'token'=>$token,'client_token'=>$device,'dining_session_id'=>$sessionId,'dining_session_proof'=>$session['session']['proof'],'idempotency_key'=>bin2hex(random_bytes(24)),'items'=>[['id'=>$itemId,'portion'=>'HALF','qty'=>2,'price'=>0.01],['id'=>$itemId,'portion'=>'FULL','qty'=>1,'price'=>0.01]]];
    [$status,$order]=request($base.'/api/order',$payload,true);$orderId=(int)($order['order']['id']??0);$expectedTotal=number_format(950+round(950*(float)$restaurant['service_charge_percent']/100,2),2,'.','');check($status===200&&$orderId>0&&$order['order']['total']===$expectedTotal,'server charges saved portion prices and configured service charge');
    $items=$db->prepare('SELECT item_name,portion,unit_price,quantity FROM order_items WHERE order_id=? ORDER BY id');$items->execute([$orderId]);$lines=$items->fetchAll(PDO::FETCH_ASSOC);check(count($lines)===2&&$lines[0]['portion']==='HALF'&&$lines[0]['unit_price']==='250.00'&&$lines[1]['portion']==='FULL'&&$lines[1]['unit_price']==='450.00','order keeps distinct portion lines');
    $payload['idempotency_key']=bin2hex(random_bytes(24));$payload['items']=[['id'=>$itemId,'qty'=>1]];[$status,$error]=request($base.'/api/order',$payload,true);check($status===422&&isset($error['error']),'portion required for split item');
}finally{
    if($orderId){$delete=$db->prepare('DELETE FROM orders WHERE id=?');$delete->execute([$orderId]);}
    if($sessionId){$delete=$db->prepare('DELETE FROM dining_sessions WHERE id=?');$delete->execute([$sessionId]);}
    if($itemId){$delete=$db->prepare('DELETE FROM menu_items WHERE id=? AND name=?');$delete->execute([$itemId,$name]);}
    session_id($testSession);session_start();$_SESSION=[];session_destroy();
}
