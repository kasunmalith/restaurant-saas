<?php
declare(strict_types=1);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $_GET['page'] = 'login';
}
require __DIR__ . '/index.php';
