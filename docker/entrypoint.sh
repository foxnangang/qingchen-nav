#!/bin/sh
# 卿辰导航 — 容器启动脚本
#
# 职责：
#   1. 保证 data 目录存在且 www-data 可写（volume 首次挂载时属主可能是 root）
#   2. 补 data/.htaccess（bind mount 会盖掉镜像里那份）
#   3. 设好 PHP 时区
#   4. 若配置了 QC_ADMIN_PASS 且还没有数据库 → 无人值守自动安装
set -e

DATA="/var/www/html/data"
DB="$DATA/nav.db"

mkdir -p "$DATA/fav" 2>/dev/null || true
chown -R www-data:www-data "$DATA" 2>/dev/null || true

# 用 ./data bind mount 时宿主机空目录会盖掉镜像里的 .htaccess，这里补一份。
# 真正的兜底是 Apache 配置里的 <Directory .../data> Require all denied，
# 这条只是让 .htaccess 方案在非 Apache（nginx + php-fpm）下也成立。
if [ ! -f "$DATA/.htaccess" ]; then
    printf 'Require all denied\n' > "$DATA/.htaccess" 2>/dev/null || true
    chown www-data:www-data "$DATA/.htaccess" 2>/dev/null || true
fi

# PHP 的时区不认系统 TZ，得写进 ini
if [ -n "${TZ:-}" ]; then
    echo "date.timezone=${TZ}" > /usr/local/etc/php/conf.d/zz-timezone.ini
fi

if [ ! -f "$DB" ]; then
    if [ -n "${QC_ADMIN_PASS:-}" ]; then
        echo "[qcnav] 未发现数据库，开始自动初始化…"
        if php /var/www/html/bin/autoinstall.php; then
            # 装完就把安装页停用：容器重建后 install.php 会跟着镜像回来，
            # 留着等于给所有人一个安装入口
            if [ -f /var/www/html/install.php ]; then
                mv /var/www/html/install.php /var/www/html/install.php.disabled
                echo "[qcnav] install.php 已禁用"
            fi
            chown -R www-data:www-data "$DATA" 2>/dev/null || true
            echo "[qcnav] 初始化完成"
        else
            echo "[qcnav] 自动初始化失败，请打开 /install.php 手动安装" >&2
        fi
    else
        echo "[qcnav] 未设置 QC_ADMIN_PASS —— 请打开网页用 install.php 完成安装"
    fi
else
    echo "[qcnav] 已检测到数据库，跳过初始化"
fi

# 只要数据库已经存在，就停用 install.php。
# 不能只在「本次自动安装成功」时停 —— 老用户升级重建容器时，镜像里的
# install.php 会跟着回来，而上面走的是「跳过初始化」分支，就没人管它了。
# （它本身有 $installed 判断，不会让你重装，但没必要把入口留在公网上。）
if [ -f "$DB" ] && [ -f /var/www/html/install.php ]; then
    mv /var/www/html/install.php /var/www/html/install.php.disabled
    echo "[qcnav] install.php 已禁用"
fi

exec "$@"
