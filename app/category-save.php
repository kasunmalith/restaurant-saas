<?php
// Included only after manager authorization, tenant and CSRF validation.
require_once __DIR__.'/image-upload.php';
$id=(int)($_POST['id']??0);$newPhotos=[];$old=[];
try {
    $db->beginTransaction();
    if($id){
        $s=$db->prepare('SELECT * FROM menu_categories WHERE id=? AND restaurant_id=? FOR UPDATE');$s->execute([$id,$rid]);$old=$s->fetch();
        if(!$old)throw new InvalidArgumentException('Category not found.');
    }
    if(($_POST['action']??'')==='delete'){
        if(!$id)throw new InvalidArgumentException('Category not found.');
        $s=$db->prepare('DELETE FROM menu_categories WHERE id=? AND restaurant_id=?');$s->execute([$id,$rid]);
    }else{
        $name=trim((string)($_POST['name']??''));
        if($name==='' || mb_strlen($name)>100)throw new InvalidArgumentException('Enter a name between 1 and 100 characters.');
        $s=$db->prepare('SELECT COUNT(*) FROM menu_items WHERE category_id=? AND restaurant_id=?');$s->execute([$id,$rid]);$count=(int)$s->fetchColumn();
        $photos=[];
        foreach(['category_photo'=>'image_path','bottom_photo'=>'bottom_image_path'] as $input=>$field){
            $hasUpload=($_FILES[$input]['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE;
            $remove=(string)($_POST['remove_'.$input]??'0');
            if(!in_array($remove,['0','1'],true))throw new InvalidArgumentException('Invalid photo action.');
            if(($hasUpload||$remove==='1') && $menuLayout!==2)throw new InvalidArgumentException('Category photos are available in Theme 2 only.');
            if($hasUpload && $input==='bottom_photo' && $count<=4)throw new InvalidArgumentException('Add more than 4 items before uploading a bottom photo.');
            if($hasUpload && $remove==='1')throw new InvalidArgumentException('Choose either upload or remove for each photo.');
            $new=save_menu_photo($_FILES[$input]??[],(int)$rid,800,'menu-categories');
            if($new!==null)$newPhotos[]=$new;
            $photos[$field]=$new??($remove==='1'?null:($old[$field]??null));
        }
        $values=[$name,max(0,min(999,(int)($_POST['sort_order']??0))),$photos['image_path'],$photos['bottom_image_path']];
        if($id){$s=$db->prepare('UPDATE menu_categories SET name=?,sort_order=?,image_path=?,bottom_image_path=? WHERE id=? AND restaurant_id=?');$s->execute([...$values,$id,$rid]);}
        else{$s=$db->prepare('INSERT INTO menu_categories(name,sort_order,image_path,bottom_image_path,restaurant_id) VALUES(?,?,?,?,?)');$s->execute([...$values,$rid]);$id=(int)$db->lastInsertId();}
    }
    $db->commit();
}catch(Throwable $error){
    if($db->inTransaction())$db->rollBack();
    foreach($newPhotos as $photo)@unlink(dirname(__DIR__).'/public/'.$photo);
    if($error instanceof InvalidArgumentException)order_fail($error->getMessage());
    if($error instanceof PDOException && $error->getCode()==='23000')order_fail('Move food items to another category before deleting this category.',409);
    error_log('Category save: '.$error->getMessage());order_fail('Unable to save category.',500);
}
// Keep unrelated uploads untouched; cleanup knows both category and item references.
try{cleanup_unused_restaurant_uploads($db,(int)$rid,array_filter([$old['image_path']??null,$old['bottom_image_path']??null]));}catch(Throwable $error){error_log($error->getMessage());}
json_out(['ok'=>true,'id'=>$id]);
