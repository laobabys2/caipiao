<?php

declare(strict_types=1);

/**
 * GET /api/combinations/list.php
 * 三种列表模式的服务器端游标分页：
 *   mode=most          买过最多：real_count > 0，按 real_count DESC, id DESC
 *   mode=least         买过最少：real_count > 0，按 real_count ASC, id ASC
 *   mode=unregistered  没被登记过：real_count = 0，按 id ASC（不看 generated_count）
 *
 * 参数：
 *   limit              可选，默认 50，1~100
 *   after_real_count   仅 most/least，与 after_id 成对出现
 *   after_id           上一页最后一条的 id
 */

require_once dirname(__DIR__, 2) . '/lib/http.php';
require_once dirname(__DIR__, 2) . '/lib/repository.php';

api_run(static function (): void {
    require_method('GET');

    $mode = isset($_GET['mode']) ? (string) $_GET['mode'] : 'most';
    $modeConfig = [
        'most'         => ['label' => '买过最多的排列', 'filter' => 'real_count > 0', 'sort' => 'real_count DESC, id DESC'],
        'least'        => ['label' => '买过最少的排列', 'filter' => 'real_count > 0', 'sort' => 'real_count ASC, id ASC'],
        'unregistered' => ['label' => '没被登记过的排列', 'filter' => 'real_count = 0', 'sort' => 'id ASC'],
    ];
    if (!isset($modeConfig[$mode])) {
        throw new AppError('mode 必须是 most、least 或 unregistered');
    }

    $limit = int_param($_GET, 'limit', 1, 100, 50, false);

    $hasRealCursor = isset($_GET['after_real_count']);
    $hasIdCursor = isset($_GET['after_id']);

    if ($mode === 'unregistered') {
        if ($hasRealCursor) {
            throw new AppError('unregistered 模式只需 after_id，不需要 after_real_count');
        }
    } elseif ($hasRealCursor !== $hasIdCursor) {
        throw new AppError('after_real_count 与 after_id 必须同时提供');
    }

    $cursorReal = null;
    $cursorId = null;

    if ($hasRealCursor) {
        $rawReal = $_GET['after_real_count'];
        if (!is_string($rawReal) || preg_match('/^\d+$/', $rawReal) !== 1) {
            throw new AppError('after_real_count 必须是非负整数');
        }
        $normalized = ltrim($rawReal, '0');
        $cursorReal = $normalized === '' ? 0 : (int) $normalized;
    }

    if ($hasIdCursor) {
        $rawId = $_GET['after_id'];
        if (!is_string($rawId) || preg_match('/^\d+$/', $rawId) !== 1) {
            throw new AppError('after_id 必须是非负整数');
        }
        $normalizedId = ltrim($rawId, '0');
        $cursorId = $normalizedId === '' ? 0 : (int) $normalizedId;
    }

    $table = (string) app_config()['tables']['combinations'];
    $params = [];
    $where = [];

    if ($mode === 'unregistered') {
        $where[] = 'real_count = 0';
        $orderBy = 'id ASC';
        if ($cursorId !== null) {
            $where[] = 'id > :cursor_id';
            $params[':cursor_id'] = $cursorId;
        }
    } elseif ($mode === 'most') {
        $where[] = 'real_count > 0';
        $orderBy = 'real_count DESC, id DESC';
        if ($cursorReal !== null && $cursorId !== null) {
            $where[] = '(real_count < :cursor_real_a OR (real_count = :cursor_real_b AND id < :cursor_id))';
            $params[':cursor_real_a'] = $cursorReal;
            $params[':cursor_real_b'] = $cursorReal;
            $params[':cursor_id'] = $cursorId;
        }
    } else {
        $where[] = 'real_count > 0';
        $orderBy = 'real_count ASC, id ASC';
        if ($cursorReal !== null && $cursorId !== null) {
            $where[] = '(real_count > :cursor_real_a OR (real_count = :cursor_real_b AND id > :cursor_id))';
            $params[':cursor_real_a'] = $cursorReal;
            $params[':cursor_real_b'] = $cursorReal;
            $params[':cursor_id'] = $cursorId;
        }
    }

    $sql = 'SELECT id, combination, real_count, generated_count FROM ' . $table
        . ' WHERE ' . implode(' AND ', $where)
        . ' ORDER BY ' . $orderBy
        . ' LIMIT :limit';

    $stmt = db()->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    }
    // 多取 1 条判断是否存在下一页
    $stmt->bindValue(':limit', $limit + 1, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll();
    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        array_pop($rows);
    }

    $items = [];
    foreach ($rows as $row) {
        $items[] = lottery_format_item($row);
    }

    $nextReal = null;
    $nextId = null;
    if ($hasMore && $items !== []) {
        $last = $items[count($items) - 1];
        $nextId = $last['id'];
        if ($mode !== 'unregistered') {
            $nextReal = $last['real_count'];
        }
    }

    $payload = [
        'mode'                   => $mode,
        'mode_label'             => $modeConfig[$mode]['label'],
        'filter'                 => $modeConfig[$mode]['filter'],
        'sort'                   => $modeConfig[$mode]['sort'],
        'limit'                  => $limit,
        'count'                  => count($items),
        'has_more'               => $hasMore,
        'next_after_real_count'  => $nextReal,
        'next_after_id'          => $nextId,
        'items'                  => $items,
        'notice'                 => '注数由本站统计生成，仅供参考，不代表彩票官方数据',
    ];

    // 「没被登记过」模式额外返回当前注数为 0 的组合总数
    if ($mode === 'unregistered') {
        $payload['zero_real_total'] = (string) db_zero_count_total();
    }

    json_ok($payload);
});
