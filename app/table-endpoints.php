<?php
declare(strict_types=1);
if (!in_array($path,['/api/admin/table','/api/admin/table/update','/api/admin/table/regenerate'],true)) return;
$user=$_SESSION['user']??null;
if (!$user || !in_array($user['role'],['RESTAURANT_ADMIN','CASHIER'],true)) order_fail('Restaurant staff access required.',403);
$check=$db->prepare("SELECT restaurant_id FROM users WHERE id=? AND role IN ('RESTAURANT_ADMIN','CASHIER') AND is_active=1");
$check->execute([$user['id']]);
$rid=(int)$check->fetchColumn();
if (!$rid) order_fail('Your account is inactive.',403);
if ($_SERVER['REQUEST_METHOD']!=='POST') order_fail('POST required.',405);
if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))) order_fail('Security token expired. Refresh the page.',403);
require_once __DIR__.'/token-crypto.php';
$restaurant=$db->prepare('SELECT slug FROM restaurants WHERE id=?');$restaurant->execute([$rid]);$slug=(string)$restaurant->fetchColumn();
$respond=function(int $id,string $number,string $token)use($slug):never{
    json_out(['ok'=>true,'id'=>$id,'table_number'=>$number,'menu_url'=>public_url($slug,$token),'qr_url'=>base().'/qr?slug='.rawurlencode($slug).'&token='.rawurlencode($token),'pdf_url'=>base().'/qr-card?slug='.rawurlencode($slug).'&token='.rawurlencode($token).'&table='.rawurlencode($number)]);
};
if ($path==='/api/admin/table') {
    $number=trim((string)($_POST['table_number']??''));
    if ($number==='' || mb_strlen($number)>30) order_fail('Enter a table number between 1 and 30 characters.');
    $token=strtoupper(bin2hex(random_bytes(8)));
    try {$s=$db->prepare('INSERT INTO restaurant_tables(restaurant_id,table_number,token_hash,token_cipher) VALUES(?,?,?,?)');$s->execute([$rid,$number,hash('sha256',$token),encrypt_table_token($token,$env)]);}
    catch(PDOException $error){if($error->getCode()==='23000')order_fail('That table number already exists.',409);throw $error;}
    $respond((int)$db->lastInsertId(),$number,$token);
}
$id=(int)($_POST['id']??0);
$s=$db->prepare('SELECT id,table_number,token_cipher FROM restaurant_tables WHERE id=? AND restaurant_id=?');$s->execute([$id,$rid]);$table=$s->fetch();
if (!$table) order_fail('Table not found.',404);
if ($path==='/api/admin/table/regenerate') {
    $token=strtoupper(bin2hex(random_bytes(8)));
    $s=$db->prepare('UPDATE restaurant_tables SET token_hash=?,token_cipher=? WHERE id=? AND restaurant_id=?');$s->execute([hash('sha256',$token),encrypt_table_token($token,$env),$id,$rid]);
    $respond($id,(string)$table['table_number'],$token);
}
$number=trim((string)($_POST['table_number']??''));
if ($number==='' || mb_strlen($number)>30) order_fail('Enter a table number between 1 and 30 characters.');
$active=(int)(($_POST['is_active']??'0')==='1');
try {$s=$db->prepare('UPDATE restaurant_tables SET table_number=?,is_active=? WHERE id=? AND restaurant_id=?');$s->execute([$number,$active,$id,$rid]);}
catch(PDOException $error){if($error->getCode()==='23000')order_fail('That table number already exists.',409);throw $error;}
json_out(['ok'=>true]);
