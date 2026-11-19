<?php
declare(strict_types=1);
if($path==='/api/online-menu'){
    if($_SERVER['REQUEST_METHOD']!=='GET')order_fail('GET required.',405);
    $slug=trim((string)($_GET['slug']??''));
    $q=$db->prepare('SELECT id,is_active,is_accepting_orders,online_ordering_enabled,delivery_minimum FROM restaurants WHERE slug=? LIMIT 1');$q->execute([$slug]);$restaurant=$q->fetch();
    if(!$restaurant||!(int)$restaurant['is_active'])order_fail('This restaurant menu is unavailable.',404);
    $subscription=$db->prepare("SELECT COUNT(*) FROM subscriptions WHERE restaurant_id=? AND status IN ('ACTIVE','TRIAL') AND starts_on<=UTC_DATE() AND expires_on>=UTC_DATE()");$subscription->execute([(int)$restaurant['id']]);
    $s=$db->prepare('SELECT id,name,is_available,price,is_portioned,half_price,full_price FROM menu_items WHERE restaurant_id=? AND is_active=1 ORDER BY id');$s->execute([(int)$restaurant['id']]);$items=$s->fetchAll();require_once __DIR__.'/menu-options.php';menu_attach_options($db,(int)$restaurant['id'],$items);
    json_out(['accepting_orders'=>(bool)$restaurant['is_accepting_orders']&&(bool)$restaurant['online_ordering_enabled']&&(bool)$subscription->fetchColumn(),'delivery_minimum'=>$restaurant['delivery_minimum'],'items'=>$items]);
}
if($path!=='/api/menu')return;
if($_SERVER['REQUEST_METHOD']!=='GET')order_fail('GET required.',405);
$slug=(string)($_GET['slug']??'');$token=(string)($_GET['token']??'');
$context=public_table_context($slug,$token);
if(!$context||!$context['table_active'])order_fail('This table QR code is invalid or inactive.',403);
if(!$context['restaurant_active'])order_fail('This menu is currently suspended. Please contact the restaurant management for assistance.',403);
$subscription=$db->prepare("SELECT COUNT(*) FROM subscriptions WHERE restaurant_id=? AND status IN ('ACTIVE','TRIAL') AND starts_on<=UTC_DATE() AND expires_on>=UTC_DATE()");$subscription->execute([$context['id']]);
$s=$db->prepare('SELECT id,name,is_available,price,is_portioned,half_price,full_price FROM menu_items WHERE restaurant_id=? AND is_active=1 ORDER BY id');$s->execute([$context['id']]);$items=$s->fetchAll();require_once __DIR__.'/menu-options.php';menu_attach_options($db,(int)$context['id'],$items);
json_out(['accepting_orders'=>(bool)$context['is_accepting_orders']&&(bool)$subscription->fetchColumn(),'service_charge_percent'=>$context['service_charge_percent']??'10.00','items'=>$items]);
