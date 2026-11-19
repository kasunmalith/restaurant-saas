<?php
declare(strict_types=1);
// Loaded before legacy routes so every public order uses the same validation.
function order_fail(string $message, int $status=422): never {
    http_response_code($status);
    json_out(['error'=>$message]);
}
function tracking_token(array $order): string {
    global $env;
    return hash_hmac('sha256', $order['id'].'|'.$order['idempotency_key'], $env['APP_KEY']);
}
function order_response(array $order): never {
    json_out(['ok'=>true,'order'=>[
        'id'=>$order['id'],'order_number'=>$order['order_number'],
        'status'=>$order['status'],'total'=>$order['total'],
        'tracking_token'=>tracking_token($order)
    ]]);
}
function new_order_number(PDO $db, int $restaurantId): string {
    $alphabet='0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    $exists=$db->prepare('SELECT 1 FROM orders WHERE restaurant_id=? AND order_number=? LIMIT 1');
    for($attempt=0;$attempt<10;$attempt++){
        $bits='';
        foreach(unpack('C*',random_bytes(5)) as $byte)$bits.=str_pad(decbin($byte),8,'0',STR_PAD_LEFT);
        $suffix='';
        for($offset=0;$offset<40;$offset+=5)$suffix.=$alphabet[bindec(substr($bits,$offset,5))];
        $number='ORD-'.$suffix;
        $exists->execute([$restaurantId,$number]);
        if(!$exists->fetchColumn())return $number;
    }
    throw new RuntimeException('Unable to generate a unique order number.');
}
if ($path==='/api/status') {
    $s=$db->prepare('SELECT * FROM orders WHERE id=?');
    $s->execute([(int)($_GET['id']??0)]);
    $order=$s->fetch();
    if (!$order || !hash_equals(tracking_token($order),(string)($_GET['token']??''))) order_fail('Order not found.',404);
    $response=['order_number'=>$order['order_number'],'status'=>$order['status'],'total'=>$order['total']];
    if(($_GET['details']??'')==='1'){
        $items=$db->prepare('SELECT item_name,portion,unit_price,quantity,subtotal,special_instruction FROM order_items WHERE order_id=? ORDER BY id');
        $items->execute([(int)$order['id']]);
        $response['order_type']=$order['order_type'];
        $response['payment_method']=$order['online_payment_method'];
        $response['delivery_address']=$order['delivery_address'];
        $response['delivery_notes']=$order['delivery_notes'];
        $response['special_request']=$order['special_request'];
        $response['created_at']=$order['created_at'];
        $response['items']=$items->fetchAll();
    }
    json_out($response);
}
if ($path==='/api/order-items') {
    $user=$_SESSION['user']??null;
    if (!$user || !in_array($user['role'],['RESTAURANT_ADMIN','CASHIER'],true)) order_fail('Sign in to view order details.',403);
    $s=$db->prepare('SELECT i.* FROM order_items i JOIN orders o ON o.id=i.order_id WHERE o.id=? AND o.restaurant_id=?');
    $s->execute([(int)($_GET['id']??0),$user['restaurant_id']]);
    json_out($s->fetchAll());
}
if ($path!=='/api/order' || $_SERVER['REQUEST_METHOD']!=='POST') return;
try {
    $d=json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR);
    if (!is_array($d)) order_fail('Invalid order.');
    $key=(string)($d['idempotency_key']??'');
    if (!preg_match('/^[a-zA-Z0-9-]{20,100}$/D',$key)) order_fail('A valid submission key is required.');
    $items=$d['items']??null;
    if (!is_array($items) || !array_is_list($items) || count($items)<1 || count($items)>50) order_fail('Select between 1 and 50 different items.');
    $selections=[];$itemIds=[];
    foreach ($items as $item) {
        if (!is_array($item) || !is_int($item['id']??null) || $item['id']<1 || !is_int($item['qty']??null) || $item['qty']<1 || $item['qty']>99) order_fail('Invalid item or quantity.');
        $portion=$item['portion']??null;
        if($portion!==null&&(!is_string($portion)||mb_strlen($portion)>40)) order_fail('Select a valid size.');
        $selectionKey=$item['id'].':'.($portion??"\0");
        if(isset($selections[$selectionKey])) order_fail('Duplicate item selection.');
        $selections[$selectionKey]=['id'=>$item['id'],'qty'=>$item['qty'],'portion'=>$portion];
        $itemIds[$item['id']]=$item['id'];
    }
    $note=$d['special_request']??'';
    if (!is_string($note) || mb_strlen($note)>1000) order_fail('Special requests must be 1,000 characters or fewer.');
    $db->beginTransaction();
    // Serialize submissions for this table, including simultaneous retries.
    $table=public_table_context((string)($d['slug']??''),(string)($d['token']??''),true);
    if (!$table || !$table['table_active']) { $db->rollBack(); order_fail('This table QR code is invalid or inactive.',403); }
    if (!$table['restaurant_active']) { $db->rollBack(); order_fail('This menu is currently suspended. Please contact the restaurant management for assistance.',403); }
    $rid=$table['restaurant_id'];
    $sessionId=(int)($d['dining_session_id']??0);$clientHash=hash('sha256',(string)($d['client_token']??''));
    $s=$db->prepare("SELECT * FROM dining_sessions WHERE id=? AND restaurant_id=? AND table_id=? AND client_token_hash=? AND status='ACTIVE' AND expires_at>UTC_TIMESTAMP() FOR UPDATE");
    $s->execute([$sessionId,$rid,$table['table_id'],$clientHash]);$visit=$s->fetch();
    if(!$visit){$db->rollBack();order_fail('This table session has expired. Refresh the menu to start a new bill.',409);}
    $expectedProof=hash_hmac('sha256',$visit['id'].'|'.$visit['client_token_hash'].'|'.$visit['expires_at'],$env['APP_KEY']);
    if(!hash_equals($expectedProof,(string)($d['dining_session_proof']??''))){$db->rollBack();order_fail('Invalid table session.',403);}
    $key=hash('sha256',$key.'|'.$rid);
    $s=$db->prepare('SELECT * FROM orders WHERE restaurant_id=? AND idempotency_key=?');
    $s->execute([$rid,$key]);
    if ($previous=$s->fetch()) {
        if ((int)$previous['table_id']!==(int)$table['table_id']) { $db->rollBack(); order_fail('Submission key already used.',409); }
        $db->commit(); order_response($previous);
    }
    $s=$db->prepare("SELECT id FROM subscriptions WHERE restaurant_id=? AND status IN ('ACTIVE','TRIAL') AND starts_on<=UTC_DATE() AND expires_on>=UTC_DATE() LIMIT 1 FOR UPDATE");
    $s->execute([$rid]);
    $accept=$db->prepare('SELECT is_accepting_orders FROM restaurants WHERE id=?');$accept->execute([$rid]);
    if (!$table['restaurant_active'] || !(int)$accept->fetchColumn() || !$s->fetch()) { $db->rollBack(); order_fail('This restaurant is not currently accepting orders.',403); }
    $s=$db->prepare('SELECT COUNT(*) FROM orders WHERE dining_session_id=? AND created_at>UTC_TIMESTAMP()-INTERVAL 1 MINUTE');
    $s->execute([$sessionId]);
    if ((int)$s->fetchColumn()>=10) { $db->rollBack(); order_fail('Please wait a minute before sending another order.',429); }
    $s=$db->prepare('SELECT * FROM menu_items WHERE restaurant_id=? AND id IN ('.implode(',',array_fill(0,count($itemIds),'?')).') ORDER BY id FOR UPDATE');
    $s->execute([$rid,...array_values($itemIds)]);
    $menuRows=$s->fetchAll();
    if (count($menuRows)!==count($itemIds)) { $db->rollBack(); order_fail('One or more items do not belong to this restaurant.'); }
    $menuById=[];foreach($menuRows as $menuRow)$menuById[(int)$menuRow['id']]=$menuRow;
    $total=0;
    $rows=[];
    foreach($selections as $selection){
        $menuRow=$menuById[$selection['id']];
        if (!$menuRow['is_active'] || !$menuRow['is_available']) { $db->rollBack(); order_fail($menuRow['name'].' is unavailable. Your other selections are preserved.'); }
        $portion=$selection['portion'];
        require_once __DIR__.'/menu-options.php';
        try{$price=menu_option_price($db,(int)$rid,$menuRow,$portion);}catch(InvalidArgumentException $error){$db->rollBack();order_fail($error->getMessage());}
        [$whole,$fraction]=array_pad(explode('.',(string)$price),2,'00');
        $cents=(int)$whole*100+(int)str_pad($fraction,2,'0');
        $total+=$cents*$selection['qty'];
        $rows[]=['id'=>$selection['id'],'name'=>$menuRow['name'],'portion'=>$portion,'price'=>$price,'qty'=>$selection['qty'],'cents'=>$cents];
    }
    $money=static fn(int $n):string=>intdiv($n,100).'.'.str_pad((string)($n%100),2,'0',STR_PAD_LEFT);
    $percentCents=(int)round((float)($table['service_charge_percent']??10)*100);
    $serviceCharge=intdiv($total*$percentCents+5000,10000);
    $grandTotal=$total+$serviceCharge;
    $s=$db->prepare('INSERT INTO orders(restaurant_id,table_id,dining_session_id,order_number,special_request,total,idempotency_key,created_at,updated_at) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
    $number=new_order_number($db,(int)$rid);
    $s->execute([$rid,$table['table_id'],$sessionId,$number,trim($note),$money($grandTotal),$key]);
    $id=$db->lastInsertId();
    $s=$db->prepare('INSERT INTO order_items(order_id,menu_item_id,item_name,portion,unit_price,quantity,subtotal) VALUES(?,?,?,?,?,?,?)');
    foreach ($rows as $row) $s->execute([$id,$row['id'],$row['name'],$row['portion'],$row['price'],$row['qty'],$money($row['cents']*$row['qty'])]);
    $db->commit();
    order_response(['id'=>$id,'order_number'=>$number,'status'=>'PENDING','total'=>$money($grandTotal),'idempotency_key'=>$key]);
} catch (JsonException $error) {
    order_fail('Invalid request format.');
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('Order submission failed: '.$error->getMessage());
    order_fail('Unable to save your order. Retry with your current selections.',500);
}
