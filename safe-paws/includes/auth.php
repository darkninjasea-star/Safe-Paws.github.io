<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

function current_user(): ?array
{
    static $loaded = false;
    static $user = null;
    if ($loaded) {
        return $user;
    }
    if (empty($_SESSION['user_id'])) {
        $loaded = true;
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? AND status = "active"');
    $stmt->execute([(int) $_SESSION['user_id']]);
    $user = $stmt->fetch() ?: null;
    $loaded = true;
    return $user;
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
    }
    session_destroy();
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        flash('Please sign in to continue.', 'warning');
        redirect('login.php');
    }
    return $user;
}

function require_role(string ...$roles): array
{
    $user = require_login();
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        exit('Access denied.');
    }
    return $user;
}

function login_attempt_key(string $email): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return hash('sha256', strtolower(trim($email)) . '|' . $ip);
}

function login_lock_seconds(string $attemptKey): int
{
    $stmt = db()->prepare(
        'SELECT COALESCE(GREATEST(0,TIMESTAMPDIFF(SECOND,CURRENT_TIMESTAMP,blocked_until)),0)
         FROM login_attempts WHERE attempt_key=?'
    );
    $stmt->execute([$attemptKey]);
    return max(0, (int) $stmt->fetchColumn());
}

function record_login_failure(string $attemptKey): void
{
    db()->prepare(
        'INSERT INTO login_attempts (attempt_key,attempt_count,last_attempt,blocked_until)
         VALUES (?,1,CURRENT_TIMESTAMP,NULL)
         ON DUPLICATE KEY UPDATE
           blocked_until=IF(last_attempt < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE), NULL,
             IF(attempt_count + 1 >= 5, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE), blocked_until)),
           attempt_count=IF(last_attempt < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE), 1, attempt_count + 1),
           last_attempt=CURRENT_TIMESTAMP'
    )->execute([$attemptKey]);
}

function clear_login_attempts(string $attemptKey): void
{
    db()->prepare('DELETE FROM login_attempts WHERE attempt_key=?')->execute([$attemptKey]);
}
