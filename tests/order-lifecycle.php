<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require dirname(__DIR__).'/vendor/autoload.php';
$env=Dotenv\Dotenv::createArrayBacked(dirname(__DIR__))->load();
if (($env['APP_ENV']??'')!=='local') throw new RuntimeException('Local development only.');
$db=new PDO('mysql:host='.$env['DB_HOST'].';dbname='.$env['DB_DATABASE'].';charset=utf8mb4',$env['DB_USERNAME'],$env['DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$curl=curl_init();curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>20]);
function callApi(string $path,?array $fields=null,bool $json=false):array {
 global $curl,$env;
 curl_setopt($curl,CURLOPT_URL,rtrim($env['APP_URL'],'/').$path);
 curl_setopt($curl,CURLOPT_HTTPHEADER,$json?['Content-Type: application/json']:[]);
 if($fields===null)curl_setopt($curl,CURLOPT_HTTPGET,true);else{curl_setopt($curl,CURLOPT_POST,true);curl_setopt($curl,CURLOPT_POSTFIELDS,$json?json_encode($fields):$fields);}
 $body=curl_exec($curl);if($body===false)throw new RuntimeException(curl_error($curl));
 return [(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),json_decode($body,true)??$body];
}
function verify(bool $ok,string $label):void {if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
$key=bin2hex(random_bytes(24));$id=null;$fixtureItem=null;
try {
 $payload=['slug'=>'olive-table','token'=>'olive-table-05-demo-token','idempotency_key'=>$key,'items'=>[['id'=>1,'qty'=>2]],'special_request'=>'Lifecycle integration test','total'=>'0.01'];
 [$code,$result]=callApi('/api/order',$payload,true);verify($code===200&&!empty($result['ok']),'order submitted');$order=$result['order'];$id=(int)$order['id'];
 verify($order['total']==='1900.00','server price overrides client total');
 [$code,$retry]=callApi('/api/order',$payload,true);verify($code===200&&(int)$retry['order']['id']===$id,'retry returns same order');
 [$code]=callApi('/api/status?id='.$id);verify($code===404,'tracking requires secret token');
 [$code]=callApi('/login',['email'=>'cashier@olive.test','password'=>'password']);verify($code===302,'cashier login');
 [$code,$html]=callApi('/dashboard');preg_match('/<script type="application\/json" id="staff-config">(.*?)<\/script>/s',$html,$match);$config=json_decode($match[1]??'{}',true);verify(!empty($config['csrf']),'cashier security token');
 $s=$db->prepare('INSERT INTO menu_items(restaurant_id,category_id,name,price) VALUES(1,1,?,1.00)');$s->execute(['Availability test '.$key]);$fixtureItem=(int)$db->lastInsertId();
 foreach ([0,0,1] as $available) {
  [$code]=callApi('/api/cashier/availability',['csrf'=>$config['csrf'],'item_id'=>$fixtureItem,'available'=>$available]);verify($code===200,'availability set to '.$available);
  [$code,$menu]=callApi('/api/menu?slug=olive-table&token=olive-table-05-demo-token');$row=array_values(array_filter($menu['items']??[],fn($item)=>(int)$item['id']===$fixtureItem));verify(count($row)===1&&(int)$row[0]['is_available']===$available,'public menu reflects availability');
 }
 $s=$db->prepare('SELECT previous_available,new_available,user_id FROM menu_availability_history WHERE menu_item_id=? ORDER BY id');$s->execute([$fixtureItem]);$availabilityHistory=$s->fetchAll(PDO::FETCH_ASSOC);
 verify(count($availabilityHistory)===2,'repeated availability request does not duplicate history');
 verify((int)$availabilityHistory[0]['previous_available']===1&&(int)$availabilityHistory[0]['new_available']===0&&(int)$availabilityHistory[1]['new_available']===1,'availability history tracks both changes');
 verify(!empty($availabilityHistory[0]['user_id']),'availability history records actor');
 [$code,$list]=callApi('/api/cashier/orders');$found=array_values(array_filter($list,fn($o)=>(int)$o['id']===$id));verify(count($found)===1&&count($found[0]['items'])===1,'cashier receives item details');
 [$code,$incremental]=callApi('/api/cashier/orders?since='.rawurlencode(gmdate('Y-m-d H:i:s',time()-120)));$changed=array_values(array_filter($incremental['orders']??[],fn($o)=>(int)$o['id']===$id));verify($code===200&&count($changed)===1&&!empty($incremental['cursor']),'incremental cashier polling returns changed orders and cursor');
 $from='PENDING';foreach(['ACCEPTED','PREPARING','READY','SERVED'] as $to){
  [$code]=callApi('/api/cashier/status',['csrf'=>$config['csrf'],'order_id'=>$id,'expected_status'=>$from,'status'=>$to]);verify($code===200,'transition '.$to);
  [$code,$status]=callApi('/api/status?id='.$id.'&token='.$order['tracking_token']);verify($code===200&&$status['status']===$to,'customer sees '.$to);$from=$to;
 }
 [$code]=callApi('/api/cashier/status',['csrf'=>$config['csrf'],'order_id'=>$id,'expected_status'=>'PENDING','status'=>'ACCEPTED']);verify($code===409,'stale cashier update rejected');
 $s=$db->prepare('SELECT new_status,user_id FROM order_status_history WHERE order_id=? ORDER BY id');$s->execute([$id]);$history=$s->fetchAll(PDO::FETCH_ASSOC);
 verify(array_column($history,'new_status')===['ACCEPTED','PREPARING','READY','SERVED'],'status history records only successful transitions');
 verify(count(array_filter($history,fn($row)=>!empty($row['user_id'])))===4,'status history records staff actor');
} finally {
 $s=$db->prepare('DELETE FROM orders WHERE idempotency_key=?');$s->execute([hash('sha256',$key.'|1')]);
 if($fixtureItem){$s=$db->prepare('DELETE FROM menu_items WHERE id=? AND name=?');$s->execute([$fixtureItem,'Availability test '.$key]);}
 curl_close($curl);
}
