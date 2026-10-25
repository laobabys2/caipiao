<?php

declare(strict_types=1);

/**
 * POST /api/register.php
 * 登记真实注数（累加），使用 request_id 做幂等，防止重复点击造成重复累加。
 *
 * 请求体（JSON）：
 *   { "red": ["01","02","03","04","05","06"], "blue": "01", "count": 3, "request_id": "uuid" }
 *
 * 说明：登记的是用户输入的注数，不代表已向彩票销售系统真实下单。
 */

require_once dirname(__DIR__) . '/lib/http.php';
require_once dirname(__DIR__) . '/lib/repository.php';
require_once dirname(__DIR__) . '/lib/captcha.php';

api_run(static function (): void {
    require_method('POST');
    $body = json_input();

    [$reds, $blue] = lottery_validate_selection($body['red'] ?? null, $body['blue'] ?? null);
    $count = int_param($body, 'count', 1, (int) app_config()['register_max_count']);

    $requestId = $body['request_id'] ?? '';
    if (!is_string($requestId) || preg_match('/^[A-Za-z0-9_-]{8,64}$/', $requestId) !== 1) {
        throw new AppError('request_id 格式不正确');
    }

    $combination = lottery_combination_string($reds, $blue);
    $row = db_find_by_combination($combination);
    if ($row === null) {
        throw new AppError('号码组合不存在', 404);
    }

    app_session_start();
    if (!isset($_SESSION['registrations']) || !is_array($_SESSION['registrations'])) {
        $_SESSION['registrations'] = [];
    }

    // 幂等：同一 request_id 只累加一次，重复请求返回当前结果
    if (isset($_SESSION['registrations'][$requestId])) {
        $latest = db_find_by_id((int) $row['id']);
        json_ok([
            'duplicate'   => true,
            'added_count' => '0',
            'item'        => $latest === null ? null : lottery_format_item($latest),
        ], '该提交已处理过，未重复累加注数');
    }

    // 人机验证：一次性任务，服务端校验（滑块落点或口算答案）
    $captchaId = $body['captcha_id'] ?? null;
    $captchaAnswer = $body['captcha_answer'] ?? null;
    if (is_int($captchaAnswer)) {
        $captchaAnswer = (string) $captchaAnswer;
    }
    if (!captcha_verify(is_string($captchaId) ? $captchaId : null, is_string($captchaAnswer) ? $captchaAnswer : null)) {
        throw new AppError('人机验证失败或已过期，请重新验证', 400);
    }

    $updated = db_increment_real_count((int) $row['id'], (int) $count);

    $_SESSION['registrations'][$requestId] = ['id' => (int) $row['id'], 'at' => time()];
    // 只保留最近 50 次提交记录，避免 Session 无限增长
    if (count($_SESSION['registrations']) > 50) {
        array_shift($_SESSION['registrations']);
    }

    json_ok([
        'duplicate'   => false,
        'added_count' => (string) $count,
        'item'        => lottery_format_item($updated),
    ], '登记成功');
});
