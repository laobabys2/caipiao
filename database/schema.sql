-- 双色球注数统计网站：数据库结构
-- 说明：只有一张号码组合主表 + 一张管理员表；不建期号统计表、明细表、日志表。

CREATE TABLE IF NOT EXISTS `lottery_combinations` (
    `id` INT UNSIGNED NOT NULL COMMENT '组合顺序号：红球组合序号*16 + 蓝球序号，由初始化脚本生成',
    `combination` CHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '组合，格式 01,02,03,04,05,06|01',
    `real_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '用户手动登记的真实注数',
    `generated_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '管理员生成的模拟注数',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_combination` (`combination`),
    KEY `idx_real_count` (`real_count`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='双色球号码组合与注数统计';

CREATE TABLE IF NOT EXISTS `lottery_admin` (
    `id` TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(32) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='后台管理员';
