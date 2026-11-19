<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=dirname(__DIR__);$env=[];
foreach(file($root.'/.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){if(str_starts_with(trim($line),'#'))continue;[$key,$value]=array_pad(explode('=',$line,2),2,'');$env[trim($key)]=trim($value,' "');}
if(!in_array($env['DB_HOST']??'', ['localhost','127.0.0.1'],true))throw new RuntimeException('Local database only.');
$db=new PDO('mysql:host='.$env['DB_HOST'].';dbname='.$env['DB_DATABASE'].';charset=utf8mb4',$env['DB_USERNAME'],$env['DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$rid=(int)$db->query('SELECT id FROM restaurants ORDER BY id LIMIT 1')->fetchColumn();if(!$rid)throw new RuntimeException('No local restaurant.');
$base=rtrim($env['APP_URL'],'/');$email='pos-audit-qa-'.bin2hex(random_bytes(6)).'@example.test';$password=bin2hex(random_bytes(16));$id=null;
$curl=curl_init();curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false]);
function visit(string $path,?array $data=null):array{global $curl,$base;curl_setopt($curl,CURLOPT_URL,$base.$path);if($data===null)curl_setopt($curl,CURLOPT_HTTPGET,true);else{curl_setopt($curl,CURLOPT_POST,true);curl_setopt($curl,CURLOPT_POSTFIELDS,$data);} $body=curl_exec($curl);if($body===false)throw new RuntimeException(curl_error($curl));return [(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),(string)$body];}
function check(bool $value,string $label):void{if(!$value)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
try{
    $q=$db->prepare('INSERT INTO users(restaurant_id,role,name,email,password_hash,is_active) VALUES(NULL,"SUPER_ADMIN","POS audit QA",?,?,1)');$q->execute([$email,password_hash($password,PASSWORD_DEFAULT)]);$id=(int)$db->lastInsertId();
    [$code]=visit('/s-admin/login',['email'=>$email,'password'=>$password]);check($code===302,'temporary Super Admin login');
    [$code,$page]=visit('/s-admin/dashboard');check($code===200&&str_contains($page,'id="pos-audit-owner"'),'Super Admin has visible bill-audit panel');
    [$code,$body]=visit('/s-admin/api/owner/pos-audit?restaurant_id='.$rid);$data=json_decode($body,true);check($code===200&&is_array($data['edits']??null),'Super Admin can access tenant-scoped audit records');
    [$code]=visit('/s-admin/api/owner/pos-audit?restaurant_id=0');check($code===422,'invalid restaurant ID is rejected');
}finally{
    if($id){$q=$db->prepare('DELETE FROM users WHERE id=? AND role="SUPER_ADMIN"');$q->execute([$id]);}
    curl_close($curl);
    echo "Temporary Super Admin removed.\n";
}
