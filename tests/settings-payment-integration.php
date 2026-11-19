<?php
declare(strict_types=1);
$base='http://localhost/restaurant-saas/public';
$db=new PDO('mysql:host=127.0.0.1;dbname=restaurant_saas;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function check(bool $ok,string $label):void{echo($ok?'PASS ':'FAIL ').$label.PHP_EOL;if(!$ok)throw new RuntimeException($label);}
function post(string $url,array $fields,string $cookie):array{$c=curl_init($url);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($fields),CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie]);$body=(string)curl_exec($c);$code=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);return[$code,json_decode($body,true)];}
function csrf(string $base,string $cookie):string{$c=curl_init($base.'/dashboard');curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$cookie]);$html=(string)curl_exec($c);curl_close($c);preg_match('/name="csrf" value="([a-f0-9]+)"/',$html,$m);return$m[1]??'';}
$admin=tempnam(sys_get_temp_dir(),'settings-admin-');$owner=tempnam(sys_get_temp_dir(),'settings-owner-');$payment=0;
try{
 post($base.'/login',['email'=>'admin@olive.test','password'=>'password'],$admin);$token=csrf($base,$admin);check($token!=='','admin authenticated');
 [$code,$result]=post($base.'/api/admin/settings',['csrf'=>$token,'contact_email'=>'qa@olive.test','contact_phone'=>'+94 11 000 0000','address'=>'QA address','timezone'=>'Asia/Colombo','is_accepting_orders'=>'0'],$admin);check($code===200&&!empty($result['ok']),'admin saves restaurant settings');
 $row=$db->query('SELECT * FROM restaurants WHERE id=1')->fetch();check($row['contact_email']==='qa@olive.test'&&!(int)$row['is_accepting_orders'],'settings scoped to restaurant');
 $payload=json_encode(['slug'=>'olive-table','token'=>'olive-table-05-demo-token','idempotency_key'=>'settings-'.bin2hex(random_bytes(12)),'items'=>[['id'=>1,'qty'=>1]]]);$c=curl_init($base.'/api/order');curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_HTTPHEADER=>['Content-Type: application/json']]);$body=(string)curl_exec($c);$code=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);check($code===403&&str_contains($body,'not currently accepting'),'paused ordering blocks customer submission');
 [$code,$result]=post($base.'/api/admin/settings',['csrf'=>$token,'contact_email'=>'','contact_phone'=>'','address'=>'','timezone'=>'Asia/Colombo','is_accepting_orders'=>'1'],$admin);check($code===200&&!empty($result['ok']),'ordering resumed');
 post($base.'/login',['email'=>'owner@example.com','password'=>'password'],$owner);$ownerToken=csrf($base,$owner);check($ownerToken!=='','owner authenticated');
 [$code,$result]=post($base.'/api/owner/payment',['csrf'=>$ownerToken,'restaurant_id'=>1,'amount'=>'125000.50','paid_on'=>date('Y-m-d'),'method'=>'Bank transfer','reference'=>'QA-PAYMENT','notes'=>'Integration test'],$owner);check($code===200&&!empty($result['id']),'owner records subscription payment');$payment=(int)$result['id'];
 $row=$db->query('SELECT * FROM subscription_payments WHERE id='.$payment)->fetch();check($row&&$row['restaurant_id']==1&&$row['amount']==='125000.50','payment stored against correct tenant and subscription');
 [$code]=post($base.'/api/owner/payment',['csrf'=>$token,'restaurant_id'=>1,'amount'=>'1','paid_on'=>date('Y-m-d')],$admin);check($code===403,'restaurant admin cannot record SaaS payments');
}finally{if($payment)$db->exec('DELETE FROM subscription_payments WHERE id='.$payment);$db->exec("UPDATE restaurants SET contact_email=NULL,contact_phone=NULL,address=NULL,timezone='Asia/Colombo',is_accepting_orders=1 WHERE id=1");@unlink($admin);@unlink($owner);}
