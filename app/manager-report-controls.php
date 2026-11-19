<?php
declare(strict_types=1);
// Included by settings-controls.php for an authenticated restaurant manager.
$reportZone=new DateTimeZone($restaurant['timezone']??'Asia/Colombo');
$reportUtc=new DateTimeZone('UTC');
$reportToday=new DateTimeImmutable('today',$reportZone);
$reportPresets=[
    'month'=>[$reportToday->modify('first day of this month'),$reportToday],
    'last_month'=>[$reportToday->modify('first day of last month'),$reportToday->modify('last day of last month')],
    'last30'=>[$reportToday->modify('-29 days'),$reportToday],
];
$reportRange=(string)($_GET['range']??(isset($_GET['from'])||isset($_GET['to'])?'custom':'month'));
if(!isset($reportPresets[$reportRange])&&$reportRange!=='custom')$reportRange='month';
$reportError='';
if($reportRange==='custom'){
    $parseDate=static function($value)use($reportZone):?DateTimeImmutable{
        if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value))return null;
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,$reportZone);
        return $date&&$date->format('Y-m-d')===$value?$date:null;
    };
    $reportFromDate=$parseDate($_GET['from']??null);
    $reportToDate=$parseDate($_GET['to']??null);
    if(!$reportFromDate||!$reportToDate||$reportFromDate>$reportToDate){
        $reportError='Enter a valid date range with From on or before To. Showing this month instead.';
        $reportRange='month';
    }
}
if($reportRange!=='custom')[$reportFromDate,$reportToDate]=$reportPresets[$reportRange];
$reportFrom=$reportFromDate->format('Y-m-d');
$reportTo=$reportToDate->format('Y-m-d');
$reportFromUtc=$reportFromDate->setTime(0,0)->setTimezone($reportUtc)->format('Y-m-d H:i:s');
$reportToUtc=$reportToDate->modify('+1 day')->setTime(0,0)->setTimezone($reportUtc)->format('Y-m-d H:i:s');
$reportDays=(int)$reportFromDate->diff($reportToDate)->format('%a')+1;
$previousFromUtc=$reportFromDate->modify('-'.$reportDays.' days')->setTime(0,0)->setTimezone($reportUtc)->format('Y-m-d H:i:s');
$reportStatuses="('PENDING','ACCEPTED','PREPARING','READY','SERVED','COMPLETED')";
$previousQuery=$db->prepare("SELECT COUNT(*) order_count,COALESCE(SUM(total),0) order_value FROM orders WHERE restaurant_id=? AND status IN $reportStatuses AND created_at>=? AND created_at<?");
$previousQuery->execute([$rid,$previousFromUtc,$reportFromUtc]);
$previous=$previousQuery->fetch();

