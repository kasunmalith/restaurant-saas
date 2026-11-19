<?php
declare(strict_types=1);

function add_category_photo_controls(string $html,PDO $db,int $rid,string $base):string {
    $s=$db->prepare('SELECT c.*, (SELECT COUNT(*) FROM menu_items i WHERE i.category_id=c.id AND i.restaurant_id=c.restaurant_id) item_count FROM menu_categories c WHERE c.restaurant_id=?');
    $s->execute([$rid]);$categories=[];
    foreach($s as $row)$categories[(int)$row['id']]=$row;
    return preg_replace_callback('~<form\b(?=[^>]*(?:id="category-form"|\bdata-category-edit\b))[^>]*>.*?</form>~s',static function(array $match)use($categories,$base):string{
        $form=$match[0];preg_match('/name="id" value="(\d+)"/',$form,$id);
        $category=$categories[(int)($id[1]??0)]??[];
        $controls='<div class="admin-category-photo-controls"><p>Theme 2 category photos · JPEG, PNG or WebP, up to 5 MB. Existing item photos are kept but not displayed.</p><div class="admin-category-photo-grid">';
        $bottomTip='';
        foreach(['image_path'=>['category_photo','Main category photo (optional)'],'bottom_image_path'=>['bottom_photo','Bottom category photo (optional)']] as $field=>[$input,$label]){
            if($field==='bottom_image_path' && (int)($category['item_count']??0)<=4){$bottomTip='<p>Add more than 4 food items to enable the optional bottom photo. Any saved bottom photo is retained.</p>';continue;}
            $controls.='<div class="admin-category-photo-field"><label>'.$label.'<input type="file" name="'.$input.'" accept="image/jpeg,image/png,image/webp"></label>';
            if(!empty($category[$field]))$controls.='<img src="'.e($base.'/'.$category[$field]).'" alt="'.e($label).'" width="96" height="96" class="admin-category-photo-preview"><label class="admin-category-photo-remove"><input type="checkbox" name="remove_'.$input.'" value="1"> Remove saved photo</label>';
            $controls.='</div>';
        }
        $position=strpos($form,'<button');
        return $position===false?$form:substr_replace($form,$controls.'</div>'.$bottomTip.'</div>',$position,0);
    },$html)??$html;
}
