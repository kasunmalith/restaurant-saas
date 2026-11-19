<?php
declare(strict_types=1);
if (!str_starts_with($path,'/api/cashier/')) return;
$s=$db->prepare("SELECT id,restaurant_id,role FROM users WHERE id=? AND is_active=1 AND role IN ('CASHIER','RESTAURANT_ADMIN')");
$s->execute([$_SESSION['user']['id']??0]);$staff=$s->fetch();
if (!$staff) order_fail('Staff access required.',403);
$tenant=(int)$staff['restaurant_id'];
if ($path==='/api/cashier/orders') {
    $cancelSetting=$db->prepare('SELECT accepted_order_cancellation_enabled FROM restaurant_pos_settings WHERE restaurant_id=?');$cancelSetting->execute([$tenant]);$cancellationEnabled=(int)$cancelSetting->fetchColumn()===1;
    $db->exec("UPDATE dining_sessions SET status='EXPIRED',closed_at=COALESCE(closed_at,UTC_TIMESTAMP()) WHERE status='ACTIVE' AND expires_at<=UTC_TIMESTAMP()");
    $count=$db->prepare('SELECT COUNT(*) FROM orders WHERE restaurant_id=?');$count->execute([$tenant]);
    header('X-Order-Count: '.(int)$count->fetchColumn());
    $since=(string)($_GET['since']??'');$incremental=(bool)preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$since);$beforeId=(int)($_GET['before_id']??0);$hasMore=false;$term=trim(substr((string)($_GET['search']??''),0,100));$searchBy=(string)($_GET['search_by']??'all');$searchSql='';$searchArgs=[];
    if(!in_array($searchBy,['all','order','table','customer','item'],true))order_fail('Choose a valid search type.');
    if($searchBy==='table')$term=preg_replace('/^table\s*/i','',$term)??$term;
    if($term!==''){$term=strtr($term,['\\'=>'\\\\','%'=>'\\%','_'=>'\\_']);$like='%'.$term.'%';$conditions=['all'=>"(o.order_number LIKE ? OR CAST(t.table_number AS CHAR) LIKE ? OR CONCAT('Table ',t.table_number) LIKE ? OR COALESCE(o.customer_name,'') LIKE ? OR COALESCE(o.customer_phone,'') LIKE ? OR EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id=o.id AND oi.item_name LIKE ?))",'order'=>'o.order_number LIKE ?','table'=>"(CAST(t.table_number AS CHAR) LIKE ? OR CONCAT('Table ',t.table_number) LIKE ?)",'customer'=>"(COALESCE(o.customer_name,'') LIKE ? OR COALESCE(o.customer_phone,'') LIKE ?)",'item'=>'EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id=o.id AND oi.item_name LIKE ?)'];$searchSql=' AND '.$conditions[$searchBy];$searchArgs=array_fill(0,$searchBy==='all'?6:($searchBy==='table'||$searchBy==='customer'?2:1),$like);}
    if($incremental){$s=$db->prepare('SELECT o.*,t.table_number,ds.session_number bill_number FROM orders o LEFT JOIN restaurant_tables t ON t.id=o.table_id LEFT JOIN dining_sessions ds ON ds.id=o.dining_session_id WHERE o.restaurant_id=? AND o.updated_at>=DATE_SUB(?,INTERVAL 1 SECOND)'.$searchSql.' ORDER BY o.updated_at,o.id LIMIT 100');$s->execute([$tenant,$since,...$searchArgs]);}
    elseif($beforeId>0){$s=$db->prepare('SELECT o.*,t.table_number,ds.session_number bill_number FROM orders o LEFT JOIN restaurant_tables t ON t.id=o.table_id LEFT JOIN dining_sessions ds ON ds.id=o.dining_session_id WHERE o.restaurant_id=? AND o.id<?'.$searchSql.' ORDER BY o.id DESC LIMIT 13');$s->execute([$tenant,$beforeId,...$searchArgs]);}
    else{$s=$db->prepare('SELECT o.*,t.table_number,ds.session_number bill_number FROM orders o LEFT JOIN restaurant_tables t ON t.id=o.table_id LEFT JOIN dining_sessions ds ON ds.id=o.dining_session_id WHERE o.restaurant_id=?'.$searchSql.' ORDER BY o.id DESC LIMIT 13');$s->execute([$tenant,...$searchArgs]);}
    $rows=$s->fetchAll();if(!$incremental){$hasMore=count($rows)>12;if($hasMore)array_pop($rows);}
    if($rows){
        foreach($rows as &$row)$row['invoice_number']=null;unset($row);
        try{$ids=array_map(static fn($row)=>(int)$row['id'],$rows);$invoices=$db->prepare('SELECT order_id,invoice_number FROM pos_invoices WHERE restaurant_id=? AND order_id IN ('.implode(',',array_fill(0,count($ids),'?')).')');$invoices->execute([$tenant,...$ids]);$numbers=[];foreach($invoices as $invoice)$numbers[(int)$invoice['order_id']]=$invoice['invoice_number'];foreach($rows as &$row)if(isset($numbers[(int)$row['id']])){$row['invoice_number']=$numbers[(int)$row['id']];$row['bill_number']=$numbers[(int)$row['id']];}unset($row);}catch(PDOException $error){/* QR ordering remains available before the POS migration. */}
    }
    $q=$db->prepare('SELECT id,item_name,portion,unit_price,quantity,subtotal FROM order_items WHERE order_id=? AND quantity>0');
    foreach ($rows as &$row) {$q->execute([$row['id']]);$row['items']=$q->fetchAll();unset($row['idempotency_key']);}unset($row);
    json_out(['orders'=>$rows,'cursor'=>gmdate('Y-m-d H:i:s'),'has_more'=>$hasMore,'cancellation_enabled'=>$cancellationEnabled]);
}
if ($_SERVER['REQUEST_METHOD']!=='POST') order_fail('POST required.',405);
if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))) order_fail('Refresh the page to renew your security token.',403);
if($path==='/api/cashier/order-item'){
    if(!in_array($staff['role'],['RESTAURANT_ADMIN','CASHIER'],true))order_fail('Restaurant staff access required.',403);
    require_once __DIR__.'/pos-core.php';
    $orderId=(int)($_POST['order_id']??0);$lineId=(int)($_POST['order_item_id']??0);$action=(string)($_POST['action']??'');$quantity=(int)($_POST['quantity']??-1);
    if(!in_array($action,['REDUCE','REMOVE'],true))order_fail('Invalid item change.');
    $db->beginTransaction();
    try{
        $q=$db->prepare('SELECT id,status,source,order_type,packaging_charge,delivery_charge FROM orders WHERE id=? AND restaurant_id=? FOR UPDATE');$q->execute([$orderId,$tenant]);$order=$q->fetch();
        if(!$order||$order['status']!=='PENDING')throw new InvalidArgumentException('Order items can only be edited before the order is accepted.');
        try{$q=$db->prepare('SELECT id FROM pos_invoices WHERE restaurant_id=? AND order_id=? LIMIT 1');$q->execute([$tenant,$orderId]);if($q->fetchColumn())throw new InvalidArgumentException('A bill already exists. Use Edit Bill to correct it.');}catch(PDOException $error){if(str_contains($error->getMessage(),'doesn\'t exist')||str_contains($error->getMessage(),'42S02')){}else throw$error;}
        $q=$db->prepare('SELECT id,unit_price,quantity FROM order_items WHERE id=? AND order_id=? FOR UPDATE');$q->execute([$lineId,$orderId]);$line=$q->fetch();if(!$line)throw new InvalidArgumentException('Order item not found.');
        $next=$action==='REMOVE'?0:$quantity;if($next<0||$next>=(int)$line['quantity'])throw new InvalidArgumentException('You can only reduce or remove this item.');
        $q=$db->prepare('UPDATE order_items SET quantity=?,subtotal=? WHERE id=? AND order_id=?');$q->execute([$next,pos_money(pos_cents((string)$line['unit_price'])*$next),$lineId,$orderId]);
        $q=$db->prepare('SELECT subtotal FROM order_items WHERE order_id=? AND quantity>0');$q->execute([$orderId]);$subtotal=0;foreach($q as $row)$subtotal+=pos_cents((string)$row['subtotal']);
        if($subtotal===0){
            $q=$db->prepare('UPDATE orders SET total=0,status=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND restaurant_id=?');$q->execute(['REJECTED',$orderId,$tenant]);
            $q=$db->prepare('INSERT INTO order_status_history(restaurant_id,order_id,user_id,previous_status,new_status,created_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP())');$q->execute([$tenant,$orderId,$staff['id'],$order['status'],'REJECTED']);
            $kotId=null;try{$q=$db->prepare('SELECT id FROM pos_kots WHERE restaurant_id=? AND order_id=? LIMIT 1');$q->execute([$tenant,$orderId]);if($q->fetchColumn())$kotId=pos_send_kot($db,$orderId,$tenant,(int)$staff['id']);}catch(PDOException $error){if(!str_contains($error->getMessage(),'doesn\'t exist')&&!str_contains($error->getMessage(),'42S02'))throw$error;}
            $db->commit();json_out(['ok'=>true,'quantity'=>$next,'total'=>'0.00','order_status'=>'REJECTED','kot_id'=>$kotId]);
        }
        $total=$subtotal+pos_cents((string)$order['packaging_charge'])+pos_cents((string)$order['delivery_charge']);if($order['source']==='QR'&&$order['order_type']==='DINE_IN'){$percent=$db->prepare('SELECT service_charge_percent FROM restaurants WHERE id=?');$percent->execute([$tenant]);$total+=pos_percent_charge($subtotal,(string)$percent->fetchColumn());}
        $q=$db->prepare('UPDATE orders SET total=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND restaurant_id=?');$q->execute([pos_money($total),$orderId,$tenant]);
        $kotId=null;try{$q=$db->prepare('SELECT id FROM pos_kots WHERE restaurant_id=? AND order_id=? LIMIT 1');$q->execute([$tenant,$orderId]);if($q->fetchColumn())$kotId=pos_send_kot($db,$orderId,$tenant,(int)$staff['id']);}catch(PDOException $error){if(!str_contains($error->getMessage(),'doesn\'t exist')&&!str_contains($error->getMessage(),'42S02'))throw$error;}
        $db->commit();json_out(['ok'=>true,'quantity'=>$next,'total'=>pos_money($total),'kot_id'=>$kotId]);
    }catch(Throwable $error){if($db->inTransaction())$db->rollBack();if($error instanceof InvalidArgumentException)order_fail($error->getMessage(),409);error_log($error->getMessage());order_fail('Unable to update order item.',500);}
}
if ($path==='/api/cashier/status') {
    if(in_array((string)($_POST['status']??''),['PREPARING','CANCELLED'],true))require_once __DIR__.'/pos-core.php';
    $from=(string)($_POST['expected_status']??'');$to=(string)($_POST['status']??'');
    $allowed=['PENDING'=>['ACCEPTED','REJECTED'],'ACCEPTED'=>['PREPARING','CANCELLED'],'PREPARING'=>['READY','CANCELLED'],'READY'=>['SERVED','CANCELLED']];
    if (!in_array($to,$allowed[$from]??[],true)) order_fail('Invalid order status transition.');
    $db->beginTransaction();$invoiceCancelled=false;
    try {
        $orderId=(int)($_POST['order_id']??0);
        $locked=$db->prepare('SELECT id,status FROM orders WHERE id=? AND restaurant_id=? FOR UPDATE');$locked->execute([$orderId,$tenant]);$order=$locked->fetch();
        if(!$order||$order['status']!==$from){$db->rollBack();order_fail('This order has changed or is unavailable. Refresh and try again.',409);}
        if($to==='CANCELLED'){
            $setting=$db->prepare('SELECT accepted_order_cancellation_enabled FROM restaurant_pos_settings WHERE restaurant_id=?');$setting->execute([$tenant]);
            if((int)$setting->fetchColumn()!==1){$db->rollBack();order_fail('Accepted-order cancellation is disabled in Restaurant Settings.',403);}
            $bill=$db->prepare('SELECT id,paid_total FROM pos_invoices WHERE order_id=? AND restaurant_id=? LIMIT 1 FOR UPDATE');$bill->execute([$orderId,$tenant]);$invoice=$bill->fetch();
            if($invoice){
                $payments=$db->prepare('SELECT COUNT(*) FROM pos_payments WHERE invoice_id=? AND restaurant_id=?');$payments->execute([(int)$invoice['id'],$tenant]);
                if((float)$invoice['paid_total']>0||(int)$payments->fetchColumn()>0){$db->rollBack();order_fail('This invoice has a recorded payment. Resolve or refund the payment before cancelling the order.',409);}
                $removeBill=$db->prepare('DELETE FROM pos_invoices WHERE id=? AND restaurant_id=? AND paid_total=0');$removeBill->execute([(int)$invoice['id'],$tenant]);
                if($removeBill->rowCount()!==1){$db->rollBack();order_fail('The unpaid bill changed while cancelling. Refresh the order and try again.',409);}
                $invoiceCancelled=true;
            }
        }
        if($to==='REJECTED'){try{$b=$db->prepare('SELECT id FROM pos_invoices WHERE order_id=? AND restaurant_id=? LIMIT 1');$b->execute([(int)($_POST['order_id']??0),$tenant]);if($b->fetchColumn()){$db->rollBack();order_fail('Generated bills cannot be rejected. Resolve the bill first.',409);}}catch(PDOException $error){/* No POS bill table before migration. */}}
        $s=$db->prepare('UPDATE orders SET status=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND restaurant_id=? AND status=?');
        $s->execute([$to,$orderId,$tenant,$from]);
        if ($s->rowCount()!==1) { $db->rollBack();order_fail('This order has changed or is unavailable. Refresh and try again.',409); }
        $s=$db->prepare('INSERT INTO order_status_history(restaurant_id,order_id,user_id,previous_status,new_status,created_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP())');
        $s->execute([$tenant,$orderId,$staff['id'],$from,$to]);
        $kotId=$to==='PREPARING'?pos_send_kot($db,$orderId,$tenant,(int)$staff['id']):null;
        if($to==='CANCELLED'){
            $sent=$db->prepare("SELECT oi.id,oi.item_name,oi.portion,oi.special_instruction,SUM(CASE WHEN ki.action='ADDED' THEN ki.quantity ELSE -ki.quantity END) sent_quantity FROM order_items oi JOIN pos_kot_items ki ON ki.order_item_id=oi.id JOIN pos_kots k ON k.id=ki.kot_id AND k.restaurant_id=? WHERE oi.order_id=? GROUP BY oi.id,oi.item_name,oi.portion,oi.special_instruction HAVING sent_quantity>0 ORDER BY oi.id");
            $sent->execute([$tenant,$orderId]);$sentItems=$sent->fetchAll();
            if($sentItems){$number='KOT-'.strtoupper(bin2hex(random_bytes(6)));$ticket=$db->prepare('INSERT INTO pos_kots(restaurant_id,order_id,kot_number,created_by,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP())');$ticket->execute([$tenant,$orderId,$number,$staff['id']]);$kotId=(int)$db->lastInsertId();$line=$db->prepare("INSERT INTO pos_kot_items(kot_id,order_item_id,item_name,portion,quantity,action,special_instruction) VALUES(?,?,?,?,?,'CANCELLED',?)");foreach($sentItems as $item)$line->execute([$kotId,$item['id'],$item['item_name'],$item['portion'],(int)$item['sent_quantity'],$item['special_instruction']]);}
        }
        $db->commit();
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack();error_log($error->getMessage());order_fail('Unable to update order. Please retry.',500); }
    json_out(['ok'=>true,'kot_id'=>$kotId??null,'status'=>$to,'invoice_cancelled'=>$invoiceCancelled]);
}
if ($path==='/api/cashier/availability') {
    if (!in_array((string)($_POST['available']??''),['0','1'],true)) order_fail('Invalid availability.');
    $db->beginTransaction();
    try {
        $s=$db->prepare('SELECT is_available FROM menu_items WHERE id=? AND restaurant_id=? FOR UPDATE');$s->execute([(int)($_POST['item_id']??0),$tenant]);
        $item=$s->fetch();
        if (!$item) {$db->rollBack();order_fail('Item not found.',404);}
        if ((int)$item['is_available']!==(int)$_POST['available']) {
            $s=$db->prepare('UPDATE menu_items SET is_available=? WHERE id=? AND restaurant_id=?');$s->execute([$_POST['available'],(int)$_POST['item_id'],$tenant]);
            $s=$db->prepare('INSERT INTO menu_availability_history(restaurant_id,menu_item_id,user_id,previous_available,new_available,created_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP())');
            $s->execute([$tenant,(int)$_POST['item_id'],$staff['id'],$item['is_available'],(int)$_POST['available']]);
        }
        $db->commit();
    }catch(Throwable $error){if($db->inTransaction())$db->rollBack();error_log($error->getMessage());order_fail('Unable to update availability.',500);}
    json_out(['ok'=>true]);
}
order_fail('Unknown staff endpoint.',404);
