<?php
declare(strict_types=1);

if ($path !== '/api/online-order' || $_SERVER['REQUEST_METHOD'] !== 'POST') return;

try {
    $data=json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR);
    if(!is_array($data))order_fail('Invalid order.');
    $slug=trim((string)($data['slug']??''));
    $type=(string)($data['order_type']??'');
    if($slug===''||mb_strlen($slug)>160||!in_array($type,['TAKEAWAY','DELIVERY'],true))order_fail('Choose Pickup or Delivery.');
    $name=trim((string)($data['customer_name']??''));
    $phone=preg_replace('/\D+/','',(string)($data['customer_phone']??''))??'';
    if(strlen($phone)===9)$phone='0'.$phone;elseif(strlen($phone)===11&&str_starts_with($phone,'94'))$phone='0'.substr($phone,2);
    $address=trim((string)($data['delivery_address']??''));
    $addressId=filter_var($data['delivery_address_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
    $saveAddress=!empty($data['save_delivery_address']);
    if($type==='TAKEAWAY')$address='';
    $deliveryNotes=trim((string)($data['delivery_notes']??''));
    if(mb_strlen($deliveryNotes)>500)order_fail('Delivery notes must be 500 characters or fewer.');
    $note=$data['special_request']??'';
    if(!is_string($note)||mb_strlen($note)>1000)order_fail('Special requests must be 1,000 characters or fewer.');
    $key=(string)($data['idempotency_key']??'');
    if(!preg_match('/^[a-zA-Z0-9-]{20,100}$/D',$key))order_fail('A valid submission key is required.');
    $items=$data['items']??null;
    if(!is_array($items)||!array_is_list($items)||count($items)<1||count($items)>50)order_fail('Select between 1 and 50 different items.');
    $selections=[];$itemIds=[];
    foreach($items as $item){
        if(!is_array($item)||!is_int($item['id']??null)||$item['id']<1||!is_int($item['qty']??null)||$item['qty']<1||$item['qty']>99)order_fail('Invalid item or quantity.');
        $portion=$item['portion']??null;
        if($portion!==null&&(!is_string($portion)||mb_strlen($portion)>40))order_fail('Select a valid size.');
        $selectionKey=$item['id'].':'.($portion??"\0");
        if(isset($selections[$selectionKey]))order_fail('Duplicate item selection.');
        $selections[$selectionKey]=['id'=>$item['id'],'qty'=>$item['qty'],'portion'=>$portion];$itemIds[$item['id']]=$item['id'];
    }

    $db->beginTransaction();
    $q=$db->prepare('SELECT id,is_active,is_accepting_orders,online_ordering_enabled,delivery_minimum FROM restaurants WHERE slug=? LIMIT 1 FOR UPDATE');$q->execute([$slug]);$restaurant=$q->fetch();
    if(!$restaurant||!(int)$restaurant['is_active']){$db->rollBack();order_fail('This restaurant menu is unavailable.',404);}
    $rid=(int)$restaurant['id'];$key=hash('sha256','ONLINE|'.$rid.'|'.$key);
    $registeredCustomer=null;$accountSession=$_SESSION['online_customer']??null;
    if(is_array($accountSession)&&(int)($accountSession['restaurant_id']??0)===$rid){$q=$db->prepare('SELECT a.customer_id,c.name,c.phone,c.address FROM online_customer_accounts a JOIN restaurant_customers c ON c.id=a.customer_id AND c.restaurant_id=a.restaurant_id WHERE a.id=? AND a.restaurant_id=? FOR UPDATE');$q->execute([(int)$accountSession['account_id'],$rid]);$registeredCustomer=$q->fetch()?:null;if(!$registeredCustomer)unset($_SESSION['online_customer']);}
    if($registeredCustomer){
        $name=(string)$registeredCustomer['name'];$phone=(string)$registeredCustomer['phone'];
        if($type==='DELIVERY'&&$addressId>0){$q=$db->prepare('SELECT address FROM online_customer_addresses WHERE id=? AND restaurant_id=? AND customer_id=? LIMIT 1 FOR UPDATE');$q->execute([$addressId,$rid,(int)$registeredCustomer['customer_id']]);$savedAddress=$q->fetchColumn();if($savedAddress===false){$db->rollBack();order_fail('Choose a valid saved delivery address.');}$address=trim((string)$savedAddress);}
        elseif($type==='DELIVERY'&&$address==='')$address=trim((string)$registeredCustomer['address']);
    }elseif($type==='DELIVERY'&&$addressId>0){$db->rollBack();order_fail('Sign in to use a saved delivery address.',401);}
    if($name===''||mb_strlen($name)>120){$db->rollBack();order_fail('Enter your name.');}
    if(!preg_match('/^0[0-9]{9}$/D',$phone)){$db->rollBack();order_fail('Enter a valid 10-digit mobile number.');}
    if($type==='DELIVERY'&&($address===''||mb_strlen($address)>500)){$db->rollBack();order_fail('Enter a delivery address (up to 500 characters).');}
    $q=$db->prepare('SELECT * FROM orders WHERE restaurant_id=? AND idempotency_key=?');$q->execute([$rid,$key]);
    if($previous=$q->fetch()){$db->commit();order_response($previous);}
    $q=$db->prepare("SELECT id FROM subscriptions WHERE restaurant_id=? AND status IN ('ACTIVE','TRIAL') AND starts_on<=UTC_DATE() AND expires_on>=UTC_DATE() LIMIT 1 FOR UPDATE");$q->execute([$rid]);
    if(!(int)$restaurant['is_accepting_orders']||!(int)$restaurant['online_ordering_enabled']||!$q->fetchColumn()){$db->rollBack();order_fail('Online ordering is currently unavailable.',403);}
    $paymentSelection=$_SESSION['online_order_payment']??null;$paymentMethod=is_array($paymentSelection)&&(int)($paymentSelection['restaurant_id']??0)===$rid?(string)($paymentSelection['method']??''):'';
    $validPaymentMethods=$type==='DELIVERY'?['CASH_ON_DELIVERY','ONLINE_PAYMENT']:['PAY_AT_PICKUP','ONLINE_PAYMENT'];
    if(!in_array($paymentMethod,$validPaymentMethods,true)){$db->rollBack();order_fail('Choose a valid payment option for Pickup or Delivery.');}
    $q=$db->prepare('SELECT COUNT(*) FROM orders WHERE restaurant_id=? AND customer_phone=? AND created_at>UTC_TIMESTAMP()-INTERVAL 1 MINUTE');$q->execute([$rid,$phone]);
    if((int)$q->fetchColumn()>=5){$db->rollBack();order_fail('Please wait a minute before placing another order.',429);}
    $q=$db->prepare('SELECT * FROM menu_items WHERE restaurant_id=? AND is_active=1 AND id IN ('.implode(',',array_fill(0,count($itemIds),'?')).') ORDER BY id FOR UPDATE');
    $q->execute([$rid,...array_values($itemIds)]);$menuRows=$q->fetchAll();
    if(count($menuRows)!==count($itemIds)){$db->rollBack();order_fail('One or more menu items are no longer available.');}
    $menuById=[];foreach($menuRows as $row)$menuById[(int)$row['id']]=$row;
    require_once __DIR__.'/menu-options.php';$rows=[];$totalCents=0;
    foreach($selections as $selection){
        $item=$menuById[$selection['id']];
        if(!(int)$item['is_available']){$db->rollBack();order_fail($item['name'].' is unavailable.');}
        try{$price=menu_option_price($db,$rid,$item,$selection['portion']);}catch(InvalidArgumentException $error){$db->rollBack();order_fail($error->getMessage());}
        [$whole,$fraction]=array_pad(explode('.',(string)$price),2,'00');$cents=(int)$whole*100+(int)str_pad($fraction,2,'0',STR_PAD_LEFT);
        $line=$cents*$selection['qty'];$totalCents+=$line;$rows[]=['item'=>$item,'portion'=>$selection['portion'],'qty'=>$selection['qty'],'price'=>$price,'line'=>$line];
    }
    $minimumCents=(int)round((float)$restaurant['delivery_minimum']*100);
    if($type==='DELIVERY'&&$totalCents<$minimumCents){$db->rollBack();$minimum=number_format((float)$restaurant['delivery_minimum'],2);order_fail('Delivery orders require a minimum food subtotal of LKR '.$minimum.'. Pickup has no minimum.');}
    $money=static fn(int $cents):string=>intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT);
    if(!$registeredCustomer){
        $q=$db->prepare("INSERT INTO restaurant_customers(restaurant_id,name,phone,address,origin,created_at,updated_at) VALUES(?,?,?,?, 'ONLINE_GUEST',UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE name=IF(origin='ONLINE_GUEST',VALUES(name),name),address=IF(origin='ONLINE_GUEST' AND VALUES(address) IS NOT NULL,VALUES(address),address),updated_at=UTC_TIMESTAMP()");
        $q->execute([$rid,$name,$phone,$type==='DELIVERY'?$address:null]);
    }
    $q=$db->prepare('SELECT id FROM restaurant_customers WHERE restaurant_id=? AND phone=?');$q->execute([$rid,$phone]);$customerId=(int)$q->fetchColumn();
    if($registeredCustomer)$orderCustomerId=(int)$registeredCustomer['customer_id'];else{$q=$db->prepare('SELECT 1 FROM online_customer_accounts WHERE restaurant_id=? AND customer_id=?');$q->execute([$rid,$customerId]);$orderCustomerId=$q->fetchColumn()?null:$customerId;}
    if($registeredCustomer&&$type==='DELIVERY'&&($addressId>0||$saveAddress)){
        $q=$db->prepare('UPDATE online_customer_addresses SET is_default=0 WHERE restaurant_id=? AND customer_id=?');$q->execute([$rid,(int)$registeredCustomer['customer_id']]);
        if($addressId>0){$q=$db->prepare('UPDATE online_customer_addresses SET is_default=1,updated_at=UTC_TIMESTAMP() WHERE id=? AND restaurant_id=? AND customer_id=?');$q->execute([$addressId,$rid,(int)$registeredCustomer['customer_id']]);}
        else{$q=$db->prepare("INSERT INTO online_customer_addresses(restaurant_id,customer_id,label,address,address_hash,is_default,created_at,updated_at) VALUES(?,?,NULL,?,SHA2(?,256),1,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE address=VALUES(address),is_default=1,updated_at=UTC_TIMESTAMP()");$q->execute([$rid,(int)$registeredCustomer['customer_id'],$address,$address]);}
    }
    $number=new_order_number($db,$rid);
    $q=$db->prepare('INSERT INTO orders(restaurant_id,table_id,dining_session_id,customer_id,order_number,special_request,total,status,idempotency_key,source,order_type,customer_name,customer_phone,delivery_address,delivery_notes,online_payment_method,created_at,updated_at) VALUES(?,NULL,NULL,?,?,?,?,\'PENDING\',?,\'QR\',?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
    $q->execute([$rid,$orderCustomerId,$number,trim($note),$money($totalCents),$key,$type,$name,$phone,$type==='DELIVERY'?$address:null,$type==='DELIVERY'&&$deliveryNotes!==''?$deliveryNotes:null,$paymentMethod]);
    $orderId=(int)$db->lastInsertId();
    $q=$db->prepare('INSERT INTO order_items(order_id,menu_item_id,item_name,portion,unit_price,quantity,subtotal) VALUES(?,?,?,?,?,?,?)');
    foreach($rows as $row)$q->execute([$orderId,$row['item']['id'],$row['item']['name'],$row['portion'],$row['price'],$row['qty'],$money($row['line'])]);
    $db->commit();
    order_response(['id'=>$orderId,'order_number'=>$number,'status'=>'PENDING','total'=>$money($totalCents),'idempotency_key'=>$key]);
}catch(JsonException $error){order_fail('Invalid request format.');}
catch(Throwable $error){if($db->inTransaction())$db->rollBack();error_log('Online order submission failed: '.$error->getMessage());order_fail('Unable to save your order. Please retry with your current selections.',500);}
