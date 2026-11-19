<?php
declare(strict_types=1);
$base='http://localhost/restaurant-saas/public';$db=new PDO('mysql:host=127.0.0.1;dbname=restaurant_saas;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function check(bool $ok,string $label):void{echo($ok?'PASS ':'FAIL ').$label.PHP_EOL;if(!$ok)throw new RuntimeException($label);}
function send(string $url,array $fields,string $cookie):array{$c=curl_init($url);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($fields),CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie]);$body=(string)curl_exec($c);$code=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);return[$code,json_decode($body,true)];}
$cookie=tempnam(sys_get_temp_dir(),'retire-');$id=0;
try{
 send($base.'/login',['email'=>'admin@olive.test','password'=>'password'],$cookie);$c=curl_init($base.'/dashboard');curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$cookie]);$html=(string)curl_exec($c);curl_close($c);preg_match('/name="csrf" value="([a-f0-9]+)"/',$html,$m);$csrf=$m[1]??'';
 [$code,$created]=send($base.'/api/admin/item',['csrf'=>$csrf,'category_id'=>1,'name'=>'QA Retirement Item','price'=>'123.45','description'=>'Temporary integration item'],$cookie);check($code===200&&!empty($created['id']),'create retirement fixture');$id=(int)$created['id'];
 [$code,$result]=send($base.'/api/admin/item/status',['csrf'=>$csrf,'id'=>$id,'is_active'=>'0'],$cookie);check($code===200&&!empty($result['ok']),'retire item');
 $c=curl_init($base.'/r/olive-table/t/olive-table-05-demo-token');curl_setopt($c,CURLOPT_RETURNTRANSFER,true);$menu=(string)curl_exec($c);curl_close($c);check(!str_contains($menu,'QA Retirement Item'),'retired item hidden from customer menu');
 $payload=json_encode(['slug'=>'olive-table','token'=>'olive-table-05-demo-token','idempotency_key'=>'retire-'.bin2hex(random_bytes(12)),'items'=>[['id'=>$id,'qty'=>1]]]);$c=curl_init($base.'/api/order');curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_HTTPHEADER=>['Content-Type: application/json']]);$body=(string)curl_exec($c);$code=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);check($code===422&&str_contains($body,'unavailable'),'retired item cannot be ordered');
 [$code,$result]=send($base.'/api/admin/item/status',['csrf'=>$csrf,'id'=>$id,'is_active'=>'1'],$cookie);check($code===200&&!empty($result['ok']),'restore item');
 $c=curl_init($base.'/r/olive-table/t/olive-table-05-demo-token');curl_setopt($c,CURLOPT_RETURNTRANSFER,true);$menu=(string)curl_exec($c);curl_close($c);check(str_contains($menu,'QA Retirement Item'),'restored item returns to customer menu');
}finally{if($id)$db->exec('DELETE FROM menu_items WHERE id='.(int)$id);@unlink($cookie);}
