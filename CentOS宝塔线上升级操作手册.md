# acg-faka 3.4.9 → 3.5.4 CentOS/宝塔线上升级操作手册

## 1. 升级目标与安全边界

本手册用于将已有业务数据的 acg-faka 3.4.9 站点升级到本仓库 3.5.4，并保留线上数据库、上传文件、数据库配置、插件配置和本地扩展。

本次升级采用“新目录准备 → 数据库增量迁移 → 同文件系统目录切换”的方式，不在正在运行且可能有本地改动的目录中直接执行 `git pull`。

必须遵守以下规则：

1. 先停止网站写入，再同时备份站点文件和数据库。
2. 数据库只执行 `kernel/Install/Upgrade-3.4.9-to-3.5.4.sql`。
3. 绝对不要在已有站点执行 `kernel/Install/Install.sql`，它是全新安装脚本，会破坏已有表。
4. 不把旧版 `config/database.php` 覆盖到新版。真实数据库配置应恢复到 `config/database.local.php`。
5. 不用旧版 `app/Plugin`、`app/Pay` 或主题目录整体覆盖新版，只单独处理仓库中不存在的第三方扩展。
6. SQL 中的 DDL 会自动提交，数据库回滚依赖升级前备份，不能依赖一个事务整体撤销。
7. 以下命令中的域名、数据库名、数据库用户和 PHP 版本均为示例占位符，执行前必须替换。

## 2. 本次数据库增量

迁移脚本只在缺失时新增以下对象，不改写已有业务记录：

| 类型 | 对象 |
| --- | --- |
| 字段 | `acg_manage.google_secret` |
| 字段 | `acg_coupon.user_limit` 及对应索引 |
| 字段 | `acg_commodity.sold_base` |
| 配置 | `acg_config.user_center_mobile_theme`，仅缺失时写入默认值 `0` |
| 上游表 | `acg_ticket`、`acg_ticket_message`、`acg_system_message`、`acg_user_message` |
| 二开表 | `acg_coupon_openapi_record`、`acg_api_notification_extract_record` |
| 二开表 | `acg_invite_reward_code`、`acg_invite_reward_relation`、`acg_invite_reward_record` |

脚本固定适用于 `acg_` 前缀，兼容 MySQL 5.7+ 和相应 MariaDB 版本，并可重复执行。

## 3. 升级前准备

### 3.1 确认运行环境

在宝塔终端或 SSH 中执行：

```bash
php -v
mysql --version
git --version
df -h
```

要求：

- PHP 不低于 8.0，并与宝塔站点当前 PHP 版本一致。
- 推荐 MySQL 5.7/8.0 或兼容 MariaDB。
- 备份盘剩余空间至少大于“当前站点目录 + 当前数据库”两倍。
- 记录宝塔中站点使用的 PHP 版本、运行用户、网站根目录、SSL 和伪静态配置。

### 3.2 设置本次发布变量

以下示例假定线上正式目录为 `/www/wwwroot/example.com`。变量只在当前 SSH 会话有效：

```bash
export SITE_ROOT=/www/wwwroot/example.com
export DOMAIN=example.com
export DB_NAME=请替换为数据库名
export DB_USER=请替换为数据库用户
export REPO_URL=https://github.com/FC-Roms/acg-faka.git
export STAMP=$(date +%Y%m%d-%H%M%S)
export BACKUP_ROOT=/www/backup/acg-faka/$STAMP
export RELEASE_ROOT=/www/wwwroot/example.com-release-$STAMP
export ROLLBACK_ROOT=/www/wwwroot/example.com-rollback-$STAMP

mkdir -p "$BACKUP_ROOT"
chmod 700 "$BACKUP_ROOT"
```

不要把数据库密码、Token 或其他密钥写进变量、命令、脚本、Git URL 或 shell 历史。后面的 `mysql -p`/`mysqldump -p` 会交互式询问密码。

### 3.3 记录当前状态

```bash
cd "$SITE_ROOT"
pwd
git status --short --branch
git remote -v
git rev-parse HEAD | tee "$BACKUP_ROOT/old-commit.txt"
php -r "require '$SITE_ROOT/config/app.php'; echo '配置文件可读取', PHP_EOL;"
```

如果 `git status` 显示线上有未提交改动，不要清理、覆盖或强制拉取。完整站点备份会保留这些内容，后续只把确认需要的私密配置和第三方扩展恢复到新目录。

