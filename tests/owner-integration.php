<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/vendor/autoload.php';
$env=Dotenv\Dotenv::createArrayBacked(dirname(__DIR__))->load();
if(($env['APP_ENV']??'')!=='local')throw new RuntimeException('Local only.');
$db=new PDO('mysql:host='.$env['DB_HOST'].';dbname='.$env['DB_DATABASE'].';charset=utf8mb4',$env['DB_USERNAME'],$env['DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$client=curl_init();curl_setopt_array($client,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>20]);
function req(string $path,?array $body=null):array{global $client,$env;curl_setopt($client,CURLOPT_URL,rtrim($env['APP_URL'],'/').$path);if($body===null)curl_setopt($client,CURLOPT_HTTPGET,true);else{curl_setopt($client,CURLOPT_POST,true);curl_setopt($client,CURLOPT_POSTFIELDS,$body);}$r=curl_exec($client);if($r===false)throw new RuntimeException(curl_error($client));return[(int)curl_getinfo($client,CURLINFO_RESPONSE_CODE),$r];}
function check(bool $value,string $label):void{if(!$value)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
$slug='qa-owner-'.bin2hex(random_bytes(8));$email=$slug.'@example.test';$password=bin2hex(random_bytes(16));
try{
 [$code]=req('/login',['email'=>'owner@example.com','password'=>'password']);check($code===302,'owner login');
 [, $html]=req('/dashboard');check(str_contains($html,'owner-create'),'restaurant form rendered');preg_match('/name="csrf" type="hidden" value="([a-f0-9]+)"/',$html,$match);$token=$match[1]??'';
 $fields=['csrf'=>$token,'name'=>'Integration Restaurant','slug'=>$slug,'email'=>$email,'password'=>$password,'fee'=>'12000.00','starts_on'=>gmdate('Y-m-d'),'expires_on'=>gmdate('Y-m-d',strtotime('+1 year'))];
 [$code,$body]=req('/api/owner/restaurant',$fields);check($code===200&&!empty(json_decode($body,true)['ok']),'restaurant created');
 $s=$db->prepare('SELECT r.id,s.fee,u.email FROM restaurants r JOIN subscriptions s ON s.restaurant_id=r.id JOIN users u ON u.restaurant_id=r.id WHERE r.slug=?');$s->execute([$slug]);$row=$s->fetch(PDO::FETCH_ASSOC);check($row&&$row['fee']==='12000.00'&&$row['email']===$email,'restaurant subscription and admin persisted');
 $fields['slug']=$slug.'-duplicate';[$code]=req('/api/owner/restaurant',$fields);check($code===409,'duplicate admin email rejected');$s=$db->prepare('SELECT COUNT(*) FROM restaurants WHERE slug=?');$s->execute([$fields['slug']]);check((int)$s->fetchColumn()===0,'failed creation rolled back');
 $tenant=(int)$row['id'];$tableToken=bin2hex(random_bytes(24));
 $s=$db->prepare('INSERT INTO restaurant_tables(restaurant_id,table_number,token_hash) VALUES(?,?,?)');$s->execute([$tenant,'QA',hash('sha256',$tableToken)]);
 $s=$db->prepare('INSERT INTO menu_categories(restaurant_id,name) VALUES(?,?)');$s->execute([$tenant,'QA']);$category=(int)$db->lastInsertId();
 $s=$db->prepare('INSERT INTO menu_items(restaurant_id,category_id,name,price) VALUES(?,?,?,?)');$s->execute([$tenant,$category,'QA item','10.00']);$item=(int)$db->lastInsertId();
 [$code,$body]=req('/api/owner/restaurant/update',['csrf'=>$token,'restaurant_id'=>$tenant,'name'=>'Integration Restaurant Updated','contact_email'=>'contact@example.test','contact_phone'=>'+94 11 111 1111','address'=>'QA address','is_active'=>'0']);check($code===200&&!empty(json_decode($body,true)['ok']),'owner edits and suspends restaurant account');$s=$db->prepare('SELECT name,contact_email,is_active FROM restaurants WHERE id=?');$s->execute([$tenant]);$edited=$s->fetch(PDO::FETCH_ASSOC);check($edited['name']==='Integration Restaurant Updated'&&$edited['contact_email']==='contact@example.test'&&!(int)$edited['is_active'],'restaurant contact and account status persisted');
 $orderPayload=['slug'=>$slug,'token'=>$tableToken,'idempotency_key'=>bin2hex(random_bytes(24)),'items'=>[['id'=>$item,'qty'=>1]]];
 $orderClient=curl_init(rtrim($env['APP_URL'],'/').'/api/order');curl_setopt_array($orderClient,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode($orderPayload)]);curl_exec($orderClient);check(curl_getinfo($orderClient,CURLINFO_RESPONSE_CODE)===403,'inactive restaurant account rejects orders');
 [$code]=req('/api/owner/restaurant/update',['csrf'=>$token,'restaurant_id'=>$tenant,'name'=>'Integration Restaurant Updated','contact_email'=>'contact@example.test','contact_phone'=>'','address'=>'','is_active'=>'1']);check($code===200,'owner reactivates restaurant account');
 [$code]=req('/api/admin/subscription',['csrf'=>$token,'restaurant_id'=>$tenant,'status'=>'SUSPENDED','starts_on'=>$fields['starts_on'],'expires_on'=>$fields['expires_on'],'fee'=>'12500.00']);check($code===200,'owner suspends subscription and updates annual fee');
 curl_exec($orderClient);check(curl_getinfo($orderClient,CURLINFO_RESPONSE_CODE)===403,'suspended restaurant rejects orders');
 [$code]=req('/api/admin/subscription',['csrf'=>$token,'restaurant_id'=>$tenant,'status'=>'ACTIVE','starts_on'=>$fields['starts_on'],'expires_on'=>$fields['expires_on'],'fee'=>'12500.00']);check($code===200,'owner reactivates subscription');
 $orderResult=json_decode(curl_exec($orderClient),true);check(curl_getinfo($orderClient,CURLINFO_RESPONSE_CODE)===200&&!empty($orderResult['ok']),'same QR token works after renewal');curl_close($orderClient);
 [$code]=req('/login',['email'=>$email,'password'=>$password]);check($code===302,'new administrator login');
 [, $html]=req('/dashboard');check(str_contains($html,'Integration Restaurant Updated')&&!str_contains($html,'id="owner-create"'),'new tenant dashboard');
 [$code]=req('/api/owner/restaurant',$fields);check($code===403,'tenant administrator cannot create tenants');
}finally{
 $s=$db->prepare('DELETE o FROM orders o JOIN restaurants r ON r.id=o.restaurant_id WHERE r.slug=?');$s->execute([$slug]);
 $s=$db->prepare('DELETE m FROM menu_items m JOIN restaurants r ON r.id=m.restaurant_id WHERE r.slug=?');$s->execute([$slug]);
 $s=$db->prepare('DELETE FROM restaurants WHERE slug IN (?,?)');$s->execute([$slug,$slug.'-duplicate']);curl_close($client);
}
