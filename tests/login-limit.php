<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require dirname(__DIR__).'/vendor/autoload.php';
$env=Dotenv\Dotenv::createArrayBacked(dirname(__DIR__))->load();
if (($env['APP_ENV']??'')!=='local') throw new RuntimeException('Local development only.');
$db=new PDO('mysql:host='.$env['DB_HOST'].';dbname='.$env['DB_DATABASE'].';charset=utf8mb4',$env['DB_USERNAME'],$env['DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
class Throttled extends RuntimeException {}
function layout(string $title,string $body):never {throw new Throttled($title);}
function check(bool $ok,string $label):void {if (!$ok) throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
$_SERVER['REQUEST_METHOD']='POST';$_SERVER['REMOTE_ADDR']='qa-login-'.bin2hex(random_bytes(12));
$testBucket=hash('sha256',$_SERVER['REMOTE_ADDR']);$path='/login';
ob_start();
try {
    for ($i=0;$i<20;$i++) require dirname(__DIR__).'/app/login-limit.php';
    $s=$db->prepare('SELECT attempts FROM login_attempts WHERE bucket=?');$s->execute([$testBucket]);check((int)$s->fetchColumn()===20,'first 20 attempts recorded');
    $blocked=false;
    try {require dirname(__DIR__).'/app/login-limit.php';}catch(Throttled $error){$blocked=true;}
    check($blocked && http_response_code()===429,'attempt 21 blocked with HTTP 429');
    check(!$db->inTransaction(),'blocked request releases transaction');
    $s=$db->prepare('UPDATE login_attempts SET window_started=UTC_TIMESTAMP()-INTERVAL 16 MINUTE WHERE bucket=?');$s->execute([$testBucket]);
    require dirname(__DIR__).'/app/login-limit.php';
    $s=$db->prepare('SELECT attempts FROM login_attempts WHERE bucket=?');$s->execute([$testBucket]);check((int)$s->fetchColumn()===1,'expired window resets counter');
}finally{
    if($db->inTransaction())$db->rollBack();
    $s=$db->prepare('DELETE FROM login_attempts WHERE bucket=?');$s->execute([$testBucket]);
    ob_end_flush();
}
