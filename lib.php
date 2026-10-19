<?php
declare(strict_types=1);

/**
 * 卿辰导航 — 私人站点导航中心 · 公共库
 *
 * 设计约束：
 *  1. 零外部依赖，只用 PHP 内置的 PDO/SQLite（本机无 MySQL 密码，避免凭据问题）
 *  2. 数据库文件放 data/，nginx 层已 return 404，不可能被公网下载
 *  3. 所有 SQL 走预处理，所有输出走 esc()
 *  4. 登录失败限流，防止撞库
 *
 * @since  2026-10-19
 */

const NAV_ROOT    = __DIR__;
const NAV_DB_PATH = NAV_ROOT . '/data/nav.db';
const NAV_SESSION = 'qcnav_sid';
const NAV_VERSION = '1.2.1';

/**
 * 程序的 URL 根路径，用于支持「装在子目录」。
 *
 * 例：网站根目录 /var/www/html，程序放在 /var/www/html/nav
 *     → 返回 '/nav'，页面里所有资源地址变成 /nav/assets/…
 *     装在根目录时返回 ''，即 /assets/…
 */
function nav_base(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }

    $root    = str_replace('\\', '/', (string) (realpath(NAV_ROOT) ?: NAV_ROOT));
    $docRoot = str_replace('\\', '/', (string) (realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: ''));

    // 首选：用 DOCUMENT_ROOT 求差集，最准
    if ($docRoot !== '' && strpos($root, $docRoot) === 0) {
        $base = rtrim(substr($root, strlen($docRoot)), '/');
        return $base;
    }

    // 兜底：从当前脚本 URL 反推（去掉入口文件名）
    $sn = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $sn = (string) preg_replace('#/(index|api|install|favicon)\.php$#', '', $sn);
    $sn = (string) preg_replace('#/admin$#', '', $sn);
    $base = rtrim($sn, '/');
    return $base;
}

/**
 * 未安装时把访客引到安装向导，避免直接建出一个没有管理员密码的空库。
 */
function nav_require_installed(): void
{
    if (file_exists(NAV_DB_PATH)) {
        return;
    }
    header('Location: ' . nav_base() . '/install.php', true, 302);
    exit;
}

// 禁止被直接访问（本文件只应通过 require 引入）
if (isset($_SERVER['SCRIPT_FILENAME'])
    && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

require_once NAV_ROOT . '/seed.php';

/** 登录失败限流参数 */
const NAV_LOCK_WINDOW = 900;   // 统计窗口 15 分钟
const NAV_LOCK_MAX    = 5;     // 窗口内最多失败 5 次

/* ==========================================================================
 * 数据库
 * ======================================================================== */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dir = dirname(NAV_DB_PATH);
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }

    $pdo = new PDO('sqlite:' . NAV_DB_PATH, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    // WAL 让读写并发不互锁；busy_timeout 避免瞬时锁冲突直接抛错
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    return $pdo;
}

/**
 * 建表 + 首次导入种子数据（幂等，可反复调用）
 */
