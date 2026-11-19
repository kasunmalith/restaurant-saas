<?php
if (($u['role']??'')!=='CASHIER' || !$restaurant) return;

require_once __DIR__.'/token-crypto.php';
$generatedQrCodes=[];
foreach($tables as $table){
    if(!(int)($table['is_active']??0))continue;
    $tableToken=decrypt_table_token($table['token_cipher']??null,$env);
    if(!$tableToken)continue;
    $generatedQrCodes[]=[
        'table_number'=>(string)$table['table_number'],
        'pdf_url'=>$base.'/qr-card?slug='.rawurlencode((string)$restaurant['slug']).'&token='.rawurlencode($tableToken).'&table='.rawurlencode((string)$table['table_number']).'&view=1',
    ];
}

$qrPanel='<section class="panel cashier-qr-panel" id="qr-codes"><div class="cashier-qr-heading"><div><p class="eyebrow">TABLE ACCESS</p><h2>QR Codes</h2><p>View the generated table QR cards as PDF documents.</p></div><span>'.count($generatedQrCodes).' generated</span></div>';
$qrPanel.='<p class="cashier-qr-tip"><strong>Tip:</strong> If no QR codes are available, please ask your manager to add the tables and generate their QR codes.</p>';
if($generatedQrCodes){
    $qrPanel.='<div class="cashier-qr-grid">';
    foreach($generatedQrCodes as $qrCode){
        $qrPanel.='<article><div><small>TABLE</small><strong>'.e($qrCode['table_number']).'</strong></div><a class="button" target="_blank" rel="noopener noreferrer" href="'.e($qrCode['pdf_url']).'">View QR PDF</a></article>';
    }
    $qrPanel.='</div>';
}else{
    $qrPanel.='<div class="cashier-qr-empty"><strong>No generated QR codes yet</strong><p>Generated table QR cards will appear here automatically.</p></div>';
}
$qrPanel.='</section>';
$html=str_replace('</main>',$qrPanel.'</main>',$html);