### 3.4 停止网站写入

在宝塔面板中进入“网站”，停止该站点；确认前台、后台、支付回调和 Open API 均已无法产生写请求。不要只关闭浏览器页面。

如果站点不能停止，至少先在后台开启维护状态，并在 Nginx 层临时限制除本机外的访问。但完整停止站点风险最低。

## 4. 完整备份

### 4.1 宝塔备份

先在宝塔面板分别执行一次：

1. 网站 → 备份：生成站点完整备份。
2. 数据库 → 备份：生成数据库完整备份。

确认两份备份状态均为成功，再继续执行命令行备份。双份备份用于规避单一工具失败。

### 4.2 命令行完整文件备份

```bash
tar --xattrs --acls -czf "$BACKUP_ROOT/site-before-upgrade.tar.gz" \
  -C "$(dirname "$SITE_ROOT")" "$(basename "$SITE_ROOT")"

tar -tzf "$BACKUP_ROOT/site-before-upgrade.tar.gz" >/dev/null
ls -lh "$BACKUP_ROOT/site-before-upgrade.tar.gz"
```

完整备份中必须能够找到以下内容（存在时）：

- `config/database.local.php`：已完成配置隔离的站点使用的真实数据库连接配置。
- `config/database.php`：仅作为旧站兼容备份；旧版可能仍把真实数据库配置保存在这里。
- `config/coupon_api.php`：仅用于检查旧环境是否误存 Token；新版优先改用环境变量。
- `kernel/Plugin.php`：插件启停状态和插件配置。
- `kernel/Install/Lock`：安装锁。
- `assets/cache`：上传文件、工单/消息图片和远程缓存。
- `runtime`：只用于故障取证，不整体恢复旧缓存。
- `.user.ini`：宝塔的 `open_basedir` 配置。
- 线上额外安装的支付插件、第三方插件和自定义主题。

### 4.3 命令行数据库备份

站点已经停止写入后执行：

```bash
if ! mysqldump -u"$DB_USER" -p \
  --single-transaction \
  --quick \
  --routines \
  --triggers \
  --events \
  --hex-blob \
  --default-character-set=utf8mb4 \
  "$DB_NAME" > "$BACKUP_ROOT/database-before-upgrade.sql"; then
  echo "数据库导出失败，禁止继续升级"
  exit 1
fi

gzip -1 "$BACKUP_ROOT/database-before-upgrade.sql"

gzip -t "$BACKUP_ROOT/database-before-upgrade.sql.gz"
ls -lh "$BACKUP_ROOT/database-before-upgrade.sql.gz"
```

`acg_config` 在旧安装中可能是 MyISAM 表，因此 `--single-transaction` 不能单独保证它的一致性；先停止网站写入是数据库备份一致性的必要条件。

如果数据库账号没有导出 routines/events 的权限，应使用有备份权限的数据库账号，不要跳过数据库备份。

## 5. 获取并核对新版本

### 5.1 先获取远端提交和迁移脚本

```bash
git -C "$SITE_ROOT" fetch "$REPO_URL" main
git -C "$SITE_ROOT" rev-parse FETCH_HEAD | tee "$BACKUP_ROOT/new-commit.txt"
git -C "$SITE_ROOT" show \
  FETCH_HEAD:kernel/Install/Upgrade-3.4.9-to-3.5.4.sql \
  > "$BACKUP_ROOT/Upgrade-3.4.9-to-3.5.4.sql"

sha256sum "$BACKUP_ROOT/Upgrade-3.4.9-to-3.5.4.sql" \
  | tee "$BACKUP_ROOT/Upgrade-3.4.9-to-3.5.4.sql.sha256"
```

这一步只读取远端内容，不改变当前运行目录的工作树。

上述命令固定从本仓库公开地址读取，不依赖旧目录的 `origin` 指向。`new-commit.txt` 是本次上线和回滚审计所用的唯一目标提交。

### 5.2 克隆到全新的发布目录

```bash
git clone --branch main --single-branch "$REPO_URL" "$RELEASE_ROOT"
git -C "$RELEASE_ROOT" checkout --detach "$(cat "$BACKUP_ROOT/new-commit.txt")"

git -C "$RELEASE_ROOT" rev-parse HEAD
cat "$BACKUP_ROOT/new-commit.txt"
git -C "$RELEASE_ROOT" status --short --branch
```