function nav_boot(): void
{
    $pdo = db();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS categories (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            name       TEXT    NOT NULL,
            icon       TEXT    NOT NULL DEFAULT '',
            accent     TEXT    NOT NULL DEFAULT '',
            sort_order INTEGER NOT NULL DEFAULT 0,
            visible    INTEGER NOT NULL DEFAULT 1,
            created_at TEXT    NOT NULL DEFAULT ''
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sites (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            category_id INTEGER NOT NULL,
            name        TEXT    NOT NULL,
            url         TEXT    NOT NULL,
            descr       TEXT    NOT NULL DEFAULT '',
            icon        TEXT    NOT NULL DEFAULT '',
            initial     TEXT    NOT NULL DEFAULT '',
            tags        TEXT    NOT NULL DEFAULT '',
            badge       TEXT    NOT NULL DEFAULT '',
            highlight   INTEGER NOT NULL DEFAULT 0,
            sort_order  INTEGER NOT NULL DEFAULT 0,
            visible     INTEGER NOT NULL DEFAULT 1,
            hit_count   INTEGER NOT NULL DEFAULT 0,
            created_at  TEXT    NOT NULL DEFAULT '',
            updated_at  TEXT    NOT NULL DEFAULT ''
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            skey  TEXT PRIMARY KEY,
            sval  TEXT NOT NULL DEFAULT ''
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_fails (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip TEXT NOT NULL,
            ts INTEGER NOT NULL
        )
    ");

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sites_cat  ON sites (category_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sites_sort ON sites (sort_order)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_fails_ip   ON login_fails (ip, ts)');

    // 首次运行才导入种子，避免覆盖用户后来的修改
    $seeded = $pdo->query("SELECT sval FROM settings WHERE skey = 'seeded'")->fetchColumn();
    if ($seeded !== '1') {
        nav_seed();
        nav_set('seeded', '1');
    }

    // 缺省设置补齐（不覆盖已有值）
    foreach (nav_default_settings() as $k => $v) {
        $has = $pdo->prepare('SELECT 1 FROM settings WHERE skey = ?');
        $has->execute([$k]);
        if (!$has->fetchColumn()) {
            nav_set($k, $v);
        }
    }
}

function nav_default_settings(): array
{
    return [
        'site_title'    => '站点导航',
        'site_subtitle' => '我的工作台',
        'owner'         => '',
        'theme'         => 'dark',        // dark | light
        'access_mode'   => 'public',      // public | pin
        'pin_hash'      => '',
        'admin_user'    => 'admin',
        'admin_hash'    => '',
        'footer_note'   => '',
    ];
}

/* ==========================================================================
 * 设置读写
 * ======================================================================== */

function nav_set(string $key, string $val): void
{
    $st = db()->prepare(
        'INSERT INTO settings (skey, sval) VALUES (?, ?)
         ON CONFLICT(skey) DO UPDATE SET sval = excluded.sval'
    );
    $st->execute([$key, $val]);
}

function nav_get(string $key, string $default = ''): string
{
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $st = db()->prepare('SELECT sval FROM settings WHERE skey = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    $cache[$key] = ($v === false || $v === null) ? $default : (string) $v;
    return $cache[$key];
}

function nav_all_settings(): array
{
    $out = [];
    foreach (db()->query('SELECT skey, sval FROM settings') as $row) {
        $out[$row['skey']] = $row['sval'];
    }
    return $out;
}

/* ==========================================================================
 * 会话与认证
 * ======================================================================== */

/**
 * 判断当前请求是不是 HTTPS。
 *
 * 只看 $_SERVER['HTTPS'] 在反向代理后面会误判 —— 后端收到的是 http，
 * 于是 session cookie 不会带 Secure 标记。容器化部署基本都在反代后面，
 * 所以要认 X-Forwarded-Proto。
 */
function nav_is_https(): bool
{
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off' && $https !== '0') {
        return true;
    }
    if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
        return true;
    }
    if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on') {
        return true;
    }
    return (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
}

function nav_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name(NAV_SESSION);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => nav_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function nav_is_logged_in(): bool
{
    nav_session();
    return !empty($_SESSION['nav_admin']);
}

function nav_client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function nav_login_locked(): bool
{
    $st = db()->prepare(
        'SELECT COUNT(*) FROM login_fails WHERE ip = ? AND ts > ?'
    );
    $st->execute([nav_client_ip(), time() - NAV_LOCK_WINDOW]);
    return ((int) $st->fetchColumn()) >= NAV_LOCK_MAX;
}

function nav_login_record_fail(): void
{
    $pdo = db();
    $st  = $pdo->prepare('INSERT INTO login_fails (ip, ts) VALUES (?, ?)');
    $st->execute([nav_client_ip(), time()]);
    // 顺手清理超过窗口的记录，表不会无限膨胀
    $pdo->prepare('DELETE FROM login_fails WHERE ts < ?')
        ->execute([time() - NAV_LOCK_WINDOW * 8]);
}

function nav_login_clear_fails(): void
{
    db()->prepare('DELETE FROM login_fails WHERE ip = ?')
        ->execute([nav_client_ip()]);
}

/**
 * 校验管理员密码
 * @return bool 是否成功
 */
function nav_try_login(string $user, string $pass): bool
{
    if (nav_login_locked()) {
        return false;
    }
    $okUser = hash_equals(nav_get('admin_user', 'admin'), $user);
    $hash   = nav_get('admin_hash', '');
    $okPass = $hash !== '' && password_verify($pass, $hash);

    if ($okUser && $okPass) {
        nav_login_clear_fails();
        nav_session();
        session_regenerate_id(true);
        $_SESSION['nav_admin']  = true;
        $_SESSION['nav_at']     = time();
        return true;
    }

    nav_login_record_fail();
    return false;
}

function nav_logout(): void
{
    nav_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool) $p['secure'], (bool) $p['httponly']);
    }
    session_destroy();
}

