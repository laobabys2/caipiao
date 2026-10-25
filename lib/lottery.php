<?php

declare(strict_types=1);

/**
 * 双色球号码与开奖日期相关的纯逻辑（不访问数据库），便于单独测试与调整。
 *
 * 号码规则：红球 01~33 中选 6 个不重复号码并升序排列；蓝球 01~16 中选 1 个。
 * 开奖日：每周二、四、日。
 */

const LOTTERY_RED_MIN = 1;
const LOTTERY_RED_MAX = 33;
const LOTTERY_RED_COUNT = 6;
const LOTTERY_BLUE_MIN = 1;
const LOTTERY_BLUE_MAX = 16;
/** 开奖日（ISO-8601 星期编号）：周二=2、周四=4、周日=7 */
const LOTTERY_DRAW_WEEKDAYS = [2, 4, 7];

/**
 * 两位数字格式化。
 */
function lottery_pad2(int $number): string
{
    return str_pad((string) $number, 2, '0', STR_PAD_LEFT);
}

/**
 * 把用户输入（数组或逗号分隔字符串）解析为升序、去重校验后的红球数组。
 *
 * @param mixed $input
 * @return int[]
 * @throws InvalidArgumentException 号码数量、范围或重复不合法时
 */
function lottery_parse_red($input): array
{
    if (is_string($input)) {
        $parts = $input === '' ? [] : preg_split('/\s*[,，\-]\s*/u', $input);
        $parts = is_array($parts) ? $parts : [];
    } elseif (is_array($input)) {
        $parts = $input;
    } else {
        throw new InvalidArgumentException('红球参数格式错误');
    }

    if (count($parts) !== LOTTERY_RED_COUNT) {
        throw new InvalidArgumentException('红球必须选择 6 个号码');
    }

    $reds = [];
    foreach ($parts as $part) {
        if (is_int($part)) {
            $value = $part;
        } elseif (is_string($part) && preg_match('/^\d{1,2}$/', trim($part)) === 1) {
            $value = (int) trim($part);
        } else {
            throw new InvalidArgumentException('红球号码格式错误');
        }

        if ($value < LOTTERY_RED_MIN || $value > LOTTERY_RED_MAX) {
            throw new InvalidArgumentException('红球号码必须在 01~33 之间');
        }
        if (isset($reds[$value])) {
            throw new InvalidArgumentException('红球号码不能重复');
        }
        $reds[$value] = $value;
    }

    $reds = array_values($reds);
    sort($reds, SORT_NUMERIC);

    return $reds;
}

/**
 * 校验并返回蓝球号码。
 *
 * @param mixed $input
 * @throws InvalidArgumentException
 */
function lottery_parse_blue($input): int
{
    if (is_int($input)) {
        $value = $input;
    } elseif (is_string($input) && preg_match('/^\d{1,2}$/', trim($input)) === 1) {
        $value = (int) trim($input);
    } else {
        throw new InvalidArgumentException('蓝球参数格式错误');
    }

    if ($value < LOTTERY_BLUE_MIN || $value > LOTTERY_BLUE_MAX) {
        throw new InvalidArgumentException('蓝球号码必须在 01~16 之间');
    }

    return $value;
}

/**
 * 校验整组号码，返回 [红球数组, 蓝球]。
 *
 * @param mixed $redInput
 * @param mixed $blueInput
 * @return array{0: int[], 1: int}
 * @throws InvalidArgumentException
 */
function lottery_validate_selection($redInput, $blueInput): array
{
    return [lottery_parse_red($redInput), lottery_parse_blue($blueInput)];
}

/**
 * 生成统一格式的组合字符串，例如 01,02,03,04,05,06|01。
 *
 * @param int[] $reds 已升序的红球数组
 */
function lottery_combination_string(array $reds, int $blue): string
{
    $parts = [];
    foreach ($reds as $red) {
        $parts[] = lottery_pad2((int) $red);
    }

    return implode(',', $parts) . '|' . lottery_pad2($blue);
}

/**
 * 解析组合字符串，格式不合法返回 null。
 *
 * @return array{red: int[], blue: int, red_text: string[], blue_text: string}|null
 */
