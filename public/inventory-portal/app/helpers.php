<?php
declare(strict_types=1);

function inv_e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function inv_base_path(): string
{
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/inventory-portal/index.php'));
    $path = rtrim(dirname($script), '/');
    $path = preg_replace('#/public/inventory-portal$#', '/inventory-portal', $path) ?? $path;
    return $path === '.' ? '' : $path;
}

function inv_route(string $page = 'dashboard'): string
{
    return inv_base_path() . '/index.php?page=' . rawurlencode($page);
}

function inv_asset(string $path): string
{
    return inv_base_path() . '/' . ltrim($path, '/');
}

function inv_redirect(string $page = 'dashboard'): never
{
    header('Location: ' . inv_route($page), true, 303);
    exit;
}

function inv_csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf'];
}

function inv_require_csrf(): void
{
    $provided = (string)($_POST['csrf'] ?? '');
    $expected = (string)($_SESSION['csrf'] ?? '');
    if ($provided === '' || $expected === '' || !hash_equals($expected, $provided)) {
        http_response_code(403);
        throw new RuntimeException('Your security token expired. Refresh the page and try again.');
    }
}

function inv_flash(?string $message = null, string $type = 'success'): ?array
{
    if ($message !== null) {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
        return null;
    }
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

function inv_money(mixed $amount): string
{
    return 'LKR ' . number_format((float)$amount, 2, '.', ',');
}

function inv_decimal(mixed $amount, int $places = 3): string
{
    return number_format((float)$amount, $places, '.', ',');
}

function inv_positive_decimal(mixed $value, string $label, bool $allowZero = false): float
{
    if (!is_numeric($value)) {
        throw new InvalidArgumentException($label . ' must be a number.');
    }
    $number = (float)$value;
    if (!is_finite($number) || ($allowZero ? $number < 0 : $number <= 0)) {
        throw new InvalidArgumentException($label . ($allowZero ? ' cannot be negative.' : ' must be greater than zero.'));
    }
    return $number;
}

function inv_now(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Colombo')))->format('Y-m-d H:i:s');
}
