<?php
declare(strict_types=1);
if (!in_array($path,['/api/admin/item','/api/admin/item/status','/api/admin/item/delete','/api/admin/category'],true)) return;
$user=$_SESSION['user']??null;
if (!$user || !in_array($user['role'],['RESTAURANT_ADMIN','CASHIER'],true)) order_fail('Restaurant staff access required.',403);
$check=$db->prepare("SELECT restaurant_id FROM users WHERE id=? AND role IN ('RESTAURANT_ADMIN','CASHIER') AND is_active=1");
$check->execute([$user['id']]);
$rid=$check->fetchColumn();
if (!$rid) order_fail('Your account is inactive.',403);
if ($_SERVER['REQUEST_METHOD']!=='POST') order_fail('POST required.',405);
if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))) order_fail('Security token expired. Refresh the page.',403);
if(in_array($path,['/api/admin/item','/api/admin/item/delete','/api/admin/category'],true)){
    $canAdd=true;$canEdit=true;$canDelete=true;$canAddCategory=true;$canDeleteCategory=true;$canRenameCategory=true;
    if($user['role']==='CASHIER'){$permissions=$db->prepare("SELECT menu_item_add_enabled,menu_item_edit_enabled,menu_item_delete_enabled,category_add_enabled,category_delete_enabled,category_rename_enabled FROM users WHERE id=? AND restaurant_id=? AND role='CASHIER' AND is_active=1");$permissions->execute([$user['id'],$rid]);$rights=$permissions->fetch(PDO::FETCH_ASSOC);if(!$rights)order_fail('Your account is inactive.',403);$canAdd=(int)$rights['menu_item_add_enabled']===1;$canEdit=(int)$rights['menu_item_edit_enabled']===1;$canDelete=(int)$rights['menu_item_delete_enabled']===1;$canAddCategory=(int)$rights['category_add_enabled']===1;$canDeleteCategory=(int)$rights['category_delete_enabled']===1;$canRenameCategory=(int)$rights['category_rename_enabled']===1;}
    if($path==='/api/admin/item/delete'&&!$canDelete)order_fail('Your account does not have permission to delete menu items.',403);
    if($path==='/api/admin/category'){if(($_POST['action']??'')==='delete'&&!$canDeleteCategory)order_fail('Your account does not have permission to delete categories.',403);if((int)($_POST['id']??0)===0&&($_POST['action']??'')!=='delete'&&!$canAddCategory)order_fail('Your account does not have permission to add categories.',403);if($user['role']==='CASHIER'&&(int)($_POST['id']??0)>0&&($_POST['action']??'')!=='delete'&&!$canRenameCategory){$currentNameQuery=$db->prepare('SELECT name FROM menu_categories WHERE id=? AND restaurant_id=?');$currentNameQuery->execute([(int)$_POST['id'],$rid]);$currentName=$currentNameQuery->fetchColumn();if($currentName!==false&&(string)($_POST['name']??'')!==(string)$currentName)order_fail('Your account does not have permission to rename categories.',403);}}
}
if($path==='/api/admin/item'){
    $canAdd=$user['role']==='CASHIER'?$canAdd:true;$canEdit=$user['role']==='CASHIER'?$canEdit:true;
    $editing=(int)($_POST['id']??0)>0;
    if($editing&&!$canEdit)order_fail('Your account does not have permission to edit menu items.',403);
    if(!$editing&&!$canAdd)order_fail('Your account does not have permission to add menu items.',403);
}
if ($path==='/api/admin/item/status') {
    $id=(int)($_POST['id']??0);$active=(string)($_POST['is_active']??'');
    if(!in_array($active,['0','1'],true))order_fail('Invalid item status.');
    $s=$db->prepare('UPDATE menu_items SET is_active=?,is_available=IF(?=1,is_available,0) WHERE id=? AND restaurant_id=?');$s->execute([(int)$active,(int)$active,$id,$rid]);
    if($s->rowCount()!==1)order_fail('Food item not found.',404);
    json_out(['ok'=>true]);
}
if ($path==='/api/admin/item/delete') {
    $id=(int)($_POST['id']??0);
    $db->beginTransaction();
    try {
        $s=$db->prepare('SELECT image_path FROM menu_items WHERE id=? AND restaurant_id=? FOR UPDATE');$s->execute([$id,$rid]);
        $item=$s->fetch();
        if(!$item){$db->rollBack();order_fail('Food item not found.',404);}
        $s=$db->prepare('DELETE FROM menu_items WHERE id=? AND restaurant_id=?');$s->execute([$id,$rid]);
        if($s->rowCount()!==1){$db->rollBack();order_fail('Food item could not be deleted.',409);}
        $db->commit();
    } catch (PDOException $error) {
        if($db->inTransaction())$db->rollBack();
        if($error->getCode()==='23000')order_fail('Apply database/006_menu_item_delete.sql before deleting food items.',409);
        error_log($error->getMessage());order_fail('Unable to delete the food item.',500);
    } catch (Throwable $error) {
        if($db->inTransaction())$db->rollBack();error_log($error->getMessage());order_fail('Unable to delete the food item.',500);
    }
    require_once __DIR__.'/image-upload.php';
    try { cleanup_unused_restaurant_uploads($db,(int)$rid,!empty($item['image_path'])?[(string)$item['image_path']]:[]); }
    catch(Throwable $error){error_log('Deleted item upload cleanup: '.$error->getMessage());}
    json_out(['ok'=>true,'id'=>$id]);
}
$layoutQuery=$db->prepare('SELECT menu_layout FROM restaurants WHERE id=?');$layoutQuery->execute([$rid]);
$menuLayout=(int)$layoutQuery->fetchColumn();
if($path==='/api/admin/category'){
    require __DIR__.'/category-save.php';
}
$name=trim((string)($_POST['name']??''));
if ($name==='' || mb_strlen($name)>100) order_fail('Enter a name between 1 and 100 characters.');
if ($path==='/api/admin/category') {
    $id=(int)($_POST['id']??0);
    if ($id) {
        $s=$db->prepare('SELECT id FROM menu_categories WHERE id=? AND restaurant_id=?');$s->execute([$id,$rid]);
        if (!$s->fetchColumn()) order_fail('Category not found.',404);
        if (($_POST['action']??'')==='delete') {
            try {$s=$db->prepare('DELETE FROM menu_categories WHERE id=? AND restaurant_id=?');$s->execute([$id,$rid]);}
            catch(PDOException $error){if($error->getCode()==='23000')order_fail('Move the food items to another category before deleting this category.',409);throw $error;}
        } else {
            $s=$db->prepare('UPDATE menu_categories SET name=?,sort_order=? WHERE id=? AND restaurant_id=?');$s->execute([$name,max(0,min(999,(int)($_POST['sort_order']??0))),$id,$rid]);
        }
        json_out(['ok'=>true,'id'=>$id]);
    }
    $s=$db->prepare('INSERT INTO menu_categories(restaurant_id,name,sort_order) VALUES(?,?,?)');
    $s->execute([$rid,$name,max(0,min(999,(int)($_POST['sort_order']??0)))]);
    json_out(['ok'=>true,'id'=>$db->lastInsertId()]);
}
$category=(int)($_POST['category_id']??0);
$s=$db->prepare('SELECT id FROM menu_categories WHERE id=? AND restaurant_id=?');
$s->execute([$category,$rid]);
if (!$s->fetchColumn()) order_fail('Select a category belonging to your restaurant.');
$portioned=(string)($_POST['is_portioned']??'0');
if(!in_array($portioned,['0','1'],true)) order_fail('Invalid portion setting.');
$isPortioned=$portioned==='1';
$validPrice=static fn(string $value):bool=>(bool)preg_match('/^(?:0|[1-9][0-9]{0,6})(?:\.[0-9]{1,2})?$/D',$value);
$price=(string)($_POST['price']??'');
$halfPrice=null;$fullPrice=null;
$optionRows=null;
if(isset($_POST['options'])){
    try{$optionRows=json_decode((string)$_POST['options'],true,16,JSON_THROW_ON_ERROR);}catch(JsonException $error){order_fail('Invalid size options.');}
    if(!is_array($optionRows)||!array_is_list($optionRows)||count($optionRows)<1||count($optionRows)>8)order_fail('Add between 1 and 8 size options.');
    $names=[];$defaults=0;
    foreach($optionRows as $index=>&$option){
        if(!is_array($option)||!is_string($option['name']??null)||!is_string($option['price']??null))order_fail('Enter a name and price for every size.');
        $option['name']=trim($option['name']);
        if($option['name']===''||mb_strlen($option['name'])>40||preg_match('/[\x00-\x1f\x7f]/',$option['name']))order_fail('Size names must be 1 to 40 characters.');
        $key=mb_strtolower($option['name']);if(isset($names[$key]))order_fail('Each size name must be unique.');$names[$key]=true;
        if(!$validPrice($option['price'])||(float)$option['price']<=0)order_fail('Enter a price greater than zero for every size.');
        $option['default']=($option['default']??false)===true;
        if($option['default']){$defaults++;$price=$option['price'];}
    }
    unset($option);
    if($defaults!==1)order_fail('Choose one default size.');
    $isPortioned=true;
    if(count($optionRows)===1&&mb_strtolower($optionRows[0]['name'])==='regular'){$isPortioned=false;$optionRows=[];}
}elseif($isPortioned){
    $halfPrice=(string)($_POST['half_price']??'');
    $fullPrice=(string)($_POST['full_price']??'');
    if(!$validPrice($halfPrice)||!$validPrice($fullPrice)||(float)$halfPrice<=0||(float)$fullPrice<=0) order_fail('Enter valid Half and Full prices greater than zero.');
    $price=$fullPrice;
}elseif(!$validPrice($price)) order_fail('Enter a valid price with up to two decimal places.');
$description=trim((string)($_POST['description']??''));
if (mb_strlen($description)>2000) order_fail('Description must be 2,000 characters or fewer.');
$id=(int)($_POST['id']??0);
$removePhoto=(string)($_POST['remove_photo']??'0');
if($menuLayout===2 && ($removePhoto==='1' || ($_FILES['photo']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE)) order_fail('Theme 2 uses category photos. Edit photos under Menu Categories.');
if(!in_array($removePhoto,['0','1'],true)) order_fail('Invalid photo action.');
$oldPhoto=null;
if ($id) {
    $s=$db->prepare('SELECT image_path FROM menu_items WHERE id=? AND restaurant_id=?');$s->execute([$id,$rid]);
    $existing=$s->fetch();
    if (!$existing) order_fail('Food item not found.',404);
    $oldPhoto=$existing['image_path'];
}
require_once __DIR__.'/image-upload.php';
try { $photo=save_menu_photo($_FILES['photo']??[],(int)$rid,300); }
catch (InvalidArgumentException $error) { order_fail($error->getMessage()); }
catch (RuntimeException $error) { error_log($error->getMessage()); order_fail('Photo storage is unavailable. Please retry.',500); }
if($photo!==null && $removePhoto==='1'){
    @unlink(dirname(__DIR__).'/public/'.$photo);
    order_fail('Choose either a new photo or remove the current one.');
}
$imagePath=$photo??($removePhoto==='1'?null:$oldPhoto);
try {
    $db->beginTransaction();
    if ($id) {
        $s=$db->prepare('UPDATE menu_items SET category_id=?,name=?,description=?,price=?,is_portioned=?,half_price=?,full_price=?,image_path=? WHERE id=? AND restaurant_id=?');
        $s->execute([$category,$name,$description,$price,(int)$isPortioned,$halfPrice,$fullPrice,$imagePath,$id,$rid]);
    } else {
        $s=$db->prepare('INSERT INTO menu_items(restaurant_id,category_id,name,description,price,is_portioned,half_price,full_price,image_path) VALUES(?,?,?,?,?,?,?,?,?)');
        $s->execute([$rid,$category,$name,$description,$price,(int)$isPortioned,$halfPrice,$fullPrice,$imagePath]);$id=(int)$db->lastInsertId();
    }
    if($id){
        $s=$db->prepare('DELETE FROM menu_item_options WHERE restaurant_id=? AND menu_item_id=?');$s->execute([$rid,$id]);
    }
    if($optionRows!==null){
        $s=$db->prepare('INSERT INTO menu_item_options(restaurant_id,menu_item_id,name,price,sort_order,is_default) VALUES(?,?,?,?,?,?)');
        foreach($optionRows as $position=>$option)$s->execute([$rid,$id,$option['name'],$option['price'],$position,$option['default']?1:0]);
    }
    $db->commit();
} catch(Throwable $error) {
    if($db->inTransaction())$db->rollBack();
    if($photo!==null) @unlink(dirname(__DIR__).'/public/'.$photo);
    error_log($error->getMessage());
    order_fail('Unable to save the food item. Please retry.',500);
}
try { cleanup_unused_restaurant_uploads($db,(int)$rid,$oldPhoto?[(string)$oldPhoto]:[]); }
catch(Throwable $error) { error_log('Upload cleanup: '.$error->getMessage()); }
json_out(['ok'=>true,'id'=>$id,'image_path'=>$imagePath]);
