<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/database/migrate-menu-layouts.php';
require dirname(__DIR__).'/app/customer-menu.php';
require dirname(__DIR__).'/app/menu-layout-controls.php';
function e($value):string{return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function base():string{return 'http://localhost/restaurant-saas';}
function layout(string $title,string $html):never{$GLOBALS['rendered']=$html;throw new RuntimeException('render complete');}
function check(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);echo 'PASS: '.$message.PHP_EOL;}
$restaurant=$db->query('SELECT r.*, (SELECT COUNT(*) FROM menu_items i WHERE i.restaurant_id=r.id) n FROM restaurants r ORDER BY n DESC LIMIT 1')->fetch();
if(!$restaurant)throw new RuntimeException('No local restaurant to test.');
$restaurant['table_id']=0;$restaurant['table_number']='Preview';
$restaurant['menu_background_path']='uploads/menu-backgrounds/1/test-texture.jpg';
$db->beginTransaction();
try{
    $s=$db->prepare("UPDATE menu_categories SET image_path='assets/images/qr-card-background.jpg',bottom_image_path='assets/images/qr-card-background.jpg' WHERE restaurant_id=?");$s->execute([$restaurant['id']]);
    $firstItem=$db->prepare('SELECT id FROM menu_items WHERE restaurant_id=? LIMIT 1');$firstItem->execute([$restaurant['id']]);$firstItemId=(int)$firstItem->fetchColumn();
    if($firstItemId){$portionFixture=$db->prepare('UPDATE menu_items SET is_portioned=1,half_price=250,full_price=450 WHERE id=?');$portionFixture->execute([$firstItemId]);}
    foreach([1,2] as $variant){
        $restaurant['menu_layout']=$variant;
        try{render_customer_menu($restaurant,'preview','preview');}catch(RuntimeException $error){if($error->getMessage()!=='render complete')throw $error;}
        $html=$GLOBALS['rendered'];
        check(str_contains($html,'class="add'),'Theme '.$variant.' retains order buttons');
        if($variant===1){check(!str_contains($html,'menu-layout-two.css'),'Theme 1 has no new stylesheet');check(!str_contains($html,'test-texture.jpg'),'Theme 1 ignores Theme 2 texture');check(str_contains($html,'class="food-img"'),'Theme 1 retains item photos');check(!str_contains($html,'category-main-photo'),'Theme 1 has no category photos');check(!str_contains($html,'data-size-picker="1"'),'Theme 1 retains its existing portion controls');if($firstItemId)check(str_contains($html,'data-portion="HALF"')&&str_contains($html,'data-portion="FULL"'),'Theme 1 keeps both portion buttons');}
        else{
            check(str_contains($html,'test-texture.jpg')&&str_contains($html,'background-size:cover'),'Theme 2 displays saved background texture');
            check(str_contains($html,'class="add theme-two-add"'),'Theme 2 uses compact add buttons');
            if($firstItemId)check(str_contains($html,'data-size-picker="1"')&&!str_contains($html,'data-portion="HALF"'),'Theme 2 portion item has one size picker button');
            check(!str_contains($html,'class="food-img"'),'Theme 2 omits item photos');
            check(str_contains($html,'category-main-photo'),'Theme 2 includes category photos');
            $s=$db->prepare('SELECT COUNT(*) FROM (SELECT category_id FROM menu_items WHERE restaurant_id=? GROUP BY category_id HAVING COUNT(*)>4) eligible');$s->execute([$restaurant['id']]);
            check(substr_count($html,'class="category-bottom-photo ')===(int)$s->fetchColumn(),'Bottom photos only for categories with more than four items');
            preg_match_all('/class="category-(?:main|bottom)-photo photo-(left|right)"/',$html,$photoSides);
            check(!empty($photoSides[1])&&count(array_filter($photoSides[1],static fn($side,$index)=>$side!==($index%2?'right':'left'),ARRAY_FILTER_USE_BOTH))===0,'Visible category photos alternate left and right');
            preg_match_all('/<section id="cat\d+" class="category-photo-(left|right)">.*?<img class="category-main-photo photo-(left|right)"/s',$html,$sectionSides,PREG_SET_ORDER);
            check(!empty($sectionSides)&&count(array_filter($sectionSides,static fn($match)=>$match[1]!==$match[2]))===0,'Category titles align opposite their photos');
            $insert=$db->prepare('INSERT INTO menu_categories(restaurant_id,name,sort_order,image_path,bottom_image_path) VALUES(?,?,?,?,?)');
            $insert->execute([$restaurant['id'],'Temporary test category',999,'assets/images/qr-card-background.jpg','assets/images/qr-card-background.jpg']);
            $testCategory=(int)$db->lastInsertId();
            $addItem=$db->prepare('INSERT INTO menu_items(restaurant_id,category_id,name,description,price) VALUES(?,?,?,?,?)');
            for($n=1;$n<=5;$n++)$addItem->execute([$restaurant['id'],$testCategory,'Temporary test item '.$n,'',100]);
            try{render_customer_menu($restaurant,'preview','preview');}catch(RuntimeException $error){if($error->getMessage()!=='render complete')throw $error;}
            check(str_contains($GLOBALS['rendered'],'class="category-bottom-photo '),'Five item category renders optional bottom photo');
            $controls=add_category_photo_controls('<form id="category-edit-'.$testCategory.'" data-category-edit><input name="id" value="'.$testCategory.'"><button>Save</button></form>',$db,(int)$restaurant['id'],base());
            check(str_contains($controls,'name="bottom_photo"'),'Five item category enables bottom photo upload');
            $form='<form id="category-form"><button>Add Category</button></form>';
            $controls=add_category_photo_controls($form,$db,(int)$restaurant['id'],base());
            check(str_contains($controls,'name="category_photo"')&&!str_contains($controls,'name="bottom_photo"'),'New category allows main photo, not premature bottom photo');
            if(in_array('--preview',$argv,true)){
                $directory=sys_get_temp_dir().'/restaurant-menu-layout-preview';if(!is_dir($directory))mkdir($directory);
                // Static read-only fixture: deliberately exclude session / order API scripts.
                $html=preg_replace('~<script.*?</script>~s','',$html);
                $links='';foreach(['vendor/bootstrap/bootstrap.min.css','app.css','category-picker.css','customer-themes.css','portion-options.css'] as $css)$links.='<link rel="stylesheet" href="'.base().'/assets/'.$css.'">';
                file_put_contents($directory.'/index.html','<!doctype html><html data-customer-theme="dark" data-menu-layout="2"><head><meta name="viewport" content="width=device-width,initial-scale=1">'.$links.'</head><body class="customer-menu-page">'.$html.'</body></html>');echo 'Preview: '.$directory.PHP_EOL;
            }
        }
    }
}finally{$db->rollBack();}
echo "All temporary category values rolled back. No orders or visits created.\n";
