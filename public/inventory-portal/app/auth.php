<?php
declare(strict_types=1);

function inventory_login(PDO $db, string $email, string $password): bool
{
    $email = mb_strtolower(trim($email));
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $bucket = hash('sha256', $email . "\n" . $ip);

    $attempts = $db->prepare('SELECT attempts, (window_started >= UTC_TIMESTAMP() - INTERVAL 15 MINUTE) AS in_window FROM inventory_login_attempts WHERE bucket_hash = ?');
    $attempts->execute([$bucket]);
    $attempt = $attempts->fetch();
    if ($attempt && (int)$attempt['attempts'] >= 10 && (int)$attempt['in_window'] === 1) {
        return false;
    }

    $query = $db->prepare(
        'SELECT u.id, u.restaurant_id, s.password_hash AS portal_password_hash, a.portal_role
         FROM users u
         INNER JOIN inventory_portal_access a
           ON a.user_id = u.id AND a.restaurant_id = u.restaurant_id AND a.is_active = 1
         INNER JOIN restaurants r ON r.id = u.restaurant_id AND r.is_active = 1
         INNER JOIN inventory_portal_settings s
           ON s.restaurant_id = r.id AND s.is_enabled = 1 AND s.password_hash IS NOT NULL
         WHERE u.email = ? AND u.is_active = 1
         LIMIT 1'
    );
    $query->execute([$email]);
    $row = $query->fetch();
    if (!$row || !password_verify($password, (string)$row['portal_password_hash'])) {
        $db->prepare(
            'INSERT INTO inventory_login_attempts (bucket_hash, attempts, window_started)
             VALUES (?, 1, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
               attempts = IF(window_started < UTC_TIMESTAMP() - INTERVAL 15 MINUTE, 1, attempts + 1),
               window_started = IF(window_started < UTC_TIMESTAMP() - INTERVAL 15 MINUTE, UTC_TIMESTAMP(), window_started)'
        )->execute([$bucket]);
        return false;
    }

    $db->prepare('DELETE FROM inventory_login_attempts WHERE bucket_hash = ?')->execute([$bucket]);
    session_regenerate_id(true);
    $_SESSION = [];
    $_SESSION['user_id'] = (int)$row['id'];
    $_SESSION['restaurant_id'] = (int)$row['restaurant_id'];
    $_SESSION['portal_role'] = (string)$row['portal_role'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return true;
}

function inventory_context(PDO $db): ?array
{
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $restaurantId = (int)($_SESSION['restaurant_id'] ?? 0);
    $role = (string)($_SESSION['portal_role'] ?? '');
    if ($userId < 1 || $restaurantId < 1 || $role === '') {
        return null;
    }

    $query = $db->prepare(
        'SELECT u.id AS user_id, u.name AS user_name, u.email, r.id AS restaurant_id,
                r.name AS restaurant_name, r.timezone, a.portal_role
         FROM users u
         INNER JOIN restaurants r ON r.id = u.restaurant_id
         INNER JOIN inventory_portal_access a
           ON a.user_id = u.id AND a.restaurant_id = r.id AND a.is_active = 1
         INNER JOIN inventory_portal_settings s
           ON s.restaurant_id = r.id AND s.is_enabled = 1 AND s.password_hash IS NOT NULL
         WHERE u.id = ? AND r.id = ? AND u.is_active = 1 AND r.is_active = 1 AND a.portal_role = ?
         LIMIT 1'
    );
    $query->execute([$userId, $restaurantId, $role]);
    $context = $query->fetch();
    if (!$context) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return null;
    }
    return $context;
}

function inventory_forget_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'secure' => (bool)$params['secure'],
            'httponly' => (bool)$params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}
