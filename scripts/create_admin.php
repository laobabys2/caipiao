<?php

declare(strict_types=1);

/**
 * 管理员账号创建 / 重置密码（仅命令行执行）。
 *
 * 用法：
 *   php scripts/create_admin.php --username=admin
 *   php scripts/create_admin.php --username=admin --password=你的密码
 *
 * 不传 --password 时会生成随机强密码并只输出一次；数据库中只保存 password_hash()。
 */

require_once dirname(__DIR__) . '/lib/http.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("该脚本只能通过命令行执行\n");
}

$options = getopt('', ['username::', 'password::']);
$username = isset($options['username']) ? trim((string) $options['username']) : 'admin';

if (preg_match('/^[A-Za-z0-9_]{3,32}$/', $username) !== 1) {
    fwrite(STDERR, "用户名必须是 3~32 位字母、数字或下划线\n");
    exit(1);
}

$password = isset($options['password']) ? (string) $options['password'] : '';
$generated = false;

if ($password === '') {
    $password = bin2hex(random_bytes(9));
    $generated = true;
}

if (strlen($password) < 8) {
    fwrite(STDERR, "密码至少 8 位\n");
    exit(1);
}

$table = (string) app_config()['tables']['admin'];
$pdo = db();

// 若管理员表不存在则创建（与 database/schema.sql 一致）
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS ' . $table . ' (
        id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
        username VARCHAR(32) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uk_username (username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare('SELECT id FROM ' . $table . ' WHERE username = :username LIMIT 1');
$stmt->execute([':username' => $username]);
$existing = $stmt->fetchColumn();

if ($existing === false) {
    $insert = $pdo->prepare('INSERT INTO ' . $table . ' (username, password_hash, created_at, updated_at) VALUES (:username, :hash, NOW(), NOW())');
    $insert->execute([':username' => $username, ':hash' => $hash]);
    echo "管理员账号已创建：{$username}\n";
} else {
    $update = $pdo->prepare('UPDATE ' . $table . ' SET password_hash = :hash, updated_at = NOW() WHERE id = :id');
    $update->execute([':hash' => $hash, ':id' => (int) $existing]);
    echo "管理员密码已重置：{$username}\n";
}

if ($generated) {
    echo "初始密码（仅本次显示，请立即保存）：{$password}\n";
}

app_log('管理员账号初始化 username=' . $username);
