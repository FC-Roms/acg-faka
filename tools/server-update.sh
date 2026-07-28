#!/usr/bin/env bash
# acg-faka 服务器安全更新脚本
# 默认用法：bash tools/server-update.sh
# 可选变量：UPDATE_REPO_DIR、UPDATE_REMOTE、UPDATE_BRANCH、UPDATE_BACKUP_DIR、UPDATE_SKIP_MIGRATION、UPDATE_RESTART_COMMAND、UPDATE_WEB_USER
set -Eeuo pipefail

IFS=$'\n\t'

REMOTE="${UPDATE_REMOTE:-origin}"
BRANCH="${UPDATE_BRANCH:-main}"
SKIP_MIGRATION="${UPDATE_SKIP_MIGRATION:-0}"
RESTART_COMMAND="${UPDATE_RESTART_COMMAND:-}"

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
if [[ -n "${UPDATE_REPO_DIR:-}" ]]; then
    REPO_DIR="$(cd -- "$UPDATE_REPO_DIR" && pwd)"
else
    REPO_DIR="$(cd -- "$SCRIPT_DIR/.." && pwd)"
fi
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP_ROOT="${UPDATE_BACKUP_DIR:-$(dirname -- "$REPO_DIR")/acg-faka-server-backups}"
BACKUP_DIR="$BACKUP_ROOT/$STAMP"
LOCK_DIR="${TMPDIR:-/tmp}/acg-faka-server-update.lock"
MIGRATION_FILES=(
    "$REPO_DIR/kernel/Install/Upgrade-3.5.4-to-3.5.6.sql"
    "$REPO_DIR/kernel/Install/Upgrade-3.5.4-seckill.sql"
)

STASH_COMMIT=""
STASH_APPLIED=0
DOCKER_MODE=0
ORIGINAL_HEAD=""
BACKUP_BRANCH="server-backup-$STAMP"
BACKUP_CREATED=0
LOCK_ACQUIRED=0

log() {
    printf '\n[%s] %s\n' "$(date '+%F %T')" "$*"
}

warn() {
    printf '\n[警告] %s\n' "$*" >&2
}

die() {
    printf '\n[失败] %s\n' "$*" >&2
    set +e
    restore_pending_stash
    if [[ "$BACKUP_CREATED" -eq 1 ]]; then
        warn "原始提交已保存在分支 $BACKUP_BRANCH。"
    fi
    exit 1
}

cleanup() {
    if [[ "$LOCK_ACQUIRED" -eq 1 ]]; then
        rmdir -- "$LOCK_DIR" 2>/dev/null || true
    fi
}

restore_pending_stash() {
    set +e

    if [[ -n "$STASH_COMMIT" && "$STASH_APPLIED" -eq 0 ]]; then
        warn "更新中断，正在恢复服务器原有修改……"
        git -C "$REPO_DIR" stash apply "$STASH_COMMIT"
        if [[ $? -ne 0 ]]; then
            warn "自动恢复出现冲突；修改仍保存在 stash $STASH_COMMIT 和备份目录 $BACKUP_DIR。"
        fi
        STASH_APPLIED=1
    fi
}

restore_stash_after_failure() {
    local exit_code=$?
    set +e

    restore_pending_stash
    if [[ "$BACKUP_CREATED" -eq 1 ]]; then
        warn "更新未完成。原始提交已保存在分支 $BACKUP_BRANCH。"
    fi
    exit "$exit_code"
}

trap cleanup EXIT
trap restore_stash_after_failure ERR

backup_path() {
    local relative_path="$1"
    local source_path="$REPO_DIR/$relative_path"
    local target_path="$BACKUP_DIR/$relative_path"

    [[ -e "$source_path" ]] || return 0
    mkdir -p -- "$(dirname -- "$target_path")"
    cp -a -- "$source_path" "$target_path"
}

restore_if_missing() {
    local relative_path="$1"
    local backup_path="$BACKUP_DIR/$relative_path"
    local target_path="$REPO_DIR/$relative_path"

    [[ -e "$backup_path" && ! -e "$target_path" ]] || return 0
    mkdir -p -- "$(dirname -- "$target_path")"
    cp -a -- "$backup_path" "$target_path"
    log "已从备份恢复：$relative_path"
}

detect_compose() {
    if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
        COMPOSE=(docker compose)
        return 0
    fi

    if command -v docker-compose >/dev/null 2>&1; then
        COMPOSE=(docker-compose)
        return 0
    fi

    return 1
}

