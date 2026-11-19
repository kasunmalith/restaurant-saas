<?php
declare(strict_types=1);
$appUrl='';
foreach (file(dirname(__DIR__).'/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (str_starts_with(trim($line), 'APP_URL=')) {
        $appUrl=trim(substr($line, strpos($line, '=') + 1), ' "');
        break;
    }
}
$appPath=rtrim((string)(parse_url($appUrl, PHP_URL_PATH) ?: dirname(dirname($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
$requestPath=(string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$relativePath=$appPath !== '' && str_starts_with($requestPath, $appPath.'/') ? substr($requestPath, strlen($appPath)) : $requestPath;
$activePortal=null;
if (preg_match('#^/(s-admin|r-owner|cashier)(?:/|$)#', $relativePath, $portalMatch)) {
    $activePortal=$portalMatch[1];
    session_name('EE_'.strtoupper(str_replace('-', '_', $activePortal)));
}
function active_portal_role(): ?string {
    return match ($GLOBALS['activePortal'] ?? null) {
        's-admin' => 'SUPER_ADMIN',
        'r-owner' => 'RESTAURANT_ADMIN',
        'cashier' => 'CASHIER',
        default => null,
    };
}
ini_set('session.use_strict_mode','1');
ini_set('session.use_only_cookies','1');
session_set_cookie_params([
    'httponly'=>true,
    'secure'=>isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off',
    'samesite'=>'Lax',
    'path'=>$activePortal !== null ? ($appPath.'/'.$activePortal) : ($appPath ?: '/'),
]);
session_start();
require_once __DIR__ . '/asset-version.php';
ob_start('version_static_asset_urls');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: no-store');
