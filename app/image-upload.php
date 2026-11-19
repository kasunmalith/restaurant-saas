<?php
declare(strict_types=1);
function save_menu_photo(array $upload, int $restaurantId, int $maxWidth=1200, string $group='menu-items'): ?string {
    if(!in_array($group,['menu-items','menu-categories','menu-backgrounds'],true))throw new InvalidArgumentException('Invalid photo destination.');
    if (($upload['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE) return null;
    $uploadError=$upload['error']??-1;
    if(in_array($uploadError,[UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE],true))throw new InvalidArgumentException('The photo is too large. Choose a photo under 5 MB.');
    if($uploadError===UPLOAD_ERR_PARTIAL)throw new InvalidArgumentException('The photo upload was interrupted. Please try again.');
    if($uploadError!==UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name']??'')) throw new InvalidArgumentException('The photo could not be uploaded. Please try again.');
    $file=$upload['tmp_name'];
    if (filesize($file)>5*1024*1024) throw new InvalidArgumentException('Photos must be 5 MB or smaller.');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file);
    if (!in_array($mime,['image/jpeg','image/png','image/webp'],true)) throw new InvalidArgumentException('Choose a JPEG, PNG or WebP photo.');
    $size=@getimagesize($file);
    if (!$size || $size[0]<1 || $size[1]<1 || $size[0]*$size[1]>16000000) throw new InvalidArgumentException('Photo dimensions exceed the 16 megapixel limit.');
    $source=@imagecreatefromstring(file_get_contents($file));
    if (!$source) throw new InvalidArgumentException('The photo is damaged or unsupported.');
    $scale=min(1,$maxWidth/$size[0]);
    $width=max(1,(int)round($size[0]*$scale));$height=max(1,(int)round($size[1]*$scale));
    $image=imagecreatetruecolor($width,$height);
    imagefill($image,0,0,imagecolorallocate($image,255,255,255));
    imagecopyresampled($image,$source,0,0,0,0,$width,$height,$size[0],$size[1]);
    $relative='uploads/'.$group.'/'.$restaurantId.'/'.bin2hex(random_bytes(16)).'.jpg';
    $path=dirname(__DIR__).'/public/'.$relative;
    if (!is_dir(dirname($path)) && !mkdir(dirname($path),0755,true)) throw new RuntimeException('Photo storage is unavailable.');
    if (!imagejpeg($image,$path,85)) throw new RuntimeException('Photo could not be saved.');
    imagedestroy($source);imagedestroy($image);
    return $relative;
}

// Only delete generated files inside this restaurant's managed upload folders.
// A grace period protects uploads in progress; replaced files can be cleaned immediately.
function cleanup_unused_restaurant_uploads(PDO $db,int $restaurantId,array $replaced=[]):int {
    $used=[];
    $query=$db->prepare('SELECT image_path FROM menu_items WHERE restaurant_id=? AND image_path IS NOT NULL');
    $query->execute([$restaurantId]);
    foreach($query->fetchAll(PDO::FETCH_COLUMN) as $path) $used[(string)$path]=true;
    $query=$db->prepare('SELECT image_path,bottom_image_path FROM menu_categories WHERE restaurant_id=?');
    $query->execute([$restaurantId]);
    foreach($query as $category) foreach($category as $path) if($path) $used[(string)$path]=true;
    $query=$db->prepare('SELECT logo_path,banner_path,menu_background_path FROM restaurants WHERE id=?');
    $query->execute([$restaurantId]);
    $restaurant=$query->fetch(PDO::FETCH_ASSOC)?:[];
    foreach(['logo_path','banner_path'] as $field) if(!empty($restaurant[$field])) $used[(string)$restaurant[$field]]=true;
    if(!empty($restaurant['menu_background_path']))$used[(string)$restaurant['menu_background_path']]=true;
    $public=realpath(dirname(__DIR__).'/public');
    if($public===false) return 0;
    $uploadRoot=realpath($public.'/uploads');
    if($uploadRoot===false || dirname($uploadRoot)!==$public) return 0;
    $removed=0;
    foreach(['menu-items','menu-categories','restaurant-logos','menu-backgrounds'] as $group){
        $groupRoot=realpath($uploadRoot.'/'.$group);
        if($groupRoot===false || dirname($groupRoot)!==$uploadRoot) continue;
        $resolved=realpath($groupRoot.'/'.$restaurantId);
        if($resolved===false || dirname($resolved)!==$groupRoot || !is_dir($resolved)) continue;
        foreach(new DirectoryIterator($resolved) as $entry){
            if(!$entry->isFile() || $entry->isLink() || !preg_match('/^[a-f0-9]{32}\.(?:jpg|png|webp)$/D',$entry->getFilename())) continue;
            $relative='uploads/'.$group.'/'.$restaurantId.'/'.$entry->getFilename();
            if(isset($used[$relative])) continue;
            if(!in_array($relative,$replaced,true) && $entry->getMTime()>time()-3600) continue;
            if(@unlink($entry->getPathname())) $removed++;
        }
    }
    $legacy='uploads/qr-logos/restaurant-'.$restaurantId.'.jpg';
    $qrRoot=realpath($uploadRoot.'/qr-logos');
    if($qrRoot!==false && dirname($qrRoot)===$uploadRoot){
        $legacyFile=$qrRoot.'/restaurant-'.$restaurantId.'.jpg';
        $currentFile=$qrRoot.'/restaurant-'.$restaurantId.'.png';
        if(is_file($currentFile) && is_file($legacyFile) && !is_link($legacyFile) && !isset($used[$legacy]) && filemtime($legacyFile)<time()-3600 && @unlink($legacyFile)) $removed++;
    }
    return $removed;
}

function delete_restaurant_uploads(int $restaurantId):int {
    if($restaurantId<1) return 0;
    $public=realpath(dirname(__DIR__).'/public');
    if($public===false) return 0;
    $uploadRoot=realpath($public.'/uploads');
    if($uploadRoot===false || dirname($uploadRoot)!==$public) return 0;
    $removed=0;
    foreach(['menu-items','menu-categories','restaurant-logos','menu-backgrounds'] as $group){
        $groupRoot=realpath($uploadRoot.'/'.$group);
        if($groupRoot===false || dirname($groupRoot)!==$uploadRoot) continue;
        $restaurantRoot=realpath($groupRoot.'/'.$restaurantId);
        if($restaurantRoot===false || dirname($restaurantRoot)!==$groupRoot || !is_dir($restaurantRoot)) continue;
        foreach(new DirectoryIterator($restaurantRoot) as $entry){
            if(!$entry->isFile() || $entry->isLink() || !preg_match('/^[a-f0-9]{32}\.(?:jpg|png|webp)$/D',$entry->getFilename())) continue;
            if(@unlink($entry->getPathname()))$removed++;
        }
        @rmdir($restaurantRoot);
    }
    $qrRoot=realpath($uploadRoot.'/qr-logos');
    if($qrRoot!==false && dirname($qrRoot)===$uploadRoot){
        foreach(['png','jpg'] as $extension){
            $qrFile=$qrRoot.'/restaurant-'.$restaurantId.'.'.$extension;
            if(is_file($qrFile) && !is_link($qrFile) && @unlink($qrFile))$removed++;
        }
    }
    return $removed;
}
