<?php

declare(strict_types=1);

/**
 * 退出登录（POST + CSRF）。
 */

require_once dirname(__DIR__) . '/lib/auth.php';

app_session_start();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    if (!headers_sent()) {
        header('Allow: POST');
    }
    http_response_code(405);
    echo '仅支持 POST 请求';
    exit;
}

$token = $_POST['_csrf'] ?? null;
if (!csrf_verify(is_string($token) ? $token : null)) {
    http_response_code(403);
    echo 'CSRF 校验失败';
    exit;
}

admin_logout();

header('Location: /admin/login.php');
exit;
