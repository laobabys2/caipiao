<?php

declare(strict_types=1);

/**
 * 人机验证（验证码）：服务端生成、Session 暂存、一次性校验。
 *
 * 目前提供两种类型，每次随机下发：
 * - slider：拖动滑块到虚线位置（服务端校验落点，允许 ±3 误差）
 * - math  ：两位数加减法口算（服务端校验答案）
 *
 * 说明：验证码用于降低脚本批量提交，不等同于强安全认证。
 */

require_once __DIR__ . '/http.php';

/** 支持的类型 */
const CAPTCHA_TYPES = ['slider', 'math'];

/** 同一 Session 最多保留的待验证任务数 */
const CAPTCHA_MAX_PENDING = 5;

/**
 * 验证码配置。
 *
 * @return array{ttl: int}
 */
function captcha_config(): array
{
    $config = app_config()['captcha'] ?? [];

    return ['ttl' => max(5, (int) ($config['ttl'] ?? 180))];
}

/**
 * 清理过期任务，避免 Session 膨胀。
 *
 * @param array<string, mixed> $store
 * @return array<string, mixed>
 */
function captcha_purge(array $store): array
{
    $now = time();
    foreach ($store as $id => $entry) {
        if (!is_array($entry) || (int) ($entry['expires'] ?? 0) < $now) {
            unset($store[$id]);
        }
    }

    return $store;
}

/**
 * 生成一个验证码任务（答案只存服务端 Session，不下发）。
 *
 * @return array<string, mixed>
 */
function captcha_issue(): array
{
    app_session_start();

    $config = captcha_config();
    $store = isset($_SESSION['captchas']) && is_array($_SESSION['captchas']) ? $_SESSION['captchas'] : [];
    $store = captcha_purge($store);

    // 只保留最近若干个，防止同一 Session 无限堆积
    if (count($store) >= CAPTCHA_MAX_PENDING) {
        $store = array_slice($store, -1 * (CAPTCHA_MAX_PENDING - 1), null, true);
    }

    $type = CAPTCHA_TYPES[random_int(0, count(CAPTCHA_TYPES) - 1)];
    $id = bin2hex(random_bytes(12));

    if ($type === 'slider') {
        $target = random_int(35, 92);
        $answer = (string) $target;
        $payload = ['target' => $target];
        $question = '按住滑块，拖动到虚线位置';
    } else {
        $a = random_int(12, 89);
        $b = random_int(3, 9);
        if (random_int(0, 1) === 0) {
            $question = sprintf('%d + %d = ?', $a, $b);
            $answer = (string) ($a + $b);
        } else {
            $a = random_int(20, 99);
            $b = random_int(3, 19);
            if ($b > $a) {
                [$a, $b] = [$b, $a];
            }
            $question = sprintf('%d - %d = ?', $a, $b);
            $answer = (string) ($a - $b);
        }
        $payload = [];
    }

    $store[$id] = [
        'type'    => $type,
        'answer'  => $answer,
        'expires' => time() + $config['ttl'],
    ];
    $_SESSION['captchas'] = $store;

    return [
        'captcha_id' => $id,
        'type'       => $type,
        'question'   => $question,
        'payload'    => $payload,
        'ttl'        => $config['ttl'],
    ];
}

/**
 * 校验验证码（一次性：无论成功失败都会作废该任务）。
 */
function captcha_verify(?string $id, ?string $answer): bool
{
    app_session_start();

    if (!is_string($id) || preg_match('/^[a-f0-9]{24}$/', $id) !== 1) {
        return false;
    }
    if (!is_string($answer)) {
        return false;
    }

    $store = isset($_SESSION['captchas']) && is_array($_SESSION['captchas']) ? $_SESSION['captchas'] : [];
    $store = captcha_purge($store);

    if (!isset($store[$id]) || !is_array($store[$id])) {
        $_SESSION['captchas'] = $store;
        return false;
    }

    $entry = $store[$id];
    unset($store[$id]);
    $_SESSION['captchas'] = $store;

    $given = trim($answer);
    if (preg_match('/^\d{1,3}$/', $given) !== 1) {
        return false;
    }

    $expected = (string) $entry['answer'];
    if (($entry['type'] ?? '') === 'slider') {
        return abs((int) $given - (int) $expected) <= 3;
    }

    return hash_equals($expected, (string) (int) $given);
}
