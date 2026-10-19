# syntax=docker/dockerfile:1

# ==========================================================================
# 卿辰导航 — 单容器镜像（Apache + PHP 8.2）
#
# 基础镜像已内置 pdo_sqlite / sqlite3 / curl / mbstring / json / openssl，
# 所以这里不需要 apt 装任何 PHP 扩展，镜像更小、构建更快。
#
# 构建：docker build -t qcnav .
# 运行：docker run -d -p 18090:80 -e QC_ADMIN_PASS='你的密码' -v qcnav-data:/var/www/html/data qcnav
# ==========================================================================

FROM php:8.2-apache

LABEL org.opencontainers.image.title="卿辰导航 (qingchen-nav)" \
      org.opencontainers.image.description="自带后台的个人站点导航页，PHP + SQLite，零第三方依赖" \
      org.opencontainers.image.source="https://github.com/foxnangang/qingchen-nav" \
      org.opencontainers.image.licenses="MIT"

# ---------- Apache ----------
# ⚠️ php:8.2-apache 默认 AllowOverride None，.htaccess 完全不生效。
#    不改成 All 的话，data/.htaccess 里的「拒绝访问」规则形同虚设，
#    数据库 data/nav.db 会变成可以直接下载的文件。
COPY docker/qcnav-apache.conf /etc/apache2/conf-available/qcnav.conf
RUN a2enconf qcnav \
 && a2enmod rewrite headers

# ---------- 应用 ----------
WORKDIR /var/www/html
COPY . /var/www/html/

# 镜像里绝不该带运行时数据；data 目录留给 volume
RUN set -eux; \
    rm -rf /var/www/html/data/*.db \
           /var/www/html/data/*.db-wal \
           /var/www/html/data/*.db-shm \
           /var/www/html/data/fav; \
    mkdir -p /var/www/html/data/fav; \
    chmod 775 /var/www/html/data; \
    chown -R www-data:www-data /var/www/html

COPY docker/entrypoint.sh /usr/local/bin/qcnav-entrypoint
RUN chmod +x /usr/local/bin/qcnav-entrypoint

# ---------- 运行 ----------
ENV TZ=Asia/Shanghai \
    QC_TITLE=卿辰导航 \
    QC_ADMIN_USER=admin

EXPOSE 80

# 用 PHP 自己发请求做健康检查，省得为 healthcheck 单独装 curl 包
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD php -r '$r=@file_get_contents("http://127.0.0.1/index.php");exit($r===false?1:0);'

ENTRYPOINT ["qcnav-entrypoint"]
CMD ["apache2-foreground"]