function lottery_parse_combination(string $combination): ?array
{
    try {
        $normalized = str_replace(['，', '|'], [',', '|'], trim($combination));
        $segments = explode('|', $normalized);
        if (count($segments) !== 2) {
            return null;
        }
        $reds = lottery_parse_red($segments[0]);
        $blue = lottery_parse_blue($segments[1]);

        return [
            'red'       => $reds,
            'blue'      => $blue,
            'red_text'  => array_map('lottery_pad2', $reds),
            'blue_text' => lottery_pad2($blue),
        ];
    } catch (InvalidArgumentException $e) {
        return null;
    }
}

/**
 * 组合记录 → 前端展示结构。
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function lottery_format_item(array $row): array
{
    $parsed = lottery_parse_combination((string) $row['combination']);
    $real = (int) $row['real_count'];
    $generated = (int) $row['generated_count'];

    return [
        'id'              => (string) $row['id'],
        'combination'     => (string) $row['combination'],
        'red'             => $parsed !== null ? $parsed['red_text'] : [],
        'blue'            => $parsed !== null ? $parsed['blue_text'] : '',
        'real_count'      => (string) $real,
        'generated_count' => (string) $generated,
        'total_count'     => (string) ($real + $generated),
    ];
}

/**
 * 是否开奖日（周二/周四/周日）。
 */
function lottery_is_draw_day(DateTimeImmutable $date): bool
{
    return in_array((int) $date->format('N'), LOTTERY_DRAW_WEEKDAYS, true);
}

/**
 * 星期中文名称。
 */
function lottery_weekday_name(DateTimeImmutable $date): string
{
    $map = [1 => '星期一', 2 => '星期二', 3 => '星期三', 4 => '星期四', 5 => '星期五', 6 => '星期六', 7 => '星期日'];

    return $map[(int) $date->format('N')];
}

/**
 * 按开奖日规则推算本站显示期号：年份 + 当年第 N 个开奖日（3 位）。
 *
 * 注意：这是本站按开奖日推算的显示编号，不是官方确认期号。
 * 非开奖日返回 null。
 */
function lottery_issue_number(DateTimeImmutable $date): ?string
{
    if (!lottery_is_draw_day($date)) {
        return null;
    }

    $yearStart = $date->setDate((int) $date->format('Y'), 1, 1);
    $sequence = 0;
    $cursor = $yearStart;

    while ($cursor <= $date) {
        if (lottery_is_draw_day($cursor)) {
            $sequence++;
        }
        $cursor = $cursor->modify('+1 day');
    }

    return sprintf('%04d%03d', (int) $date->format('Y'), $sequence);
}

/**
 * 下一个开奖日（严格大于给定日期）。
 */
function lottery_next_draw_date(DateTimeImmutable $date): DateTimeImmutable
{
    $cursor = $date->modify('+1 day');
    for ($i = 0; $i < 7; $i++) {
        if (lottery_is_draw_day($cursor)) {
            return $cursor;
        }
        $cursor = $cursor->modify('+1 day');
    }

    return $cursor;
}

/**
 * 日期/开奖状态/期号展示信息。
 *
 * @return array<string, mixed>
 */
function lottery_draw_meta(DateTimeImmutable $now): array
{
    $isDrawDay = lottery_is_draw_day($now);
    $issue = lottery_issue_number($now);
    $nextDraw = lottery_next_draw_date($now);
    $nextIssue = lottery_issue_number($nextDraw);

    return [
        'date'            => $now->format('Y-m-d'),
        'weekday'         => lottery_weekday_name($now),
        'is_draw_day'     => $isDrawDay,
        'draw_label'      => $isDrawDay ? '今日开奖' : '今日无开奖',
        'issue'           => $issue,
        'issue_label'     => $issue !== null ? '第 ' . $issue . ' 期' : '今日无开奖',
        'issue_note'      => '期号为本站按开奖日规则推算的显示编号，非官方确认期号',
        'next_draw_date'  => $nextDraw->format('Y-m-d'),
        'next_draw_weekday' => lottery_weekday_name($nextDraw),
        'next_issue'      => $nextIssue,
        'reset_hour'      => (int) app_config()['reset_hour'],
        'timezone'        => (string) app_config()['timezone'],
        'server_time'     => $now->format('Y-m-d H:i:s'),
    ];
}