`RELEASE_ROOT` 的 HEAD 必须与 `new-commit.txt` 完全相同，且工作树必须干净。

仓库已经跟踪 `vendor`，本次依赖清单没有变化，默认不运行 `composer update`。只有确认 `vendor/autoload.php` 缺失时，才在相同 PHP 主版本下执行：

```bash
cd "$RELEASE_ROOT"
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
```

禁止执行 `composer update`，以免线上生成未经验证的新依赖版本。

## 6. 恢复配置与持久化文件到新目录

### 6.1 恢复必要文件

```bash
if [ -s "$SITE_ROOT/config/database.local.php" ]; then
  DB_CONFIG_SOURCE="$SITE_ROOT/config/database.local.php"
else
  DB_CONFIG_SOURCE="$SITE_ROOT/config/database.php"
  echo "未找到 database.local.php，将旧站 database.php 迁移为新版本地配置"
fi

install -m 640 "$DB_CONFIG_SOURCE" "$RELEASE_ROOT/config/database.local.php"

php -r '
$config = require $argv[1];
if (!is_array($config) || empty($config["host"]) || empty($config["database"]) || empty($config["username"]) || ($config["prefix"] ?? "") !== "acg_") {
    fwrite(STDERR, "数据库本地配置格式无效\n");
    exit(1);
}
if ((string)$config["database"] !== (string)$argv[2]) {
    fwrite(STDERR, "DB_NAME 与站点配置的数据库名不一致\n");
    exit(1);
}
echo "数据库本地配置格式检查通过\n";
' "$RELEASE_ROOT/config/database.local.php" "$DB_NAME"

install -m 640 "$SITE_ROOT/kernel/Plugin.php" \
  "$RELEASE_ROOT/kernel/Plugin.php"

install -m 640 "$SITE_ROOT/kernel/Install/Lock" \
  "$RELEASE_ROOT/kernel/Install/Lock"

mkdir -p "$RELEASE_ROOT/assets/cache" "$RELEASE_ROOT/runtime"
rsync -a "$SITE_ROOT/assets/cache/" "$RELEASE_ROOT/assets/cache/"

if [ -f "$SITE_ROOT/.user.ini" ]; then
  cp -a "$SITE_ROOT/.user.ini" "$RELEASE_ROOT/.user.ini"
fi
```

关键说明：

- 新版真实配置只放在 `database.local.php`。旧站若尚未分离配置，只把旧 `database.php` 的配置内容迁移到这个本地文件，绝不覆盖新版 `config/database.php` 安全加载器。
- `kernel/Plugin.php` 用于恢复 ApiNotification、InviteReward、Remember 等插件的配置和启停状态。
- 不恢复旧 `runtime` 内容，让 3.5.4 重新生成缓存。
- 不从旧目录覆盖仓库内已有的 ApiNotification、InviteReward、Remember、Cartoon 或 MountFuji 代码。

### 6.2 处理 Coupon Open API Token

`config/coupon_api.php` 应保持仓库中的无密钥版本。Token 优先通过 PHP-FPM 环境变量 `ACG_COUPON_API_TOKEN` 提供。

在宝塔面板“软件商店 → 当前 PHP 版本 → 配置修改”中找到 PHP-FPM 的 `[www]` 进程池配置，加入：

```ini
env[ACG_COUPON_API_TOKEN] = "在宝塔面板中手工粘贴真实Token"
```

不要把真实 Token 写进本文档、Git 文件或终端命令历史。保存后先检查 PHP-FPM 配置，再重载 PHP-FPM。不同宝塔/PHP 版本的配置路径不同，以面板显示的当前版本配置为准。

### 6.3 单独处理第三方扩展

先仅查看旧目录中可能额外存在的扩展：

```bash
find "$SITE_ROOT/app/Plugin" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort \
  > "$BACKUP_ROOT/old-plugins.txt"
find "$RELEASE_ROOT/app/Plugin" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort \
  > "$BACKUP_ROOT/new-plugins.txt"
comm -23 "$BACKUP_ROOT/old-plugins.txt" "$BACKUP_ROOT/new-plugins.txt"
```

对输出的每个第三方插件逐个确认 3.5.4/PHP 版本兼容性后再复制。支付插件和自定义主题使用相同方式逐个比对。不要直接执行整个目录覆盖，因为这会把已升级的核心插件或主题降回旧版。