$reportOrders=$db->prepare("SELECT o.created_at,o.total,t.table_number FROM orders o LEFT JOIN restaurant_tables t ON t.id=o.table_id AND t.restaurant_id=o.restaurant_id WHERE o.restaurant_id=? AND o.status IN $reportStatuses AND o.created_at>=? AND o.created_at<? ORDER BY o.created_at");
$reportOrders->execute([$rid,$reportFromUtc,$reportToUtc]);
$reportCount=0;$reportValue=0.0;$tableCounts=[];$tableOrderCount=0;$dayValues=[];
foreach($reportOrders as $order){
    $reportCount++;$value=(float)$order['total'];$reportValue+=$value;
    if($order['table_number']!==null){$table='Table '.(string)$order['table_number'];$tableCounts[$table]=($tableCounts[$table]??0)+1;$tableOrderCount++;}
    $day=(new DateTimeImmutable($order['created_at'],$reportUtc))->setTimezone($reportZone)->format('Y-m-d');
    $dayValues[$day]=($dayValues[$day]??0)+$value;
}
$reportOrders->closeCursor();
$averageValue=$reportCount?$reportValue/$reportCount:0;
$previousValue=(float)$previous['order_value'];
$changeBadge=static function(float $current,float $before):string{
    if($before<=0)return '<span class="manager-report-change is-neutral">'.($current>0?'New activity':'No change').'</span>';
    $change=($current-$before)/$before*100;
    $sign=$change>0?'+':'';
    return '<span class="manager-report-change '.($change>=0?'is-up':'is-down').'">'.($change>=0?'↑ ':'↓ ').$sign.number_format($change,1).'%</span>';
};
$itemPhotoExpression=(int)($restaurant['menu_layout']??1)===2?'COALESCE(c.image_path,m.image_path)':'COALESCE(m.image_path,c.image_path)';
$itemNameExpression="COALESCE(m.name,REPLACE(REPLACE(oi.item_name,' (Full Portion)',''),' (Half Portion)',''))";
$topQuery=$db->prepare("SELECT $itemNameExpression item_name,SUM(oi.quantity) quantity,SUM(oi.subtotal) item_value,MAX($itemPhotoExpression) image_path FROM order_items oi JOIN orders o ON o.id=oi.order_id LEFT JOIN menu_items m ON m.id=oi.menu_item_id AND m.restaurant_id=o.restaurant_id LEFT JOIN menu_categories c ON c.id=m.category_id AND c.restaurant_id=o.restaurant_id WHERE o.restaurant_id=? AND o.status IN $reportStatuses AND o.created_at>=? AND o.created_at<? GROUP BY $itemNameExpression ORDER BY quantity DESC,item_value DESC LIMIT 10");
$topQuery->execute([$rid,$reportFromUtc,$reportToUtc]);
$popular=$topQuery->fetchAll();
$topItems=array_slice($popular,0,5);
arsort($tableCounts,SORT_NUMERIC);
$topTables=array_slice($tableCounts,0,4,true);
$otherTables=array_sum(array_slice($tableCounts,4));
if($otherTables)$topTables['Other tables']=$otherTables;
$tableColors=['#ffbd49','#19a87c','#319aca','#b39add','#ed8768'];
$gradientParts=[];$tableLegend='';$degree=0.0;$tableIndex=0;
foreach($topTables as $label=>$count){
    $next=$degree+360*$count/max(1,$tableOrderCount);$color=$tableColors[$tableIndex%count($tableColors)];
    $gradientParts[]=$color.' '.number_format($degree,2,'.','').'deg '.number_format($next,2,'.','').'deg';
    $degree=$next;
    $tableLegend.='<li><span class="manager-report-swatch" style="background:'.$color.'"></span><span>'.e($label).'</span><strong>'.number_format($count).'</strong></li>';
    $tableIndex++;
}
$donutStyle=$gradientParts?'background:conic-gradient('.implode(',',$gradientParts).')':'background:#3c4653';

$bucketSize=max(1,(int)ceil($reportDays/45));
$bucketCount=(int)ceil($reportDays/$bucketSize);
$bucketValues=array_fill(0,$bucketCount,0.0);
foreach($dayValues as $date=>$value){
    $offset=(int)$reportFromDate->diff(new DateTimeImmutable($date,$reportZone))->format('%a');
    $index=min($bucketCount-1,intdiv($offset,$bucketSize));
    $bucketValues[$index]+=$value;
}
$maxBucket=max(1,max($bucketValues));
$bars='';
foreach($bucketValues as $index=>$value){
    $date=$reportFromDate->modify('+'.($index*$bucketSize).' days');
    $height=$value>0?max(3,round($value/$maxBucket*100,1)):0;
    $label=$date->format($bucketSize===1?'j M':'d M');
    $bars.='<div class="manager-report-bar-wrap" title="'.e($label).' · LKR '.number_format($value,2).'"><span class="manager-report-bar" style="height:'.$height.'%"></span><small>'.($index===0||$index===$bucketCount-1||$index%max(1,(int)ceil($bucketCount/6))===0?e($label):'').'</small></div>';
}
$dailyCount=min(30,$reportDays);$dailyStart=$reportToDate->modify('-'.($dailyCount-1).' days');
$dailySeries=[];
for($n=0;$n<$dailyCount;$n++){
    $day=$dailyStart->modify('+'.$n.' days')->format('Y-m-d');
    $dailySeries[]=(float)($dayValues[$day]??0);
}
$maxDaily=max(1,max($dailySeries));$points=[];
foreach($dailySeries as $index=>$value)$points[]=round($index/max(1,$dailyCount-1)*560,1).','.round(112-$value/$maxDaily*93,1);
$linePoints=implode(' ',$points);
$areaPoints='0,112 '.$linePoints.' 560,112';
$lastDayValue=(float)($dayValues[$reportTo]??0);
$priorDay=$reportToDate->modify('-1 day')->format('Y-m-d');
$priorDayValue=(float)($dayValues[$priorDay]??0);

