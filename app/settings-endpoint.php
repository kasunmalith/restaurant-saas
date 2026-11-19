<?php
declare(strict_types=1);
if ($path!=='/api/admin/settings') return;
$user=$_SESSION['user']??null;
$isCashier=($user['role']??null)==='CASHIER';
if (!$user || !in_array($user['role'],['RESTAURANT_ADMIN','CASHIER'],true)) order_fail('Restaurant staff access required.',403);
$s=$db->prepare("SELECT restaurant_id FROM users WHERE id=? AND role IN ('RESTAURANT_ADMIN','CASHIER') AND is_active=1");$s->execute([$user['id']]);$rid=(int)$s->fetchColumn();
if (!$rid) order_fail('Your account is inactive.',403);
if ($_SERVER['REQUEST_METHOD']!=='POST') order_fail('POST required.',405);
if (empty($_SESSION['csrf'])||!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))) order_fail('Security token expired. Refresh the page.',403);
if($isCashier){
    $allowedKeys=['csrf','is_accepting_orders','receipt_width_mm'];
    if(array_diff(array_keys($_POST),$allowedKeys))order_fail('Cashier settings may only update ordering availability and POS paper width.',403);
    $acceptingValue=(string)($_POST['is_accepting_orders']??'');
    $widthValue=(string)($_POST['receipt_width_mm']??'');
    if(!in_array($acceptingValue,['0','1'],true))order_fail('Select a valid online-ordering status.');
    if(!in_array($widthValue,['58','80'],true))order_fail('Select a valid POS thermal paper width.');
    try{
        $db->beginTransaction();
        $s=$db->prepare('UPDATE restaurants SET is_accepting_orders=? WHERE id=?');$s->execute([(int)$acceptingValue,$rid]);
        $s=$db->prepare('INSERT INTO restaurant_pos_settings (restaurant_id,receipt_width_mm,updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE receipt_width_mm=VALUES(receipt_width_mm),updated_by=VALUES(updated_by)');$s->execute([$rid,(int)$widthValue,(int)$user['id']]);
        $s=$db->prepare('SELECT manager_bill_edit_enabled FROM restaurant_pos_settings WHERE restaurant_id=?');$s->execute([$rid]);$billEditEnabled=(int)$s->fetchColumn();
        $db->commit();
    }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
    json_out(['ok'=>true,'manager_bill_edit_enabled'=>$billEditEnabled]);
}
$timezone=(string)($_POST['timezone']??'');
if (!in_array($timezone,DateTimeZone::listIdentifiers(),true)) order_fail('Select a valid timezone.');
$email=trim((string)($_POST['contact_email']??''));$phone=trim((string)($_POST['contact_phone']??''));$address=trim((string)($_POST['address']??''));
if ($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL)) order_fail('Enter a valid contact email.');
if (mb_strlen($email)>190||mb_strlen($phone)>40||mb_strlen($address)>2000) order_fail('Contact details are too long.');
$accepting=(int)(($_POST['is_accepting_orders']??'0')==='1');
$servicePercent=trim((string)($_POST['service_charge_percent']??''));
$deliveryMinimum=trim((string)($_POST['delivery_minimum']??'1000.00'));
$printWidth=(int)($_POST['receipt_width_mm']??80);
if(!in_array($printWidth,[58,80],true))order_fail('Select a valid POS thermal paper width.');
$billEditSetting=(string)($_POST['manager_bill_edit_enabled']??'0');
if(!in_array($billEditSetting,['0','1'],true))order_fail('Select whether manager bill correction is enabled.');
$acceptedOrderCancellationSetting=(string)($_POST['accepted_order_cancellation_enabled']??'0');
if(!in_array($acceptedOrderCancellationSetting,['0','1'],true))order_fail('Select whether accepted-order cancellation is enabled.');
if(!array_key_exists('service_charge_percent',$_POST)){$s=$db->prepare('SELECT service_charge_percent FROM restaurants WHERE id=?');$s->execute([$rid]);$servicePercent=(string)$s->fetchColumn();}
if(!array_key_exists('delivery_minimum',$_POST)){$s=$db->prepare('SELECT delivery_minimum FROM restaurants WHERE id=?');$s->execute([$rid]);$deliveryMinimum=(string)$s->fetchColumn();}
if(!preg_match('/^(?:0|[1-9][0-9]?|100)(?:\.[0-9]{1,2})?$/D',$servicePercent)|| (float)$servicePercent>100)order_fail('Service charge must be a percentage from 0 to 100, with up to two decimals.');
if(!preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/D',$deliveryMinimum)||(float)$deliveryMinimum>9999999999.99)order_fail('Delivery minimum must be a valid LKR amount.');
$s=$db->prepare('UPDATE restaurants SET contact_email=?,contact_phone=?,address=?,timezone=?,is_accepting_orders=?,service_charge_percent=?,delivery_minimum=? WHERE id=?');
$printSetting=$db->prepare('INSERT INTO restaurant_pos_settings (restaurant_id,receipt_width_mm,manager_bill_edit_enabled,accepted_order_cancellation_enabled,updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE receipt_width_mm=VALUES(receipt_width_mm),manager_bill_edit_enabled=VALUES(manager_bill_edit_enabled),accepted_order_cancellation_enabled=VALUES(accepted_order_cancellation_enabled),updated_by=VALUES(updated_by)');
$printSetting->execute([$rid,$printWidth,(int)$billEditSetting,(int)$acceptedOrderCancellationSetting,(int)$user['id']]);
$s->execute([$email?:null,$phone?:null,$address?:null,$timezone,$accepting,number_format((float)$servicePercent,2,'.',''),number_format((float)$deliveryMinimum,2,'.',''),$rid]);
json_out(['ok'=>true,'manager_bill_edit_enabled'=>(int)$billEditSetting,'accepted_order_cancellation_enabled'=>(int)$acceptedOrderCancellationSetting]);
