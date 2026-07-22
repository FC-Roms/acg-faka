-- acg-faka 3.4.9 -> 3.5.4 安全增量升级脚本
--
-- 适用范围：
--   1. 已安装的 3.4.9 站点，数据库表前缀固定为 acg_。
--   2. MySQL 5.7+ 或兼容版本的 MariaDB。
--
-- 安全约束：
--   1. 本脚本只新增缺失字段、索引、配置项和数据表。
--   2. 不删除对象，不清空表，不修改已有业务记录。
--   3. 可重复执行；已存在的对象会被跳过。
--   4. DDL 会自动提交，不能依赖事务整体回滚。执行前必须完成数据库全量备份。
--   5. 不要在线上执行 kernel/Install/Install.sql，该文件是全新安装脚本。

SET NAMES utf8mb4;
SET @acg_database := DATABASE();

-- 0. 前置检查：确认已选择数据库，并且 3.4.9 的基础表齐全。
-- 任一表不存在或未选择数据库时，mysql 客户端会在任何 DDL 前停止。
SELECT '3.4.9 基础表检查通过' AS migration_status
FROM (SELECT 1 AS seed) AS migration_seed
LEFT JOIN `acg_config` AS base_config ON 1 = 0
LEFT JOIN `acg_manage` AS base_manage ON 1 = 0
LEFT JOIN `acg_coupon` AS base_coupon ON 1 = 0
LEFT JOIN `acg_commodity` AS base_commodity ON 1 = 0
LEFT JOIN `acg_user` AS base_user ON 1 = 0
LEFT JOIN `acg_order` AS base_order ON 1 = 0
LEFT JOIN `acg_upload` AS base_upload ON 1 = 0
LIMIT 1;

-- 1. 上游 3.5.x：管理员 Google Authenticator 密钥字段。
SELECT COUNT(*) INTO @acg_exists
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @acg_database
  AND TABLE_NAME = 'acg_manage'
  AND COLUMN_NAME = 'google_secret';

SET @acg_sql := IF(
    @acg_exists > 0,
    'SELECT ''跳过 acg_manage.google_secret：字段已存在'' AS migration_status',
    'ALTER TABLE `acg_manage` ADD COLUMN `google_secret` varchar(64) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL COMMENT ''谷歌验证器密钥'' AFTER `note`'
);
PREPARE acg_stmt FROM @acg_sql;
EXECUTE acg_stmt;
DEALLOCATE PREPARE acg_stmt;

-- 2. 本地二开：优惠券使用限制字段。
SELECT COUNT(*) INTO @acg_exists
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @acg_database
  AND TABLE_NAME = 'acg_coupon'
  AND COLUMN_NAME = 'user_limit';

SET @acg_sql := IF(
    @acg_exists > 0,
    'SELECT ''跳过 acg_coupon.user_limit：字段已存在'' AS migration_status',
    'ALTER TABLE `acg_coupon` ADD COLUMN `user_limit` tinyint UNSIGNED NOT NULL DEFAULT 0 COMMENT ''使用限制：0=不限，1=仅限绑定邮箱或手机号的新用户，2=登录会员每人限用一次'' AFTER `sku`'
);
PREPARE acg_stmt FROM @acg_sql;
EXECUTE acg_stmt;
DEALLOCATE PREPARE acg_stmt;

-- 已存在以 user_limit 为首列的普通索引时不重复创建。
SELECT COUNT(*) INTO @acg_exists
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = @acg_database
  AND TABLE_NAME = 'acg_coupon'
  AND COLUMN_NAME = 'user_limit'
  AND SEQ_IN_INDEX = 1
  AND INDEX_NAME <> 'PRIMARY';

SET @acg_sql := IF(
    @acg_exists > 0,
    'SELECT ''跳过 acg_coupon.user_limit 索引：索引已存在'' AS migration_status',
    'ALTER TABLE `acg_coupon` ADD INDEX `user_limit` (`user_limit`)'
);
PREPARE acg_stmt FROM @acg_sql;
EXECUTE acg_stmt;
DEALLOCATE PREPARE acg_stmt;

