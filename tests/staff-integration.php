<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/vendor/autoload.php';
$env=Dotenv\Dotenv::createArrayBacked(dirname(__DIR__))->load();
if(($env['APP_ENV']??'')!=='local')throw new RuntimeException('Local development only.');
$db=new PDO('mysql:host='.$env['DB_HOST'].';dbname='.$env['DB_DATABASE'].';charset=utf8mb4',$env['DB_USERNAME'],$env['DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
function sessionClient(){ $c=curl_init();curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>20]);return $c; }
function send($c,string $path,?array $body=null):array{global $env;curl_setopt($c,CURLOPT_URL,rtrim($env['APP_URL'],'/').$path);if($body===null)curl_setopt($c,CURLOPT_HTTPGET,true);else{curl_setopt($c,CURLOPT_POST,true);curl_setopt($c,CURLOPT_POSTFIELDS,$body);}$result=curl_exec($c);if($result===false)throw new RuntimeException(curl_error($c));return[(int)curl_getinfo($c,CURLINFO_RESPONSE_CODE),$result];}
function check(bool $ok,string $name):void{if(!$ok)throw new RuntimeException('FAIL '.$name);echo 'PASS '.$name.PHP_EOL;}
$admin=sessionClient();$cashier=sessionClient();$email='qa-'.bin2hex(random_bytes(8)).'@example.test';$password=bin2hex(random_bytes(16));
try{
 [$code]=send($admin,'/login',['email'=>'admin@olive.test','password'=>'password']);check($code===302,'administrator login');
 [, $page]=send($admin,'/dashboard');preg_match('/name="csrf" value="([a-f0-9]+)"/',$page,$match);$token=$match[1]??'';
 [$code,$body]=send($admin,'/api/admin/staff',['csrf'=>$token,'name'=>'Integration cashier','email'=>$email,'password'=>$password]);check($code===200&&!empty(json_decode($body,true)['ok']),'cashier account created');
 $s=$db->prepare('SELECT id,restaurant_id,password_hash FROM users WHERE email=?');$s->execute([$email]);$user=$s->fetch(PDO::FETCH_ASSOC);check($user&&$user['restaurant_id']==1&&password_verify($password,$user['password_hash']),'tenant and password hash correct');
 [$code]=send($cashier,'/login',['email'=>$email,'password'=>$password]);check($code===302,'new cashier can sign in');
 [$code]=send($cashier,'/api/cashier/orders');check($code===200,'active cashier can read orders');
 [$code]=send($cashier,'/api/admin/staff',['csrf'=>$token,'name'=>'Unauthorized','email'=>'no@example.test','password'=>$password]);check($code===403,'cashier cannot create staff');
 [$code]=send($admin,'/api/admin/staff',['csrf'=>$token,'id'=>$user['id'],'active'=>'0']);check($code===200,'administrator deactivates cashier');
 [$code]=send($cashier,'/api/cashier/orders');check($code===403,'existing cashier session loses API access');
 [$code]=send($cashier,'/dashboard');check($code===302,'inactive cashier redirected away from dashboard');
 [$code]=send($admin,'/api/admin/staff',['csrf'=>$token,'id'=>$user['id'],'active'=>'1']);check($code===200,'administrator reactivates cashier');
}finally{$s=$db->prepare("DELETE FROM users WHERE email=? AND role='CASHIER'");$s->execute([$email]);curl_close($admin);curl_close($cashier);}
