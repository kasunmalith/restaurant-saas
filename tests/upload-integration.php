<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/vendor/autoload.php';
$root=dirname(__DIR__);
$env=Dotenv\Dotenv::createArrayBacked($root)->load();
if (($env['APP_ENV']??'')!=='local') throw new RuntimeException('Run only on local development.');
$db=new PDO('mysql:host='.$env['DB_HOST'].';dbname='.$env['DB_DATABASE'].';charset=utf8mb4',$env['DB_USERNAME'],$env['DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$base=rtrim($env['APP_URL'],'/');
$curl=curl_init();curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>20]);
function request(string $path,?array $fields=null):array {
    global $curl,$base;
    curl_setopt($curl,CURLOPT_URL,$base.$path);
    if ($fields!==null) {curl_setopt($curl,CURLOPT_POST,true);curl_setopt($curl,CURLOPT_POSTFIELDS,$fields);} else curl_setopt($curl,CURLOPT_HTTPGET,true);
    $body=curl_exec($curl);if ($body===false) throw new RuntimeException(curl_error($curl));
    return [(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),$body];
}
function check(bool $value,string $label):void {if (!$value) throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
$name='Upload integration '.bin2hex(random_bytes(8));$id=null;$imagePath=null;$fixture=__DIR__.'/upload-fixture.png';
try {
    [$code]=request('/login',['email'=>'admin@olive.test','password'=>'password']);check($code===302,'admin login');
    [$code,$body]=request('/dashboard');
    check($code===200 && str_contains($body,'name="photo"'),'upload field on dashboard');
    preg_match('/name="csrf" value="([a-f0-9]+)"/',$body,$match);$csrf=$match[1]??'';
    $fields=['csrf'=>$csrf,'name'=>$name,'category_id'=>'1','price'=>'123.45'];
    [$code]=request('/api/admin/item',$fields+['photo'=>new CURLFile($root.'/.env.example','text/plain','fake.jpg')]);check($code===422,'non-image upload rejected');
    $image=imagecreatetruecolor(1600,800);imagepng($image,$fixture);imagedestroy($image);
    [$code,$body]=request('/api/admin/item',$fields+['photo'=>new CURLFile($fixture,'image/png','food.png')]);
    $result=json_decode($body,true);check($code===200 && !empty($result['ok']),'photo and item saved');$id=(int)$result['id'];
    $s=$db->prepare('SELECT * FROM menu_items WHERE id=? AND name=?');$s->execute([$id,$name]);$item=$s->fetch();
    check((bool)$item,'created item in database');$imagePath=$root.'/public/'.$item['image_path'];
    $size=getimagesize($imagePath);check($size[0]===1200 && $size[1]===600 && $size['mime']==='image/jpeg','proportional resize and JPEG re-encoding');
    [$code,$body]=request('/r/olive-table/t/olive-table-05-demo-token');check($code===200 && str_contains($body,$name) && str_contains($body,$item['image_path']),'customer menu includes uploaded photo');
    [$code,$body]=request('/'.$item['image_path']);check($code===200 && str_starts_with($body,"\xff\xd8"),'saved image served over HTTP');
    [$code]=request('/api/admin/item',$fields+['id'=>(string)$id]);check($code===200,'edit without replacing image');
    $s->execute([$id,$name]);check($s->fetch()['image_path']===$item['image_path'],'existing image preserved');
} finally {
    if ($id) {$s=$db->prepare('DELETE FROM menu_items WHERE id=? AND name=?');$s->execute([$id,$name]);}
    if ($imagePath && is_file($imagePath)) unlink($imagePath);
    if (is_file($fixture)) unlink($fixture);
    curl_close($curl);
}
