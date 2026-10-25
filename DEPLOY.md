# 双色球注数统计网站 · 部署说明

本文档说明如何把本项目部署到 Linux / Windows 服务器，包含环境要求、数据库初始化、站点配置、管理员创建、每日清零计划任务、部署自检与常见问题。

---

## 1. 环境要求

| 项目 | 要求 | 说明 |
| --- | --- | --- |
| PHP | 7.3 及以上（8.x 亦可） | 需要扩展：`pdo_mysql`、`json`、`mbstring` |
| PHP CLI | 与 Web 版同版本 | 全量初始化、每日清零、创建管理员都通过命令行执行 |
| MySQL | 8.0 及以上（5.7 亦可） | 表使用 InnoDB |
| Web 服务器 | Nginx 或 Apache | 站点根目录必须指向项目根目录 |
| 其他 | 无需 Composer / Node / Redis | 纯原生 PHP + HTML/CSS/JS |

检查命令：

```bash
php -v
php -m | grep -E 'pdo_mysql|json|mbstring'
```

> 注意区分 **php-fpm / php-cgi（Web 用）** 与 **php（CLI 用）**。部分服务器只装了前者，初始化与清零会执行失败。

---

## 2. 目录说明

```
lottery/
├── index.php                     前台单页
├── admin/                        管理后台（登录、生成模拟注数、单组查询）
├── api/                          所有 JSON 接口
│   └── combinations/list.php     三种列表模式（游标分页）
├── lib/                          公共库（HTTP、认证、验证码、号码逻辑、数据访问）
├── config/database.php           数据库连接、时区、上限、日志、Session 配置
├── database/schema.sql           建表 SQL
├── scripts/
│   ├── init_combinations.php     全量号码初始化（仅 CLI）
│   ├── reset_counts.php          每日清零（仅 CLI）
│   └── create_admin.php          管理员创建/改密（仅 CLI）
├── assets/                       前端 CSS / JS
├── logs/                         运行日志（api.log、reset.log、cron.log）
└── storage/sessions/             Session 文件目录
```

**不要上传**本地的 `storage/sessions/*`、`logs/*.log`、`logs/reset.lock`；目录结构保留即可。

---

## 3. 创建数据库与账号

```sql
CREATE DATABASE caipian DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'lottery'@'127.0.0.1' IDENTIFIED BY '替换为强密码';
GRANT ALL PRIVILEGES ON caipian.* TO 'lottery'@'127.0.0.1';
FLUSH PRIVILEGES;
```

说明：初始化脚本需要建表、建索引权限，因此授予该库的 ALL 即可（不要授予全局权限）。

也可以手动导入结构（初始化脚本也会自动建表，二者选一）：

```bash
mysql -h 127.0.0.1 -u lottery -p caipian < database/schema.sql
```

表结构要点：

- `lottery_combinations`：`id` 主键、`combination` 唯一索引、`idx_real_count (real_count, id)`；
- `lottery_admin`：管理员账号，密码为 `password_hash()` 哈希，不存明文。

---

## 4. 配置数据库连接

配置项集中在本文件：`config/database.php`，支持环境变量覆盖，优先级高于文件默认值。

| 环境变量 | 默认值 | 说明 |
| --- | --- | --- |
| `LOTTERY_DB_HOST` | `127.0.0.1` | 数据库地址 |
| `LOTTERY_DB_PORT` | `3306` | 端口 |
| `LOTTERY_DB_NAME` | `caipian` | 库名 |
| `LOTTERY_DB_USER` | `caipian` | 账号 |
| `LOTTERY_DB_PASS` | `caipian` | 密码（生产必须修改） |
| `LOTTERY_SESSION_PATH` | `<项目>/storage/sessions` | Session 目录，建议指到站点外 |
| `LOTTERY_CAPTCHA_TTL` | `180` | 人机验证有效期（秒），下限 5 |

**方式 A：环境变量（推荐，密码不写进代码）**

php-fpm 池配置（例如 `/etc/php/8.2/fpm/pool.d/www.conf`）：

```ini
env[LOTTERY_DB_HOST] = 127.0.0.1
env[LOTTERY_DB_NAME] = caipian
env[LOTTERY_DB_USER] = lottery
env[LOTTERY_DB_PASS] = 你的强密码
env[LOTTERY_SESSION_PATH] = /var/lib/php/lottery-sessions
```

Apache 可在站点配置中使用 `SetEnv`。修改后重启 PHP。

**方式 B：直接修改 `config/database.php` 中的默认值**（文件权限保持 644）。

---

## 5. 目录权限

