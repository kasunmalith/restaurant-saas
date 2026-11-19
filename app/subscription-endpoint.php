<?php
if ($path!=='/api/admin/subscription') return;
$s=$db->prepare("SELECT id FROM users WHERE id=? AND role='SUPER_ADMIN' AND is_active=1");$s->execute([$_SESSION['user']['id']??0]);
if (!$s->fetchColumn()) order_fail('Owner access required.',403);
if ($_SERVER['REQUEST_METHOD']!=='POST') order_fail('POST required.',405);
if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))) order_fail('Refresh your security token.',403);
$status=(string)($_POST['status']??'');$expires=(string)($_POST['expires_on']??'');$starts=(string)($_POST['starts_on']??'');$fee=(string)($_POST['fee']??'');$plan=(string)($_POST['plan']??'Annual');
if (!in_array($status,['ACTIVE','TRIAL','EXPIRED','SUSPENDED'],true)) order_fail('Invalid subscription status.');
if (!in_array($plan,['Monthly','Annual'],true)) order_fail('Choose a monthly or annual billing period.');
$date=DateTimeImmutable::createFromFormat('!Y-m-d',$expires);
if (!$date || $date->format('Y-m-d')!==$expires) order_fail('Enter a valid expiration date.');
$startDate=DateTimeImmutable::createFromFormat('!Y-m-d',$starts);if(!$startDate||$startDate->format('Y-m-d')!==$starts)order_fail('Enter a valid start date.');if(!preg_match('/^(0|[1-9][0-9]{0,8})(\.[0-9]{1,2})?$/D',$fee))order_fail('Enter a valid '.$plan.' fee.');
$s=$db->prepare('SELECT id,starts_on FROM subscriptions WHERE restaurant_id=? ORDER BY id DESC LIMIT 1');$s->execute([(int)($_POST['restaurant_id']??0)]);$subscription=$s->fetch();
if (!$subscription) order_fail('Subscription not found.',404);
if ($expires<$starts) order_fail('Expiration cannot precede the subscription start.');
try{
 $db->beginTransaction();
 $s=$db->prepare('UPDATE subscriptions SET plan=?,status=?,starts_on=?,expires_on=?,fee=? WHERE id=?');$s->execute([$plan,$status,$starts,$expires,$fee,$subscription['id']]);
 $restaurantId=(int)($_POST['restaurant_id']??0);
 if(in_array($status,['ACTIVE','TRIAL'],true)&&$starts<=gmdate('Y-m-d')&&$expires>=gmdate('Y-m-d')){$s=$db->prepare('UPDATE restaurants SET is_active=1 WHERE id=?');$s->execute([$restaurantId]);}
 elseif(in_array($status,['EXPIRED','SUSPENDED'],true)){$s=$db->prepare('UPDATE restaurants SET is_active=0 WHERE id=?');$s->execute([$restaurantId]);}
 $db->commit();
}catch(Throwable $error){if($db->inTransaction())$db->rollBack();error_log('Subscription update: '.$error->getMessage());order_fail('Unable to update the subscription.',500);}
json_out(['ok'=>true]);
