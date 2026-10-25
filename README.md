# 以统计学打败统计学 · 双色球注数统计

一个自建的双色球号码组合注数统计网站：用户选择 6 个红球 + 1 个蓝球后登记注数，页面按「买过最多 / 买过最少 / 没被登记过」三种模式分页展示全部 17,721,088 种合法组合的注数，帮助彩民了解开奖前还有哪些号码组合没有被买过。

> 本站注数为彩民登记与统计结果，仅供参考，不代表彩票官方销量或官方购买数据。
> 显示期号为按开奖日规则推算的编号，非官方确认期号。

---

## 功能特性

**前台**
- 双色球 33 红 + 16 蓝方块选号，最多 6 红 1 蓝，红球自动升序
- 选定组合后可「查询注数」，显示该组合当前注数
- 「登记注数」带人机验证：滑块 / 口算两种验证码随机下发，服务端校验、一次性使用
- 三种列表模式（服务端游标分页，默认 50 条/页）：
  - 买过最多：`real_count > 0`，按注数从高到低
  - 买过最少：`real_count > 0`，按注数从低到高
  - 没被登记过：`real_count = 0`（即使有生成注数也属于此模式），并在标签上方显示当前注数为 0 的组合总数
- 顶部实时显示北京时间、星期、是否开奖、推算期号与下个开奖日（每周二、四、日）
- 每日 22:00（北京时间）自动清零全部注数，组合本身保留

**后台（`/admin/`）**
- 账号密码登录（bcrypt 哈希、Session、登录失败锁定、CSRF 防护）
- 随机生成模拟注数：随机挑选 N 组不同组合，每组随机 M~K 注，批量累加到生成注数
- 组合表初始化状态检查、单组号码注数查询、生成结果统计
- 确认后才执行、一次性操作令牌防重复提交

**运维**
- 命令行全量初始化（17,721,088 条，批量写入、断点续跑、重复执行保护）
- 命令行每日清零（分批 UPDATE、文件锁、时间保护、日志记录）

---

## 技术栈

| 层 | 选型 |
| --- | --- |
| 后端 | 原生 PHP 7.3+（兼容 8.x）+ PDO 预处理 |
| 数据库 | MySQL 8.0（InnoDB），单表 + 管理员表 |
| 前端 | 原生 HTML / CSS / JavaScript（无框架、无构建） |
| 依赖 | 无 Composer、无 Node、无 Redis |

---

## 目录结构

```
.
├── index.php                     前台单页
├── admin/                        管理后台
│   ├── login.php / logout.php
│   ├── index.php                 生成模拟注数、单组查询
│   └── api/generate.php
├── api/
│   ├── meta.php                  日期 / 星期 / 开奖状态 / 期号
│   ├── combination.php           单组号码注数查询
│   ├── captcha.php               人机验证任务下发
│   ├── register.php              登记注数（幂等）
│   └── combinations/list.php     三种列表模式（游标分页）
├── lib/
│   ├── http.php                  引导、JSON 响应、错误处理、Session
│   ├── lottery.php               号码校验、组合格式、开奖日与期号推算
│   ├── auth.php                  管理员认证、CSRF、一次性令牌
│   ├── captcha.php               滑块 / 口算验证码
│   └── repository.php            查询、累加、批量更新、分批清零
├── config/database.php           数据库、时区、上限、日志、Session 配置
├── database/schema.sql           建表 SQL
├── scripts/
│   ├── init_combinations.php     全量号码初始化（仅 CLI）
│   ├── reset_counts.php          每日清零（仅 CLI）
│   └── create_admin.php          管理员创建 / 改密（仅 CLI）
├── assets/                       app.css / app.js / admin.js
├── logs/                         运行日志（不对外访问）
├── storage/sessions/             Session 目录（不对外访问）
├── DEPLOY.md                     部署说明
└── README.md
```

---

## 快速开始（本地）

### 1. 准备环境

- PHP 7.3+（需 `pdo_mysql`、`json`、`mbstring`），同时需要 CLI 版
- MySQL 8.0+
- 无需安装任何依赖

### 2. 建库并配置连接

```sql
CREATE DATABASE caipian DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'caipian'@'127.0.0.1' IDENTIFIED BY '你的密码';
GRANT ALL PRIVILEGES ON caipian.* TO 'caipian'@'127.0.0.1';
```

配置方式（二选一）：

- 环境变量：`LOTTERY_DB_HOST` / `LOTTERY_DB_PORT` / `LOTTERY_DB_NAME` / `LOTTERY_DB_USER` / `LOTTERY_DB_PASS`
- 直接修改 `config/database.php` 中的默认值

其他可选环境变量：`LOTTERY_SESSION_PATH`（Session 目录）、`LOTTERY_CAPTCHA_TTL`（验证码有效期，默认 180 秒）。

