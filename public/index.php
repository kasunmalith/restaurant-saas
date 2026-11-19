<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/session.php';
date_default_timezone_set('Asia/Colombo');
$root=dirname(__DIR__); $env=[];
if (is_file($root.'/vendor/autoload.php')) require $root.'/vendor/autoload.php';
if (is_file($root.'/.env')) foreach(file($root.'/.env', FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line){ if(str_starts_with(trim($line),'#')) continue; [$k,$v]=array_pad(explode('=', $line,2),2,''); $env[trim($k)]=trim($v,' "'); }
$db=new PDO('mysql:host='.($env['DB_HOST']??'127.0.0.1').';dbname='.($env['DB_DATABASE']??'restaurant_saas').';charset=utf8mb4',$env['DB_USERNAME']??'root',$env['DB_PASSWORD']??'', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec("SET time_zone = '+00:00'");
require_once $root.'/app/subscription-status.php';
sync_expired_restaurant_subscriptions($db);
require_once $root.'/app/token-crypto.php';
require_once $root.'/app/menu-visit-counter.php';
function e($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');} function json_out($v):never{header('Content-Type: application/json'); echo json_encode($v); exit;}
function site_base():string{
    global $env;
    $configuredPath=parse_url($env['APP_URL']??'',PHP_URL_PATH);
    if(is_string($configuredPath) && $configuredPath!=='' && $configuredPath!=='/') return rtrim($configuredPath,'/');
    return rtrim(dirname($_SERVER['SCRIPT_NAME']),'/');
} function base():string{return site_base().(($GLOBALS['activePortal']??null)?'/'.$GLOBALS['activePortal']:'');}
function csrf():string{if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(24));return $_SESSION['csrf'];}
function require_csrf():void{if(!hash_equals($_SESSION['csrf']??'', $_SERVER['HTTP_X_CSRF_TOKEN']??''))json_out(['error'=>'Invalid security token']);}
function public_url(string $slug,string $tableToken):string{global $env;return (($_SERVER['HTTPS']??'off')==='on'?'https':'http').'://'.($_SERVER['HTTP_HOST']??'localhost').site_base().'/'.rawurlencode($slug).'/'.rawurlencode(table_public_hash($tableToken,$env));}
function public_table_context(string $slug,string $hashCode,bool $lock=false): ?array{global $db,$env;$s=$db->prepare('SELECT r.*,r.id restaurant_id,r.is_active restaurant_active,t.id table_id,t.table_number,t.token_hash,t.is_active table_active FROM restaurants r JOIN restaurant_tables t ON t.restaurant_id=r.id WHERE r.slug=? AND t.is_active=1'.($lock?' FOR UPDATE':''));$s->execute([$slug]);foreach($s as $table)if(hash_equals(table_public_hash_from_digest($table['token_hash'],$env),strtoupper($hashCode)))return$table;return null;}
function valid_demo_login_alias(array $user,string $password,PDO $db):bool{
    if($password!=='demo')return false;
    $allowedAccounts=[
        'demo@admin.com'=>'RESTAURANT_ADMIN',
        'demo@cashier.com'=>'CASHIER',
    ];
    $email=strtolower(trim((string)($user['email']??'')));
    if(($allowedAccounts[$email]??null)!==($user['role']??null))return false;
    $restaurant=$db->prepare('SELECT slug FROM restaurants WHERE id=? AND is_active=1');
    $restaurant->execute([(int)($user['restaurant_id']??0)]);
    return $restaurant->fetchColumn()==='demo-restaurant';
}
function layout(string $title,string $body):never{$credit='<footer class="text-center p-4">Software Development by <a href="https://www.energeticedge.lk/" target="_blank" rel="noopener noreferrer">Energetic Edge (Pvt) Ltd</a></footer>';echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="asset-order-review-version" content="'.e(asset_version_for('order-review.js')).'"><title>'.e($title).'</title><link rel="stylesheet" href="'.base().'/assets/vendor/bootstrap/bootstrap.min.css"><link rel="stylesheet" href="'.base().'/assets/app.css"><link rel="stylesheet" href="'.base().'/assets/login.css?v=1"><link rel="stylesheet" href="'.base().'/assets/category-picker.css?v=3"><link rel="stylesheet" href="'.base().'/assets/customer-themes.css?v=26"><link rel="stylesheet" href="'.base().'/assets/portion-options.css?v=4"></head><body>'.$body.$credit.'<script src="'.base().'/assets/vendor/bootstrap/bootstrap.bundle.min.js"></script><script src="'.base().'/assets/customer-themes.js?v=4"></script></body></html>';exit;}
require_once $root.'/app/remember-login.php';
remember_login_restore($db,$env);
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH); $path=preg_replace('#^'.preg_quote(base(),'#').'#','',$path)?:'/';
if(str_starts_with($path,'/public/')){header('Location: '.base().substr($path,7),true,301);exit;}
require $root.'/app/order-endpoints.php';
require $root.'/app/online-order-endpoint.php';
require $root.'/app/online-customer-endpoint.php';
require $root.'/app/table-session-endpoint.php';
require $root.'/app/admin-endpoints.php';
require $root.'/app/table-endpoints.php';
require $root.'/app/settings-endpoint.php';
require $root.'/app/online-ordering-toggle-endpoint.php';
require $root.'/app/payment-endpoint.php';
require $root.'/app/public-menu-endpoint.php';
require $root.'/app/qr-branding-endpoint.php';
require $root.'/app/qr-endpoints.php';
require $root.'/app/report-endpoint.php';
require $root.'/app/staff-endpoints.php';
require $root.'/app/profile-endpoint.php';
require $root.'/app/owner-endpoints.php';
require $root.'/app/inventory-portal-owner-endpoint.php';
require $root.'/app/pos-feature-endpoint.php';
require $root.'/app/pos-endpoints.php';
require $root.'/app/subscription-endpoint.php';
require $root.'/app/login-limit.php';
require $root.'/app/cashier-endpoints.php';
if ($path==='/menu-admin') { header('Location: '.base().'/dashboard#menu'); exit; }
$demoHint=($env['APP_ENV']??'production')==='local'?'<small>Local demo: owner@example.com / password</small>':'';
$portalLabel=match ($activePortal) {'s-admin'=>'Super Admin','r-owner'=>'Manager','cashier'=>'Cashier',default=>'Restaurant ordering SaaS'};
if($path==='/login' && $_SERVER['REQUEST_METHOD']==='POST'){
    $s=$db->prepare('SELECT * FROM users WHERE email=? AND is_active=1');$s->execute([$_POST['email']??'']);$u=$s->fetch();
    $submittedPassword=(string)($_POST['password']??'');
    $passwordAccepted=$u && (password_verify($submittedPassword,$u['password_hash']) || valid_demo_login_alias($u,$submittedPassword,$db));
    if($passwordAccepted && (active_portal_role()===null || $u['role']===active_portal_role())){
        session_regenerate_id(true);$_SESSION['user']=$u;if(($_POST['remember']??'')==='1')remember_login_issue($u,$env);else remember_login_clear();header('Location: '.base().'/dashboard');exit;
    }
    $loginError='<p role="alert">Invalid credentials or wrong portal.</p>';
}
if($path==='/login'){
    if(isset($_SESSION['user']) && (active_portal_role()===null || $_SESSION['user']['role']===active_portal_role())){header('Location: '.base().'/dashboard');exit;}
    layout('Login · '.$portalLabel,'<main class="auth"><form method="post"><h1>Energetic Edge</h1><p class="muted">'.e($portalLabel).'</p>'.($loginError??'').'<input name="email" type="email" placeholder="Email" required><input name="password" type="password" placeholder="Password" required><label class="remember-login"><input name="remember" type="checkbox" value="1"><span>Remember me for 1 week</span></label><button>Sign in</button>'.$demoHint.'</form></main>');
}
if(preg_match('#^/([^/]+)/menu/?$#',$path,$parts)){ $slug=rawurldecode($parts[1]);$q=$db->prepare('SELECT * FROM restaurants WHERE slug=? AND is_active=1 LIMIT 1');$q->execute([$slug]);$restaurant=$q->fetch();if(!$restaurant)layout('Unavailable','<main class="shell"><h1>Menu unavailable</h1><p>This restaurant menu is unavailable.</p></main>');record_restaurant_menu_visit($db,(int)$restaurant['id']);$restaurant['table_id']=0;$restaurant['table_number']='';require $root.'/app/customer-menu.php';render_customer_menu($restaurant,$slug,'',true);}
if(preg_match('#^/([^/]+)/([^/]+)/?$#',$path,$parts)){ $slug=rawurldecode($parts[1]); $hashCode=rawurldecode($parts[2]); $table=public_table_context($slug,$hashCode);if(!$table)layout('Unavailable','<main class="shell"><h1>Menu unavailable</h1><p>This table QR code is invalid or inactive.</p></main>');if(!(int)$table['restaurant_active'])layout('Menu suspended','<main class="shell suspended-menu"><section><span aria-hidden="true">!</span><h1>Menu suspended</h1><p>This menu is currently suspended. Please contact the restaurant management for assistance.</p></section></main>');record_restaurant_menu_visit($db,(int)$table['restaurant_id']);require $root.'/app/customer-menu.php';render_customer_menu($table,$slug,$hashCode);}
if(str_starts_with($path,'/r/')){ $parts=explode('/',trim($path,'/')); $slug=rawurldecode($parts[1]??''); $hashCode=rawurldecode($parts[3]??''); $s=$db->prepare('SELECT t.id FROM restaurants r JOIN restaurant_tables t ON t.restaurant_id=r.id WHERE r.slug=? AND t.is_active=1 AND t.token_hash=?');$s->execute([$slug,hash('sha256',$hashCode)]);if(!$s->fetch())layout('Unavailable','<main class="shell"><h1>Menu unavailable</h1><p>This table QR code is invalid or inactive.</p></main>');header('Location: '.public_url($slug,$hashCode),true,301);exit;}
if(preg_match('#^/([^/]+)/([^/]+)/?$#',$path,$parts)){ $slug=rawurldecode($parts[1]); $hashCode=rawurldecode($parts[2]); $s=$db->prepare('SELECT r.*,t.id table_id,t.table_number FROM restaurants r JOIN restaurant_tables t ON t.restaurant_id=r.id WHERE r.slug=? AND t.is_active=1 AND t.token_hash=?');$s->execute([$slug,hash('sha256',$hashCode)]);$r=$s->fetch();if(!$r)layout('Unavailable','<main class="shell"><h1>Menu unavailable</h1><p>This table QR code is invalid or inactive.</p></main>');$cats=$db->prepare('SELECT * FROM menu_categories WHERE restaurant_id=? ORDER BY sort_order,id');$cats->execute([$r['id']]);$cats=$cats->fetchAll();$items=$db->prepare('SELECT * FROM menu_items WHERE restaurant_id=? AND is_active=1 ORDER BY category_id,id');$items->execute([$r['id']]);$groups=[];foreach($items as $it)$groups[$it['category_id']][]=$it;$html='<header class="hero" style="--brand:'.e($r['brand_color']).'"><div><span class="eyebrow">TABLE '.e($r['table_number']).'</span><h1>'.e($r['name']).'</h1><p>'.e($r['description']).'</p><p id="ordering-status" role="status"></p></div></header><main class="menu shell"><nav class="chips">';foreach($cats as $c)$html.='<a href="#cat'.$c['id'].'">'.e($c['name']).'</a>';$html.='</nav><input class="search" id="search" placeholder="Search the menu...">';foreach($cats as $c){$html.='<section id="cat'.$c['id'].'"><h2>'.e($c['name']).'</h2><div class="grid">';foreach($groups[$c['id']]??[] as $it){$html.='<article class="food" data-name="'.e(strtolower($it['name'])).'"><div class="food-img">'.($it['image_path']?'<img src="'.base().'/'.e($it['image_path']).'">':'🍽️').'</div><div><h3>'.e($it['name']).'</h3><p>'.e($it['description']).'</p><strong>LKR '.number_format((float)$it['price'],2).'</strong><button class="add" data-id="'.$it['id'].'" data-price="'.$it['price'].'" data-name="'.e($it['name']).'" '.(!$it['is_available']?'disabled':'').'>'.($it['is_available']?'Add +':'Unavailable').'</button></div></article>';}$html.='</div></section>';}$branding='';if($r['logo_path'])$branding.='<img alt="Restaurant logo" style="width:88px;height:88px;object-fit:contain;background:white;border-radius:14px" src="'.base().'/'.e($r['logo_path']).'">';if($r['banner_path'])$branding.='<img alt="Restaurant banner" style="width:100%;max-height:280px;object-fit:cover;border-radius:18px;margin-top:20px" src="'.base().'/'.e($r['banner_path']).'">';$html=str_replace('<h1>'.e($r['name']).'</h1>',$branding.'<h1>'.e($r['name']).'</h1>',$html);$html.='</main><button class="sticky" id="view">View Order <span>0 items · LKR 0.00</span></button><script>window.APP='.json_encode(['base'=>base(),'restaurant_id'=>$r['id'],'table_id'=>$r['table_id'],'slug'=>$slug,'token'=>$hashCode]).';</script><script src="'.base().'/assets/menu.js?v=11"></script>';layout($r['name'],$html);}
if($path==='/logout'){
    remember_login_clear();
    $_SESSION=[];session_destroy();
    $cookie=session_get_cookie_params();
    setcookie(session_name(),'',[
        'expires'=>time()-3600,
        'path'=>(string)($cookie['path']?:'/'),
        'domain'=>(string)($cookie['domain']??''),
        'secure'=>(bool)$cookie['secure'],
        'httponly'=>(bool)$cookie['httponly'],
        'samesite'=>(string)($cookie['samesite']?:'Lax'),
    ]);
    header('Location: '.base().'/login');exit;
}
if($path==='/'||$path==='/dashboard'){header('Location: '.base().(isset($_SESSION['user'])?'/dashboard':'/login'));exit;}
http_response_code(404);layout('Not Found','<main class="shell"><h1>Page not found</h1><p>The requested page does not exist.</p></main>');
