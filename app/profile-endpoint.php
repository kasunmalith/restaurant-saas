<?php
if ($path!=='/api/admin/profile') return;
$s=$db->prepare("SELECT restaurant_id FROM users WHERE id=? AND role='RESTAURANT_ADMIN' AND is_active=1");$s->execute([$_SESSION['user']['id']??0]);$tenant=$s->fetchColumn();
if (!$tenant) order_fail('Restaurant administrator access required.',403);
if ($_SERVER['REQUEST_METHOD']!=='POST') order_fail('POST required.',405);
if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))) order_fail('Refresh your security token.',403);
if(isset($_POST['action']) && $_POST['action']!=='') order_fail('Unsupported profile action.',403);
$theme=(string)($_POST['menu_theme']??'');
$themeColors=['light'=>'#174b3a','dim'=>'#285040','dark'=>'#071d15'];
if (!isset($themeColors[$theme])) order_fail('Select a valid menu theme.');
$menuLayout=(string)($_POST['menu_layout']??'1');
if(!in_array($menuLayout,['1','2'],true)) order_fail('Select a valid menu layout.');
$removeLogo=(string)($_POST['remove_logo']??'0');
if(!in_array($removeLogo,['0','1'],true)) order_fail('Invalid logo action.');
$removeBackground=(string)($_POST['remove_menu_background']??'0');
if(!in_array($removeBackground,['0','1'],true)) order_fail('Invalid background action.');
$printLogo=(string)($_POST['print_logo_on_receipt']??'0');
if(!in_array($printLogo,['0','1'],true))order_fail('Select whether the restaurant logo should print on receipts.');
$receiptTemplate=(string)($_POST['receipt_template']??'1');
if(!in_array($receiptTemplate,['1','2'],true))order_fail('Select a valid POS bill template.');
function save_profile_logo(array $upload,int $restaurantId):?string {
    if(($upload['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE) return null;
    if(($upload['error']??-1)!==UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name']??'')) throw new InvalidArgumentException('The logo could not be uploaded.');
    $file=$upload['tmp_name'];
    if(filesize($file)>5*1024*1024) throw new InvalidArgumentException('The logo must be 5 MB or smaller.');
    if((new finfo(FILEINFO_MIME_TYPE))->file($file)!=='image/png') throw new InvalidArgumentException('Upload a PNG logo.');
    $size=@getimagesize($file);
    if(!$size || $size[0]<1 || $size[1]<1 || $size[0]*$size[1]>16000000) throw new InvalidArgumentException('Logo dimensions exceed the 16 megapixel limit.');
    $relative='uploads/restaurant-logos/'.$restaurantId.'/'.bin2hex(random_bytes(16)).'.png';
    $destination=dirname(__DIR__).'/public/'.$relative;
    if(!is_dir(dirname($destination)) && !mkdir(dirname($destination),0755,true)) throw new RuntimeException('Logo storage is unavailable.');
    if($size[0]<=500){
        if(!move_uploaded_file($file,$destination)) throw new RuntimeException('The logo could not be saved.');
    }else{
        $source=@imagecreatefrompng($file);
        if(!$source) throw new InvalidArgumentException('The PNG logo is damaged.');
        $width=500;$height=max(1,(int)round($size[1]*$width/$size[0]));
        $image=imagecreatetruecolor($width,$height);
        imagealphablending($image,false);imagesavealpha($image,true);
        imagefill($image,0,0,imagecolorallocatealpha($image,0,0,0,127));
        imagecopyresampled($image,$source,0,0,0,0,$width,$height,$size[0],$size[1]);
        $saved=imagepng($image,$destination,6);
        imagedestroy($image);imagedestroy($source);
        if(!$saved){@unlink($destination);throw new RuntimeException('The logo could not be saved.');}
    }
    return $relative;
}
$photos=[];
try {
    require_once __DIR__.'/image-upload.php';
    $photo=save_profile_logo($_FILES['logo']??[],(int)$tenant);
    if ($photo!==null) $photos['logo_path']=$photo;
    if($photo!==null && $removeLogo==='1') throw new InvalidArgumentException('Choose either a new logo or remove the current one.');
    $background=save_menu_photo($_FILES['menu_background']??[],(int)$tenant,1800,'menu-backgrounds');
    if($background!==null)$photos['menu_background_path']=$background;
    if($background!==null && $removeBackground==='1')throw new InvalidArgumentException('Choose either a new texture or remove the current one.');
    $s=$db->prepare('SELECT logo_path,menu_background_path FROM restaurants WHERE id=?');$s->execute([$tenant]);
    $previous=$s->fetch(PDO::FETCH_ASSOC)?:[];
    $oldLogo=$previous['logo_path']??null;
    $oldBackground=$previous['menu_background_path']??null;
    $logoPath=$photo??($removeLogo==='1'?null:$oldLogo);
    $backgroundPath=$background??($removeBackground==='1'?null:$oldBackground);
    $db->beginTransaction();
    $s=$db->prepare('UPDATE restaurants SET brand_color=?,logo_path=?,menu_layout=?,menu_background_path=? WHERE id=?');
    $s->execute([$themeColors[$theme],$logoPath,(int)$menuLayout,$backgroundPath,$tenant]);
    $logoSetting=$db->prepare('INSERT INTO restaurant_pos_settings(restaurant_id,print_logo_on_receipt,receipt_template,updated_by) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE print_logo_on_receipt=VALUES(print_logo_on_receipt),receipt_template=VALUES(receipt_template),updated_by=VALUES(updated_by)');
    $logoSetting->execute([(int)$tenant,(int)$printLogo,(int)$receiptTemplate,(int)$_SESSION['user']['id']]);
    $db->commit();
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    foreach ($photos as $photo) {
        // Only remove newly generated assets belonging to this failed request.
        $file=dirname(__DIR__).'/public/'.$photo;
        if (is_file($file)) unlink($file);
    }
    if ($error instanceof InvalidArgumentException) order_fail($error->getMessage());
    error_log($error->getMessage());order_fail('Unable to save profile. Please retry.',500);
}
try { cleanup_unused_restaurant_uploads($db,(int)$tenant,array_filter([$oldLogo,$oldBackground])); }
catch(Throwable $error) { error_log('Upload cleanup: '.$error->getMessage()); }
json_out(['ok'=>true,'logo_url'=>$logoPath?base().'/'.ltrim((string)$logoPath,'/').'?v='.time():null,'background_url'=>$backgroundPath?base().'/'.ltrim((string)$backgroundPath,'/').'?v='.time():null]);
