<?php
declare(strict_types=1);
$base='http://localhost/restaurant-saas/public';
function check(bool $ok,string $label):void{echo($ok?'PASS ':'FAIL ').$label.PHP_EOL;if(!$ok)throw new RuntimeException($label);}
function request(string $url,string $cookie,?array $post=null):array{$c=curl_init($url);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie]);if($post!==null)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]);$body=(string)curl_exec($c);$code=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);return[$code,$body,json_decode($body,true)];}
$cookie=tempnam(sys_get_temp_dir(),'golden-');
try{
 [$code]=request($base.'/login',$cookie,['email'=>'cashier@golden.test','password'=>'password']);check($code===302,'Golden Spoon cashier login');
 [$code,$html]=request($base.'/dashboard',$cookie);check($code===200&&str_contains($html,'Golden Spoon Cafe')&&!str_contains($html,'The Olive Table'),'cashier dashboard identifies correct tenant');preg_match('/<script type="application\/json" id="staff-config">(.*?)<\/script>/s',$html,$m);$config=json_decode($m[1]??'{}',true);
 [$code,$body,$orders]=request($base.'/api/cashier/orders',$cookie);$numbers=array_column($orders,'order_number');check($code===200&&in_array('DEMO-GS-1001',$numbers,true)&&!in_array('DEMO-OT-1001',$numbers,true),'Golden Spoon cashier receives only Golden Spoon orders');
 [$code,$body,$result]=request($base.'/api/cashier/availability',$cookie,['csrf'=>$config['csrf']??'','item_id'=>1,'available'=>'0']);check($code===404&&str_contains($body,'Item not found'),'cashier cannot change another tenant item');
 [$code,$menu]=request($base.'/r/golden-spoon/t/golden-spoon-05-demo-token',$cookie);check($code===200&&str_contains($menu,'Golden Spoon Cafe')&&str_contains($menu,'TABLE 05'),'Golden Spoon Table 05 QR opens correct menu');
}finally{@unlink($cookie);}