$rangeOptions=['month'=>'This Month','last_month'=>'Last Month','last30'=>'Last 30 Days','custom'=>'Custom Dates'];
$rangeSelect='<label class="manager-report-select"><span class="visually-hidden">Period</span><select name="range" form="manager-report-form" aria-label="Report period">';
foreach($rangeOptions as $key=>$label){
    $preset=$reportPresets[$key]??[$reportFromDate,$reportToDate];
    $rangeSelect.='<option value="'.$key.'" data-from="'.$preset[0]->format('Y-m-d').'" data-to="'.$preset[1]->format('Y-m-d').'"'.($reportRange===$key?' selected':'').'>'.$label.'</option>';
}
$rangeSelect.='</select></label>';
$reports='<section class="panel manager-reports" id="reports" data-section-title="Order Reports"><div class="manager-report-heading"><div><p class="eyebrow">RESTAURANT ANALYTICS</p><h2>Order Reports</h2><p>Explore order trends for your restaurant.</p></div><span class="manager-report-period">'.e($reportFromDate->format('d M Y')).' – '.e($reportToDate->format('d M Y')).'</span></div>';
$reports.='<form id="manager-report-form" method="get" action="'.e($base).'/dashboard#reports" class="manager-report-filter"><label>From<input type="date" name="from" value="'.e($reportFrom).'" required></label><label>To<input type="date" name="to" value="'.e($reportTo).'" required></label><button type="submit">Apply</button><a class="manager-report-export" href="'.e($base).'/admin/orders.csv?from='.rawurlencode($reportFrom).'&amp;to='.rawurlencode($reportTo).'">Download Orders CSV</a></form>';
if($reportError)$reports.='<p class="manager-report-error" role="alert">'.e($reportError).'</p>';
$reports.='<div class="manager-report-board"><article class="manager-report-card manager-report-overview"><div class="manager-report-card-head"><h3>Sales Overview</h3>'.$rangeSelect.'</div><strong class="manager-report-total">LKR '.number_format($reportValue,2).'</strong>'.$changeBadge($reportValue,$previousValue).'<p>Submitted order value · '.number_format($reportCount).' orders</p><div class="manager-report-bars" role="img" aria-label="Order value by '.($bucketSize===1?'day':'period').'">'.$bars.'</div></article>';
$reports.='<article class="manager-report-card manager-report-top"><div class="manager-report-card-head"><h3>Top Selling Items</h3><span>By quantity</span></div>';
if(!$topItems)$reports.='<p class="manager-report-empty">No items sold in this period.</p>';
foreach($topItems as $index=>$item){
    $photo=(string)($item['image_path']??'');
    $image=$photo!==''&&is_file(dirname(__DIR__).'/public/'.ltrim($photo,'/'))?'<img src="'.e($base.'/'.ltrim($photo,'/')).'" alt="">':'<span aria-hidden="true">🍽</span>';
    $reports.='<div class="manager-report-item"><span class="manager-report-rank">'.($index+1).'</span><span class="manager-report-item-photo">'.$image.'</span><span class="manager-report-item-name">'.e($item['item_name']).'</span><strong>'.number_format((int)$item['quantity']).'</strong></div>';
}
$reports.='</article><article class="manager-report-card manager-report-tables"><div class="manager-report-card-head"><h3>Orders by Table</h3><span>'.number_format($tableOrderCount).' table orders</span></div><div class="manager-report-table-content"><div class="manager-report-donut" style="'.$donutStyle.'"><div><strong>'.number_format($tableOrderCount).'</strong><small>Orders</small></div></div>';
$reports.=$tableLegend?'<ul class="manager-report-legend">'.$tableLegend.'</ul>':'<p class="manager-report-empty">No table orders in this period.</p>';
$reports.='</div></article><article class="manager-report-card manager-report-daily"><div class="manager-report-card-head"><h3>Daily Sales</h3><span>'.e($reportToDate->format('d M Y')).'</span></div><strong class="manager-report-total">LKR '.number_format($lastDayValue,2).'</strong>'.($dailyCount>1?$changeBadge($lastDayValue,$priorDayValue):'').'<p>Submitted order value on the last selected day</p><svg class="manager-report-line" viewBox="0 0 560 124" role="img" aria-label="Daily submitted order value for the last '.(int)$dailyCount.' days"><defs><linearGradient id="managerDailyFill" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#ffc363" stop-opacity=".35"/><stop offset="1" stop-color="#ffc363" stop-opacity="0"/></linearGradient></defs><polygon points="'.$areaPoints.'" fill="url(#managerDailyFill)"/><polyline points="'.$linePoints.'" fill="none" stroke="#ffbd49" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/></svg></article></div>';
$reports.='<div class="manager-report-footer"><div><strong>'.number_format($reportCount).'</strong><span>Orders</span></div><div><strong>LKR '.number_format($averageValue,2).'</strong><span>Average submitted order</span></div><div><strong>LKR '.number_format((float)($previous['order_value']??0),2).'</strong><span>Previous matching period</span></div></div>';
$reports.='<details class="manager-report-details"><summary>View item performance (top 10)</summary><div class="manager-report-table-scroll"><table><thead><tr><th>Popular item</th><th>Quantity</th><th>Submitted value</th></tr></thead><tbody>';
foreach($popular as $item)$reports.='<tr><td>'.e($item['item_name']).'</td><td>'.number_format((int)$item['quantity']).'</td><td>LKR '.number_format((float)$item['item_value'],2).'</td></tr>';
if(!$popular)$reports.='<tr><td colspan="3">No items sold in this period.</td></tr>';
$reports.='</tbody></table></div></details>';
$reports.=(!empty($posEnabled)?'<div class="manager-report-bottom"><p class="manager-report-note">Reports include submitted orders except rejected orders. Values are not confirmed collected revenue; customer payments are not recorded here. Dates use '.e($reportZone->getName()).' time.</p><div class="manager-report-actions"><button type="button" class="button" id="open-pos-sales-report">Open POS sales and payment report</button></div></div><dialog class="manager-pos-report-dialog" id="manager-pos-report-dialog" aria-labelledby="manager-pos-report-title"><div class="manager-pos-report-header"><span class="manager-pos-report-mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V10m5 10V5m5 15v-7m5 7V3"/></svg></span><div class="manager-pos-report-copy"><h2 id="manager-pos-report-title">POS Sales &amp; Payment Report</h2><p>View detailed sales, payment and order statistics for your selected period</p></div><button type="button" class="manager-pos-report-close" aria-label="Close report">×</button></div><iframe title="POS Sales and Payment Report" data-src="'.e($base.'/pos?view=report&embed=1').'"></iframe></dialog>':'<p class="manager-report-note">Reports include submitted orders except rejected orders. Values are not confirmed collected revenue; customer payments are not recorded here. Dates use '.e($reportZone->getName()).' time.</p>').'</section>';
$reports=preg_replace('~<div class="manager-report-actions">.*?</div>~s','',$reports)??$reports;
$reports=preg_replace('~<dialog class="manager-pos-report-dialog".*?</dialog>~s','',$reports)??$reports;
$posReports='';
if(!empty($posEnabled))$posReports='<section class="panel manager-pos-report-section" id="pos-sales-report" data-section-title="POS Sales &amp; Payment Report"><div class="manager-report-heading"><div><p class="eyebrow">RESTAURANT ANALYTICS</p><h2>POS Sales &amp; Payment Report</h2><p>View detailed sales, payment and order statistics for your selected period.</p></div></div><iframe id="dashboard-pos-report-frame" title="POS Sales &amp; Payment Report" data-src="'.e($base.'/pos?view=report&embed=1').'" loading="lazy"></iframe></section>';
$html=str_replace('</main>',$reports.$posReports.'</main>',$html);
$html=str_replace('</head>','<link rel="stylesheet" href="'.e($base).'/assets/manager-reports.css?v=8"></head>',$html);
$html=str_replace('</body>','<script src="'.e($base).'/assets/manager-reports.js?v=3"></script></body>',$html);
