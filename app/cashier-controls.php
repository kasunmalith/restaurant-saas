<?php
if (!in_array($u['role'],['RESTAURANT_ADMIN','CASHIER'],true)) return;
$_SESSION['csrf']??=bin2hex(random_bytes(32));
$config=json_encode(['base'=>$base,'csrf'=>$_SESSION['csrf'],'timezone'=>$restaurant['timezone']??'Asia/Colombo','role'=>$u['role'],'restaurant_id'=>(int)($restaurant['id']??0),'restaurant_name'=>$restaurant['name']??'','items'=>array_values(array_filter($items,fn($item)=>(int)($item['is_active']??1)===1))],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
$html=str_replace('</body>','<script type="application/json" id="staff-config">'.$config.'</script><script src="'.e($base).'/assets/cashier.js?v=49"></script></body>',$html);