run_direct_migration() {
    local migration_file="$1"
    local config_file="$REPO_DIR/config/database.php"
    local db_host db_port db_name db_user db_password

    command -v php >/dev/null 2>&1 || return 1
    command -v mysql >/dev/null 2>&1 || return 1
    [[ -f "$config_file" ]] || return 1

    db_host="$(php -r '$c=require $argv[1]; echo $c["host"] ?? "127.0.0.1";' "$config_file")"
    db_port="$(php -r '$c=require $argv[1]; echo $c["port"] ?? "3306";' "$config_file")"
    db_name="$(php -r '$c=require $argv[1]; echo $c["database"] ?? "";' "$config_file")"
    db_user="$(php -r '$c=require $argv[1]; echo $c["username"] ?? "";' "$config_file")"
    db_password="$(php -r '$c=require $argv[1]; echo $c["password"] ?? "";' "$config_file")"

    [[ -n "$db_name" && -n "$db_user" ]] || return 1

    MYSQL_PWD="$db_password" mysql \
        --host="$db_host" \
        --port="$db_port" \
        --user="$db_user" \
        "$db_name" < "$migration_file"
}

run_migration() {
    local migration_file

    if [[ "$SKIP_MIGRATION" == "1" ]]; then
        warn "已按 UPDATE_SKIP_MIGRATION=1 跳过数据库迁移。"
        return 0
    fi

    for migration_file in "${MIGRATION_FILES[@]}"; do
        [[ -f "$migration_file" ]] || die "未找到数据库迁移文件：$migration_file"
    done

    if detect_compose && [[ -n "$("${COMPOSE[@]}" ps -q mysql 2>/dev/null)" ]]; then
        log "检测到 Docker Compose，正在执行数据库迁移……"
        for migration_file in "${MIGRATION_FILES[@]}"; do
            log "正在执行迁移：$(basename -- "$migration_file")"
            "${COMPOSE[@]}" exec -T mysql sh -lc \
                'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' < "$migration_file"
        done
        DOCKER_MODE=1
        return 0
    fi

    log "正在使用服务器 PHP/MySQL 配置执行数据库迁移……"
    for migration_file in "${MIGRATION_FILES[@]}"; do
        log "正在执行迁移：$(basename -- "$migration_file")"
        if ! run_direct_migration "$migration_file"; then
            die "无法自动连接数据库或执行迁移 $(basename -- "$migration_file")。请确认 php、mysql 命令及 config/database.php 配置可用，或临时设置 UPDATE_SKIP_MIGRATION=1。"
        fi
    done
}

restore_application_permissions() {
    local web_user web_group path private_file

    if [[ "$(id -u)" -ne 0 ]]; then
        warn "当前不是 root，已跳过插件和运行目录所有权恢复。"
        return 0
    fi

    web_user="${UPDATE_WEB_USER:-$(ps -eo user=,comm= | awk '$2 ~ /^php-fpm/ && $1 != "root" {print $1; exit}')}"
    if [[ -z "$web_user" ]] || ! id "$web_user" >/dev/null 2>&1; then
        if id www >/dev/null 2>&1; then
            web_user="www"
        elif id www-data >/dev/null 2>&1; then
            web_user="www-data"
        else
            warn "无法识别 PHP-FPM 用户，已跳过插件和运行目录所有权恢复。"
            return 0
        fi
    fi
    web_group="$(id -gn "$web_user")"

    for path in \
        "$REPO_DIR/app/Pay" \
        "$REPO_DIR/app/Plugin" \
        "$REPO_DIR/app/View/User/Theme" \
        "$REPO_DIR/runtime" \
        "$REPO_DIR/assets/cache" \
        "$REPO_DIR/kernel/Install/OS" \
        "$REPO_DIR/kernel/Install/Update"; do
        [[ -e "$path" ]] || continue
        chown -R "$web_user:$web_group" "$path"
        chmod -R u+rwX "$path"
    done

    if [[ -f "$REPO_DIR/favicon.ico" && ! -L "$REPO_DIR/favicon.ico" ]]; then
        chown "$web_user:$web_group" "$REPO_DIR/favicon.ico"
        chmod 644 "$REPO_DIR/favicon.ico"
    fi

    shopt -s nullglob
    for private_file in \
        "$REPO_DIR/config/database.local.php" \
        "$REPO_DIR/config/store.php" \
        "$REPO_DIR/.env" \
        "$REPO_DIR"/.env.* \
        "$REPO_DIR/.user.ini"; do
        [[ -f "$private_file" ]] || continue
        [[ "$(basename -- "$private_file")" == ".env.example" ]] && continue
        chown "$web_user:$web_group" "$private_file"
        chmod 600 "$private_file"
    done
    shopt -u nullglob

    log "已恢复插件、支付接口、主题和运行目录写入权限：$web_user:$web_group"
}

clear_cache() {
    if [[ "$DOCKER_MODE" -eq 1 ]]; then
        log "正在重建应用容器……"
        "${COMPOSE[@]}" up -d --build app

        log "正在清理容器内视图缓存……"
        "${COMPOSE[@]}" exec -T app sh -lc \
            'if [ -d /var/www/html/runtime/view ]; then find /var/www/html/runtime/view -mindepth 1 -delete; fi'
        return 0
    fi

    if [[ -d "$REPO_DIR/runtime/view" ]]; then
        log "正在清理视图缓存……"
        find "$REPO_DIR/runtime/view" -mindepth 1 -delete
    fi

    if [[ -n "$RESTART_COMMAND" ]]; then
        log "正在执行服务重启命令……"
        bash -lc "$RESTART_COMMAND"
    else
        warn "非 Docker 部署未自动重启 PHP；如启用了 OPcache，请在宝塔或 systemctl 中重启 PHP 服务。"
    fi
}

