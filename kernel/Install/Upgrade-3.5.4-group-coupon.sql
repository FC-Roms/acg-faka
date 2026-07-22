-- acg-faka 3.5.4 群优惠券安全增量脚本
--
-- 适用范围：表前缀固定为 acg_，MySQL 5.7+ 或兼容 MariaDB。
-- 只新增群券字段、AstrBot 群成员凭据表，并回填现有入群优惠券记录。
-- DDL 会自动提交，执行前必须完成数据库备份。

SET NAMES utf8mb4;
SET @acg_database := DATABASE();

-- 未选择数据库或优惠券基础表不存在时，在任何 DDL 前停止。
SELECT '优惠券基础表检查通过' AS migration_status
FROM (SELECT 1 AS seed) AS migration_seed
LEFT JOIN `acg_coupon` AS base_coupon ON 1 = 0
LIMIT 1;

-- 1. 群券允许的群号列表，保存规范化的 123456|654321 格式。
SELECT COUNT(*) INTO @acg_exists
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @acg_database
  AND TABLE_NAME = 'acg_coupon'
  AND COLUMN_NAME = 'group_ids';

SET @acg_sql := IF(
    @acg_exists > 0,
    'SELECT ''跳过 acg_coupon.group_ids：字段已存在'' AS migration_status',
    'ALTER TABLE `acg_coupon` ADD COLUMN `group_ids` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT ''群优惠券适用群号，多个使用|分隔'' AFTER `user_limit`'
);
PREPARE acg_stmt FROM @acg_sql;
EXECUTE acg_stmt;
DEALLOCATE PREPARE acg_stmt;

-- 2. AstrBot 验证过的群成员凭据。同一 QQ 可以分别属于多个群。
CREATE TABLE IF NOT EXISTS `acg_coupon_group_member` (
    `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
    `qq` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
    `target_email` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
    `group_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
    `nickname` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
    `source` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
    `create_time` datetime NOT NULL,
    `update_time` datetime NULL DEFAULT NULL,
    `last_request_time` datetime NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `uk_coupon_group_member` (`qq`, `group_id`),
    INDEX `coupon_group_member_email_index` (`target_email`),
    INDEX `coupon_group_member_group_index` (`group_id`),
    INDEX `coupon_group_member_source_index` (`source`),
    INDEX `coupon_group_member_create_time_index` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;

-- 3. 复用已有 AstrBot 入群领券记录，老成员无需重新领取。
SELECT COUNT(*) INTO @acg_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = @acg_database
  AND TABLE_NAME = 'acg_coupon_openapi_record';

SET @acg_sql := IF(
    @acg_exists > 0,
    'INSERT IGNORE INTO `acg_coupon_group_member` (`qq`, `target_email`, `group_id`, `nickname`, `source`, `create_time`, `update_time`, `last_request_time`) SELECT `qq`, `target_email`, `group_id`, `nickname`, `source`, `create_time`, `update_time`, `last_request_time` FROM `acg_coupon_openapi_record` WHERE `group_id` REGEXP ''^[1-9][0-9]{4,11}$''',
    'SELECT ''跳过老成员回填：acg_coupon_openapi_record 不存在'' AS migration_status'
);
PREPARE acg_stmt FROM @acg_sql;
EXECUTE acg_stmt;
DEALLOCATE PREPARE acg_stmt;

-- 4. 核验结果，missing_count 必须全部为 0。
SELECT 'coupon_group_ids_column' AS check_type,
       IF(EXISTS(
           SELECT 1
           FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = @acg_database
             AND TABLE_NAME = 'acg_coupon'
             AND COLUMN_NAME = 'group_ids'
       ), 0, 1) AS missing_count;

SELECT 'coupon_group_member_table' AS check_type,
       IF(EXISTS(
           SELECT 1
           FROM information_schema.TABLES
           WHERE TABLE_SCHEMA = @acg_database
             AND TABLE_NAME = 'acg_coupon_group_member'
       ), 0, 1) AS missing_count;

SELECT COUNT(*) AS astrbot_member_credential_count
FROM `acg_coupon_group_member`;

SELECT '群优惠券数据库升级完成' AS migration_status;
