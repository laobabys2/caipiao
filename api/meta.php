<?php

declare(strict_types=1);

/**
 * GET /api/meta.php
 * 返回北京时间日期、星期、开奖状态、推算期号与全站提示信息。
 */

require_once dirname(__DIR__) . '/lib/http.php';
require_once dirname(__DIR__) . '/lib/repository.php';

api_run(static function (): void {
    require_method('GET');

    $timezone = new DateTimeZone((string) app_config()['timezone']);
    $now = new DateTimeImmutable('now', $timezone);
    $meta = lottery_draw_meta($now);

    $meta['total_combinations'] = (string) app_config()['total_combinations'];
    $meta['initialized'] = db_is_initialized();
    $meta['notice'] = '注数为本站统计生成，仅供查询参考，不代表彩票官方销量或官方购买数据';
    $meta['reset_notice'] = sprintf('每日 %02d:00（北京时间）自动清零全部注数', (int) app_config()['reset_hour']);

    json_ok($meta);
});
