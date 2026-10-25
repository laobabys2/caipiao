<?php

declare(strict_types=1);

/**
 * 应用配置与 PDO 连接工厂。
 *
 * 说明：
 * - 原生 PHP + PDO，无框架；数据库、时区、清零时间、上限、日志路径集中在此配置。
 * - 生产环境请通过环境变量注入数据库账号密码，不要依赖代码中的默认值。
 * - 表名集中在此，如实际表名不同只需改这里。
 */

/**
 * 返回应用配置（单例，进程内只构建一次）。
 *
 * @return array<string, mixed>
 */
function app_config(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $env = static function (string $key, string $default): string {
        $value = getenv($key);
        return ($value === false || $value === '') ? $default : $value;
    };

    $config = [
        'db' => [
            'host'    => $env('LOTTERY_DB_HOST', '127.0.0.1'),
            'port'    => (int) $env('LOTTERY_DB_PORT', '3306'),
            'name'    => $env('LOTTERY_DB_NAME', 'caipian'),
            'user'    => $env('LOTTERY_DB_USER', 'caipian'),
            'pass'    => $env('LOTTERY_DB_PASS', 'caipian'),
            'charset' => 'utf8mb4',
        ],
        // 实际表名映射
        'tables' => [
            'combinations' => 'lottery_combinations',
            'admin'        => 'lottery_admin',
        ],
        // 全站统一时区（清零时间也按此时区计算）
        'timezone' => 'Asia/Shanghai',
        // 每日清零小时（24 小时制，北京时间）
        'reset_hour' => 22,
        // 双色球合法组合总数
        'total_combinations' => 17721088,
        // 单次登记真实注数上限（防止误输入与滥用）
        'register_max_count' => 100000,
        // 人机验证码有效期（秒），可通过环境变量覆盖
        'captcha' => [
            'ttl' => (int) $env('LOTTERY_CAPTCHA_TTL', '180'),
        ],
        // 管理员随机生成上限
        'generate' => [
            'max_groups'          => 5000,
            'max_notes_per_group' => 1000,
            'batch_size'          => 500,
        ],
        // 全量初始化批大小
        'init_batch_size' => 2000,
        // Session 配置
        'session' => [
            'name'     => 'LOTTERYSESSID',
            'lifetime' => 7200,
        ],
        // 服务端详细异常日志（不返回给客户端）
        'log_file' => dirname(__DIR__) . '/logs/api.log',
    ];

    return $config;
}

/**
 * 获取 PDO 连接（单例）。
 *
 * @throws PDOException 连接失败时抛出，由调用方统一捕获并记录日志。
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $db  = app_config()['db'];
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $db['host'],
        $db['port'],
        $db['name'],
        $db['charset']
    );

    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // 关闭模拟预处理，真正使用 MySQL 服务端预处理语句
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ]);

    return $pdo;
}

/**
 * 写入服务端日志。写入失败时退化为 PHP 默认错误日志，绝不向客户端输出细节。
 */
function app_log(string $message): void
{
    $file = (string) app_config()['log_file'];
    $dir  = dirname($file);

    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $line = sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $message);

    if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
        error_log($line);
    }
}