### 3. 初始化全量号码（一次性）

```bash
php scripts/init_combinations.php --batch=5000
php scripts/init_combinations.php --status     # 查看进度
```

- 自动建表、批量写入，中断后重跑自动断点续跑，不会重复插入
- 结束校验：组合总数必须为 **17,721,088**
- 参考耗时：约 3 分钟（约 10 万行/秒，视磁盘而定）

### 4. 创建管理员

```bash
php scripts/create_admin.php --username=admin
# 输出一次性随机密码，请立即保存
```

### 5. 启动

```bash
# 开发用 PHP 内置服务器
php -S 127.0.0.1:8123 -t .

# 生产请用 Nginx / Apache，参见 DEPLOY.md
```

- 前台：<http://127.0.0.1:8123/>
- 后台：<http://127.0.0.1:8123/admin/login.php>

---

## 常用命令

| 命令 | 说明 |
| --- | --- |
| `php scripts/init_combinations.php` | 全量初始化 / 断点续跑 |
| `php scripts/init_combinations.php --status` | 查看初始化进度 |
| `php scripts/init_combinations.php --limit=200000` | 只写入指定行数（测试用） |
| `php scripts/reset_counts.php --dry-run` | 查看待清零行数，不改数据 |
| `php scripts/reset_counts.php --force` | 立即清零（跳过 22:00 时间保护） |
| `php scripts/reset_counts.php` | 计划任务用法（22:00 前会跳过，退出码 2） |
| `php scripts/create_admin.php --username=admin --password=...` | 创建管理员 / 重置密码 |

清零计划任务示例（Linux）：

```cron
CRON_TZ=Asia/Shanghai
0 22 * * * /usr/bin/php /var/www/lottery/scripts/reset_counts.php >> /var/www/lottery/logs/cron.log 2>&1
```

---

## API 一览

所有接口返回统一 JSON：`{"success":bool,"message":string,"data":mixed}`，失败时返回 400/401/403/404/405/409/500。

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET | `/api/meta.php` | 日期、星期、开奖状态、推算期号、下个开奖日 |
| GET | `/api/combination.php?red=01,02,03,04,05,06&blue=01` | 单组号码注数 |
| GET | `/api/captcha.php` | 下发验证码（`slider` 滑块 / `math` 口算随机） |
| POST | `/api/register.php` | 登记注数，body：`red`、`blue`、`count`、`request_id`、`captcha_id`、`captcha_answer` |
| GET | `/api/combinations/list.php?mode=most&limit=50` | 列表（`most` / `least` / `unregistered`），游标参数 `after_real_count` + `after_id`（`unregistered` 只需 `after_id`） |
| GET/POST | `/admin/login.php` | 管理员登录（CSRF 保护） |
| POST | `/admin/logout.php` | 退出登录（CSRF 保护） |
| GET | `/admin/index.php` | 后台页面（未登录跳转登录页） |
| POST | `/admin/api/generate.php` | 生成模拟注数，body：`groups`、`min_notes`、`max_notes`、`nonce`，请求头 `X-CSRF-Token` |

登记接口说明：
- 同一 `request_id` 重复提交不会重复累加（幂等）；
- 每组验证码一次性使用，过期或已用会返回 400，需重新获取；
- `count` 取值 1~100000。

---

## 数据说明

主表 `lottery_combinations` 只有四个业务字段：

| 字段 | 说明 |
| --- | --- |
| `id` | 组合顺序号（红球组合序号 × 16 + 蓝球序号） |
| `combination` | 组合字符串，如 `01,02,03,04,05,06\|01`，唯一索引 |
| `real_count` | 用户登记的注数 |
| `generated_count` | 管理员生成的模拟注数 |

- 前台统一显示「已购买注数」= `real_count + generated_count`；
- 「是否买过 / 列表筛选」只看 `real_count`；
- 每天 22:00 清零只把两个注数字段置 0，不删除组合、不改号码与 id。

---

## 部署

生产部署（Nginx/Apache、权限、HTTPS、计划任务、备份、自检清单）详见 [DEPLOY.md](DEPLOY.md)。

安全提示：
- 修改数据库密码并改用环境变量注入；
- 重新创建管理员密码，不要使用示例密码；
- 服务器层禁止访问 `scripts/`、`logs/`、`storage/`、`config/`、`lib/`、`database/`；
- 开启 HTTPS。

---

## 说明与边界

- 本项目仅做号码组合注数的登记与展示，**不提供任何购彩、代购、预测或官方数据服务**；
- 验证码用于降低脚本批量提交，属人机摩擦措施，不等同于强安全认证；
- 开奖日为每周二、周四、周日，期号为本站按开奖日规则推算，不是官方期号；
- 组合表为固定数据，请勿手工删除或修改号码组合本身。
