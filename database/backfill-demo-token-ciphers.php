<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/vendor/autoload.php';
$env=Dotenv\Dotenv::createArrayBacked(dirname(__DIR__))->load();
if(($env['APP_ENV']??'')!=='local')throw new RuntimeException('Demo token backfill is local-development only.');
require dirname(__DIR__).'/app/token-crypto.php';
$db=new PDO('mysql:host='.$env['DB_HOST'].';dbname='.$env['DB_DATABASE'].';charset=utf8mb4',$env['DB_USERNAME'],$env['DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$tokens=['olive-table-01-demo-token','olive-table-02-demo-token','olive-table-03-demo-token','olive-table-04-demo-token','olive-table-05-demo-token','golden-spoon-01-demo-token','golden-spoon-02-demo-token'];
$find=$db->prepare('SELECT id FROM restaurant_tables WHERE token_hash=?');$save=$db->prepare('UPDATE restaurant_tables SET token_cipher=? WHERE id=? AND token_hash=?');$count=0;
foreach($tokens as $token){$hash=hash('sha256',$token);$find->execute([$hash]);if($id=$find->fetchColumn()){$save->execute([encrypt_table_token($token,$env),$id,$hash]);$count+=$save->rowCount();}}
echo 'Protected '.$count.' demo table tokens.'.PHP_EOL;
