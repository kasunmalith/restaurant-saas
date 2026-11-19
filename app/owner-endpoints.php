<?php
if (!in_array($path,['/api/owner/restaurant','/api/owner/restaurant/update','/api/owner/restaurant/delete','/api/owner/account','/api/owner/uploads/cleanup','/api/owner/restaurant/empty-orders','/api/owner/demo/delete-customers'],true)) return;
$s=$db->prepare("SELECT id FROM users WHERE id=? AND role='SUPER_ADMIN' AND is_active=1");$s->execute([$_SESSION['user']['id']??0]);
if (!$s->fetchColumn()) order_fail('Owner access required.',403);
if ($_SERVER['REQUEST_METHOD']!=='POST') order_fail('POST required.',405);
if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))) order_fail('Refresh your security token.',403);
if($path==='/api/owner/demo/delete-customers'){
 $db->beginTransaction();
 try{
  $demo=$db->prepare("SELECT id FROM restaurants WHERE id=? AND slug='demo-restaurant' FOR UPDATE");$demo->execute([(int)($_POST['restaurant_id']??0)]);$restaurantId=(int)$demo->fetchColumn();
  if(!$restaurantId)throw new InvalidArgumentException('This action is only available for the demo restaurant.');
  $count=$db->prepare('SELECT COUNT(*) FROM restaurant_customers WHERE restaurant_id=?');$count->execute([$restaurantId]);$found=(int)$count->fetchColumn();
  $delete=$db->prepare('DELETE FROM restaurant_customers WHERE restaurant_id=?');$delete->execute([$restaurantId]);$deleted=$delete->rowCount();
  $db->commit();json_out(['ok'=>true,'deleted'=>$deleted,'matched'=>$found]);
 }catch(Throwable $error){if($db->inTransaction())$db->rollBack();if($error instanceof InvalidArgumentException)order_fail($error->getMessage(),403);error_log('Demo customer deletion: '.$error->getMessage());order_fail('Unable to delete demo customers.',500);}
}
if($path==='/api/owner/restaurant/empty-orders'){
 $db->beginTransaction();
 try{
  $restaurant=$db->prepare('SELECT id FROM restaurants WHERE id=? FOR UPDATE');$restaurant->execute([(int)($_POST['restaurant_id']??0)]);$restaurantId=(int)$restaurant->fetchColumn();
  if(!$restaurantId)throw new InvalidArgumentException('Restaurant not found.');
  $pending=$db->prepare("SELECT COUNT(*) FROM orders o LEFT JOIN pos_invoices i ON i.order_id=o.id AND i.restaurant_id=o.restaurant_id WHERE o.restaurant_id=? AND ((i.id IS NOT NULL AND i.payment_status IN ('UNPAID','PARTIALLY_PAID')) OR (i.id IS NULL AND o.order_type='DINE_IN' AND o.status='SERVED'))");$pending->execute([$restaurantId]);$pendingBills=(int)$pending->fetchColumn();
  $detach=$db->prepare('UPDATE orders SET dining_session_id=NULL WHERE restaurant_id=? AND dining_session_id IS NOT NULL');$detach->execute([$restaurantId]);
  $delete=$db->prepare('DELETE FROM orders WHERE restaurant_id=?');$delete->execute([$restaurantId]);$deleted=$delete->rowCount();
  $sessions=$db->prepare("DELETE FROM dining_sessions WHERE restaurant_id=? AND (status<>'ACTIVE' OR expires_at<=UTC_TIMESTAMP())");$sessions->execute([$restaurantId]);$sessionsDeleted=$sessions->rowCount();
  $db->commit();json_out(['ok'=>true,'deleted'=>$deleted,'pending_bills_deleted'=>$pendingBills,'expired_sessions_deleted'=>$sessionsDeleted]);
 }catch(Throwable $error){if($db->inTransaction())$db->rollBack();if($error instanceof InvalidArgumentException)order_fail($error->getMessage(),404);error_log($error->getMessage());order_fail('Unable to clear restaurant orders.',500);}
}
if($path==='/api/owner/uploads/cleanup'){
 $restaurantId=(int)($_POST['restaurant_id']??0);
 if($restaurantId<1)order_fail('Select a valid restaurant.');
 $s=$db->prepare('SELECT id FROM restaurants WHERE id=?');$s->execute([$restaurantId]);
 if(!$s->fetchColumn())order_fail('Restaurant not found.',404);
 require_once __DIR__.'/image-upload.php';
 try{$removed=cleanup_unused_restaurant_uploads($db,$restaurantId);}
 catch(Throwable $error){error_log('Owner upload cleanup: '.$error->getMessage());order_fail('Unable to clean unused uploads. Please retry.',500);}
 json_out(['ok'=>true,'removed'=>$removed]);
}
if($path==='/api/owner/account'){
 $userId=(int)$_SESSION['user']['id'];$email=trim((string)($_POST['email']??''));$currentPassword=(string)($_POST['current_password']??'');$newPassword=(string)($_POST['new_password']??'');$confirmPassword=(string)($_POST['confirm_password']??'');
 if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>190)order_fail('Enter a valid login email address.');
 if($currentPassword==='')order_fail('Enter your current password to confirm this change.',403);
 if($newPassword!==''&&(strlen($newPassword)<5||strlen($newPassword)>72))order_fail('New password must be between 5 and 72 characters.');
 if($newPassword!==$confirmPassword)order_fail('New password and confirmation do not match.');
 $s=$db->prepare("SELECT email,password_hash FROM users WHERE id=? AND role='SUPER_ADMIN' AND is_active=1");$s->execute([$userId]);$account=$s->fetch();
 if(!$account||!password_verify($currentPassword,$account['password_hash']))order_fail('Current password is incorrect.',403);
 if($email===$account['email']&&$newPassword==='')order_fail('Change the email or enter a new password.');
 try{
  if($newPassword!==''){$s=$db->prepare('UPDATE users SET email=?,password_hash=? WHERE id=?');$s->execute([$email,password_hash($newPassword,PASSWORD_DEFAULT),$userId]);}
  else{$s=$db->prepare('UPDATE users SET email=? WHERE id=?');$s->execute([$email,$userId]);}
 }catch(PDOException $error){if($error->getCode()==='23000')order_fail('That email address is already in use.',409);throw $error;}
 session_regenerate_id(true);$_SESSION['user']['email']=$email;
 json_out(['ok'=>true,'email'=>$email]);
}
if($path==='/api/owner/restaurant/delete'){
 $id=(int)($_POST['restaurant_id']??0);
 if($id<1)order_fail('Select a valid restaurant.');
 $s=$db->prepare('SELECT name FROM restaurants WHERE id=?');$s->execute([$id]);$restaurantName=$s->fetchColumn();
 if($restaurantName===false)order_fail('Restaurant not found.',404);
 try{
  $db->beginTransaction();
  $s=$db->prepare('DELETE oi FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.restaurant_id=?');$s->execute([$id]);
  $s=$db->prepare('DELETE FROM order_status_history WHERE restaurant_id=?');$s->execute([$id]);
  $s=$db->prepare('DELETE FROM orders WHERE restaurant_id=?');$s->execute([$id]);
  $s=$db->prepare('DELETE FROM dining_sessions WHERE restaurant_id=?');$s->execute([$id]);
  $s=$db->prepare('DELETE FROM menu_availability_history WHERE restaurant_id=?');$s->execute([$id]);
  $s=$db->prepare('DELETE FROM menu_items WHERE restaurant_id=?');$s->execute([$id]);
  $s=$db->prepare('DELETE FROM menu_categories WHERE restaurant_id=?');$s->execute([$id]);
  $s=$db->prepare('DELETE FROM restaurant_tables WHERE restaurant_id=?');$s->execute([$id]);
  $s=$db->prepare('DELETE FROM subscription_payments WHERE restaurant_id=?');$s->execute([$id]);
  foreach(['inventory_portal_access','inventory_portal_settings'] as $portalTable){try{$s=$db->prepare('DELETE FROM '.$portalTable.' WHERE restaurant_id=?');$s->execute([$id]);}catch(PDOException $portalError){if($portalError->getCode()!=='42S02')throw $portalError;}}
  $s=$db->prepare('DELETE FROM subscriptions WHERE restaurant_id=?');$s->execute([$id]);
  $s=$db->prepare('DELETE FROM restaurant_menu_visits WHERE restaurant_id=?');$s->execute([$id]);
  $s=$db->prepare('DELETE FROM users WHERE restaurant_id=?');$s->execute([$id]);
  $s=$db->prepare('DELETE FROM restaurants WHERE id=?');$s->execute([$id]);
  if($s->rowCount()!==1)throw new RuntimeException('Restaurant could not be deleted.');
  $db->commit();
 }catch(Throwable $error){if($db->inTransaction())$db->rollBack();error_log('Owner restaurant deletion: '.$error->getMessage());order_fail('Unable to permanently delete this restaurant.',500);}
 $removedFiles=0;
 try{require_once __DIR__.'/image-upload.php';$removedFiles=delete_restaurant_uploads($id);}catch(Throwable $error){error_log('Restaurant upload deletion: '.$error->getMessage());}
 json_out(['ok'=>true,'name'=>$restaurantName,'removed_files'=>$removedFiles]);
}
if($path==='/api/owner/restaurant/update'){
 $id=(int)($_POST['restaurant_id']??0);$name=trim((string)($_POST['name']??''));$email=trim((string)($_POST['contact_email']??''));$phone=trim((string)($_POST['contact_phone']??''));$address=trim((string)($_POST['address']??''));$active=(string)($_POST['is_active']??'');$adminEmail=trim((string)($_POST['admin_email']??''));$adminPassword=(string)($_POST['admin_new_password']??'');$adminPasswordConfirm=(string)($_POST['admin_confirm_password']??'');
 if($id<1||$name===''||mb_strlen($name)>150)order_fail('Enter a valid restaurant name.');if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))order_fail('Enter a valid contact email.');if(mb_strlen($email)>190||mb_strlen($phone)>40||mb_strlen($address)>2000||!in_array($active,['0','1'],true))order_fail('Invalid restaurant details.');
 $s=$db->prepare('SELECT id FROM restaurants WHERE id=?');$s->execute([$id]);if(!$s->fetchColumn())order_fail('Restaurant not found.',404);
 $s=$db->prepare("SELECT id FROM users WHERE restaurant_id=? AND role='RESTAURANT_ADMIN' ORDER BY is_active DESC,id LIMIT 1");$s->execute([$id]);$adminId=(int)$s->fetchColumn();
 if($adminId){if(!filter_var($adminEmail,FILTER_VALIDATE_EMAIL)||strlen($adminEmail)>190)order_fail('Enter a valid restaurant administrator login email.');if($adminPassword!==''&&(strlen($adminPassword)<5||strlen($adminPassword)>72))order_fail('Administrator password must be between 5 and 72 characters.');if($adminPassword!==$adminPasswordConfirm)order_fail('Administrator password confirmation does not match.');}
 try{$db->beginTransaction();$s=$db->prepare('UPDATE restaurants SET name=?,contact_email=?,contact_phone=?,address=?,is_active=? WHERE id=?');$s->execute([$name,$email?:null,$phone?:null,$address?:null,(int)$active,$id]);if($adminId){if($adminPassword!==''){$s=$db->prepare("UPDATE users SET email=?,password_hash=? WHERE id=? AND restaurant_id=? AND role='RESTAURANT_ADMIN'");$s->execute([$adminEmail,password_hash($adminPassword,PASSWORD_DEFAULT),$adminId,$id]);}else{$s=$db->prepare("UPDATE users SET email=? WHERE id=? AND restaurant_id=? AND role='RESTAURANT_ADMIN'");$s->execute([$adminEmail,$adminId,$id]);}if($s->rowCount()===0&&$adminPassword===''){$s=$db->prepare("SELECT id FROM users WHERE id=? AND restaurant_id=? AND role='RESTAURANT_ADMIN'");$s->execute([$adminId,$id]);if(!$s->fetchColumn())throw new RuntimeException('Administrator account changed while saving.');}}$db->commit();}catch(PDOException $error){if($db->inTransaction())$db->rollBack();if($error->getCode()==='23000')order_fail('That login email is already in use.',409);error_log($error->getMessage());order_fail('Unable to update restaurant and administrator login.',500);}catch(RuntimeException $error){if($db->inTransaction())$db->rollBack();order_fail($error->getMessage(),409);}
 json_out(['ok'=>true]);
}
$name=trim((string)($_POST['name']??''));$slug=trim((string)($_POST['slug']??''));if($slug==='')$slug=$name;$email=trim((string)($_POST['email']??''));$password=(string)($_POST['password']??'');
if(function_exists('iconv')){$asciiSlug=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$slug);if($asciiSlug!==false)$slug=$asciiSlug;}
$slug=str_replace(["'",'"','`'],'',$slug);$slug=strtolower($slug);$slug=preg_replace('/[^a-z0-9]+/','-',$slug)??'';$slug=trim($slug,'-');
if ($name==='' || mb_strlen($name)>150 || $slug==='' || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$slug) || strlen($slug)>160) order_fail('Enter a restaurant name and URL slug that converts to English letters, numbers, and hyphens.');
if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>190 || strlen($password)<5 || strlen($password)>72) order_fail('Enter a valid administrator email and a 5–72 byte password.');
$start=(string)($_POST['starts_on']??'');$end=(string)($_POST['expires_on']??'');$plan=(string)($_POST['plan']??'');
if(!in_array($plan,['Monthly','Annual'],true))order_fail('Choose a monthly or annual billing period.');
foreach ([$start,$end] as $date) { $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if (!$d || $d->format('Y-m-d')!==$date) order_fail('Enter valid subscription dates.'); }
if ($end<$start) order_fail('Expiration cannot precede the start date.');
$fee=(string)($_POST['fee']??'0');if (!preg_match('/^(0|[1-9][0-9]{0,8})(\.[0-9]{1,2})?$/D',$fee)) order_fail('Enter a valid '.$plan.' fee.');
try {
 require_once __DIR__.'/token-crypto.php';
 $db->beginTransaction();
 $s=$db->prepare('INSERT INTO restaurants(name,slug) VALUES(?,?)');$s->execute([$name,$slug]);$tenant=$db->lastInsertId();
 $s=$db->prepare("INSERT INTO users(restaurant_id,role,name,email,password_hash) VALUES(?,'RESTAURANT_ADMIN',?,?,?)");$s->execute([$tenant,$name.' Admin',$email,password_hash($password,PASSWORD_DEFAULT)]);
 $s=$db->prepare("INSERT INTO subscriptions(restaurant_id,plan,fee,starts_on,expires_on,status) VALUES(?,?,?,?,?,'ACTIVE')");$s->execute([$tenant,$plan,$fee,$start,$end]);
 $tableToken=strtoupper(bin2hex(random_bytes(8)));
 $s=$db->prepare('INSERT INTO restaurant_tables(restaurant_id,table_number,token_hash,token_cipher) SELECT ?,?,?,? WHERE NOT EXISTS (SELECT 1 FROM restaurant_tables WHERE restaurant_id=?)');
 $s->execute([$tenant,'1',hash('sha256',$tableToken),encrypt_table_token($tableToken,$env),$tenant]);
 $db->commit();json_out(['ok'=>true,'id'=>$tenant,'slug'=>$slug]);
}catch(Throwable $error){if($db->inTransaction())$db->rollBack();if($error instanceof PDOException && $error->getCode()==='23000')order_fail('Restaurant slug or administrator email already exists.',409);error_log($error->getMessage());order_fail('Unable to create restaurant.',500);}
