<?php

declare(strict_types=1);

/**
 * 全量号码组合初始化（仅命令行执行）。
 *
 * 用法：
 *   php scripts/init_combinations.php                 # 全量初始化 / 断点续跑
 *   php scripts/init_combinations.php --batch=5000    # 自定义批大小
 *   php scripts/init_combinations.php --limit=200000  # 只初始化到指定行数（便于测试/分批）
 *   php scripts/init_combinations.php --status        # 只查看进度
 *
 * 特性：
 * - 组合唯一，红球升序，格式 01,02,03,04,05,06|01；
 * - 批量插入 + INSERT IGNORE，可重复执行；
 * - 通过 MAX(id) 断点续跑，不重复插入已有数据；
 * - 完成时校验数量必须为 17,721,088，并补齐统计索引。
 */

require_once dirname(__DIR__) . '/lib/http.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("该脚本只能通过命令行执行\n");
}

$options = getopt('', ['batch::', 'limit::', 'status']);
$batchSize = isset($options['batch']) ? max(100, (int) $options['batch']) : (int) app_config()['init_batch_size'];
$limitRows = isset($options['limit']) ? max(1, (int) $options['limit']) : null;
$statusOnly = array_key_exists('status', $options);

$total = (int) app_config()['total_combinations'];
$table = (string) app_config()['tables']['combinations'];
$pdo = db();

/**
 * 执行 database/schema.sql 中的建表语句（IF NOT EXISTS，安全）。
 */
function init_ensure_schema(PDO $pdo): void
{
    $file = dirname(__DIR__) . '/database/schema.sql';
    if (!is_file($file)) {
        throw new RuntimeException('未找到 database/schema.sql');
    }

    $sql = (string) file_get_contents($file);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    foreach (explode(';', $sql) as $statement) {
        $statement = trim($statement);
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

/**
 * 组合数 C(n, k)。
 */
function init_binom(int $n, int $k): int
{
    if ($k < 0 || $n < $k) {
        return 0;
    }
    if ($k === 0 || $k === $n) {
        return 1;
    }
    $k = min($k, $n - $k);
    $result = 1;
    for ($i = 1; $i <= $k; $i++) {
        $result = intdiv($result * ($n - $k + $i), $i);
    }

    return $result;
}

/**
 * 组合数系统反演：第 index 个红球组合（0 基，按字典序），返回升序 6 个红球。
 *
 * @return int[]
 */
function init_unrank_red(int $index): array
{
    $result = [];
    $start = 1;
    $remaining = LOTTERY_RED_COUNT;

    for ($position = 0; $position < LOTTERY_RED_COUNT; $position++) {
        $remaining = LOTTERY_RED_COUNT - $position;
        for ($value = $start; $value <= LOTTERY_RED_MAX; $value++) {
            $count = init_binom(LOTTERY_RED_MAX - $value, $remaining - 1);
            if ($index < $count) {
                $result[] = $value;
                $start = $value + 1;
                break;
            }
            $index -= $count;
        }
    }

    return $result;
}

/**
 * 生成 [fromId, toId] 区间的插入值。
 */
function init_build_values(int $fromId, int $toId): string
{
    $rows = [];
    for ($id = $fromId; $id <= $toId; $id++) {
        $comboIndex = intdiv($id - 1, LOTTERY_BLUE_MAX);
        $blue = (($id - 1) % LOTTERY_BLUE_MAX) + 1;
        $reds = init_unrank_red($comboIndex);
        $rows[] = sprintf('(%d,%s)', $id, "'" . lottery_combination_string($reds, $blue) . "'");
    }

    return implode(',', $rows);
}

/**
 * 索引是否存在。
 */
function init_index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.statistics
          WHERE table_schema = DATABASE() AND table_name = :table_name AND index_name = :index_name'
    );
    $stmt->execute([':table_name' => $table, ':index_name' => $index]);

    return (int) $stmt->fetchColumn() > 0;
}

