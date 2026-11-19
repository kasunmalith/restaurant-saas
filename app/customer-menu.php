<?php
declare(strict_types=1);

function render_customer_menu(array $restaurant,string $slug,string $hashCode,bool $onlineOrder=false): never
{
    global $db;
    $categories=$db->prepare('SELECT * FROM menu_categories WHERE restaurant_id=? ORDER BY sort_order,id');
    $categories->execute([$restaurant['id']]);
    $categories=$categories->fetchAll();
    $items=$db->prepare('SELECT * FROM menu_items WHERE restaurant_id=? AND is_active=1 ORDER BY category_id,id');
    $items->execute([$restaurant['id']]);
    $items=$items->fetchAll();require_once __DIR__.'/menu-options.php';menu_attach_options($db,(int)$restaurant['id'],$items);
    $groups=[];
    foreach($items as $item) $groups[$item['category_id']][]=$item;
    $categoryLayout=(int)($restaurant['menu_layout']??1)===2;
    $categoryItemCounts=[];
    if($categoryLayout){
        $itemCounts=$db->prepare('SELECT category_id,COUNT(*) count FROM menu_items WHERE restaurant_id=? GROUP BY category_id');
        $itemCounts->execute([$restaurant['id']]);
        foreach($itemCounts as $row)$categoryItemCounts[(int)$row['category_id']]=(int)$row['count'];
    }

    // The former brand-color field now stores the restaurant-controlled menu theme.
    $theme=['#285040'=>'dim','#071d15'=>'dark'][strtolower((string)($restaurant['brand_color']??''))]??'light';
    $logoPath=(string)($restaurant['logo_path']??'');
    $logo=$logoPath!==''?'<img class="customer-restaurant-logo" src="'.e(base().'/'.ltrim($logoPath,'/')).'" alt="'.e($restaurant['name']).' logo">':'';
    $badge=$onlineOrder?'<div class="customer-table-badge customer-online-badge"><span>ORDER ONLINE</span><strong>Menu</strong></div>':'<div class="customer-table-badge" aria-label="Table '.e($restaurant['table_number']).'"><span>TABLE</span><strong>'.e($restaurant['table_number']).'</strong></div>';
    $accountButton=$onlineOrder?'<button type="button" id="online-account-toggle" class="customer-account-launcher" aria-label="Open customer account" title="Customer account"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4.5 21a7.5 7.5 0 0 1 15 0"/></svg></button>':'';
    $html='<script>document.documentElement.dataset.customerTheme='.json_encode($theme).';</script><header class="hero"><div class="customer-hero-inner"><div class="customer-hero-brand">'.$logo.'<h1 class="visually-hidden">'.e($restaurant['name']).'</h1><p id="ordering-status" role="status"></p></div>'.$badge.$accountButton.'</div></header><main class="menu shell"><nav class="chips">';
    foreach($categories as $category) $html.='<a href="#cat'.$category['id'].'">'.e($category['name']).'</a>';
    $html.='</nav><input class="search" id="search" placeholder="Search the menu...">';
    if($categoryLayout){
        $texture=(string)($restaurant['menu_background_path']??'');
        $textureCss=$texture!==''?'<style>html[data-menu-layout="2"] body.customer-menu-page{background-image:linear-gradient(#0c100c66,#0c100c66),url('.json_encode(base().'/'.ltrim($texture,'/'),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES).');background-size:cover;background-position:center;background-attachment:fixed}</style>':'';
        $html='<link rel="stylesheet" href="'.e(base()).'/assets/menu-layout-two.css?v=12">'.$textureCss.'<script>document.documentElement.dataset.menuLayout="2";</script>'.$html;
    }
    $categoryPhotoNumber=0;
    foreach($categories as $category){
        $mainPhotoSide='';
        if($categoryLayout && !empty($category['image_path'])){$categoryPhotoNumber++;$mainPhotoSide=$categoryPhotoNumber%2?'left':'right';}
        $html.='<section id="cat'.$category['id'].'"'.($mainPhotoSide?' class="category-photo-'.$mainPhotoSide.'"':'').'><h2>'.e($category['name']).'</h2>';
        if($mainPhotoSide)$html.='<img class="category-main-photo photo-'.$mainPhotoSide.'" src="'.e(base().'/'.$category['image_path']).'" alt="'.e($category['name']).'" loading="lazy">';
        $html.='<div class="grid">';
        foreach($groups[$category['id']]??[] as $item){
            $sizes=$item['options'];
            if(!$sizes&&(int)$item['is_portioned']===1)$sizes=[['name'=>'Full','portion'=>'FULL','price'=>$item['full_price']],['name'=>'Half','portion'=>'HALF','price'=>$item['half_price']]];
            if(count($sizes)>=3){
                $photo=$item['image_path']?'<img src="'.e(base().'/'.ltrim($item['image_path'],'/')).'" alt="'.e($item['name']).'" loading="lazy">':'🍽️';
                $html.='<article class="food food-has-options" data-name="'.e(strtolower($item['name'])).'"><div class="food-img">'.$photo.'</div><div class="food-body"><h3>'.e($item['name']).'</h3><p>'.e($item['description']).'</p></div><div class="food-options"><div class="food-options-heading"><strong>Select Size / Portion</strong><span>Choose your preferred portion size</span></div><div class="food-portion-options">';
                foreach($sizes as $size){
                    $portion=$size['portion']??$size['name'];$price=$size['price'];
                    $html.='<div class="food-portion"><span class="food-portion-thumb" aria-hidden="true">'.$photo.'</span><span class="food-portion-details"><strong class="food-portion-label">'.e($size['name']).'</strong></span><strong class="food-portion-price">LKR '.number_format((float)$price,2).'</strong><button class="add" type="button" data-id="'.$item['id'].'" data-portion="'.e($portion).'" data-price="'.e($price).'" data-name="'.e($item['name']).'" '.(!$item['is_available']||$onlineOrder?'disabled':'').'>'.($item['is_available']?'Add +':'Unavailable').'</button></div>';
                }
                $html.='</div></div></article>';
                continue;
            }
            $html.='<article class="food" data-name="'.e(strtolower($item['name'])).'">'.($categoryLayout?'':'<div class="food-img">'.($item['image_path']?'<img src="'.base().'/'.e($item['image_path']).'">':'🍽️').'</div>').'<div><h3>'.e($item['name']).'</h3><p>'.e($item['description']).'</p>';
            if($categoryLayout){
                if($sizes){
                    $html.='<span class="theme-two-price">From '.number_format((float)min(array_column($sizes,'price')),0).'</span><button class="add theme-two-add" type="button" data-size-picker="1" data-options="'.e(json_encode(array_map(static fn($size)=>['name'=>$size['name'],'portion'=>$size['portion']??$size['name'],'price'=>$size['price']],$sizes),JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_HEX_TAG)).'" data-id="'.$item['id'].'" data-name="'.e($item['name']).'" aria-label="Choose size for '.e($item['name']).'" '.(!$item['is_available']||$onlineOrder?'disabled':'').'>+</button>';
                }else{
                    $html.='<span class="theme-two-price">'.number_format((float)$item['price'],0).'</span><button class="add theme-two-add" type="button" data-id="'.$item['id'].'" data-price="'.e($item['price']).'" data-name="'.e($item['name']).'" aria-label="Add '.e($item['name']).'" '.(!$item['is_available']||$onlineOrder?'disabled':'').'>+</button>';
                }
            }elseif($sizes){
                $html.='<div class="food-portion-options">';
                foreach($sizes as $size){
                    $portion=$size['portion']??$size['name'];$price=$size['price'];
                    $html.='<div class="food-portion"><span class="food-portion-label">'.e($size['name']).'</span><strong>LKR '.number_format((float)$price,2).'</strong><button class="add" type="button" data-id="'.$item['id'].'" data-portion="'.e($portion).'" data-price="'.e($price).'" data-name="'.e($item['name']).'" '.(!$item['is_available']||$onlineOrder?'disabled':'').'>'.($item['is_available']?'Add +':'Unavailable').'</button></div>';
                }
                $html.='</div>';
            }else{
                $html.='<strong>LKR '.number_format((float)$item['price'],2).'</strong><button class="add" type="button" data-id="'.$item['id'].'" data-price="'.e($item['price']).'" data-name="'.e($item['name']).'" '.(!$item['is_available']||$onlineOrder?'disabled':'').'>'.($item['is_available']?'Add +':'Unavailable').'</button>';
            }
            $html.='</div></article>';
        }
        $html.='</div>';
        if($categoryLayout && ($categoryItemCounts[(int)$category['id']]??0)>4 && !empty($category['bottom_image_path'])){$categoryPhotoNumber++;$html.='<img class="category-bottom-photo photo-'.($categoryPhotoNumber%2?'left':'right').'" src="'.e(base().'/'.$category['bottom_image_path']).'" alt="'.e($category['name']).' selection" loading="lazy">';}
        $html.='</section>';
    }
    $accountPanel=$onlineOrder?'<dialog id="online-account-dialog" class="customer-account-dialog" aria-labelledby="online-account-title"><button type="button" class="customer-account-close" aria-label="Close customer account">×</button><div class="customer-account-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4.5 21a7.5 7.5 0 0 1 15 0"/></svg></div><h2 id="online-account-title">Customer Account</h2><p id="online-account-summary">Sign in or create an account to save your orders and enjoy a faster checkout.</p><div id="online-account-panel"><div class="customer-account-tabs" role="tablist" aria-label="Customer account"><button type="button" id="online-account-tab-login" data-account-tab="login" class="is-active" role="tab" aria-selected="true">Sign In</button><button type="button" id="online-account-tab-register" data-account-tab="register" role="tab" aria-selected="false">Register</button></div><div class="online-account-forms"><form id="online-login-form"><h3 class="visually-hidden">Sign in</h3><label>Phone Number<input name="phone" type="tel" autocomplete="tel" placeholder="077 123 4567" required></label><label>Password<input name="password" type="password" autocomplete="current-password" placeholder="Enter your password" required></label><button type="submit">Sign In</button><button type="button" id="online-account-forgot">Forgot password?</button><p role="status"></p></form><form id="online-register-form" hidden><h3 class="visually-hidden">Create account</h3><label>Full Name<input name="name" maxlength="120" autocomplete="name" placeholder="Enter your full name" required></label><label>Email<input name="email" type="email" maxlength="190" autocomplete="email" placeholder="Enter your email address" required></label><small class="online-registration-email-note">Email is required for password recovery. Email verification is not required.</small><label>Phone Number<input name="phone" type="tel" maxlength="20" autocomplete="tel" placeholder="077 123 4567" required></label><label>Primary Address<input name="address" maxlength="500" autocomplete="street-address" placeholder="Enter your delivery address" required></label><label>Password (8+ characters)<input name="password" type="password" minlength="8" maxlength="200" autocomplete="new-password" placeholder="Create a password" required></label><button type="submit">Create Account</button><p role="status"></p></form><form id="online-forgot-form" hidden><h3>Forgot password</h3><p>Enter the phone number and email saved to your account. If they match, we’ll send a temporary password.</p><label>Phone Number<input name="phone" type="tel" autocomplete="tel" placeholder="077 123 4567" required></label><label>Email<input name="email" type="email" maxlength="190" autocomplete="email" placeholder="Email used for registration" required></label><button type="submit">Send Temporary Password</button><button type="button" data-account-back-login>Back to Sign In</button><p role="status" aria-live="polite"></p></form><form id="online-reset-form" hidden><h3>Set a new password</h3><label>Phone Number<input name="phone" type="tel" autocomplete="tel" required></label><label>10-digit temporary password<input name="temp_password" type="text" inputmode="numeric" pattern="[0-9]{10}" maxlength="10" autocomplete="one-time-code" required></label><label>New password (8+ characters)<input name="password" type="password" minlength="8" maxlength="200" autocomplete="new-password" required></label><label>Confirm new password<input name="confirm_password" type="password" minlength="8" maxlength="200" autocomplete="new-password" required></label><button type="submit">Update Password</button><button type="button" data-account-back-login>Back to Sign In</button><p role="status" aria-live="polite"></p></form></div><div id="online-account-history" aria-live="polite"></div></div></dialog>':'';
    $html.='</main>'.$accountPanel.'<button class="sticky" id="view"'.($onlineOrder?' disabled':'').'>View Order <span>0 items · LKR 0.00</span></button><script>window.APP='.json_encode(['base'=>base(),'restaurant_id'=>$restaurant['id'],'table_id'=>$restaurant['table_id'],'slug'=>$slug,'token'=>$hashCode,'csrf'=>csrf(),'service_charge_percent'=>$restaurant['service_charge_percent']??'10.00','delivery_minimum'=>$restaurant['delivery_minimum']??'1000.00','order_mode'=>$onlineOrder?'ONLINE':'TABLE']).';</script><script src="'.base().'/assets/menu.js?v=16"></script>';
    layout($restaurant['name'],$html);
}