## 7. 新目录静态检查

```bash
test -f "$RELEASE_ROOT/index.php"
test -f "$RELEASE_ROOT/vendor/autoload.php"
test -f "$RELEASE_ROOT/config/database.local.php"
test -f "$RELEASE_ROOT/kernel/Install/Lock"
test -f "$RELEASE_ROOT/kernel/Install/Upgrade-3.4.9-to-3.5.4.sql"

php -l "$RELEASE_ROOT/config/database.php"
php -l "$RELEASE_ROOT/config/database.local.php"
php -l "$RELEASE_ROOT/config/app.php"
```

检查迁移脚本中没有业务删除语句：

```bash
grep -nEi '^[[:space:]]*(DROP|DELETE|TRUNCATE)[[:space:]]' \
  "$BACKUP_ROOT/Upgrade-3.4.9-to-3.5.4.sql" || true
```

正常结果应为空。此处不要运行 `kernel/Install/Install.sql`。

## 8. 执行数据库增量迁移

仍然保持网站停止状态。执行时启用 `pipefail`，确保即使日志经过 `tee`，MySQL 错误仍会返回失败状态：

```bash
set -o pipefail
mysql --default-character-set=utf8mb4 \
  -u"$DB_USER" -p "$DB_NAME" \
  < "$BACKUP_ROOT/Upgrade-3.4.9-to-3.5.4.sql" \
  2>&1 | tee "$BACKUP_ROOT/database-upgrade.log"
MYSQL_STATUS=${PIPESTATUS[0]}
set +o pipefail

if [ "$MYSQL_STATUS" -ne 0 ]; then
  echo "数据库迁移失败，禁止切换网站目录。请查看 $BACKUP_ROOT/database-upgrade.log"
  exit 1
fi
```

日志末尾四项 `missing_count` 必须全部为 `0`，并出现“增量数据库升级完成”。再执行一次只读核验：

```bash
mysql -u"$DB_USER" -p "$DB_NAME" -e "
SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'acg_ticket','acg_ticket_message','acg_system_message','acg_user_message',
    'acg_coupon_openapi_record','acg_api_notification_extract_record',
    'acg_invite_reward_code','acg_invite_reward_relation','acg_invite_reward_record'
  )
ORDER BY TABLE_NAME;
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND (
    (TABLE_NAME='acg_manage' AND COLUMN_NAME='google_secret') OR
    (TABLE_NAME='acg_coupon' AND COLUMN_NAME='user_limit') OR
    (TABLE_NAME='acg_commodity' AND COLUMN_NAME='sold_base')
  );
SELECT \`key\`, \`value\` FROM acg_config WHERE \`key\`='user_center_mobile_theme';
" | tee "$BACKUP_ROOT/database-verify.log"
```

如果迁移中断，不要反复尝试不同 SQL。先保留日志并定位失败对象；本脚本可以在原因修复后重复执行，但必须确认前一次执行到哪一步。

## 9. 切换到新版本

### 9.1 设置权限

宝塔默认 PHP-FPM/Nginx 用户通常为 `www`；如果站点使用其他用户，请替换：

```bash
chown -R www:www "$RELEASE_ROOT"

chmod 640 "$RELEASE_ROOT/config/database.local.php"
chmod 640 "$RELEASE_ROOT/kernel/Plugin.php"
chmod 640 "$RELEASE_ROOT/kernel/Install/Lock"
chmod -R u+rwX,g+rwX,o-rwx "$RELEASE_ROOT/runtime" "$RELEASE_ROOT/assets/cache"
```

### 9.2 同文件系统目录切换

确认三个路径值无误并且 `RELEASE_ROOT` 与 `SITE_ROOT` 位于同一个文件系统：

```bash
printf 'SITE_ROOT=%s\nRELEASE_ROOT=%s\nROLLBACK_ROOT=%s\n' \
  "$SITE_ROOT" "$RELEASE_ROOT" "$ROLLBACK_ROOT"
df -P "$SITE_ROOT" "$RELEASE_ROOT"
```

确认无误后执行：

```bash
mv "$SITE_ROOT" "$ROLLBACK_ROOT"
mv "$RELEASE_ROOT" "$SITE_ROOT"
```

最终正式路径仍为宝塔原来配置的 `SITE_ROOT`，所以通常不需要修改 Nginx 网站根目录。旧版本完整保留在 `ROLLBACK_ROOT`。

