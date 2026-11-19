<?php
declare(strict_types=1);
if(!in_array($path,['/qr','/qr-card','/qr-cards'],true))return;
if(!class_exists('Endroid\\QrCode\\QrCode')){http_response_code(503);exit('QR library unavailable.');}

$makeQr=static function(string $url):string{
    $qr=Endroid\QrCode\QrCode::create($url)->setSize(500)->setMargin(18);
    return(new Endroid\QrCode\Writer\PngWriter())->write($qr)->getString();
};
$logoDataUri=static function(int $restaurantId,?string $fallbackPath):string{
    $qrLogo=dirname(__DIR__).'/public/uploads/qr-logos/restaurant-'.$restaurantId.'.png';
    $legacyQrLogo=dirname(__DIR__).'/public/uploads/qr-logos/restaurant-'.$restaurantId.'.jpg';
    $file=is_file($qrLogo)?$qrLogo:(is_file($legacyQrLogo)?$legacyQrLogo:($fallbackPath?dirname(__DIR__).'/public/'.ltrim($fallbackPath,'/'):null));
    if(!$file||!is_file($file))return'';
    $mime=mime_content_type($file)?:'image/jpeg';
    if(!in_array($mime,['image/jpeg','image/png'],true))return'';
    return'data:'.$mime.';base64,'.base64_encode((string)file_get_contents($file));
};
$backgroundFile=dirname(__DIR__).'/public/assets/images/qr-card-background.jpg';
if(!is_file($backgroundFile)){http_response_code(503);exit('QR card background is unavailable.');}
$backgroundData='data:image/jpeg;base64,'.base64_encode((string)file_get_contents($backgroundFile));
$renderCard=static function(string $tableNumber,string $qrPng,string $logoData)use($backgroundData):string{
    $logo=$logoData!==''?'<div class="logo-box"><img src="'.$logoData.'" alt="Restaurant logo"></div>':'';
    return'<section class="qr-card"><img class="card-background" src="'.$backgroundData.'" alt="">'.$logo.'<div class="table-number">'.e($tableNumber).'</div><img class="qr-code" src="data:image/png;base64,'.base64_encode($qrPng).'" alt="Table QR code"></section>';
};
$streamPdf=static function(string $cards,string $filename,bool $download=true):never{
    if(!class_exists('Dompdf\\Dompdf')){http_response_code(503);exit('PDF library unavailable.');}
$css='@page{size:6in 8in;margin:0}html,body{width:432pt;height:576pt;margin:0;padding:0}body{font-family:DejaVu Sans,Arial,sans-serif}.qr-card{position:relative;width:432pt;height:576pt;margin:0;overflow:hidden;page-break-inside:avoid}.qr-card+.qr-card{page-break-before:always}.card-background{position:absolute;z-index:0;left:0;top:0;width:432pt;height:576pt}.logo-box{position:absolute;z-index:2;left:36pt;top:14pt;width:216pt;height:90pt;text-align:center;line-height:90pt}.logo-box img{max-width:100%;max-height:100%;vertical-align:middle}.table-number{position:absolute;z-index:2;left:278pt;top:30pt;width:96pt;height:48pt;text-align:center;color:#f6d77d;font-size:34pt;font-weight:bold;line-height:48pt}.qr-code{position:absolute;z-index:2;left:125.5pt;top:181pt;width:168pt;height:168pt}';
    $pdf=new Dompdf\Dompdf();
    $pdf->loadHtml('<!doctype html><html><head><meta charset="utf-8"><style>'.$css.'</style></head><body>'.$cards.'</body></html>','UTF-8');
    $pdf->setPaper([0,0,432,576]);
    $pdf->render();
    $pdf->stream($filename,['Attachment'=>$download]);
    exit;
};

if($path==='/qr'||$path==='/qr-card'){
    $slug=(string)($_GET['slug']??'');$token=(string)($_GET['token']??'');
    $s=$db->prepare('SELECT r.id,r.name,r.logo_path,t.table_number FROM restaurants r JOIN restaurant_tables t ON t.restaurant_id=r.id WHERE r.slug=? AND r.is_active=1 AND t.is_active=1 AND t.token_hash=?');
    $s->execute([$slug,hash('sha256',$token)]);$record=$s->fetch();
    if(!$record){http_response_code(404);exit('QR code not found.');}
    $png=$makeQr(public_url($slug,$token));
    if($path==='/qr'){header('Content-Type: image/png');header('Content-Disposition: inline; filename="table-'.preg_replace('/[^a-zA-Z0-9_-]/','-',$record['table_number']).'-qr.png"');echo$png;exit;}
    $cards=$renderCard((string)$record['table_number'],$png,$logoDataUri((int)$record['id'],$record['logo_path']??null));
    $streamPdf($cards,'table-'.preg_replace('/[^a-zA-Z0-9_-]/','-',$record['table_number']).'.pdf',($_GET['view']??'')!=='1');
}

$user=$_SESSION['user']??null;
if(!$user||!in_array($user['role'],['RESTAURANT_ADMIN','CASHIER'],true))order_fail('Restaurant staff access required.',403);
$s=$db->prepare("SELECT restaurant_id FROM users WHERE id=? AND role IN ('RESTAURANT_ADMIN','CASHIER') AND is_active=1");$s->execute([$user['id']]);$rid=(int)$s->fetchColumn();
if(!$rid)order_fail('Your account is inactive.',403);
require_once __DIR__.'/token-crypto.php';
$s=$db->prepare('SELECT id,name,slug,logo_path FROM restaurants WHERE id=?');$s->execute([$rid]);$restaurant=$s->fetch();
$ids=array_values(array_filter(array_map('intval',explode(',',(string)($_GET['ids']??'')))));
$sql='SELECT id,table_number,token_cipher FROM restaurant_tables WHERE restaurant_id=? AND is_active=1';$params=[$rid];
if($ids){$sql.=' AND id IN ('.implode(',',array_fill(0,count($ids),'?')).')';$params=[...$params,...$ids];}
$sql.=' ORDER BY table_number';$s=$db->prepare($sql);$s->execute($params);$tables=$s->fetchAll();
if(!$tables)order_fail('No active tables selected.',404);
$logo=$logoDataUri($rid,$restaurant['logo_path']??null);$cards=[];
foreach($tables as$table){
    $token=decrypt_table_token($table['token_cipher'],$env);
    if(!$token)continue;
    $cards[]=$renderCard((string)$table['table_number'],$makeQr(public_url($restaurant['slug'],$token)),$logo);
}
if(!$cards)order_fail('Regenerate the selected table QR codes once before creating a combined PDF.',409);
$streamPdf(implode('',$cards),'restaurant-table-qr-cards.pdf');
