<?php

declare(strict_types=1);

/**
 * GET /api/captcha.php
 * 下发一个随机类型的验证码任务（滑块 / 口算），答案只保存在服务端 Session。
 */

require_once dirname(__DIR__) . '/lib/captcha.php';

api_run(static function (): void {
    require_method('GET');

    json_ok(captcha_issue(), '验证码已生成');
});
