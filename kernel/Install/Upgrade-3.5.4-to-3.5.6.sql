-- acg-faka 3.5.4/3.5.5 升级到 3.5.6。
-- 脚本可重复执行，仅补齐 3.5.6 新增的管理员会话表和回调 IP 白名单配置。
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `acg_manage_session` (
    `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键id',
    `manage_id` int UNSIGNED NOT NULL COMMENT '管理员id',
    `session_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '会话标识SHA-256哈希',
    `device_type` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '设备类型',
    `device_name` varchar(96) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '设备名称',
    `user_agent` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '登录User-Agent',
    `login_ip` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '登录IP',
    `last_ip` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '最近IP',
    `created_time` datetime NOT NULL COMMENT '登录时间',
    `last_seen_time` datetime NOT NULL COMMENT '最近活跃时间',
    `expires_time` datetime NOT NULL COMMENT '过期时间',
    `revoked_time` datetime NULL DEFAULT NULL COMMENT '撤销时间',
    PRIMARY KEY (`id`) USING BTREE,
    UNIQUE INDEX `session_hash` (`session_hash` ASC) USING BTREE,
    INDEX `manage_active` (`manage_id` ASC, `revoked_time` ASC, `expires_time` ASC) USING BTREE,
    INDEX `last_seen_time` (`last_seen_time` ASC) USING BTREE,
    CONSTRAINT `acg_manage_session_ibfk_1` FOREIGN KEY (`manage_id`) REFERENCES `acg_manage` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=1 CHARACTER SET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;

INSERT IGNORE INTO `acg_config` (`key`, `value`)
VALUES ('callback_ip_whitelist', '0');

INSERT IGNORE INTO `acg_config` (`key`, `value`)
VALUES ('callback_ip_whitelist_rules', '');
