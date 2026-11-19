<?php
declare(strict_types=1);
$pdo = new PDO('mysql:host=127.0.0.1;dbname=restaurant_saas;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$checks = [
 'restaurants' => 'SELECT COUNT(*) FROM restaurants',
 'users' => 'SELECT COUNT(*) FROM users',
 'tables' => 'SELECT COUNT(*) FROM restaurant_tables',
 'menu_items' => 'SELECT COUNT(*) FROM menu_items',
 'active_subscriptions' => "SELECT COUNT(*) FROM subscriptions WHERE status IN ('ACTIVE','TRIAL')",
];
foreach ($checks as $name=>$sql) { $count=(int)$pdo->query($sql)->fetchColumn(); if($count<1) throw new RuntimeException("$name check failed"); echo "PASS $name=$count\n"; }
$hash=$pdo->query("SELECT password_hash FROM users WHERE email='owner@example.com'")->fetchColumn(); if(!password_verify('password',(string)$hash)) throw new RuntimeException('super admin password check failed'); echo "PASS super_admin_password\n";
