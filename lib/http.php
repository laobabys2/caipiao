<?php

declare(strict_types=1);

/**
 * 全站引导：配置、时区、错误处理、统一 JSON 响应与 Session。
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/lottery.php';

date_default_timezone_set((string) app_config()['timezone']);

if (PHP_SAPI !== 'cli') {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    header('X-Content-Type-Options: nosniff');
}

/**
 * 业务异常：携带 HTTP 状态码，由 api_run() 统一转换成 JSON 错误响应。
 */
class AppError extends RuntimeException
{
    /** @var int */
    private $status;

    public function __construct(string $message, int $status = 400)
    {
        parent::__construct($message);
        $this->status = $status;
    }

    public function getStatus(): int
    {
        return $this->status;
    }
}

/**
 * 输出 JSON 并结束请求。
 *
 * @param array<string, mixed> $payload
 */
function json_response(int $status, array $payload): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        // 注数实时变化，禁止浏览器/代理缓存
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * 成功响应。
 *
 * @param mixed $data
 */
function json_ok($data, string $message = '查询成功'): void
{
    json_response(200, [
        'success' => true,
        'message' => $message,
        'data'    => $data,
    ]);
}

/**
 * 失败响应。
 */
function fail(string $message, int $status = 400): void
{
    json_response($status, [
        'success' => false,
        'message' => $message,
        'data'    => null,
    ]);
}

/**
 * 统一的 API 执行包装：捕获业务异常与未预期异常，避免向客户端泄漏细节。
 */
function api_run(callable $handler): void
{
    try {
        $handler();
    } catch (AppError $e) {
        fail($e->getMessage(), $e->getStatus());
    } catch (InvalidArgumentException $e) {
        // 号码等输入校验失败统一作为 400
        fail($e->getMessage(), 400);
    } catch (Throwable $e) {
        app_log(sprintf(
            '%s 未捕获异常: %s in %s:%d%s',
            $_SERVER['SCRIPT_NAME'] ?? 'cli',
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            PHP_EOL . $e->getTraceAsString()
        ));
        fail('服务器内部错误，请稍后重试', 500);
    }
}

/**
 * 限制请求方法。
 */
function require_method(string $method): void
{
    $actual = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : '';
    if ($actual !== strtoupper($method)) {
        if (!headers_sent()) {
            header('Allow: ' . strtoupper($method));
        }
        throw new AppError('请求方法不被允许，仅支持 ' . strtoupper($method), 405);
    }
}

/**
 * 读取 JSON 请求体（空体返回空数组）。
 *
 * @return array<string, mixed>
 */
function json_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new AppError('请求体必须是合法的 JSON 对象');
    }

    return $decoded;
}

/**
 * 读取整数参数并校验范围。
 *
 * @param array<string, mixed> $source
 */
function int_param(array $source, string $key, int $min, int $max, ?int $default = null, bool $required = true): ?int
{
    if (!array_key_exists($key, $source) || $source[$key] === null) {
        if ($required) {
            throw new AppError('缺少参数 ' . $key);
        }
        return $default;
    }

    $value = $source[$key];
    if (is_int($value)) {
        $number = $value;
    } elseif (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
        $number = (int) trim($value);
    } else {
        throw new AppError('参数 ' . $key . ' 必须是整数');
    }

    if ($number < $min || $number > $max) {
        throw new AppError(sprintf('参数 %s 必须在 %d~%d 之间', $key, $min, $max));
    }

    return $number;
}

/**
 * 启动 Session（管理员登录、登记幂等与 CSRF 使用）。
 *
 * Session 文件默认写入项目内的 storage/sessions（环境变量 LOTTERY_SESSION_PATH 可覆盖），
 * 避免依赖系统临时目录的写入权限；部署时请禁止 Web 直接访问该目录。
 */
function app_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    if (PHP_SAPI === 'cli') {
        return;
    }

    $config = app_config()['session'];
    $secure = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off');

    $savePath = getenv('LOTTERY_SESSION_PATH');
    if ($savePath === false || $savePath === '') {
        $savePath = dirname(__DIR__) . '/storage/sessions';
    }
    if (!is_dir($savePath)) {
        @mkdir($savePath, 0770, true);
    }
    if (is_dir($savePath) && is_writable($savePath)) {
        session_save_path($savePath);
    }

    session_name((string) $config['name']);
    session_set_cookie_params([
        'lifetime' => (int) $config['lifetime'],
        'path'     => '/',
        'httponly' => true,
        'secure'   => $secure,
        'samesite' => 'Lax',
    ]);

    if (session_start() === false) {
        app_log('Session 启动失败，save_path=' . $savePath);
    }

    // 定期更换 Session ID，降低被固定利用的风险
    if (!isset($_SESSION['issued_at'])) {
        $_SESSION['issued_at'] = time();
    } elseif (time() - (int) $_SESSION['issued_at'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['issued_at'] = time();
    }
}
