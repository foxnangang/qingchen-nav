<?php
declare(strict_types=1);

/**
 * 卿辰导航 — 无人值守安装（容器 / CLI 用）
 *
 * 由 docker/entrypoint.sh 在首次启动时调用。
 * 读取环境变量建库并写入管理员账号，等价于走一遍 install.php，但不需要人点。
 *
 * 环境变量：
 *   QC_ADMIN_PASS  必填，≥8 位
 *   QC_ADMIN_USER  可选，默认 admin
 *   QC_TITLE       可选，默认「卿辰导航」
 *   QC_SUBTITLE    可选
 *   QC_OWNER       可选
 *
 * @since  2026-10-19
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/lib.php';

function env_str(string $key, string $def = ''): string
{
    $v = getenv($key);
    return ($v === false || trim($v) === '') ? $def : trim($v);
}

$pass = env_str('QC_ADMIN_PASS');
$user = env_str('QC_ADMIN_USER', 'admin');
$title = env_str('QC_TITLE', '卿辰导航');

if ($pass === '') {
    fwrite(STDERR, "[autoinstall] QC_ADMIN_PASS 未设置\n");
    exit(1);
}
if (strlen($pass) < 8) {
    fwrite(STDERR, "[autoinstall] QC_ADMIN_PASS 至少 8 位\n");
    exit(1);
}
if (!preg_match('/^[A-Za-z0-9_-]{3,32}$/', $user)) {
    fwrite(STDERR, "[autoinstall] QC_ADMIN_USER 需为 3-32 位字母、数字、下划线或短横线\n");
    exit(1);
}

try {
    nav_boot();                                          // 建表 + 导入示例站点
    nav_set('site_title',    mb_substr($title, 0, 30, 'UTF-8'));
    nav_set('site_subtitle', mb_substr(env_str('QC_SUBTITLE'), 0, 60, 'UTF-8'));
    nav_set('owner',         mb_substr(env_str('QC_OWNER'), 0, 20, 'UTF-8'));
    nav_set('admin_user',    $user);
    nav_set('admin_hash',    password_hash($pass, PASSWORD_DEFAULT));

    $dir = NAV_ROOT . '/data/fav';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    echo "[autoinstall] 已创建 $user / {$title}\n";
} catch (Throwable $e) {
    fwrite(STDERR, '[autoinstall] 失败：' . $e->getMessage() . "\n");
    exit(1);
}