main() {
    cd -- "$REPO_DIR"

    git rev-parse --is-inside-work-tree >/dev/null 2>&1 || die "$REPO_DIR 不是 Git 仓库。"

    if ! mkdir -- "$LOCK_DIR" 2>/dev/null; then
        die "检测到另一个更新任务正在运行：$LOCK_DIR"
    fi
    LOCK_ACQUIRED=1

    local current_branch remote_head stash_before stash_after stash_name env_path relative_path original_umask
    current_branch="$(git branch --show-current)"
    [[ "$current_branch" == "$BRANCH" ]] || die "当前分支为 $current_branch，请切换到 $BRANCH 后重试。"

    git remote get-url "$REMOTE" >/dev/null 2>&1 || die "Git 远端 $REMOTE 不存在。"

    ORIGINAL_HEAD="$(git rev-parse HEAD)"
    original_umask="$(umask)"
    umask 077
    mkdir -p -- "$BACKUP_DIR"
    umask "$original_umask"

    log "正在备份服务器配置和本地修改……"
    git branch "$BACKUP_BRANCH" "$ORIGINAL_HEAD"
    BACKUP_CREATED=1
    git status --short > "$BACKUP_DIR/git-status.txt"
    git diff HEAD --binary > "$BACKUP_DIR/local-changes.patch"
    printf '%s\n' "$ORIGINAL_HEAD" > "$BACKUP_DIR/original-head.txt"

    shopt -s nullglob
    for env_path in "$REPO_DIR"/.env "$REPO_DIR"/.env.*; do
        relative_path="${env_path#"$REPO_DIR"/}"
        [[ "$relative_path" == ".env.example" ]] && continue
        backup_path "$relative_path"
    done
    shopt -u nullglob

    backup_path ".user.ini"
    backup_path "config/database.local.php"
    backup_path "kernel/Install/Lock"
    backup_path "secrets"
    backup_path "private"

    stash_name="server-local-$STAMP"
    stash_before="$(git rev-parse -q --verify refs/stash 2>/dev/null || true)"
    git stash push --include-untracked --message "$stash_name"
    stash_after="$(git rev-parse -q --verify refs/stash 2>/dev/null || true)"

    if [[ -n "$stash_after" && "$stash_after" != "$stash_before" ]]; then
        STASH_COMMIT="$stash_after"
        printf '%s\n' "$STASH_COMMIT" > "$BACKUP_DIR/stash-commit.txt"
        log "服务器修改已暂存：$STASH_COMMIT"
    else
        log "服务器没有需要暂存的 tracked/untracked 修改。"
    fi

    log "正在获取 $REMOTE/$BRANCH 最新代码……"
    git fetch --prune "$REMOTE" "$BRANCH"
    remote_head="$(git rev-parse FETCH_HEAD)"

    if ! git merge-base --is-ancestor "$ORIGINAL_HEAD" "$remote_head"; then
        die "服务器分支与远端已经分叉，已停止更新，未执行强制覆盖。"
    fi

    # Git 以 root 更新时也要保证 PHP-FPM 可读，避免备份阶段的 077 权限泄漏到程序文件。
    umask 022
    git merge --ff-only "$remote_head"
    umask "$original_umask"

    shopt -s nullglob
    for env_path in "$BACKUP_DIR"/.env "$BACKUP_DIR"/.env.*; do
        relative_path="${env_path#"$BACKUP_DIR"/}"
        restore_if_missing "$relative_path"
    done
    shopt -u nullglob

    restore_if_missing ".user.ini"
    restore_if_missing "config/database.local.php"
    restore_if_missing "kernel/Install/Lock"
    restore_if_missing "secrets"
    restore_if_missing "private"

    if [[ -n "$STASH_COMMIT" ]]; then
        log "正在重新应用服务器本地修改……"
        STASH_APPLIED=1
        if ! git stash apply "$STASH_COMMIT"; then
            warn "本地修改与新代码存在冲突。请执行 git status 查看；原修改仍保存在 stash $STASH_COMMIT。"
            exit 1
        fi
    fi

    restore_application_permissions
    run_migration
    clear_cache

    log "更新完成。"
    printf '当前提交：%s\n' "$(git log -1 --oneline)"
    printf '备份目录：%s\n' "$BACKUP_DIR"
    printf '备份分支：%s\n' "$BACKUP_BRANCH"
    if [[ -n "$STASH_COMMIT" ]]; then
        printf '保留的 stash：%s（验证无误后可手动删除）\n' "$STASH_COMMIT"
    fi
    printf '工作区状态：\n'
    git status --short
}

main "$@"