```bash
cd /var/www/lottery
chown -R www-data:www-data .
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;
chmod -R 775 logs storage
```

- `logs/` 需要可写（接口异常、清零记录）；
- `storage/sessions/` 需要可写（登录、CSRF、验证码、登记幂等）；
- 若把 Session 指到站点外，确保该目录存在且 Web 用户可写。

---

## 6. 站点配置

### 6.1 Nginx

```nginx
server {
    listen 80;
    server_name lottery.example.com;
    root /var/www/lottery;
    index index.php;

    # 敏感目录禁止外部访问（Nginx 不读取 .htaccess，必须写在这里）
    location ^~ /storage/  { deny all; }
    location ^~ /logs/     { deny all; }
    location ^~ /scripts/  { deny all; }
    location ^~ /lib/      { deny all; }
    location ^~ /config/   { deny all; }
    location ^~ /database/ { deny all; }
    location ~ /\.(?!well-known) { deny all; }

    location / { try_files $uri $uri/ =404; }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_read_timeout 120s;
    }
}
```

### 6.2 Apache

- `DocumentRoot` 指向项目根目录；
- 开启 `AllowOverride All`，项目内已包含 `storage/.htaccess`、`logs/.htaccess` 的拒绝规则；
- 其余目录（`scripts/`、`config/`、`lib/`、`database/`）建议在站点配置中补一条拒绝规则：

```apache
<DirectoryMatch "^/var/www/lottery/(scripts|config|lib|database)">
    Require all denied
</DirectoryMatch>
```

- 建议开启 HTTPS（Session Cookie 会根据 HTTPS 自动加 `secure` 标记）。

### 6.3 生产建议

- 为站点启用 HTTPS；
- 不要开启目录列表；
- `scripts/`、`logs/`、`storage/`、`config/`、`lib/`、`database/` 一律禁止 Web 访问（脚本本身对浏览器访问返回 403，但仍建议在服务器层拒绝）。

---

## 7. 全量号码初始化

共 17,721,088 条组合，只能通过命令行初始化（浏览器访问脚本会返回 403）。

```bash
cd /var/www/lottery

# 前台试跑（可选）
php scripts/init_combinations.php --limit=200000 --batch=2000

# 正式全量，建议后台执行
nohup php -d memory_limit=512M scripts/init_combinations.php --batch=5000 > logs/init.log 2>&1 &
tail -f logs/init.log
```

脚本特性：

- 自动建表（`database/schema.sql`，`IF NOT EXISTS`）；
- 组合按「红球字典序 + 蓝球」生成，天然唯一，`INSERT IGNORE` 可重复执行；
- 通过 `MAX(id)` 断点续跑，中断后重跑同一条命令即可继续；
- 空表时先移除统计索引以提升写入速度，完成后自动重建；
- 结束校验总数必须为 17,721,088，否则报错退出。

校验进度：

```bash
php scripts/init_combinations.php --status
# 进度: 17,721,088 / 17,721,088 (100%)
```

参考性能：本机（MySQL 8.0，64M buffer pool）约 171 秒、约 10 万行/秒。生产建议调大 `innodb_buffer_pool_size`（数据约 1.8GB，建议 ≥ 2G）后再初始化与查询。

---

## 8. 创建管理员

```bash
php scripts/create_admin.php --username=admin
# 输出一次性随机密码，请立即保存

# 或指定密码 / 重置密码
php scripts/create_admin.php --username=admin --password='自定义强密码'
```

- 后台入口：`https://你的域名/admin/login.php`
- 密码使用 `password_hash()`（bcrypt）存储；登录失败 5 次锁定 5 分钟；登录与生成操作均有 CSRF 防护。
- 数据库中只保存哈希值，不保存明文。

---

## 9. 每日 22:00 清零

清零脚本只把 `real_count`、`generated_count` 置 0，不删除组合、不改 id 与 `combination`；按 id 区间分批 UPDATE，文件锁防并发，执行结果写入 `logs/reset.log`。

### 9.1 Linux Cron

```bash
crontab -e
```

```cron
CRON_TZ=Asia/Shanghai
0 22 * * * /usr/bin/php /var/www/lottery/scripts/reset_counts.php >> /var/www/lottery/logs/cron.log 2>&1
```

时区注意：

- 脚本内部按 `Asia/Shanghai` 判断"是否已过 22:00"；
- 但 **cron 的触发时刻按服务器时区计算**；
- 服务器为 UTC 且 cron 不支持 `CRON_TZ` 时，改为 `0 14 * * *`（= 北京时间 22:00），或把系统时区设为 `Asia/Shanghai`。

### 9.2 Windows 任务计划

