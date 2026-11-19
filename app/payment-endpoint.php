<?php
declare(strict_types=1);
if ($path!=='/api/owner/payment') return;
$s=$db->prepare("SELECT id FROM users WHERE id=? AND role='SUPER_ADMIN' AND is_active=1");$s->execute([$_SESSION['user']['id']??0]);$owner=(int)$s->fetchColumn();
if (!$owner) order_fail('Owner access required.',403);
if ($_SERVER['REQUEST_METHOD']!=='POST') order_fail('POST required.',405);
if (empty($_SESSION['csrf'])||!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))) order_fail('Refresh your security token.',403);
$rid=(int)($_POST['restaurant_id']??0);$amount=(string)($_POST['amount']??'');$paid=(string)($_POST['paid_on']??'');
if(!preg_match('/^(0|[1-9][0-9]{0,8})(\.[0-9]{1,2})?$/D',$amount))order_fail('Enter a valid payment amount.');
$date=DateTimeImmutable::createFromFormat('!Y-m-d',$paid);if(!$date||$date->format('Y-m-d')!==$paid)order_fail('Enter a valid payment date.');
$method=trim((string)($_POST['method']??''));$reference=trim((string)($_POST['reference']??''));$notes=trim((string)($_POST['notes']??''));
if(mb_strlen($method)>60||mb_strlen($reference)>120||mb_strlen($notes)>500)order_fail('Payment details are too long.');
$s=$db->prepare('SELECT id FROM subscriptions WHERE restaurant_id=? ORDER BY id DESC LIMIT 1');$s->execute([$rid]);$subscription=(int)$s->fetchColumn();if(!$subscription)order_fail('Subscription not found.',404);
$s=$db->prepare('INSERT INTO subscription_payments(restaurant_id,subscription_id,amount,paid_on,method,reference,notes,created_by) VALUES(?,?,?,?,?,?,?,?)');
$s->execute([$rid,$subscription,$amount,$paid,$method?:null,$reference?:null,$notes?:null,$owner]);
json_out(['ok'=>true,'id'=>$db->lastInsertId()]);
