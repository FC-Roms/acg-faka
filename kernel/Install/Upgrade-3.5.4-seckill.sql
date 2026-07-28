-- acg-faka 3.5.4 秒杀增强：秒杀价格与到期处理
-- 可重复执行，执行前请完成数据库备份。
SET NAMES utf8mb4;
SET @acg_database := DATABASE();

SELECT COUNT(*) INTO @acg_exists
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @acg_database
  AND TABLE_NAME = 'acg_commodity'
  AND COLUMN_NAME = 'seckill_price';

SET @acg_sql := IF(
    @acg_exists > 0,
    'SELECT ''跳过 acg_commodity.seckill_price：字段已存在'' AS migration_status',
    'ALTER TABLE `acg_commodity` ADD COLUMN `seckill_price` decimal(10, 2) UNSIGNED NULL DEFAULT NULL COMMENT ''秒杀价格'' AFTER `seckill_end_time`'
);
PREPARE acg_stmt FROM @acg_sql;
EXECUTE acg_stmt;
DEALLOCATE PREPARE acg_stmt;

SELECT COUNT(*) INTO @acg_exists
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @acg_database
  AND TABLE_NAME = 'acg_commodity'
  AND COLUMN_NAME = 'seckill_expire_action';

SET @acg_sql := IF(
    @acg_exists > 0,
    'SELECT ''跳过 acg_commodity.seckill_expire_action：字段已存在'' AS migration_status',
    'ALTER TABLE `acg_commodity` ADD COLUMN `seckill_expire_action` tinyint UNSIGNED NOT NULL DEFAULT 0 COMMENT ''秒杀到期处理：0=恢复原价，1=下架商品'' AFTER `seckill_price`'
);
PREPARE acg_stmt FROM @acg_sql;
EXECUTE acg_stmt;
DEALLOCATE PREPARE acg_stmt;