```powershell
schtasks /Create /SC DAILY /ST 22:00 /TN "LotteryReset" ^
 /TR "\"C:\php\php.exe\" \"D:\www\lottery\scripts\reset_counts.php\"" /F
```

### 9.3 宝塔 / 1Panel 等面板

新增「计划任务 → Shell 脚本」，周期设为每天 22:00，脚本内容：

```bash
/usr/bin/php /www/wwwroot/lottery/scripts/reset_counts.php
```

（面板时区为 UTC 时，执行时间填 14:00。）

### 9.4 Docker

```cron
0 22 * * * docker exec lottery php /app/scripts/reset_counts.php >> /var/log/lottery-reset.log 2>&1
```

### 9.5 手动测试与退出码

```bash
php scripts/reset_counts.php --dry-run   # 只统计待清零行数
php scripts/reset_counts.php --force     # 忽略 22:00 时间保护，立即执行
php scripts/reset_counts.php             # 计划任务用法（22:00 前会被跳过）
```

| 退出码 | 含义 |
| --- | --- |
| 0 | 执行成功 |
| 1 | 执行失败（详见日志） |
| 2 | 当前未到 22:00，已跳过（需 `--force`） |
| 3 | 已有清零任务在运行，本次跳过 |

---

## 10. 部署自检

```bash
curl -I https://你的域名/                                             # 200
curl -s https://你的域名/api/meta.php                                 # success=true, initialized=true
curl -s "https://你的域名/api/combination.php?red=01,02,03,04,05,06&blue=01"
curl -s "https://你的域名/api/combinations/list.php?mode=unregistered&limit=1"
curl -o /dev/null -w "%{http_code}\n" https://你的域名/scripts/reset_counts.php   # 403
php /var/www/lottery/scripts/reset_counts.php --dry-run               # 打印待清零条数
```

浏览器人工检查：

1. 首页顶部日期、星期、开奖状态、期号正常；
2. 选 6 红 1 蓝 → 点「查询注数」显示该组合注数；
3. 点「登记注数」→ 完成滑块/口算验证 → 显示登记成功与最新注数；
4. 三种列表模式可切换、可翻页，「没被登记过」上方显示注数为 0 的总数；
5. `/admin/login.php` 能登录，后台可生成模拟注数、可查询单组号码；
6. 刷新页面后注数保持（未到 22:00 不会被清空）。

---

## 11. 备份与恢复

```bash
# 备份（约 1.8GB，建议低峰期执行并压缩）
mysqldump -h 127.0.0.1 -u lottery -p --single-transaction caipian \
  lottery_combinations lottery_admin | gzip > /backup/lottery-$(date +%F).sql.gz

# 恢复
gunzip -c /backup/lottery-2026-10-25.sql.gz | mysql -h 127.0.0.1 -u lottery -p caipian
```

号码组合表是固定数据，只需备份一次；日常只需备份 `lottery_admin`（管理员账号）。

---

## 12. 常见问题

| 现象 | 排查方向 |
| --- | --- |
| 接口 500 | 查看 `logs/api.log`；确认数据库账号密码、`pdo_mysql` 扩展 |
| 管理员登录后立刻掉线 | `storage/sessions` 或 `LOTTERY_SESSION_PATH` 不可写；文件属主是否为 Web 用户 |
| 初始化报权限错误 | 数据库账号是否具备该库的 CREATE / ALTER 权限 |
| 初始化很慢 | 调大 `innodb_buffer_pool_size`；确认 `idx_real_count` 未在写入前创建（脚本会自动处理） |
| 浏览器访问 scripts 返回 403 | 正常设计，脚本只允许 CLI 执行 |
| 清零没执行 | 检查 cron 时区、PHP CLI 路径、`logs/reset.log`、退出码 |
| 22:00 前手动执行被跳过 | 加 `--force` 强制执行 |
| 注数查询显示旧值 | 已对接口设置 `Cache-Control: no-store`；如仍异常请强制刷新浏览器 |
| 页面样式/脚本不更新 | 静态资源带版本号（如 `app.js?v=3`），升级前端时同步改版本号 |

---

## 13. 安全清单

- [ ] 修改数据库密码，且不写入公开代码（使用环境变量）
- [ ] 重新创建管理员密码，不使用默认/示例密码
- [ ] 开启 HTTPS
- [ ] 服务器层禁止访问 `scripts/`、`logs/`、`storage/`、`config/`、`lib/`、`database/`
- [ ] `logs/` 与 Session 目录不对公网开放
- [ ] 定期检查 `logs/api.log` 与 `logs/reset.log`
- [ ] 数据备份可用（至少备份 `lottery_admin`）
