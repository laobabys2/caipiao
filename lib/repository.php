<?php

declare(strict_types=1);

/**
 * 数据库访问层：组合查询、注数登记、批量生成与清零。
 */

require_once __DIR__ . '/http.php';

/**
 * 当前组合表最大 id（用于判断初始化是否完成，走主键，开销极小）。
 */
function db_max_combination_id(): int
{
    $table = (string) app_config()['tables']['combinations'];
    $value = db()->query('SELECT MAX(id) FROM ' . $table)->fetchColumn();

    return $value === false || $value === null ? 0 : (int) $value;
}

/**
 * 组合表是否已完成全量初始化。
 */
function db_is_initialized(): bool
{
    return db_max_combination_id() >= (int) app_config()['total_combinations'];
}

/**
 * 按组合字符串查询（唯一索引）。
 *
 * @return array<string, mixed>|null
 */
function db_find_by_combination(string $combination): ?array
{
    $table = (string) app_config()['tables']['combinations'];
    $stmt = db()->prepare('SELECT id, combination, real_count, generated_count FROM ' . $table . ' WHERE combination = :combination LIMIT 1');
    $stmt->execute([':combination' => $combination]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

/**
 * 按 id 查询。
 *
 * @return array<string, mixed>|null
 */
function db_find_by_id(int $id): ?array
{
    $table = (string) app_config()['tables']['combinations'];
    $stmt = db()->prepare('SELECT id, combination, real_count, generated_count FROM ' . $table . ' WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

/**
 * 当前数据库中注数为 0 的组合数量。
 *
 * 用「合法组合总数 - 已登记数」计算：已登记（real_count > 0）走 idx_real_count 索引范围，
 * 通常只有少量行，代价远低于对 1772 万行做 real_count = 0 的全量计数。
 */
function db_zero_count_total(): int
{
    if (!db_is_initialized()) {
        return 0;
    }

    $table = (string) app_config()['tables']['combinations'];
    $stmt = db()->query('SELECT COUNT(*) FROM ' . $table . ' WHERE real_count > 0');
    $registered = (int) $stmt->fetchColumn();

    return max(0, (int) app_config()['total_combinations'] - $registered);
}

/**
 * 累加真实注数并返回最新记录。
 *
 * @return array<string, mixed>
 */
function db_increment_real_count(int $id, int $count): array
{
    $table = (string) app_config()['tables']['combinations'];
    $stmt = db()->prepare('UPDATE ' . $table . ' SET real_count = real_count + :count WHERE id = :id');
    $stmt->execute([':count' => $count, ':id' => $id]);

    $row = db_find_by_id($id);
    if ($row === null) {
        throw new AppError('号码组合不存在', 404);
    }

    return $row;
}

/**
 * 批量累加模拟注数（单事务，失败回滚）。
 *
 * @param array<int, int> $counts 组合 id => 本次生成注数
 * @return int 实际更新行数
 */
function db_apply_generated_counts(array $counts): int
{
    if ($counts === []) {
        return 0;
    }

    $table = (string) app_config()['tables']['combinations'];
    $caseParts = [];
    $inParts = [];
    // 占位符顺序：先全部 CASE 参数（id, notes），再全部 IN 参数（id）
    $caseParams = [];
    $inParams = [];

    foreach ($counts as $id => $notes) {
        $caseParts[] = 'WHEN ? THEN ?';
        $caseParams[] = (int) $id;
        $caseParams[] = (int) $notes;
        $inParts[] = '?';
        $inParams[] = (int) $id;
    }

    $params = array_merge($caseParams, $inParams);

    $sql = 'UPDATE ' . $table
        . ' SET generated_count = generated_count + CASE id ' . implode(' ', $caseParts) . ' ELSE 0 END'
        . ' WHERE id IN (' . implode(',', $inParts) . ')';

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $index => $value) {
            $stmt->bindValue($index + 1, $value, PDO::PARAM_INT);
        }
        $stmt->execute();
        $affected = $stmt->rowCount();
        $pdo->commit();

        return $affected;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * 分批清零全部注数（不删除组合、不改 id 与 combination）。
 *
 * @param int $batchSize 每批处理的 id 区间大小
 * @param callable|null $onBatch 回调：function (int $from, int $to, int $affected): void
 * @return array<string, mixed> 统计结果
 */
function db_reset_counts(int $batchSize = 200000, ?callable $onBatch = null): array
{
    $table = (string) app_config()['tables']['combinations'];
    $maxId = db_max_combination_id();
    $affectedTotal = 0;
    $batches = 0;
    $pdo = db();

    if ($maxId === 0) {
        return ['max_id' => 0, 'affected' => 0, 'batches' => 0];
    }

    $stmt = $pdo->prepare(
        'UPDATE ' . $table
        . ' SET real_count = 0, generated_count = 0'
        . ' WHERE id BETWEEN :from_id AND :to_id AND (real_count > 0 OR generated_count > 0)'
    );

    for ($from = 1; $from <= $maxId; $from += $batchSize) {
        $to = min($from + $batchSize - 1, $maxId);
        $stmt->execute([':from_id' => $from, ':to_id' => $to]);
        $affected = $stmt->rowCount();
        $affectedTotal += $affected;
        $batches++;
        if ($onBatch !== null) {
            $onBatch($from, $to, $affected);
        }
    }

    return ['max_id' => $maxId, 'affected' => $affectedTotal, 'batches' => $batches];
}