try {
    init_ensure_schema($pdo);

    $maxId = (int) $pdo->query('SELECT MAX(id) FROM ' . $table)->fetchColumn();
    $count = (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();

    echo sprintf("目标总数: %s，当前 MAX(id)=%s，当前行数=%s\n", number_format($total), number_format($maxId), number_format($count));

    if ($statusOnly) {
        echo '进度: ' . number_format($count) . ' / ' . number_format($total)
            . ' (' . round($count / $total * 100, 2) . "%)\n";
        exit(0);
    }

    if ($count >= $total) {
        echo "组合表已完整初始化，无需重复写入。\n";
        if (!init_index_exists($pdo, $table, 'idx_real_count')) {
            echo "正在补齐索引 idx_real_count ...\n";
            $pdo->exec('CREATE INDEX idx_real_count ON ' . $table . ' (real_count, id)');
            echo "索引已创建。\n";
        }
        exit(0);
    }

    // 空表时先移除统计索引，批量写入完成后再创建，可显著提升初始化速度
    if ($count === 0 && init_index_exists($pdo, $table, 'idx_real_count')) {
        echo "空表检测到 idx_real_count，先删除以加速批量写入（结束后自动重建）...\n";
        $pdo->exec('ALTER TABLE ' . $table . ' DROP INDEX idx_real_count');
    }

    $startId = $maxId + 1;
    $endId = $limitRows !== null ? min($total, $startId + $limitRows - 1) : $total;

    if ($startId > $endId) {
        echo "没有需要写入的数据。\n";
        exit(0);
    }

    echo sprintf(
        "开始写入 id %s ~ %s，批大小 %d，预计 %s 行\n",
        number_format($startId),
        number_format($endId),
        $batchSize,
        number_format($endId - $startId + 1)
    );

    $insertSql = 'INSERT IGNORE INTO ' . $table . ' (id, combination) VALUES ';
    $written = 0;
    $startedAt = microtime(true);

    for ($from = $startId; $from <= $endId; $from += $batchSize) {
        $to = min($from + $batchSize - 1, $endId);
        $values = init_build_values($from, $to);
        $pdo->exec($insertSql . $values);
        $written += ($to - $from + 1);

        if ($written % ($batchSize * 10) < $batchSize) {
            $elapsed = max(0.001, microtime(true) - $startedAt);
            $rate = $written / $elapsed;
            $remain = max(0, $endId - $to);
            printf(
                "  id=%s 已写入 %s 行，%.0f 行/秒，剩余约 %s\n",
                number_format($to),
                number_format($written),
                $rate,
                $remain > 0 ? gmdate('H:i:s', (int) ($remain / max(1, $rate))) : '0'
            );
        }
    }

    $elapsed = microtime(true) - $startedAt;
    printf("本次写入 %s 行，耗时 %.1f 秒（%.0f 行/秒）\n", number_format($written), $elapsed, $written / max(0.001, $elapsed));

    $finalCount = (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    $finalMax = (int) $pdo->query('SELECT MAX(id) FROM ' . $table)->fetchColumn();
    printf("当前行数 %s，MAX(id) %s\n", number_format($finalCount), number_format($finalMax));

    if ($finalMax >= $total) {
        if ($finalCount !== $total) {
            throw new RuntimeException(sprintf('数量校验失败：期望 %d，实际 %d', $total, $finalCount));
        }
        if (!init_index_exists($pdo, $table, 'idx_real_count')) {
            echo "正在创建索引 idx_real_count ...\n";
            $pdo->exec('CREATE INDEX idx_real_count ON ' . $table . ' (real_count, id)');
            echo "索引已创建。\n";
        }
        echo "初始化完成，组合总数 " . number_format($finalCount) . "，与规则值一致。\n";
    } else {
        echo "已达到本次 --limit 限制，可再次运行本脚本继续断点续跑。\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, '初始化失败：' . $e->getMessage() . PHP_EOL);
    app_log('init_combinations.php 失败: ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString());
    exit(1);
}