function nav_csrf(): string
{
    nav_session();
    if (empty($_SESSION['nav_csrf'])) {
        $_SESSION['nav_csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['nav_csrf'];
}

function nav_check_csrf(?string $token): bool
{
    nav_session();
    return !empty($_SESSION['nav_csrf'])
        && is_string($token)
        && hash_equals($_SESSION['nav_csrf'], $token);
}

/* ==========================================================================
 * 输出与响应
 * ======================================================================== */

function esc(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_out($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_ok(array $extra = []): void
{
    json_out(['ok' => true] + $extra);
}

function json_err(string $msg, int $status = 400): void
{
    json_out(['ok' => false, 'error' => $msg], $status);
}

/* ==========================================================================
 * 数据读写
 * ======================================================================== */

function nav_categories(bool $onlyVisible = false): array
{
    $sql = 'SELECT * FROM categories';
    if ($onlyVisible) {
        $sql .= ' WHERE visible = 1';
    }
    $sql .= ' ORDER BY sort_order ASC, id ASC';
    return db()->query($sql)->fetchAll();
}

function nav_sites(int $categoryId = 0, bool $onlyVisible = false): array
{
    $sql    = 'SELECT * FROM sites WHERE 1 = 1';
    $params = [];
    if ($categoryId > 0) {
        $sql .= ' AND category_id = ?';
        $params[] = $categoryId;
    }
    if ($onlyVisible) {
        $sql .= ' AND visible = 1';
    }
    $sql .= ' ORDER BY sort_order ASC, id ASC';
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/**
 * 前端引导数据：一个请求拿走全部内容，避免多次往返
 */
function nav_bootstrap(): array
{
    $cats  = nav_categories(true);
    $sites = nav_sites(0, true);

    $byCat = [];
    foreach ($sites as $s) {
        $byCat[(int) $s['category_id']][] = nav_public_site($s);
    }

    $out = [];
    foreach ($cats as $c) {
        $cid = (int) $c['id'];
        if (empty($byCat[$cid])) {
            continue; // 空分类不展示
        }
        $out[] = [
            'id'     => $cid,
            'name'   => $c['name'],
            'icon'   => $c['icon'],
            'accent' => $c['accent'],
            'sites'  => $byCat[$cid],
        ];
    }

    return [
        'title'    => nav_get('site_title', '站点导航'),
        'subtitle' => nav_get('site_subtitle', ''),
        'owner'    => nav_get('owner', ''),
        'theme'    => nav_get('theme', 'dark'),
        'bg'       => nav_get('bg', 'aurora'),
        'footer'   => nav_get('footer_note', ''),
        'version'  => NAV_VERSION,
        'groups'   => $out,
        'total'    => count($sites),
    ];
}

/** 前端只拿必要字段，不暴露 hit_count 之类内部数据 */
function nav_public_site(array $s): array
{
    return [
        'id'    => (int) $s['id'],
        'name'  => $s['name'],
        'url'   => $s['url'],
        'descr' => $s['descr'],
        'icon'  => $s['icon'],
        'init'  => $s['initial'] !== '' ? $s['initial'] : mb_substr($s['name'], 0, 1, 'UTF-8'),
        'tags'  => $s['tags'] !== '' ? array_values(array_filter(array_map('trim', explode(',', $s['tags'])))) : [],
        'badge' => $s['badge'],
        'hl'    => (int) $s['highlight'],
    ];
}

/**
 * 规范化 URL：补协议、去尾部空格、只允许 http/https
 */
function nav_normalize_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }
    $parts = parse_url($url);
    if (!$parts || empty($parts['host'])) {
        return '';
    }
    if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
        return '';
    }
    return $url;
}

function nav_now(): string
{
    return date('Y-m-d H:i:s');
}
