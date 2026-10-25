<?php

declare(strict_types=1);

/**
 * GET /api/combination.php?red=01,02,03,04,05,06&blue=01
 * 查询单组号码的真实注数、模拟注数与总注数。
 */

require_once dirname(__DIR__) . '/lib/http.php';
require_once dirname(__DIR__) . '/lib/repository.php';

api_run(static function (): void {
    require_method('GET');

    $redInput = $_GET['red'] ?? '';
    $blueInput = $_GET['blue'] ?? '';
    [$reds, $blue] = lottery_validate_selection($redInput, $blueInput);

    $combination = lottery_combination_string($reds, $blue);
    $row = db_find_by_combination($combination);
    if ($row === null) {
        throw new AppError('号码组合不存在', 404);
    }

    json_ok(lottery_format_item($row));
});
