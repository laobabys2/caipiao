<?php

declare(strict_types=1);

/**
 * POST /admin/api/generate.php
 * 管理员随机生成模拟注数：随机挑选组合 + 每组随机注数 + 批量累加 generated_count。
 *
 * 安全：Session 登录 + CSRF + 一次性操作令牌（nonce）。不修改 real_count。
 */

require_once dirname(__DIR__, 2) . '/lib/auth.php';
require_once dirname(__DIR__, 2) . '/lib/repository.php';

app_session_start();
$admin = admin_current_user();

api_run(static function () use ($admin): void {
    require_method('POST');
    if ($admin === null) {
        throw new AppError('请先登录管理员后台', 401);
    }

    $body = json_input();
    require_csrf_api($body);

    $nonce = $body['nonce'] ?? null;
    if (!generate_nonce_consume(is_string($nonce) ? $nonce : null)) {
        throw new AppError('本次操作已执行或已过期，请刷新页面后重试', 409);
    }

    $config = app_config()['generate'];
    $groups = (int) int_param($body, 'groups', 1, (int) $config['max_groups']);
    $minNotes = (int) int_param($body, 'min_notes', 1, (int) $config['max_notes_per_group']);
    $maxNotes = (int) int_param($body, 'max_notes', 1, (int) $config['max_notes_per_group']);

    if ($maxNotes < $minNotes) {
        throw new AppError('每组最大注数不能小于最小注数');
    }

    if (!db_is_initialized()) {
        throw new AppError('组合表尚未初始化完成，禁止生成模拟注数', 409);
    }

    $total = (int) app_config()['total_combinations'];
    $startedAt = microtime(true);

    // 随机挑选不重复的组合 id
    $selected = [];
    while (count($selected) < $groups) {
        $selected[random_int(1, $total)] = true;
    }
    $ids = array_keys($selected);
    shuffle($ids);

    $table = (string) app_config()['tables']['combinations'];
    $batchSize = max(1, (int) $config['batch_size']);

    $processedGroups = 0;
    $notesSum = 0;
    $failedGroups = 0;
    $pdo = db();

    try {
        foreach (array_chunk($ids, $batchSize) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $pdo->prepare('SELECT id FROM ' . $table . ' WHERE id IN (' . $placeholders . ')');
            foreach ($chunk as $index => $id) {
                $stmt->bindValue($index + 1, (int) $id, PDO::PARAM_INT);
            }
            $stmt->execute();
            $exists = [];
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $idValue) {
                $exists[(int) $idValue] = true;
            }

            $counts = [];
            foreach ($chunk as $id) {
                if (!isset($exists[(int) $id])) {
                    $failedGroups++;
                    continue;
                }
                $counts[(int) $id] = random_int($minNotes, $maxNotes);
            }

            if ($counts === []) {
                continue;
            }

            $affected = db_apply_generated_counts($counts);
            if ($affected !== count($counts)) {
                throw new RuntimeException(sprintf('批量更新行数不符：期望 %d，实际 %d', count($counts), $affected));
            }

            $processedGroups += count($counts);
            $notesSum += array_sum($counts);
        }
    } catch (Throwable $e) {
        app_log(sprintf(
            'generate.php 失败 admin=%s groups=%d processed=%d notes=%d: %s',
            (string) ($admin['username'] ?? ''),
            $groups,
            $processedGroups,
            $notesSum,
            $e->getMessage()
        ));

        json_response(500, [
            'success' => false,
            'message' => '生成过程中发生错误，已完成的部分已提交，请根据统计结果核对后再操作',
            'data'    => [
                'requested_groups' => $groups,
                'processed_groups' => $processedGroups,
                'generated_notes'  => $notesSum,
                'failed_groups'    => $failedGroups,
                'min_notes'        => $minNotes,
                'max_notes'        => $maxNotes,
            ],
        ]);
    }

    $elapsed = round(microtime(true) - $startedAt, 3);
    app_log(sprintf(
        '随机生成模拟注数 admin=%s groups=%d notes=%d failed=%d elapsed=%.3fs',
        (string) ($admin['username'] ?? ''),
        $processedGroups,
        $notesSum,
        $failedGroups,
        $elapsed
    ));

    json_ok([
        'requested_groups' => $groups,
        'processed_groups' => $processedGroups,
        'generated_notes'  => $notesSum,
        'failed_groups'    => $failedGroups,
        'min_notes'        => $minNotes,
        'max_notes'        => $maxNotes,
        'elapsed_seconds'  => $elapsed,
        'nonce'            => generate_nonce_issue(),
    ], '生成完成');
});
