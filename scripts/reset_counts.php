<?php

declare(strict_types=1);

/**
 * 每日清零脚本（仅命令行执行）。
 *
 * 用法：
 *   php scripts/reset_counts.php --dry-run     # 只统计将会清零的行数，不修改数据
 *   php scripts/reset_counts.php --force       # 忽略“22:00 之后才允许执行”的时间保护
 *   php scripts/reset_counts.php               # 22:00 之后正常执行（计划任务使用）
 *
 * 行为：
 * - 只把 real_count 与 generated_count 置 0，不删除组合、不改 id 与 combination；
 * - 按 id 区间分批 UPDATE，避免一次更新 1772 万行造成长时间锁与巨大回滚段；
 * - 使用文件锁防止重复并发执行；
 * - 执行时间与结果写入 logs/reset.log。
 */

require_once dirname(__DIR__) . '/lib/repository.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("该脚本只能通过命令行执行\n");
}

$options = getopt('', ['dry-run', 'force', 'batch::']);
$dryRun = array_key_exists('dry-run', $options);
$force = array_key_exists('force', $options);
$batchSize = isset($options['batch']) ? max(1000, (int) $options['batch']) : 200000;

$resetHour = (int) app_config()['reset_hour'];
$now = new DateTimeImmutable('now', new DateTimeZone((string) app_config()['timezone']));

$logFile = dirname(__DIR__) . '/logs/reset.log';
if (!is_dir(dirname($logFile))) {
    @mkdir(dirname($logFile), 0775, true);
}

/**
 * 写清零日志（同时写 api.log，便于统一排查）。
 */
function reset_log(string $message): void
{
    global $logFile;
    $line = sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $message);
    if (@file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX) === false) {
        error_log($line);
    }
    app_log('[reset] ' . $message);
}

if (!$dryRun && !$force && (int) $now->format('G') < $resetHour) {
    $message = sprintf('当前北京时间 %s 未到 %02d:00，清零已跳过（如需强制执行请加 --force）', $now->format('Y-m-d H:i:s'), $resetHour);
    echo $message . PHP_EOL;
    reset_log($message);
    exit(2);
}

// 防并发：独占锁
$lockFile = dirname(__DIR__) . '/logs/reset.lock';
$lockHandle = @fopen($lockFile, 'c');
if ($lockHandle === false) {
    fwrite(STDERR, "无法创建锁文件：{$lockFile}\n");
    exit(1);
}
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "已有清零任务在运行，本次退出\n");
    reset_log('检测到并发执行，本次跳过');
    fclose($lockHandle);
    exit(3);
}

$startedAt = microtime(true);

try {
    $table = (string) app_config()['tables']['combinations'];
    $maxId = db_max_combination_id();

    if ($dryRun) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE real_count > 0 OR generated_count > 0');
        $stmt->execute();
        $pending = (int) $stmt->fetchColumn();
        $message = sprintf('DRY-RUN：MAX(id)=%s，需要清零的组合 %s 条，未修改任何数据', number_format($maxId), number_format($pending));
        echo $message . PHP_EOL;
        reset_log($message);
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        exit(0);
    }

    $result = db_reset_counts($batchSize, static function (int $from, int $to, int $affected): void {
        printf("  区间 %s ~ %s 清零 %s 行\n", number_format($from), number_format($to), number_format($affected));
    });

    $elapsed = round(microtime(true) - $startedAt, 3);
    $message = sprintf(
        '清零完成：MAX(id)=%s，共处理 %d 批，实际清零 %s 行，耗时 %.3f 秒',
        number_format($result['max_id']),
        $result['batches'],
        number_format($result['affected']),
        $elapsed
    );
    echo $message . PHP_EOL;
    reset_log($message);
} catch (Throwable $e) {
    $message = '清零失败：' . $e->getMessage();
    fwrite(STDERR, $message . PHP_EOL);
    reset_log($message . PHP_EOL . $e->getTraceAsString());
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    exit(1);
}

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
exit(0);
