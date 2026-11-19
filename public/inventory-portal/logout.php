<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}
try {
    inv_require_csrf();
} catch (RuntimeException $exception) {
    http_response_code(403);
    exit('Security token invalid. Return to the portal and try again.');
}
inventory_forget_session();
header('Location: ' . inv_base_path() . '/login.php', true, 303);
exit;
