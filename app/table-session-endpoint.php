<?php
declare(strict_types=1);
if ($path!=='/api/table-session') return;
if ($_SERVER['REQUEST_METHOD']!=='POST') order_fail('POST required.',405);
try {
 $data=json_decode(file_get_contents('php://input'),true,16,JSON_THROW_ON_ERROR);$client=(string)($data['client_token']??'');
 if(!preg_match('/^[a-zA-Z0-9-]{20,100}$/D',$client))order_fail('A valid device session is required.');
 $db->beginTransaction();$db->exec("UPDATE dining_sessions SET status='EXPIRED',closed_at=COALESCE(closed_at,UTC_TIMESTAMP()) WHERE status='ACTIVE' AND expires_at<=UTC_TIMESTAMP()");
 $table=public_table_context((string)($data['slug']??''),(string)($data['token']??''),true);
 if(!$table||!$table['table_active']){$db->rollBack();order_fail('This table QR code is invalid or inactive.',403);}
 if(!$table['restaurant_active']){$db->rollBack();order_fail('This menu is currently suspended. Please contact the restaurant management for assistance.',403);}
 $clientHash=hash('sha256',$client);$s=$db->prepare("SELECT * FROM dining_sessions WHERE table_id=? AND client_token_hash=? AND status='ACTIVE' AND expires_at>UTC_TIMESTAMP() ORDER BY id DESC LIMIT 1 FOR UPDATE");$s->execute([$table['table_id'],$clientHash]);$visit=$s->fetch();
 if(!$visit){$number='VIS-'.gmdate('ymd').'-'.strtoupper(bin2hex(random_bytes(4)));$s=$db->prepare("INSERT INTO dining_sessions(restaurant_id,table_id,session_number,client_token_hash,status,started_at,expires_at) VALUES(?,?,?,?,'ACTIVE',UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 6 HOUR))");$s->execute([$table['restaurant_id'],$table['table_id'],$number,$clientHash]);$id=(int)$db->lastInsertId();$s=$db->prepare('SELECT * FROM dining_sessions WHERE id=?');$s->execute([$id]);$visit=$s->fetch();}
 $db->commit();$proof=hash_hmac('sha256',$visit['id'].'|'.$visit['client_token_hash'].'|'.$visit['expires_at'],$env['APP_KEY']);json_out(['ok'=>true,'session'=>['id'=>(int)$visit['id'],'number'=>$visit['session_number'],'expires_at'=>$visit['expires_at'],'proof'=>$proof]]);
}catch(JsonException $error){order_fail('Invalid request format.');}catch(Throwable $error){if($db->inTransaction())$db->rollBack();error_log('Table session failed: '.$error->getMessage());order_fail('Unable to start this table session.',500);}
