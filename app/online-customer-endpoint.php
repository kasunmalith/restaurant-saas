<?php
declare(strict_types=1);

if(!in_array($path,['/api/online-customer','/api/online-customer-history'],true))return;
$slug=trim((string)($_GET['slug']??''));
$restaurantQuery=$db->prepare('SELECT id,is_active FROM restaurants WHERE slug=? LIMIT 1');$restaurantQuery->execute([$slug]);$restaurant=$restaurantQuery->fetch();
if(!$restaurant||!(int)$restaurant['is_active'])order_fail('This restaurant menu is unavailable.',404);
$restaurantId=(int)$restaurant['id'];
$current=$_SESSION['online_customer']??null;
$currentValid=is_array($current)&&(int)($current['restaurant_id']??0)===$restaurantId;
$customerPayload=static function(array $customer)use($db,$restaurantId):array{
    $addresses=$db->prepare('SELECT id,label,address,is_default FROM online_customer_addresses WHERE restaurant_id=? AND customer_id=? ORDER BY is_default DESC,id ASC');
    $addresses->execute([$restaurantId,(int)$customer['customer_id']]);
    $account=$db->prepare('SELECT email FROM online_customer_accounts WHERE restaurant_id=? AND customer_id=? LIMIT 1');$account->execute([$restaurantId,(int)$customer['customer_id']]);
    return ['name'=>(string)$customer['name'],'phone'=>(string)$customer['phone'],'email'=>(string)($account->fetchColumn()?:''),'address'=>(string)($customer['address']??''),'addresses'=>$addresses->fetchAll()];
};
$canSendResetEmail=static function()use($env):bool{
    $host=strtolower((string)parse_url('http://'.($_SERVER['HTTP_HOST']??parse_url($env['APP_URL']??'',PHP_URL_HOST)??'localhost'),PHP_URL_HOST));
    $local=in_array($host,['localhost','127.0.0.1','::1'],true)||str_ends_with($host,'.localhost')||str_ends_with($host,'.local')||str_ends_with($host,'.test');
    $https=strtolower((string)($_SERVER['HTTPS']??''))==='on'||strtolower((string)parse_url($env['APP_URL']??'',PHP_URL_SCHEME))==='https';
    return ($env['APP_ENV']??'')==='production'&&!$local&&$https&&filter_var($env['MAIL_FROM']??'',FILTER_VALIDATE_EMAIL)!==false;
};
if($path==='/api/online-customer-history'){
    if($_SERVER['REQUEST_METHOD']!=='GET')order_fail('GET required.',405);
    if(!$currentValid)order_fail('Sign in to view your order history.',401);
    $q=$db->prepare('SELECT o.id,o.order_number,o.order_type,o.status,o.total,o.online_payment_method,o.delivery_address,o.delivery_notes,o.special_request,o.created_at FROM orders o JOIN online_customer_accounts a ON a.id=? AND a.restaurant_id=o.restaurant_id AND a.customer_id=o.customer_id WHERE o.restaurant_id=? AND o.customer_id=? AND o.created_at>a.created_at AND o.source=\'QR\' AND o.table_id IS NULL ORDER BY o.id DESC LIMIT 50');$q->execute([(int)$current['account_id'],$restaurantId,(int)$current['customer_id']]);$orders=$q->fetchAll();
    if($orders){$ids=array_map(static fn($o)=>(int)$o['id'],$orders);$items=$db->prepare('SELECT order_id,item_name,portion,unit_price,quantity,subtotal FROM order_items WHERE order_id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY id');$items->execute($ids);$byOrder=[];foreach($items as $item)$byOrder[(int)$item['order_id']][]=$item;foreach($orders as &$order){$order['items']=$byOrder[(int)$order['id']]??[];}unset($order);}
    json_out(['orders'=>$orders]);
}
if($_SERVER['REQUEST_METHOD']==='GET'){
    if(!$currentValid){unset($_SESSION['online_customer']);json_out(['logged_in'=>false]);}
    $q=$db->prepare('SELECT a.customer_id,c.name,c.phone,c.address FROM online_customer_accounts a JOIN restaurant_customers c ON c.id=a.customer_id AND c.restaurant_id=a.restaurant_id WHERE a.id=? AND a.restaurant_id=?');$q->execute([(int)$current['account_id'],$restaurantId]);$customer=$q->fetch();
    if(!$customer){unset($_SESSION['online_customer']);json_out(['logged_in'=>false]);}
    json_out(['logged_in'=>true,'customer'=>$customerPayload($customer)]);
}
if($_SERVER['REQUEST_METHOD']!=='POST')order_fail('POST required.',405);
if(!hash_equals((string)($_SESSION['csrf']??''),(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'')))order_fail('Security token expired. Refresh the menu and try again.',403);
try{$data=json_decode(file_get_contents('php://input'),true,16,JSON_THROW_ON_ERROR);}catch(JsonException){order_fail('Invalid request format.');}
if(!is_array($data))order_fail('Invalid request.');
$action=(string)($data['action']??'');
if($action==='check-phone'){
    $phone=preg_replace('/\D+/','',(string)($data['phone']??''))??'';if(strlen($phone)===9)$phone='0'.$phone;elseif(strlen($phone)===11&&str_starts_with($phone,'94'))$phone='0'.substr($phone,2);
    if(!preg_match('/^0[0-9]{9}$/D',$phone))order_fail('Enter a valid 10-digit mobile number.');
    $q=$db->prepare('SELECT 1 FROM online_customer_accounts a JOIN restaurant_customers c ON c.id=a.customer_id AND c.restaurant_id=a.restaurant_id WHERE a.restaurant_id=? AND c.phone=? LIMIT 1');$q->execute([$restaurantId,$phone]);
    json_out(['ok'=>true,'registered'=>(bool)$q->fetchColumn()]);
}
if($action==='forgot-password'){
    $phone=preg_replace('/\D+/','',(string)($data['phone']??''))??'';$email=strtolower(trim((string)($data['email']??'')));
    if(strlen($phone)===9)$phone='0'.$phone;elseif(strlen($phone)===11&&str_starts_with($phone,'94'))$phone='0'.substr($phone,2);
    if(!preg_match('/^0[0-9]{9}$/D',$phone)||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>190)order_fail('Enter the registered 10-digit phone number and a valid email address.');
    if(!$canSendResetEmail())json_out(['ok'=>true,'available'=>false,'message'=>'Password recovery email is available on the live production site only.']);
    $generic='If the phone number and email match an account, a temporary password will be sent.';
    $accountQuery=$db->prepare('SELECT a.id,a.email,r.name restaurant_name FROM online_customer_accounts a JOIN restaurant_customers c ON c.id=a.customer_id AND c.restaurant_id=a.restaurant_id JOIN restaurants r ON r.id=a.restaurant_id WHERE a.restaurant_id=? AND c.phone=? AND LOWER(a.email)=? LIMIT 1');$accountQuery->execute([$restaurantId,$phone,$email]);$account=$accountQuery->fetch();
    if($account){
        $ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');$ipHash=hash_hmac('sha256',$ip,(string)($env['APP_KEY']??''));
        $rate=$db->prepare('SELECT COUNT(*) FROM online_customer_password_resets WHERE restaurant_id=? AND account_id=? AND created_at>=UTC_TIMESTAMP()-INTERVAL 1 HOUR');$rate->execute([$restaurantId,(int)$account['id']]);
        $ipRate=$db->prepare('SELECT COUNT(*) FROM online_customer_password_resets WHERE requested_ip_hash=? AND created_at>=UTC_TIMESTAMP()-INTERVAL 1 HOUR');$ipRate->execute([$ipHash]);
        if((int)$rate->fetchColumn()<3&&(int)$ipRate->fetchColumn()<10){
            $temporaryPassword=(string)random_int(1000000000,9999999999);$resetId=0;
            try{$db->beginTransaction();$expire=$db->prepare('UPDATE online_customer_password_resets SET expires_at=UTC_TIMESTAMP() WHERE restaurant_id=? AND account_id=? AND used_at IS NULL');$expire->execute([$restaurantId,(int)$account['id']]);$insert=$db->prepare('INSERT INTO online_customer_password_resets(restaurant_id,account_id,temp_password_hash,attempts,requested_ip_hash,created_at,expires_at) VALUES(?,?,?,0,?,UTC_TIMESTAMP(),UTC_TIMESTAMP()+INTERVAL 15 MINUTE)');$insert->execute([$restaurantId,(int)$account['id'],password_hash($temporaryPassword,PASSWORD_DEFAULT),$ipHash]);$resetId=(int)$db->lastInsertId();$db->commit();}
            catch(Throwable $error){if($db->inTransaction())$db->rollBack();error_log('Customer password reset request failed: '.$error->getMessage());order_fail('Unable to send a password reset email. Please try again.',500);}
            $restaurantSenderName=mb_encode_mimeheader(str_replace(["\r","\n"],'',(string)$account['restaurant_name']),'UTF-8');$subject=mb_encode_mimeheader('Password reset code - '.$account['restaurant_name'],'UTF-8');$body="Your temporary password is: {$temporaryPassword}\n\nEnter this 10-digit code on the restaurant menu's password reset screen, then choose a new password.\n\nThis code expires in 15 minutes and can be used once. If you did not request this, please ignore this email.";$headers='From: '.$restaurantSenderName.' <'.$env['MAIL_FROM'].">\r\nContent-Type: text/plain; charset=UTF-8";
            $sent=@mail($email,$subject,$body,$headers);
            if($sent){$mark=$db->prepare('UPDATE online_customer_password_resets SET sent_at=UTC_TIMESTAMP() WHERE id=? AND restaurant_id=?');$mark->execute([$resetId,$restaurantId]);}
            else{$expire=$db->prepare('UPDATE online_customer_password_resets SET expires_at=UTC_TIMESTAMP() WHERE id=? AND restaurant_id=?');$expire->execute([$resetId,$restaurantId]);error_log('Customer password reset mail transport failed for restaurant '.$restaurantId);}
        }
    }
    json_out(['ok'=>true,'available'=>true,'message'=>$generic]);
}
if($action==='reset-password'){
    $phone=preg_replace('/\D+/','',(string)($data['phone']??''))??'';$temporaryPassword=(string)($data['temp_password']??'');$password=(string)($data['password']??'');$confirmation=(string)($data['confirm_password']??'');
    if(strlen($phone)===9)$phone='0'.$phone;elseif(strlen($phone)===11&&str_starts_with($phone,'94'))$phone='0'.substr($phone,2);
    if(!preg_match('/^0[0-9]{9}$/D',$phone)||!preg_match('/^[0-9]{10}$/D',$temporaryPassword))order_fail('Enter the registered phone number and 10-digit temporary password.');
    if(strlen($password)<8||strlen($password)>200)order_fail('New password must be at least 8 characters.');
    if($password!==$confirmation)order_fail('New password and confirmation do not match.');
    try{
        $db->beginTransaction();$q=$db->prepare('SELECT a.id account_id,a.customer_id,x.id reset_id,x.temp_password_hash,x.attempts FROM online_customer_accounts a JOIN restaurant_customers c ON c.id=a.customer_id AND c.restaurant_id=a.restaurant_id JOIN online_customer_password_resets x ON x.account_id=a.id AND x.restaurant_id=a.restaurant_id WHERE a.restaurant_id=? AND c.phone=? AND x.sent_at IS NOT NULL AND x.used_at IS NULL AND x.expires_at>UTC_TIMESTAMP() AND x.attempts<5 ORDER BY x.id DESC LIMIT 1 FOR UPDATE');$q->execute([$restaurantId,$phone]);$reset=$q->fetch();
        if(!$reset){$db->rollBack();order_fail('Temporary password is invalid or expired. Request a new one.',422);}
        if(!password_verify($temporaryPassword,$reset['temp_password_hash'])){$attempt=$db->prepare('UPDATE online_customer_password_resets SET attempts=attempts+1 WHERE id=? AND restaurant_id=?');$attempt->execute([(int)$reset['reset_id'],$restaurantId]);$db->commit();order_fail('Temporary password is invalid or expired. Request a new one.',422);}
        $update=$db->prepare('UPDATE online_customer_accounts SET password_hash=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND restaurant_id=?');$update->execute([password_hash($password,PASSWORD_DEFAULT),(int)$reset['account_id'],$restaurantId]);$consume=$db->prepare('UPDATE online_customer_password_resets SET used_at=UTC_TIMESTAMP() WHERE restaurant_id=? AND account_id=? AND used_at IS NULL');$consume->execute([$restaurantId,(int)$reset['account_id']]);$db->commit();
        if((int)($current['account_id']??0)===(int)$reset['account_id'])unset($_SESSION['online_customer']);
        json_out(['ok'=>true,'message'=>'Password updated. You can now sign in with your new password.']);
    }catch(Throwable $error){if($db->inTransaction())$db->rollBack();error_log('Customer password reset completion failed: '.$error->getMessage());order_fail('Unable to update the password. Please try again.',500);}
}
if($action==='select-payment'){
    $method=(string)($data['payment_method']??'');
    if(!in_array($method,['CASH_ON_DELIVERY','PAY_AT_PICKUP','ONLINE_PAYMENT'],true))order_fail('Choose a valid payment option.');
    $_SESSION['online_order_payment']=['restaurant_id'=>$restaurantId,'method'=>$method];
    json_out(['ok'=>true]);
}
if($action==='logout'){
    if($currentValid){unset($_SESSION['online_customer']);session_regenerate_id(true);}unset($_SESSION['online_order_payment']);
    json_out(['ok'=>true]);
}
if(in_array($action,['add-address','set-default-address'],true)){
    if(!$currentValid)order_fail('Sign in to manage your saved addresses.',401);
    $account=$db->prepare('SELECT a.customer_id FROM online_customer_accounts a JOIN restaurant_customers c ON c.id=a.customer_id AND c.restaurant_id=a.restaurant_id WHERE a.id=? AND a.restaurant_id=? LIMIT 1');$account->execute([(int)($current['account_id']??0),$restaurantId]);$customerId=(int)$account->fetchColumn();
    if($customerId<1){unset($_SESSION['online_customer']);order_fail('Sign in again to manage your saved addresses.',401);}
    try{
        $db->beginTransaction();
        $lock=$db->prepare('SELECT id FROM restaurant_customers WHERE id=? AND restaurant_id=? FOR UPDATE');$lock->execute([$customerId,$restaurantId]);if(!$lock->fetchColumn()){$db->rollBack();order_fail('Customer account not found.',404);}
        if($action==='add-address'){
            $label=trim((string)($data['label']??''));$address=trim((string)($data['address']??''));$makeDefault=!empty($data['make_default']);
            if(mb_strlen($label)>80){$db->rollBack();order_fail('Address label must be 80 characters or fewer.');}
            if($address===''||mb_strlen($address)>500){$db->rollBack();order_fail('Enter an address (up to 500 characters).');}
            $duplicate=$db->prepare('SELECT id FROM online_customer_addresses WHERE restaurant_id=? AND customer_id=? AND address_hash=SHA2(?,256) LIMIT 1 FOR UPDATE');$duplicate->execute([$restaurantId,$customerId,$address]);if($duplicate->fetchColumn()){$db->rollBack();order_fail('This address is already saved.',409);}
            $count=$db->prepare('SELECT COUNT(*) FROM online_customer_addresses WHERE restaurant_id=? AND customer_id=?');$count->execute([$restaurantId,$customerId]);$makeDefault=$makeDefault||(int)$count->fetchColumn()===0;
            if($makeDefault){$clear=$db->prepare('UPDATE online_customer_addresses SET is_default=0,updated_at=UTC_TIMESTAMP() WHERE restaurant_id=? AND customer_id=?');$clear->execute([$restaurantId,$customerId]);}
            $insert=$db->prepare('INSERT INTO online_customer_addresses(restaurant_id,customer_id,label,address,address_hash,is_default,created_at,updated_at) VALUES(?,?,?,?,SHA2(?,256),?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');$insert->execute([$restaurantId,$customerId,$label!==''?$label:null,$address,$address,$makeDefault?1:0]);
            if($makeDefault){$update=$db->prepare('UPDATE restaurant_customers SET address=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND restaurant_id=?');$update->execute([$address,$customerId,$restaurantId]);}
        }else{
            $addressId=filter_var($data['address_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$addressId){$db->rollBack();order_fail('Choose a valid saved address.');}
            $selected=$db->prepare('SELECT address FROM online_customer_addresses WHERE id=? AND restaurant_id=? AND customer_id=? FOR UPDATE');$selected->execute([(int)$addressId,$restaurantId,$customerId]);$address=$selected->fetchColumn();if($address===false){$db->rollBack();order_fail('Saved address not found.',404);}
            $clear=$db->prepare('UPDATE online_customer_addresses SET is_default=0,updated_at=UTC_TIMESTAMP() WHERE restaurant_id=? AND customer_id=?');$clear->execute([$restaurantId,$customerId]);
            $choose=$db->prepare('UPDATE online_customer_addresses SET is_default=1,updated_at=UTC_TIMESTAMP() WHERE id=? AND restaurant_id=? AND customer_id=?');$choose->execute([(int)$addressId,$restaurantId,$customerId]);
            $update=$db->prepare('UPDATE restaurant_customers SET address=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND restaurant_id=?');$update->execute([$address,$customerId,$restaurantId]);
        }
        $db->commit();
        $customerQuery=$db->prepare('SELECT id customer_id,name,phone,address FROM restaurant_customers WHERE id=? AND restaurant_id=?');$customerQuery->execute([$customerId,$restaurantId]);$customer=$customerQuery->fetch();
        json_out(['ok'=>true,'customer'=>$customerPayload($customer)]);
    }catch(Throwable $error){if($db->inTransaction())$db->rollBack();if($error instanceof PDOException&&$error->getCode()==='23000')order_fail('This address is already saved.',409);error_log('Online customer saved address failed: '.$error->getMessage());order_fail('Unable to save the address. Please retry.',500);}
}
if($action==='register'){
    $name=trim((string)($data['name']??''));$email=strtolower(trim((string)($data['email']??'')));$phone=preg_replace('/\D+/','',(string)($data['phone']??''))??'';$address=trim((string)($data['address']??''));$password=(string)($data['password']??'');
    if(strlen($phone)===9)$phone='0'.$phone;elseif(strlen($phone)===11&&str_starts_with($phone,'94'))$phone='0'.substr($phone,2);
    if($name===''||mb_strlen($name)>120)order_fail('Enter your name (up to 120 characters).');
    if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>190)order_fail('Enter a valid email address.');
    if(!preg_match('/^0[0-9]{9}$/D',$phone))order_fail('Enter a valid 10-digit mobile number.');
    if($address===''||mb_strlen($address)>500)order_fail('Enter your address (up to 500 characters).');
    if(strlen($password)<8||strlen($password)>200)order_fail('Password must be at least 8 characters.');
    try{
        $db->beginTransaction();
        $existing=$db->prepare('SELECT c.id,c.name,c.phone,c.address,c.origin,a.id account_id FROM restaurant_customers c LEFT JOIN online_customer_accounts a ON a.restaurant_id=c.restaurant_id AND a.customer_id=c.id WHERE c.restaurant_id=? AND c.phone=? LIMIT 1 FOR UPDATE');$existing->execute([$restaurantId,$phone]);$existingCustomer=$existing->fetch();
        if($existingCustomer&&$existingCustomer['account_id']){$db->rollBack();order_fail('An account already exists for this phone number. Please sign in.',409);}
        if($existingCustomer){
            $customerId=(int)$existingCustomer['id'];
            $q=$db->prepare('UPDATE restaurant_customers SET name=?,address=?,origin=\'ONLINE_REGISTRATION\',updated_at=UTC_TIMESTAMP() WHERE id=? AND restaurant_id=?');$q->execute([$name,$address,$customerId,$restaurantId]);
            $existingCustomer['name']=$name;$existingCustomer['address']=$address;
        }else{
            $q=$db->prepare("INSERT INTO restaurant_customers(restaurant_id,name,phone,address,origin,created_at,updated_at) VALUES(?,?,?,?, 'ONLINE_REGISTRATION',UTC_TIMESTAMP(),UTC_TIMESTAMP())");$q->execute([$restaurantId,$name,$phone,$address]);$customerId=(int)$db->lastInsertId();
            $existingCustomer=['id'=>$customerId,'name'=>$name,'phone'=>$phone,'address'=>$address,'origin'=>'ONLINE_REGISTRATION'];
        }
        $q=$db->prepare('INSERT INTO online_customer_accounts(restaurant_id,customer_id,email,password_hash,created_at,updated_at) VALUES(?,?,?,?,?,?)');$q->execute([$restaurantId,$customerId,$email,password_hash($password,PASSWORD_DEFAULT),gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s')]);$accountId=(int)$db->lastInsertId();
        $q=$db->prepare("INSERT INTO online_customer_addresses(restaurant_id,customer_id,label,address,address_hash,is_default,created_at,updated_at) VALUES(?,?, 'Primary',?,SHA2(?,256),1,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE is_default=1,updated_at=UTC_TIMESTAMP()");$q->execute([$restaurantId,$customerId,$address,$address]);
        $db->commit();session_regenerate_id(true);$_SESSION['online_customer']=['account_id'=>$accountId,'restaurant_id'=>$restaurantId,'customer_id'=>$customerId];json_out(['ok'=>true,'logged_in'=>true,'customer'=>$customerPayload(['customer_id'=>$customerId,'name'=>$existingCustomer['name'],'phone'=>$existingCustomer['phone'],'address'=>$existingCustomer['address']])]);
    }catch(Throwable $error){if($db->inTransaction())$db->rollBack();if($error instanceof PDOException&&$error->getCode()==='23000')order_fail('An account already exists for this phone number. Please sign in.',409);error_log('Online customer registration failed: '.$error->getMessage());order_fail('Unable to register. Please retry.',500);}
}
if($action==='login'){
    $phone=preg_replace('/\D+/','',(string)($data['phone']??''))??'';$password=(string)($data['password']??'');if(strlen($phone)===9)$phone='0'.$phone;elseif(strlen($phone)===11&&str_starts_with($phone,'94'))$phone='0'.substr($phone,2);
    if(!preg_match('/^0[0-9]{9}$/D',$phone)||$password==='')order_fail('Enter your registered phone number and password.');
    $q=$db->prepare('SELECT a.id account_id,a.customer_id,a.password_hash,c.name,c.phone,c.address FROM online_customer_accounts a JOIN restaurant_customers c ON c.id=a.customer_id AND c.restaurant_id=a.restaurant_id WHERE a.restaurant_id=? AND c.phone=? LIMIT 1');$q->execute([$restaurantId,$phone]);$account=$q->fetch();
    if(!$account||!password_verify($password,$account['password_hash']))order_fail('Phone number or password is incorrect.',401);
    session_regenerate_id(true);$_SESSION['online_customer']=['account_id'=>(int)$account['account_id'],'restaurant_id'=>$restaurantId,'customer_id'=>(int)$account['customer_id']];json_out(['ok'=>true,'logged_in'=>true,'customer'=>$customerPayload($account)]);
}
order_fail('Unsupported account action.',400);
