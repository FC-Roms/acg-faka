<?php
declare(strict_types=1);

$localConfig = __DIR__ . '/database.local.php';

if (is_file($localConfig)) {
    return require $localConfig;
}

// 公开仓库只保留不可用的安全默认值；安装器会把真实凭据写入已忽略的 database.local.php。
return [
    'driver' => 'mysql',
    'host' => '127.0.0.1',
    'database' => '',
    'username' => '',
    'password' => '',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => 'acg_',
];
