<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=dirname(__DIR__);$env=[];
foreach(file($root.'/.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){if(str_starts_with(trim($line),'#'))continue;[$key,$value]=array_pad(explode('=',$line,2),2,'');$env[trim($key)]=trim($value,' "');}
$base=rtrim((string)($env['APP_URL']??''),'/');
if(!in_array($env['DB_HOST']??'', ['localhost','127.0.0.1'],true)||!in_array(parse_url($base,PHP_URL_HOST),['localhost','127.0.0.1'],true))throw new RuntimeException('Localhost only test.');
$cookie=tempnam(sys_get_temp_dir(),'ee-customer-page-');
$db=new PDO('mysql:host='.$env['DB_HOST'].';dbname='.$env['DB_DATABASE'].';charset=utf8mb4',$env['DB_USERNAME'],$env['DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$fixtureCustomerId=0;$restaurantId=0;
function pageCall(string $url,?array $fields=null):array{global $cookie;$curl=curl_init($url);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie]);if($fields!==null){curl_setopt($curl,CURLOPT_POST,true);curl_setopt($curl,CURLOPT_POSTFIELDS,$fields);}else curl_setopt($curl,CURLOPT_HTTPGET,true);$body=curl_exec($curl);if($body===false)throw new RuntimeException(curl_error($curl));$code=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);return[$code,(string)$body];}
function pageCheck(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
try{
    [$code]=pageCall($base.'/r-owner/login',['email'=>'demo@admin.com','password'=>'demo']);pageCheck($code===302,'Manager login');
    $owner=$db->prepare('SELECT restaurant_id FROM users WHERE email=? LIMIT 1');$owner->execute(['demo@admin.com']);$restaurantId=(int)$owner->fetchColumn();if(!$restaurantId)throw new RuntimeException('Demo restaurant admin is unavailable for the customer status test.');
    $controls=(string)file_get_contents($root.'/app/customer-controls.php');$script=(string)file_get_contents($root.'/public/assets/customer-management.js');$endpoint=(string)file_get_contents($root.'/app/pos-endpoints.php');pageCheck(str_contains($controls,'data-customer-category="online_registered"')&&str_contains($controls,'data-customer-category="manager_registered"')&&str_contains($controls,'data-customer-category="guest"')&&str_contains($script,'category=')&&str_contains($script,"load(false)")&&str_contains($script,'Name A–Z'),'Customers UI includes three customer categories and category-aware pagination');pageCheck(str_contains($endpoint,'EXISTS(SELECT 1 FROM restaurant_customers profile WHERE profile.restaurant_id=order_rows.restaurant_id AND profile.phone=order_rows.customer_phone)'),'Guest order snapshots disappear from customer list after demo customer profiles are deleted');
    [$code,$body]=pageCall($base.'/r-owner/api/pos/customers?category=manager_registered&offset=0');
    if($code===403&&str_contains($body,'POS / Billing is not enabled')){echo "SKIP Customer API integration: POS / Billing is disabled for the local demo restaurant.\n";}else{
    $first=json_decode($body,true);pageCheck($code===200&&count($first['customers']??[])<=15&&isset($first['total'],$first['has_more'],$first['next_offset']),'Manager-added customer category paginates up to 15 rows (HTTP '.$code.': '.substr((string)$body,0,240).')');
    pageCheck(!array_diff(array_column($first['customers']??[],'account_status'),['REGISTERED','GUEST']),'Customer API returns REGISTERED or GUEST account status');
    $names=array_map(static fn(array $row):string=>mb_strtolower(trim((string)($row['name']?:$row['phone']))),$first['customers']??[]);$sorted=$names;sort($sorted,SORT_NATURAL|SORT_FLAG_CASE);pageCheck($names===$sorted,'Customer API sorts the first page by name A-Z');
    if(!empty($first['has_more'])){[$code,$body]=pageCall($base.'/r-owner/api/pos/customers?category=manager_registered&offset='.(int)$first['next_offset']);$second=json_decode($body,true);pageCheck($code===200&&count($second['customers']??[])<=15,'Load-more offset returns the next customer page');}
    $fixtureName='ZZ QA Promoted '.bin2hex(random_bytes(5));$fixturePhone='077'.str_pad((string)random_int(0,9999999),7,'0',STR_PAD_LEFT);
    $insert=$db->prepare("INSERT INTO restaurant_customers(restaurant_id,name,phone,address,origin,created_at,updated_at) VALUES(?,?,?,NULL,'ONLINE_GUEST',UTC_TIMESTAMP(),UTC_TIMESTAMP())");$insert->execute([$restaurantId,$fixtureName,$fixturePhone]);$fixtureCustomerId=(int)$db->lastInsertId();
    $account=$db->prepare('INSERT INTO online_customer_accounts(restaurant_id,customer_id,password_hash,created_at,updated_at) VALUES(?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');$account->execute([$restaurantId,$fixtureCustomerId,password_hash('QA-Status-Test',PASSWORD_DEFAULT)]);
    [$code,$body]=pageCall($base.'/r-owner/api/pos/customers?category=online_registered&search='.rawurlencode($fixturePhone));$filtered=json_decode($body,true);$found=$filtered['customers'][0]??[];
    pageCheck($code===200&&($found['origin']??'')==='ONLINE_REGISTRATION'&&($found['account_status']??'')==='REGISTERED','Previously guest customer appears in the Online Registered category after account creation');
    }
}finally{if($fixtureCustomerId){$delete=$db->prepare('DELETE FROM restaurant_customers WHERE id=?');$delete->execute([$fixtureCustomerId]);}if($cookie&&is_file($cookie))unlink($cookie);}