-- 3. 本地二开：商品前台虚拟销量基数。
SELECT COUNT(*) INTO @acg_exists
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @acg_database
  AND TABLE_NAME = 'acg_commodity'
  AND COLUMN_NAME = 'sold_base';

SET @acg_sql := IF(
    @acg_exists > 0,
    'SELECT ''跳过 acg_commodity.sold_base：字段已存在'' AS migration_status',
    'ALTER TABLE `acg_commodity` ADD COLUMN `sold_base` int UNSIGNED NOT NULL DEFAULT 0 COMMENT ''前台已售增加数量'' AFTER `purchase_count`'
);
PREPARE acg_stmt FROM @acg_sql;
EXECUTE acg_stmt;
DEALLOCATE PREPARE acg_stmt;

-- 4. 上游 3.5.x：移动端用户中心主题配置。已有配置值保持不变。
INSERT INTO `acg_config` (`key`, `value`)
SELECT 'user_center_mobile_theme', '0'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM `acg_config`
    WHERE `key` = 'user_center_mobile_theme'
);

-- 5. 上游 3.5.1：工单表。
CREATE TABLE IF NOT EXISTS `acg_ticket` (
    `id` int UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键id',
    `ticket_no` char(22) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '工单编号',
    `user_id` int UNSIGNED NOT NULL COMMENT '创建会员id',
    `type` tinyint UNSIGNED NOT NULL COMMENT '类型：0=售前咨询，1=售后支持',
    `priority` tinyint UNSIGNED NOT NULL DEFAULT 1 COMMENT '优先级：0=低，1=中，2=高',
    `status` tinyint UNSIGNED NOT NULL DEFAULT 0 COMMENT '状态：0=待客服，1=待用户，2=已解决，3=已关闭',
    `title` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '标题',
    `commodity_id` int UNSIGNED NULL DEFAULT NULL COMMENT '关联商品id',
    `commodity_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '商品名称快照',
    `order_id` int UNSIGNED NULL DEFAULT NULL COMMENT '关联订单id',
    `order_trade_no` char(19) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '订单号快照',
    `order_source` tinyint UNSIGNED NOT NULL DEFAULT 0 COMMENT '订单来源：0=无，1=会员，2=游客',
    `proof_upload_id` int UNSIGNED NULL DEFAULT NULL COMMENT '购买凭证上传记录id',
    `proof_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '购买凭证路径快照',
    `last_message_id` bigint UNSIGNED NULL DEFAULT NULL COMMENT '最后消息id',
    `last_sender_type` tinyint UNSIGNED NULL DEFAULT NULL COMMENT '最后发言方：0=用户，1=管理员，2=系统',
    `last_message_excerpt` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '最后消息摘要',
    `last_message_time` datetime NULL DEFAULT NULL COMMENT '最后消息时间',
    `user_unread` int UNSIGNED NOT NULL DEFAULT 0 COMMENT '用户未读数',
    `manage_unread` int UNSIGNED NOT NULL DEFAULT 0 COMMENT '后台未读数',
    `closed_by` int UNSIGNED NULL DEFAULT NULL COMMENT '结束工单的管理员id',
    `closed_time` datetime NULL DEFAULT NULL COMMENT '结束时间',
    `create_time` datetime NOT NULL COMMENT '创建时间',
    `update_time` datetime NOT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE,
    UNIQUE INDEX `ticket_no` (`ticket_no`) USING BTREE,
    INDEX `user_status_message` (`user_id`, `status`, `last_message_time`) USING BTREE,
    INDEX `status_priority_message` (`status`, `priority`, `last_message_time`) USING BTREE,
    INDEX `commodity_id` (`commodity_id`) USING BTREE,
    INDEX `order_id` (`order_id`) USING BTREE,
    INDEX `proof_upload_id` (`proof_upload_id`) USING BTREE,
    INDEX `closed_by` (`closed_by`) USING BTREE,
    CONSTRAINT `acg_ticket_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `acg_user` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
    CONSTRAINT `acg_ticket_ibfk_2` FOREIGN KEY (`commodity_id`) REFERENCES `acg_commodity` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT,
    CONSTRAINT `acg_ticket_ibfk_3` FOREIGN KEY (`order_id`) REFERENCES `acg_order` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT,
    CONSTRAINT `acg_ticket_ibfk_4` FOREIGN KEY (`proof_upload_id`) REFERENCES `acg_upload` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT,
    CONSTRAINT `acg_ticket_ibfk_5` FOREIGN KEY (`closed_by`) REFERENCES `acg_manage` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `acg_ticket_message` (
    `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键id',
    `ticket_id` int UNSIGNED NOT NULL COMMENT '工单id',
    `sender_type` tinyint UNSIGNED NOT NULL COMMENT '发送方：0=用户，1=管理员，2=系统',
    `sender_id` int UNSIGNED NULL DEFAULT NULL COMMENT '发送方id',
    `sender_name` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '发送方名称快照',
    `kind` tinyint UNSIGNED NOT NULL DEFAULT 0 COMMENT '消息类型：0=正文，1=解决回复，2=关闭事件',
    `content` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL COMMENT '消息内容',
    `create_ip` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '发送IP',
    `create_time` datetime NOT NULL COMMENT '发送时间',
    PRIMARY KEY (`id`) USING BTREE,
    INDEX `ticket_message_id` (`ticket_id`, `id`) USING BTREE,
    INDEX `sender` (`sender_type`, `sender_id`) USING BTREE,
    INDEX `create_time` (`create_time`) USING BTREE,
    CONSTRAINT `acg_ticket_message_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `acg_ticket` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;

-- 6. 上游 3.5.1：系统消息与用户消息表。
CREATE TABLE IF NOT EXISTS `acg_system_message` (
    `id` int UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键id',
    `audience_type` tinyint UNSIGNED NOT NULL COMMENT '接收范围：0=全体用户，1=会员等级，2=指定用户',
    `audience_id` int UNSIGNED NULL DEFAULT NULL COMMENT '会员等级id或指定用户id',
    `audience_name` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '接收范围名称快照',
    `title` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '消息标题',
    `content` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '净化后的消息正文',
    `summary` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '消息摘要',
    `jump_url` varchar(2048) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '点击跳转地址',
    `recipient_count` int UNSIGNED NOT NULL DEFAULT 0 COMMENT '发送时接收人数',
    `created_by` int UNSIGNED NULL DEFAULT NULL COMMENT '创建管理员id',
    `updated_by` int UNSIGNED NULL DEFAULT NULL COMMENT '最后编辑管理员id',
    `manage_name` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '创建管理员名称快照',
    `update_manage_name` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '最后编辑管理员名称快照',
    `create_time` datetime NOT NULL COMMENT '发送时间',
    `update_time` datetime NOT NULL COMMENT '最后编辑时间',
    PRIMARY KEY (`id`) USING BTREE,
    INDEX `audience` (`audience_type`, `audience_id`) USING BTREE,
    INDEX `create_time` (`create_time`) USING BTREE,
    INDEX `created_by` (`created_by`) USING BTREE,
    INDEX `updated_by` (`updated_by`) USING BTREE,
    CONSTRAINT `acg_system_message_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `acg_manage` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT,
    CONSTRAINT `acg_system_message_ibfk_2` FOREIGN KEY (`updated_by`) REFERENCES `acg_manage` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `acg_user_message` (
    `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键id',
    `message_id` int UNSIGNED NOT NULL COMMENT '系统消息id',
    `user_id` int UNSIGNED NOT NULL COMMENT '接收用户id',
    `read_time` datetime NULL DEFAULT NULL COMMENT '首次阅读时间',
    `create_time` datetime NOT NULL COMMENT '接收时间',
    PRIMARY KEY (`id`) USING BTREE,
    UNIQUE INDEX `message_user` (`message_id`, `user_id`) USING BTREE,
    INDEX `user_message` (`user_id`, `id`) USING BTREE,
    INDEX `user_read_message` (`user_id`, `read_time`, `id`) USING BTREE,
    CONSTRAINT `acg_user_message_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `acg_system_message` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
    CONSTRAINT `acg_user_message_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `acg_user` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;

-- 7. 本地二开：QQ 专属优惠券 Open API 记录表。
CREATE TABLE IF NOT EXISTS `acg_coupon_openapi_record` (
    `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
    `qq` varchar(32) NOT NULL,
    `target_email` varchar(191) NOT NULL,
    `coupon_id` int UNSIGNED NOT NULL,
    `group_id` varchar(64) NULL DEFAULT NULL,
    `nickname` varchar(191) NULL DEFAULT NULL,
    `source` varchar(64) NULL DEFAULT NULL,
    `request_count` int UNSIGNED NOT NULL DEFAULT 1,
    `raw` text NULL,
    `create_time` datetime NOT NULL,
    `update_time` datetime NULL DEFAULT NULL,
    `last_request_time` datetime NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `coupon_openapi_qq_unique` (`qq`),
    UNIQUE INDEX `coupon_openapi_coupon_unique` (`coupon_id`),
    INDEX `coupon_openapi_target_email_index` (`target_email`),
    INDEX `coupon_openapi_group_id_index` (`group_id`),
    INDEX `coupon_openapi_source_index` (`source`),
    INDEX `coupon_openapi_create_time_index` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;

-- 8. 本地二开：ApiNotification 卡密提取回传记录表。
CREATE TABLE IF NOT EXISTS `acg_api_notification_extract_record` (
    `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
    `trade_no` varchar(64) NULL DEFAULT NULL,
    `order_id` varchar(64) NULL DEFAULT NULL,
    `commodity_id` varchar(64) NULL DEFAULT NULL,
    `card` varchar(191) NOT NULL,
    `status` tinyint NOT NULL DEFAULT 1,
    `extracted_at` datetime NULL DEFAULT NULL,
    `source` varchar(64) NULL DEFAULT NULL,
    `raw` text NULL,
    `created_at` datetime NULL DEFAULT NULL,
    `updated_at` datetime NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `api_notification_card_unique` (`card`),
    INDEX `api_notification_trade_no_index` (`trade_no`),
    INDEX `api_notification_commodity_id_index` (`commodity_id`),
    INDEX `api_notification_extracted_at_index` (`extracted_at`)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;

-- 9. 本地二开：邀请奖励表。
CREATE TABLE IF NOT EXISTS `acg_invite_reward_code` (
    `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` int UNSIGNED NOT NULL,
    `code` varchar(32) NOT NULL,
    `status` tinyint NOT NULL DEFAULT 1,
    `create_time` datetime NOT NULL,
    `update_time` datetime NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `invite_reward_code_user_unique` (`user_id`),
    UNIQUE INDEX `invite_reward_code_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `acg_invite_reward_relation` (
    `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
    `inviter_user_id` int UNSIGNED NOT NULL,
    `invitee_user_id` int UNSIGNED NOT NULL,
    `invite_code` varchar(32) NOT NULL,
    `source` varchar(64) NULL DEFAULT NULL,
    `register_ip` varchar(64) NULL DEFAULT NULL,
    `device_id` varchar(64) NULL DEFAULT NULL,
    `fingerprint` varchar(128) NULL DEFAULT NULL,
    `risk_level` tinyint NOT NULL DEFAULT 0,
    `status` tinyint NOT NULL DEFAULT 1,
    `first_paid_order_id` int UNSIGNED NULL DEFAULT NULL,
    `first_paid_time` datetime NULL DEFAULT NULL,
    `create_time` datetime NOT NULL,
    `update_time` datetime NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `invite_reward_relation_invitee_unique` (`invitee_user_id`),
    INDEX `invite_reward_relation_inviter_index` (`inviter_user_id`),
    INDEX `invite_reward_relation_code_index` (`invite_code`),
    INDEX `invite_reward_relation_ip_index` (`register_ip`),
    INDEX `invite_reward_relation_device_index` (`device_id`),
    INDEX `invite_reward_relation_status_index` (`status`),
    INDEX `invite_reward_relation_create_time_index` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `acg_invite_reward_record` (
    `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
    `relation_id` int UNSIGNED NOT NULL,
    `user_id` int UNSIGNED NOT NULL,
    `role` varchar(16) NOT NULL,
    `trigger_type` varchar(32) NOT NULL,
    `trigger_id` varchar(64) NOT NULL DEFAULT '',
    `reward_type` varchar(32) NOT NULL,
    `reward_payload` text NULL,
    `reward_result` text NULL,
    `status` tinyint NOT NULL DEFAULT 0,
    `remark` varchar(255) NULL DEFAULT NULL,
    `create_time` datetime NOT NULL,
    `grant_time` datetime NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `uk_invite_reward_once` (`relation_id`, `user_id`, `role`, `trigger_type`, `trigger_id`, `reward_type`),
    INDEX `invite_reward_record_relation_index` (`relation_id`),
    INDEX `invite_reward_record_user_index` (`user_id`),
    INDEX `invite_reward_record_status_index` (`status`),
    INDEX `invite_reward_record_create_time_index` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;

-- 10. 结果核验。missing_count 必须全部为 0。
SELECT 'columns' AS check_type, COUNT(*) AS missing_count
FROM (
    SELECT 'acg_manage' AS table_name, 'google_secret' AS column_name
    UNION ALL SELECT 'acg_coupon', 'user_limit'
    UNION ALL SELECT 'acg_commodity', 'sold_base'
) AS expected
LEFT JOIN information_schema.COLUMNS AS actual
    ON actual.TABLE_SCHEMA = @acg_database
   AND actual.TABLE_NAME = expected.table_name
   AND actual.COLUMN_NAME = expected.column_name
WHERE actual.COLUMN_NAME IS NULL;

SELECT 'tables' AS check_type, COUNT(*) AS missing_count
FROM (
    SELECT 'acg_ticket' AS table_name
    UNION ALL SELECT 'acg_ticket_message'
    UNION ALL SELECT 'acg_system_message'
    UNION ALL SELECT 'acg_user_message'
    UNION ALL SELECT 'acg_coupon_openapi_record'
    UNION ALL SELECT 'acg_api_notification_extract_record'
    UNION ALL SELECT 'acg_invite_reward_code'
    UNION ALL SELECT 'acg_invite_reward_relation'
    UNION ALL SELECT 'acg_invite_reward_record'
) AS expected
LEFT JOIN information_schema.TABLES AS actual
    ON actual.TABLE_SCHEMA = @acg_database
   AND actual.TABLE_NAME = expected.table_name
WHERE actual.TABLE_NAME IS NULL;

SELECT 'config' AS check_type,
       IF(EXISTS(
           SELECT 1 FROM `acg_config` WHERE `key` = 'user_center_mobile_theme'
       ), 0, 1) AS missing_count;

SELECT 'coupon_user_limit_index' AS check_type,
       IF(EXISTS(
           SELECT 1
           FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = @acg_database
             AND TABLE_NAME = 'acg_coupon'
             AND COLUMN_NAME = 'user_limit'
             AND SEQ_IN_INDEX = 1
             AND INDEX_NAME <> 'PRIMARY'
       ), 0, 1) AS missing_count;

SELECT 'acg-faka 3.4.9 -> 3.5.4 增量数据库升级完成' AS migration_status;
