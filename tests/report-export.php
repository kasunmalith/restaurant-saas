<?php
declare(strict_types=1);
$base='http://localhost/restaurant-saas/public';$cookie=tempnam(sys_get_temp_dir(),'report-');
function check(bool $ok,string $label):void{echo($ok?'PASS ':'FAIL ').$label.PHP_EOL;if(!$ok)throw new RuntimeException($label);}
try{$c=curl_init($base.'/login');curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['email'=>'admin@golden.test','password'=>'password']),CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie]);curl_exec($c);check(curl_getinfo($c,CURLINFO_RESPONSE_CODE)===302,'Golden Spoon administrator login');curl_close($c);
$c=curl_init($base.'/admin/orders.csv?from=2020-01-01&to=2030-12-31');curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$cookie]);$csv=(string)curl_exec($c);$code=curl_getinfo($c,CURLINFO_RESPONSE_CODE);$type=curl_getinfo($c,CURLINFO_CONTENT_TYPE);curl_close($c);check($code===200&&str_starts_with((string)$type,'text/csv'),'CSV export response');check(str_contains($csv,'DEMO-GS-1001')&&!str_contains($csv,'DEMO-OT-1001'),'CSV export is tenant scoped');check(str_contains($csv,'Submitted Order Value (LKR)'),'CSV distinguishes submitted order value');
}finally{@unlink($cookie);}
