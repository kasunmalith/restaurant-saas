<?php
declare(strict_types=1);
if($path!=='/admin/orders.csv')return;
$user=$_SESSION['user']??null;if(!$user||!in_array($user['role'],['RESTAURANT_ADMIN','CASHIER'],true)){http_response_code(403);exit('Restaurant staff access required.');}
$s=$db->prepare("SELECT u.restaurant_id,r.timezone FROM users u JOIN restaurants r ON r.id=u.restaurant_id WHERE u.id=? AND u.role IN ('RESTAURANT_ADMIN','CASHIER') AND u.is_active=1");$s->execute([$user['id']]);$account=$s->fetch();$rid=(int)($account['restaurant_id']??0);if(!$rid){http_response_code(403);exit('Account inactive.');}
$from=(string)($_GET['from']??date('Y-m-01'));$to=(string)($_GET['to']??date('Y-m-d'));$status=(string)($_GET['status']??'ALL');
foreach([$from,$to]as$date){$parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$parsed||$parsed->format('Y-m-d')!==$date){http_response_code(422);exit('Invalid date.');}}
$statuses=['PENDING','ACCEPTED','PREPARING','READY','SERVED','COMPLETED','REJECTED'];if($status!=='ALL'&&!in_array($status,$statuses,true)){http_response_code(422);exit('Invalid status.');}
$zone=new DateTimeZone($account['timezone']??'Asia/Colombo');$utc=new DateTimeZone('UTC');$fromUtc=(new DateTimeImmutable($from.' 00:00:00',$zone))->setTimezone($utc)->format('Y-m-d H:i:s');$toUtc=(new DateTimeImmutable($to.' 00:00:00',$zone))->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
$sql='SELECT o.order_number,t.table_number,o.status,o.total,o.special_request,o.created_at FROM orders o LEFT JOIN restaurant_tables t ON t.id=o.table_id AND t.restaurant_id=o.restaurant_id WHERE o.restaurant_id=? AND o.created_at>=? AND o.created_at<?';$params=[$rid,$fromUtc,$toUtc];if($status!=='ALL'){$sql.=' AND o.status=?';$params[]=$status;}$sql.=' ORDER BY o.created_at DESC,o.id DESC';$s=$db->prepare($sql);$s->execute($params);
$safe=static function($value):string{$value=(string)$value;return preg_match('/^[=+\-@]/',$value)?"'".$value:$value;};
header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="orders-'.$from.'-to-'.$to.'.csv"');$out=fopen('php://output','wb');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,['Order Number','Table','Status','Submitted Order Value (LKR)','Special Request','Created At (UTC)']);foreach($s as$row)fputcsv($out,array_map($safe,array_values($row)));fclose($out);exit;
