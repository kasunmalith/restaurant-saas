<?php
if ($path!=='/api/admin/staff') return;
require_once __DIR__.'/cashier-menu-options.php';
$parseDashboardMenuPermissions=static function():string{$allowed=[];foreach(cashier_dashboard_menu_options() as $items)$allowed=array_merge($allowed,array_keys($items));$submitted=$_POST['cashier_dashboard_menu_permissions']??[];if(!is_array($submitted)||count($submitted)>count($allowed))order_fail('Invalid cashier dashboard permissions.');$selected=[];foreach($submitted as $key){if(!is_string($key)||!in_array($key,$allowed,true))order_fail('Invalid cashier dashboard permissions.');$selected[]=$key;}return json_encode(array_values(array_unique($selected)),JSON_THROW_ON_ERROR);};
$s=$db->prepare("SELECT restaurant_id FROM users WHERE id=? AND is_active=1 AND role='RESTAURANT_ADMIN'");
$s->execute([$_SESSION['user']['id']??0]);$tenant=$s->fetchColumn();
if (!$tenant) order_fail('Restaurant administrator access required.',403);
if ($_SERVER['REQUEST_METHOD']!=='POST') order_fail('POST required.',405);
if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))) order_fail('Refresh your security token.',403);
$id=(int)($_POST['id']??0);
if ($id) {
    if (($_POST['action']??'')==='update_login') {
        $email=trim((string)($_POST['email']??''));$password=(string)($_POST['new_password']??'');$confirm=(string)($_POST['confirm_password']??'');
        if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>190) order_fail('Enter a valid cashier login email.');
        if ($password!=='' && (strlen($password)<5 || strlen($password)>72)) order_fail('Use a password between 5 and 72 bytes.');
        if ($password!==$confirm) order_fail('New password and confirmation do not match.');
        $menuItemAddEnabled=(string)($_POST['menu_item_add_enabled']??'0');$menuItemEditEnabled=(string)($_POST['menu_item_edit_enabled']??'0');$menuItemDeleteEnabled=(string)($_POST['menu_item_delete_enabled']??'0');$categoryAddEnabled=(string)($_POST['category_add_enabled']??'0');$categoryDeleteEnabled=(string)($_POST['category_delete_enabled']??'0');$categoryRenameEnabled=(string)($_POST['category_rename_enabled']??'0');
        if(!in_array($menuItemAddEnabled,['0','1'],true)||!in_array($menuItemEditEnabled,['0','1'],true)||!in_array($menuItemDeleteEnabled,['0','1'],true)||!in_array($categoryAddEnabled,['0','1'],true)||!in_array($categoryDeleteEnabled,['0','1'],true)||!in_array($categoryRenameEnabled,['0','1'],true))order_fail('Invalid cashier permissions.');
        $s=$db->prepare("SELECT id,cashier_dashboard_menu_permissions FROM users WHERE id=? AND restaurant_id=? AND role='CASHIER'");$s->execute([$id,$tenant]);$existingCashier=$s->fetch();if(!$existingCashier)order_fail('Cashier account not found.',404);$dashboardMenuPermissions=isset($_POST['cashier_dashboard_menu_permissions_present'])?$parseDashboardMenuPermissions():$existingCashier['cashier_dashboard_menu_permissions'];
        try {if($password!==''){$s=$db->prepare("UPDATE users SET email=?,password_hash=?,menu_item_add_enabled=?,menu_item_edit_enabled=?,menu_item_delete_enabled=?,category_add_enabled=?,category_delete_enabled=?,category_rename_enabled=?,cashier_dashboard_menu_permissions=? WHERE id=? AND restaurant_id=? AND role='CASHIER'");$s->execute([$email,password_hash($password,PASSWORD_DEFAULT),(int)$menuItemAddEnabled,(int)$menuItemEditEnabled,(int)$menuItemDeleteEnabled,(int)$categoryAddEnabled,(int)$categoryDeleteEnabled,(int)$categoryRenameEnabled,$dashboardMenuPermissions,$id,$tenant]);}else{$s=$db->prepare("UPDATE users SET email=?,menu_item_add_enabled=?,menu_item_edit_enabled=?,menu_item_delete_enabled=?,category_add_enabled=?,category_delete_enabled=?,category_rename_enabled=?,cashier_dashboard_menu_permissions=? WHERE id=? AND restaurant_id=? AND role='CASHIER'");$s->execute([$email,(int)$menuItemAddEnabled,(int)$menuItemEditEnabled,(int)$menuItemDeleteEnabled,(int)$categoryAddEnabled,(int)$categoryDeleteEnabled,(int)$categoryRenameEnabled,$dashboardMenuPermissions,$id,$tenant]);}}
        catch(PDOException $error){if($error->getCode()==='23000')order_fail('This email is already registered.',409);throw $error;}
        json_out(['ok'=>true,'email'=>$email,'menu_item_add_enabled'=>(int)$menuItemAddEnabled,'menu_item_edit_enabled'=>(int)$menuItemEditEnabled,'menu_item_delete_enabled'=>(int)$menuItemDeleteEnabled,'category_add_enabled'=>(int)$categoryAddEnabled,'category_delete_enabled'=>(int)$categoryDeleteEnabled,'category_rename_enabled'=>(int)$categoryRenameEnabled,'cashier_dashboard_menu_permissions'=>json_decode((string)$dashboardMenuPermissions,true)?:[]]);
    }
    if (!in_array((string)($_POST['active']??''),['0','1'],true)) order_fail('Invalid account status.');
    $s=$db->prepare("UPDATE users SET is_active=? WHERE id=? AND restaurant_id=? AND role='CASHIER'");
    $s->execute([$_POST['active'],$id,$tenant]);
    if (!$s->rowCount()) order_fail('Account not found or already in that state.',409);
    json_out(['ok'=>true]);
}
$name=trim((string)($_POST['name']??''));$email=trim((string)($_POST['email']??''));$password=(string)($_POST['password']??'');
if ($name==='' || mb_strlen($name)>120 || strlen($email)>190 || !filter_var($email,FILTER_VALIDATE_EMAIL)) order_fail('Enter a valid name and email.');
if (strlen($password)<5 || strlen($password)>72) order_fail('Use a password between 5 and 72 bytes.');
try {
    $dashboardMenuPermissions=isset($_POST['cashier_dashboard_menu_permissions_present'])?$parseDashboardMenuPermissions():null;
    $s=$db->prepare("INSERT INTO users(restaurant_id,role,name,email,password_hash,cashier_dashboard_menu_permissions) VALUES(?,'CASHIER',?,?,?,?)");
    $s->execute([$tenant,$name,$email,password_hash($password,PASSWORD_DEFAULT),$dashboardMenuPermissions]);
} catch (PDOException $error) {
    if ($error->getCode()==='23000') order_fail('This email is already registered.',409);
    throw $error;
}
json_out(['ok'=>true]);
