<?php
if (($u['role']??'')!=='SUPER_ADMIN') return;

$reportQuery=trim((string)($_GET['restaurant_report_q']??''));
if(mb_strlen($reportQuery)>150)$reportQuery=mb_substr($reportQuery,0,150);
$reportSql="SELECT r.id,r.name,r.slug,r.is_active,
    COALESCE(v.visit_count,0) menu_visits,
    (SELECT COUNT(*) FROM menu_items m WHERE m.restaurant_id=r.id AND m.is_active=1) item_count,
    (SELECT COUNT(*) FROM restaurant_tables t WHERE t.restaurant_id=r.id) table_count,
    COALESCE((SELECT SUM(o.total) FROM orders o WHERE o.restaurant_id=r.id AND o.status<>'REJECTED'),0) total_sales
    FROM restaurants r
    LEFT JOIN restaurant_menu_visits v ON v.restaurant_id=r.id";
$reportParams=[];
if($reportQuery!==''){
    $reportSql.=' WHERE r.name LIKE ? OR r.slug LIKE ?';
    $search='%'.$reportQuery.'%';
    $reportParams=[$search,$search];
}
$reportSql.=' ORDER BY r.name';
$reportStatement=$db->prepare($reportSql);
$reportStatement->execute($reportParams);
$reportRows=$reportStatement->fetchAll();

$totalVisits=array_sum(array_map(fn($row)=>(int)$row['menu_visits'],$reportRows));
$totalItems=array_sum(array_map(fn($row)=>(int)$row['item_count'],$reportRows));
$totalTables=array_sum(array_map(fn($row)=>(int)$row['table_count'],$reportRows));
$totalSales=array_sum(array_map(fn($row)=>(float)$row['total_sales'],$reportRows));

$reports='<section class="panel owner-reports" id="restaurant-reports"><div class="owner-report-heading"><div><p class="eyebrow">USAGE OVERVIEW</p><h2>Restaurant Reports</h2><p>Search a restaurant to review its menu usage and current setup.</p></div><span>'.count($reportRows).' results</span></div>';
$reports.='<form class="owner-report-search" method="get" action="'.e($base).'/dashboard#restaurant-reports"><label>Search restaurants<input type="search" name="restaurant_report_q" maxlength="150" value="'.e($reportQuery).'" placeholder="Restaurant name or URL slug"></label><button>Search</button><a class="button" href="'.e($base).'/dashboard#restaurant-reports">Clear</a></form>';
$reports.='<div class="cards owner-report-summary"><div><small>Menu visits</small><b>'.number_format($totalVisits).'</b></div><div><small>Menu items</small><b>'.number_format($totalItems).'</b></div><div><small>Tables</small><b>'.number_format($totalTables).'</b></div><div><small>Total sales</small><b>LKR '.number_format($totalSales,2).'</b></div></div>';
$reports.='<p class="owner-report-note">Menu visits are counted at restaurant level each time a valid customer menu is opened. Total sales include submitted orders except rejected orders; customer payment collection is not recorded by this system.</p>';
if($reportRows){
    $reports.='<div class="owner-report-table-wrap"><table class="table owner-report-table"><thead><tr><th>Restaurant</th><th>Status</th><th>Menu visits</th><th>Menu items</th><th>Tables</th><th>Total sales (LKR)</th></tr></thead><tbody>';
    foreach($reportRows as $row){
        $reports.='<tr><td><strong>'.e($row['name']).'</strong><small>'.e($row['slug']).'</small></td><td><span class="pill '.(!(int)$row['is_active']?'off':'').'">'.((int)$row['is_active']?'Active':'Suspended').'</span></td><td>'.number_format((int)$row['menu_visits']).'</td><td>'.number_format((int)$row['item_count']).'</td><td>'.number_format((int)$row['table_count']).'</td><td>'.number_format((float)$row['total_sales'],2).'</td></tr>';
    }
    $reports.='</tbody></table></div>';
}else{
    $reports.='<div class="owner-report-empty"><strong>No restaurants found</strong><p>Try a different restaurant name or URL slug.</p></div>';
}
$reports.='</section>';
$html=str_replace('</main>',$reports.'</main>',$html);
$html=str_replace('<a href="#restaurant-management">','<a href="#restaurant-reports">Reports</a><a href="#restaurant-management">',$html);
$html=str_replace('</head>','<link rel="stylesheet" href="'.e($base).'/assets/owner-reports.css?v=1"></head>',$html);
