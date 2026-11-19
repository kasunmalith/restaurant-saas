<?php
declare(strict_types=1);
$base='http://localhost/restaurant-saas/public';
$db=new PDO('mysql:host=127.0.0.1;dbname=restaurant_saas;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function check(bool $condition,string $label):void{echo ($condition?'PASS ':'FAIL ').$label.PHP_EOL;if(!$condition)throw new RuntimeException($label);}
function form(string $url,array $fields,string $cookie):array{$c=curl_init($url);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($fields),CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie]);$body=curl_exec($c);$code=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);return[$code,json_decode((string)$body,true)];}
$cookie=tempnam(sys_get_temp_dir(),'table-test-');$id=0;
try{
    [$code]=form($base.'/login',['email'=>'admin@olive.test','password'=>'password'],$cookie);check($code===302,'restaurant admin login');
    $c=curl_init($base.'/dashboard');curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$cookie]);$html=(string)curl_exec($c);curl_close($c);preg_match('/name="csrf" value="([a-f0-9]+)"/',$html,$match);$csrf=$match[1]??'';check($csrf!=='','dashboard exposes CSRF token');
    $number='QA-'.bin2hex(random_bytes(3));
    [$code,$created]=form($base.'/api/admin/table',['csrf'=>$csrf,'table_number'=>$number],$cookie);check($code===200&&!empty($created['ok'])&&!empty($created['token']??parse_url($created['menu_url']??'',PHP_URL_PATH)),'create table and QR links');$id=(int)$created['id'];
    $row=$db->query('SELECT * FROM restaurant_tables WHERE id='.$id)->fetch();check($row&&$row['token_cipher']&&$row['token_hash']!==$row['token_cipher'],'token encrypted at rest');
    [$code,$updated]=form($base.'/api/admin/table/update',['csrf'=>$csrf,'id'=>$id,'table_number'=>$number.'X','is_active'=>'0'],$cookie);check($code===200&&!empty($updated['ok']),'rename and deactivate table');
    $old=$created['menu_url'];$c=curl_init('http://localhost'.$old);curl_setopt($c,CURLOPT_RETURNTRANSFER,true);$body=(string)curl_exec($c);curl_close($c);check(str_contains($body,'invalid or inactive'),'inactive QR is rejected');
    [$code,$regenerated]=form($base.'/api/admin/table/regenerate',['csrf'=>$csrf,'id'=>$id],$cookie);check($code===200&&!empty($regenerated['menu_url']),'regenerate QR token');
    [$code,$updated]=form($base.'/api/admin/table/update',['csrf'=>$csrf,'id'=>$id,'table_number'=>$number.'X','is_active'=>'1'],$cookie);check($code===200&&!empty($updated['ok']),'reactivate table');
    $c=curl_init('http://localhost'.$regenerated['menu_url']);curl_setopt($c,CURLOPT_RETURNTRANSFER,true);$body=(string)curl_exec($c);curl_close($c);check(str_contains($body,'The Olive Table'),'new QR opens menu');
    $c=curl_init('http://localhost'.$old);curl_setopt($c,CURLOPT_RETURNTRANSFER,true);$body=(string)curl_exec($c);curl_close($c);check(str_contains($body,'invalid or inactive'),'old QR revoked');
}finally{if($id)$db->exec('DELETE FROM restaurant_tables WHERE id='.(int)$id);@unlink($cookie);}
