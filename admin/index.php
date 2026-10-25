<?php

declare(strict_types=1);

/**
 * 管理员后台首页（/admin/index.php）：随机生成模拟注数、查询指定组合。
 */

require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/repository.php';

app_session_start();
$admin = admin_require_login_page();

$csrf = csrf_token();
$nonce = generate_nonce_issue();

$total = (int) app_config()['total_combinations'];
$maxId = db_max_combination_id();
$initialized = $maxId >= $total;
$generateConfig = app_config()['generate'];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
    <title>管理后台 - 双色球注数统计</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="admin-page">
<header class="topbar admin-topbar">
    <div class="brand">双色球注数统计 · 管理后台</div>
    <div class="admin-user">
        <span>当前管理员：<?= htmlspecialchars((string) $admin['username'], ENT_QUOTES, 'UTF-8') ?></span>
        <form method="post" action="/admin/logout.php" class="inline-form">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="ghost small">退出登录</button>
        </form>
        <a class="ghost small link-button" href="/" target="_blank" rel="noopener">打开前台</a>
    </div>
</header>

<main class="admin-main">
    <section class="card">
        <h2>组合表状态</h2>
        <div class="stats">
            <div class="stat"><span>目标组合数</span><strong><?= number_format($total) ?></strong></div>
            <div class="stat"><span>当前 MAX(id)</span><strong><?= number_format($maxId) ?></strong></div>
            <div class="stat"><span>初始化状态</span><strong class="<?= $initialized ? 'ok' : 'warn' ?>"><?= $initialized ? '已完成' : '未完成' ?></strong></div>
        </div>
        <?php if (!$initialized): ?>
            <div class="message error">
                组合表尚未初始化完成，禁止生成模拟注数。请在服务器命令行执行：
                <code>php scripts/init_combinations.php</code>
            </div>
        <?php endif; ?>
        <p class="hint">每日 <?= sprintf('%02d:00', (int) app_config()['reset_hour']) ?>（北京时间）由计划任务自动清零全部注数，组合本身不会被删除。</p>
    </section>

    <section class="card">
        <h2>随机生成模拟注数</h2>
        <p class="hint">生成的注数只累加到「模拟注数 generated_count」，不会改动用户登记的真实注数。</p>
        <form id="generateForm" class="grid-form" data-initialized="<?= $initialized ? '1' : '0' ?>">
            <input type="hidden" id="generateNonce" value="<?= htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') ?>">

            <label class="field">
                <span>随机挑选组数（1~<?= (int) $generateConfig['max_groups'] ?>）</span>
                <input type="number" id="groupsInput" min="1" max="<?= (int) $generateConfig['max_groups'] ?>" value="100" required>
            </label>

            <label class="field">
                <span>每组最小注数（1~<?= (int) $generateConfig['max_notes_per_group'] ?>）</span>
                <input type="number" id="minInput" min="1" max="<?= (int) $generateConfig['max_notes_per_group'] ?>" value="1" required>
            </label>

            <label class="field">
                <span>每组最大注数（1~<?= (int) $generateConfig['max_notes_per_group'] ?>）</span>
                <input type="number" id="maxInput" min="1" max="<?= (int) $generateConfig['max_notes_per_group'] ?>" value="20" required>
            </label>

            <button type="submit" class="primary" id="generateBtn" <?= $initialized ? '' : 'disabled' ?>>预览并确认生成</button>
        </form>
        <div id="generateMessage" class="message" role="status"></div>
        <div id="generateResult" class="result-panel" hidden></div>
    </section>

    <section class="card">
        <h2>查询指定组合</h2>
        <form id="lookupForm" class="grid-form">
            <label class="field">
                <span>红球（6 个，逗号分隔，如 01,02,03,04,05,06）</span>
                <input type="text" id="lookupRed" placeholder="01,02,03,04,05,06" required>
            </label>
            <label class="field">
                <span>蓝球（01~16）</span>
                <input type="text" id="lookupBlue" placeholder="01" maxlength="2" required>
            </label>
            <button type="submit" class="ghost">查询</button>
        </form>
        <div id="lookupMessage" class="message" role="status"></div>
        <div id="lookupResult" class="result-panel" hidden></div>
    </section>
</main>

<script src="/assets/admin.js"></script>
</body>
</html>
