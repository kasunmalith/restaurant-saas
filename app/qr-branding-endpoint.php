<?php
declare(strict_types=1);
if ($path!=='/api/admin/qr-logo') return;
$user=$_SESSION['user']??null;
if (!$user || !in_array($user['role'],['RESTAURANT_ADMIN','CASHIER'],true)) order_fail('Restaurant staff access required.',403);
$check=$db->prepare("SELECT restaurant_id FROM users WHERE id=? AND role IN ('RESTAURANT_ADMIN','CASHIER') AND is_active=1");
$check->execute([$user['id']]);
$restaurantId=(int)$check->fetchColumn();
if (!$restaurantId) order_fail('Your account is inactive.',403);
if ($_SERVER['REQUEST_METHOD']!=='POST') order_fail('POST required.',405);
if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))) order_fail('Refresh your security token.',403);
$staging=null;$backup=null;$target=null;
try {
    $upload=$_FILES['logo']??[];
    $uploadError=(int)($upload['error']??UPLOAD_ERR_NO_FILE);
    if ($uploadError===UPLOAD_ERR_NO_FILE) throw new InvalidArgumentException('Choose a logo image to upload.');
    if ($uploadError!==UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name']??'')) throw new InvalidArgumentException('The logo could not be uploaded.');
    $temporaryPath=$upload['tmp_name'];
    if (filesize($temporaryPath)>5*1024*1024) throw new InvalidArgumentException('The logo must be 5 MB or smaller.');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
    if (!in_array($mime,['image/png','image/jpeg','image/webp'],true)) throw new InvalidArgumentException('Choose a PNG, JPEG or WebP logo.');
    $dimensions=@getimagesize($temporaryPath);
    if (!$dimensions || $dimensions[0]<1 || $dimensions[1]<1 || $dimensions[0]*$dimensions[1]>16000000) throw new InvalidArgumentException('The logo dimensions exceed the 16 megapixel limit.');
    $source=@imagecreatefromstring(file_get_contents($temporaryPath));
    if (!$source) throw new InvalidArgumentException('The logo image is damaged or unsupported.');
    $scale=min(1,1200/max($dimensions[0],$dimensions[1]));
    $width=max(1,(int)round($dimensions[0]*$scale));
    $height=max(1,(int)round($dimensions[1]*$scale));
    $image=imagecreatetruecolor($width,$height);
    imagealphablending($image,false);
    imagesavealpha($image,true);
    imagefill($image,0,0,imagecolorallocatealpha($image,0,0,0,127));
    imagecopyresampled($image,$source,0,0,0,0,$width,$height,$dimensions[0],$dimensions[1]);
    imagedestroy($source);
    $directory=dirname(__DIR__).'/public/uploads/qr-logos';
    if (!is_dir($directory) && !mkdir($directory,0755,true)) throw new RuntimeException('QR logo storage is unavailable.');
    $target=$directory.'/restaurant-'.$restaurantId.'.png';
    $staging=$target.'.new';
    $backup=$target.'.previous';
    if (!imagepng($image,$staging,6)) throw new RuntimeException('The QR logo could not be staged.');
    imagedestroy($image);
    if (is_file($target)) {
        if (is_file($backup) && !unlink($backup)) throw new RuntimeException('Old QR logo backup could not be cleared.');
        if (!rename($target,$backup)) throw new RuntimeException('Existing QR logo could not be preserved.');
    }
    if (!rename($staging,$target)) {
        if (is_file($backup)) rename($backup,$target);
        throw new RuntimeException('The QR logo could not be saved.');
    }
    if (is_file($backup)) unlink($backup);
    require_once __DIR__.'/image-upload.php';
    try { cleanup_unused_restaurant_uploads($db,$restaurantId); }
    catch(Throwable $cleanupError) { error_log('Upload cleanup: '.$cleanupError->getMessage()); }
    json_out(['ok'=>true,'path'=>'uploads/qr-logos/restaurant-'.$restaurantId.'.png']);
} catch (Throwable $error) {
    if ($backup!==null && is_file($backup) && $target!==null && !is_file($target)) @rename($backup,$target);
    if ($staging!==null && is_file($staging)) @unlink($staging);
    if ($error instanceof InvalidArgumentException) order_fail($error->getMessage());
    error_log($error->getMessage());
    order_fail('Unable to save the QR card logo. Please retry.',500);
}
