<?php
declare(strict_types=1);

const REMEMBER_LOGIN_SECONDS = 7 * 24 * 60 * 60;

function remember_login_cookie_name(): ?string
{
    $portal=$GLOBALS['activePortal']??null;
    return is_string($portal)&&$portal!==''?'EE_REMEMBER_'.strtoupper(str_replace('-','_',$portal)):null;
}

function remember_login_cookie_options(int $expires): array
{
    $portal=(string)($GLOBALS['activePortal']??'');
    return [
        'expires'=>$expires,
        'path'=>rtrim(site_base(),'/').'/'.$portal,
        'domain'=>'',
        'secure'=>isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off',
        'httponly'=>true,
        'samesite'=>'Lax',
    ];
}

function remember_login_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value),'+/','-_'),'=');
}

function remember_login_decode(string $value): ?string
{
    $padding=(4-strlen($value)%4)%4;
    $decoded=base64_decode(strtr($value,'-_','+/').str_repeat('=',$padding),true);
    return $decoded===false?null:$decoded;
}

function remember_login_clear(): void
{
    $name=remember_login_cookie_name();
    if($name===null)return;
    setcookie($name,'',remember_login_cookie_options(time()-3600));
    unset($_COOKIE[$name]);
}

function remember_login_issue(array $user,array $env): void
{
    $name=remember_login_cookie_name();
    $portal=$GLOBALS['activePortal']??null;
    $appKey=(string)($env['APP_KEY']??'');
    if($name===null||!is_string($portal)||$appKey===''||empty($user['password_hash']))return;
    $expires=time()+REMEMBER_LOGIN_SECONDS;
    $body=remember_login_encode(json_encode([
        'id'=>(int)$user['id'],
        'role'=>(string)$user['role'],
        'portal'=>$portal,
        'expires'=>$expires,
    ],JSON_UNESCAPED_SLASHES));
    $passwordVersion=hash('sha256',(string)$user['password_hash']);
    $signature=hash_hmac('sha256',$body.'|'.$passwordVersion,$appKey);
    setcookie($name,$body.'.'.$signature,remember_login_cookie_options($expires));
}

function remember_login_restore(PDO $db,array $env): void
{
    if(isset($_SESSION['user']))return;
    $name=remember_login_cookie_name();
    $portal=$GLOBALS['activePortal']??null;
    $expectedRole=active_portal_role();
    $appKey=(string)($env['APP_KEY']??'');
    if($name===null||!is_string($portal)||$expectedRole===null||$appKey===''||empty($_COOKIE[$name]))return;
    $parts=explode('.',(string)$_COOKIE[$name],2);
    $json=count($parts)===2?remember_login_decode($parts[0]):null;
    $payload=$json!==null?json_decode($json,true):null;
    if(!is_array($payload)||(int)($payload['id']??0)<1||($payload['portal']??null)!==$portal||($payload['role']??null)!==$expectedRole||(int)($payload['expires']??0)<time()){
        remember_login_clear();return;
    }
    $query=$db->prepare('SELECT * FROM users WHERE id=? AND role=? AND is_active=1');
    $query->execute([(int)$payload['id'],$expectedRole]);
    $user=$query->fetch();
    if(!$user){remember_login_clear();return;}
    $passwordVersion=hash('sha256',(string)$user['password_hash']);
    $expected=hash_hmac('sha256',$parts[0].'|'.$passwordVersion,$appKey);
    if(!hash_equals($expected,$parts[1])){remember_login_clear();return;}
    session_regenerate_id(true);
    $_SESSION['user']=$user;
}