## 10. 重载服务并解除维护

1. 在宝塔面板重启当前站点使用的 PHP-FPM，使 OPcache 和环境变量生效。
2. 检查 PHP-FPM 启动成功，确认没有配置语法错误。
3. 在宝塔面板启动网站。
4. 如有独立队列、计划任务或守护进程，确认其工作目录仍是最终的 `SITE_ROOT` 后再重启。

执行基础探活：

```bash
curl -I -H "Host: $DOMAIN" http://127.0.0.1/
curl -I "https://$DOMAIN/"
git -C "$SITE_ROOT" rev-parse HEAD
git -C "$SITE_ROOT" status --short --branch
```

HTTP 应返回预期的 `200` 或正常跳转，线上 HEAD 应等于 `new-commit.txt`。`database.local.php`、`runtime`、`assets/cache` 和安装锁已由 `.gitignore` 排除；恢复真实配置后，已被仓库跟踪的 `kernel/Plugin.php` 可能显示为本地修改，这是预期现象，绝不能提交到公开仓库。

## 11. 业务验收清单

按以下顺序做只读或小额可控验收：

1. 首页、商品详情、分类、Cartoon/MountFuji PC 与移动端主题。
2. 后台登录、Google 2FA 配置入口、商品、优惠券、订单列表。
3. 用户注册/登录、用户中心、优惠券钱包、邀请奖励页面。
4. 工单创建/回复、系统消息发送/阅读。
5. 商品虚拟销量 `sold_base` 的后台保存与前台展示。
6. QQ 专属优惠券创建、绑定限制与每会员限用一次规则。
7. ApiNotification 配置、支付通知、Card-Extract 回传和历史提取状态。
8. Remember 插件登录保持功能。
9. 上传一张测试图片并确认 `assets/cache` 可读写。
10. 使用测试商品完成一笔最低金额订单，确认支付回调、自动发货、邮件/通知和订单查询链路。

同时观察宝塔的网站错误日志、PHP 错误日志和插件运行日志。验收未通过前不要删除旧目录和备份。

## 12. 回滚方案

### 12.1 只回滚文件版本

新增字段和新表对旧版代码通常向后兼容。若新代码异常但数据库迁移成功，优先只回滚文件：

```bash
# 先在宝塔停止网站
export FAILED_ROOT=/www/wwwroot/example.com-failed-$STAMP
mv "$SITE_ROOT" "$FAILED_ROOT"
mv "$ROLLBACK_ROOT" "$SITE_ROOT"
# 在宝塔重启当前 PHP-FPM，再启动网站
```

不要使用 `git reset --hard` 回滚线上目录，以免覆盖私密配置、上传文件或未提交的线上内容。

### 12.2 恢复数据库备份

只有确认增量 DDL 导致数据库级故障、且必须恢复到升级前精确状态时才恢复数据库。恢复会覆盖备份时间点后的数据，因此网站必须保持停止状态：

```bash
gzip -t "$BACKUP_ROOT/database-before-upgrade.sql.gz"
set -o pipefail
gunzip -c "$BACKUP_ROOT/database-before-upgrade.sql.gz" \
  | mysql -u"$DB_USER" -p "$DB_NAME"
RESTORE_STATUS=$?
set +o pipefail

if [ "$RESTORE_STATUS" -ne 0 ]; then
  echo "数据库恢复失败，网站必须继续保持停止状态"
  exit 1
fi
```

恢复后重新执行第 8 节的只读核验，并确认旧版本站点能正常工作。

## 13. 后续日常升级方式

以后仍按以下流程更新：

1. `git fetch origin main`，先查看提交和变更，不在运行目录直接 `git pull`。
2. 停止写入并备份文件、数据库。
3. 克隆/检出到新的 release 目录。
4. 恢复 `database.local.php`、`kernel/Plugin.php`、`Install/Lock` 和 `assets/cache`。
5. 仅执行对应版本的增量 SQL。
6. 静态检查后切换目录，重启 PHP-FPM，完成业务验收。
7. 至少保留一个已验证旧版本目录和一份可用数据库备份。

升级确认稳定后，再按备份保留策略清理旧 release。清理前必须再次核对绝对路径，不能删除当前 `SITE_ROOT`、最新数据库备份或仓库外私密备份。
