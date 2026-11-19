<?php
declare(strict_types=1);

if($path!=='/api/online-ordering-toggle')return;
if($_SERVER['REQUEST_METHOD']!=='POST')order_fail('POST required.',405);
$user=$_SESSION['user']??null;
if(!$user||!in_array(($user['role']??''),['RESTAURANT_ADMIN','CASHIER'],true))order_fail('Restaurant staff access required.',403);
if(empty($_SESSION['csrf'])||!hash_equals((string)$_SESSION['csrf'],(string)($_POST['csrf']??'')))order_fail('Security token expired. Refresh the page.',403);
$enabled=(string)($_POST['enabled']??'');
if(!in_array($enabled,['0','1'],true))order_fail('Choose whether online ordering is ON or OFF.');
$owner=$db->prepare("SELECT restaurant_id FROM users WHERE id=? AND role IN ('RESTAURANT_ADMIN','CASHIER') AND is_active=1");$owner->execute([(int)$user['id']]);$restaurantId=(int)$owner->fetchColumn();
if(!$restaurantId)order_fail('Your manager account is inactive.',403);
try{
    $db->beginTransaction();
    $update=$db->prepare('UPDATE restaurants SET online_ordering_enabled=? WHERE id=?');$update->execute([(int)$enabled,$restaurantId]);
    $db->commit();
    json_out(['ok'=>true,'enabled'=>(int)$enabled]);
}catch(Throwable $error){if($db->inTransaction())$db->rollBack();error_log('Online ordering toggle failed: '.$error->getMessage());order_fail('Unable to update online ordering. Please retry.',500);}
