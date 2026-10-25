<?php

declare(strict_types=1);

/**
 * 管理员认证、CSRF 与一次性操作令牌。
 */

require_once __DIR__ . '/http.php';

/** 登录失败锁定阈值与时长（秒） */
const ADMIN_MAX_ATTEMPTS = 5;
const ADMIN_LOCK_SECONDS = 300;

/**
 * 当前登录管理员（未登录返回 null）。
 *
 * @return array<string, mixed>|null
 */
function admin_current_user(): ?array
{
    if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }
    if (empty($_SESSION['admin']) || !is_array($_SESSION['admin'])) {
        return null;
    }

    return $_SESSION['admin'];
}

/**
 * API 场景：未登录直接返回 401。
 *
 * @return array<string, mixed>
 */
function admin_require_login_api(): array
{
    $user = admin_current_user();
    if ($user === null) {
        throw new AppError('请先登录管理员后台', 401);
    }

    return $user;
}

/**
 * 页面场景：未登录跳转到登录页。
 *
 * @return array<string, mixed>
 */
function admin_require_login_page(): array
{
    $user = admin_current_user();
    if ($user === null) {
        header('Location: /admin/login.php');
        exit;
    }

    return $user;
}

/**
 * 生成 / 读取 CSRF Token。
 */
function csrf_token(): string
{
    app_session_start();
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * 校验 CSRF Token（请求头 X-CSRF-Token 或表单字段 _csrf）。
 */
function csrf_verify(?string $token): bool
{
    app_session_start();
    $expected = $_SESSION['csrf_token'] ?? '';

    return is_string($expected) && $expected !== '' && is_string($token) && hash_equals($expected, $token);
}

/**
 * API 场景：CSRF 校验失败返回 403。
 *
 * @param array<string, mixed> $body
 */
function require_csrf_api(array $body): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($body['_csrf'] ?? null);
    if (!csrf_verify(is_string($token) ? $token : null)) {
        throw new AppError('CSRF 校验失败，请刷新页面后重试', 403);
    }
}

/**
 * 登录失败计数与锁定判断。
 */
function admin_login_locked_seconds(): int
{
    app_session_start();
    $lockedUntil = (int) ($_SESSION['login_locked_until'] ?? 0);
    $remain = $lockedUntil - time();

    return $remain > 0 ? $remain : 0;
}

/**
 * 记录一次登录失败。
 */
function admin_record_login_failure(): void
{
    app_session_start();
    $attempts = (int) ($_SESSION['login_attempts'] ?? 0) + 1;
    $_SESSION['login_attempts'] = $attempts;
    if ($attempts >= ADMIN_MAX_ATTEMPTS) {
        $_SESSION['login_attempts'] = 0;
        $_SESSION['login_locked_until'] = time() + ADMIN_LOCK_SECONDS;
    }
}

/**
 * 登录成功后清理失败计数并重置 Session ID。
 *
 * @param array<string, mixed> $admin
 */
function admin_mark_logged_in(array $admin): void
{
    app_session_start();
    session_regenerate_id(true);
    $_SESSION['admin'] = $admin;
    $_SESSION['login_attempts'] = 0;
    unset($_SESSION['login_locked_until']);
    $_SESSION['issued_at'] = time();
}

/**
 * 退出登录。
 */
function admin_logout(): void
{
    app_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
    }
    session_destroy();
}

/**
 * 读取管理员记录（按用户名）。
 *
 * @return array<string, mixed>|null
 */
function admin_find_by_username(string $username): ?array
{
    $table = (string) app_config()['tables']['admin'];
    $stmt = db()->prepare('SELECT id, username, password_hash FROM ' . $table . ' WHERE username = :username LIMIT 1');
    $stmt->execute([':username' => $username]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

/**
 * 校验账号密码。失败统一返回 false，避免暴露账号是否存在。
 */
function admin_attempt_login(string $username, string $password): bool
{
    $row = admin_find_by_username($username);
    if ($row === null) {
        // 执行一次哈希校验，降低通过耗时差异判断用户是否存在
        password_verify($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
        return false;
    }

    if (!password_verify($password, (string) $row['password_hash'])) {
        return false;
    }

    // 需要时自动升级哈希
    if (password_needs_rehash((string) $row['password_hash'], PASSWORD_DEFAULT)) {
        $table = (string) app_config()['tables']['admin'];
        $stmt = db()->prepare('UPDATE ' . $table . ' SET password_hash = :hash, updated_at = NOW() WHERE id = :id');
        $stmt->execute([':hash' => password_hash($password, PASSWORD_DEFAULT), ':id' => (int) $row['id']]);
    }

    return true;
}

/**
 * 生成一次性操作令牌（用于“确认后执行”的生成操作，防止重复提交）。
 */
function generate_nonce_issue(): string
{
    app_session_start();
    $nonce = bin2hex(random_bytes(16));
    $_SESSION['generate_nonce'] = $nonce;

    return $nonce;
}

/**
 * 校验并消费一次性操作令牌。
 */
function generate_nonce_consume(?string $nonce): bool
{
    app_session_start();
    $expected = $_SESSION['generate_nonce'] ?? '';
    if (!is_string($expected) || $expected === '' || !is_string($nonce) || !hash_equals($expected, $nonce)) {
        return false;
    }
    unset($_SESSION['generate_nonce']);

    return true;
}
