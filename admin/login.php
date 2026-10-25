<?php

declare(strict_types=1);

/**
 * 管理员登录页（/admin/login.php）
 */

require_once dirname(__DIR__) . '/lib/auth.php';

app_session_start();

if (admin_current_user() !== null) {
    header('Location: /admin/index.php');
    exit;
}

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = $_POST['_csrf'] ?? null;
    $remain = admin_login_locked_seconds();

    if (!csrf_verify(is_string($token) ? $token : null)) {
        $error = '页面已过期，请刷新后重试';
    } elseif ($remain > 0) {
        $error = sprintf('登录尝试过多，请 %d 秒后再试', $remain);
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $error = '请输入账号和密码';
        } elseif (admin_attempt_login($username, $password)) {
            admin_mark_logged_in(['username' => $username, 'login_at' => date('Y-m-d H:i:s')]);
            header('Location: /admin/index.php');
            exit;
        } else {
            admin_record_login_failure();
            app_log(sprintf(
                '管理员登录失败 username=%s ip=%s',
                $username,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ));
            $error = '账号或密码错误';
        }
    }
}

$csrf = csrf_token();
$lockedSeconds = admin_login_locked_seconds();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>管理员登录 - 双色球注数统计</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="auth-page">
<main class="auth-card">
    <h1>管理员登录</h1>
    <p class="hint">双色球注数统计后台</p>

    <?php if ($error !== ''): ?>
        <div class="message error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php elseif ($lockedSeconds > 0): ?>
        <div class="message error">登录尝试过多，请 <?= (int) $lockedSeconds ?> 秒后再试</div>
    <?php endif; ?>

    <form method="post" action="/admin/login.php" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
        <label class="field">
            <span>账号</span>
            <input type="text" name="username" maxlength="32" required autofocus>
        </label>
        <label class="field">
            <span>密码</span>
            <input type="password" name="password" maxlength="128" required>
        </label>
        <button type="submit" class="primary">登录</button>
    </form>

    <p class="hint small">首次使用请先在服务器命令行执行：<code>php scripts/create_admin.php --username=admin</code></p>
    <p class="hint small"><a href="/">返回前台页面</a></p>
</main>
</body>
</html>
