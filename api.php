<?php
declare(strict_types=1);

/**
 * 卿辰导航 — API
 *
 * 路由说明
 *   公开：bootstrap / hit / pin_verify / login
 *   需要登录：其余全部（含 admin_data / site_* / cat_* / settings_* / export / import）
 *
 * 所有写操作要求 header 带 X-CSRF-Token，token 由 PHP 渲染进页面
 *
 * @since  2026-10-19
 */

require __DIR__ . '/lib.php';

header('X-Robots-Tag: noindex, nofollow');
nav_boot();

/* ---------- 请求解析 ---------- */

function body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return $_POST;
    }
    $j = json_decode($raw, true);
    return is_array($j) ? $j : $_POST;
}

function require_admin(): void
{
    if (!nav_is_logged_in()) {
        json_err('未登录或会话已过期', 401);
    }
}

function s(array $a, string $k, string $def = ''): string
{
    $v = $a[$k] ?? $def;
    return is_scalar($v) ? trim((string) $v) : $def;
}

function i(array $a, string $k, int $def = 0): int
{
    return isset($a[$k]) && is_numeric($a[$k]) ? (int) $a[$k] : $def;
}

/* ---------- PIN 访问保护（可选） ---------- */

function pin_ok(): bool
{
    nav_session();
    if (!empty($_SESSION['nav_pin_ok'])) {
        return true;
    }
    $cookie = $_COOKIE['qcnav_pin'] ?? '';
    $hash   = nav_get('pin_hash', '');
    return $cookie !== '' && $hash !== '' && hash_equals($hash, $cookie);
}

/* ---------- 路由 ---------- */

$action = (string) ($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* 写操作统一校验 CSRF（登录与 PIN 验证除外，它们本身就要凭据） */
if ($method === 'POST' && !in_array($action, ['login', 'pin_verify'], true)) {
    if (!nav_check_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        json_err('请求校验失败，请刷新页面后重试', 419);
    }
}

switch ($action) {

    /* ==================== 公开接口 ==================== */

    case 'bootstrap':
        if (nav_get('access_mode') === 'pin' && !pin_ok()) {
            json_out([
                'ok'        => false,
                'need_pin'  => true,
                'title'     => nav_get('site_title', '站点导航'),
                'subtitle'  => nav_get('site_subtitle', ''),
            ]);
        }
        json_out(['ok' => true] + nav_bootstrap());
        break;

    case 'pin_verify':
        $in = body();
        $hash = nav_get('pin_hash', '');
        if ($hash === '') {
            json_err('未设置访问码', 400);
        }
        if (nav_login_locked()) {
            json_err('尝试次数过多，请 15 分钟后再试', 429);
        }
        if (!password_verify(s($in, 'pin'), $hash)) {
            nav_login_record_fail();
            json_err('访问码不正确', 403);
        }
        nav_login_clear_fails();
        nav_session();
        $_SESSION['nav_pin_ok'] = true;
        setcookie('qcnav_pin', $hash, [
            'expires'  => time() + 86400 * 30,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        json_ok();
        break;

    case 'hit':
        $in = body();
        $id = i($in, 'id');
        if ($id > 0) {
            db()->prepare('UPDATE sites SET hit_count = hit_count + 1 WHERE id = ?')
                ->execute([$id]);
        }
        json_ok();
        break;

    case 'login':
        $in   = body();
        $user = s($in, 'username');
        $pass = (string) ($in['password'] ?? '');
        if (nav_login_locked()) {
            json_err('失败次数过多，请 15 分钟后再试', 429);
        }
        if (!nav_try_login($user, $pass)) {
            json_err('用户名或密码错误', 403);
        }
        json_ok(['csrf' => nav_csrf(), 'redirect' => './']);
        break;

    case 'logout':
        nav_logout();
        json_ok();
        break;

    /* ==================== 后台数据 ==================== */

    case 'admin_data':
        require_admin();
        $cats  = nav_categories(false);
        $sites = nav_sites(0, false);
        $byCat = [];
        foreach ($sites as $row) {
            $byCat[(int) $row['category_id']][] = $row;
        }
        json_out([
            'ok'     => true,
            'cats'   => $cats,
            'sites'  => $byCat,
            'stats'  => [
                'sites' => count($sites),
                'cats'  => count($cats),
                'hits'  => (int) db()->query('SELECT COALESCE(SUM(hit_count),0) FROM sites')->fetchColumn(),
            ],
            'config' => [
                'title'    => nav_get('site_title'),
                'subtitle' => nav_get('site_subtitle'),
                'owner'    => nav_get('owner'),
                'theme'    => nav_get('theme'),
                'bg'       => nav_get('bg', 'aurora'),
                'bg_image' => nav_get('bg_image', ''),
                'bg_dim'   => nav_get('bg_dim', '42'),
                'access'   => nav_get('access_mode'),
                'footer'   => nav_get('footer_note'),
                'user'     => nav_get('admin_user'),
            ],
            'csrf'   => nav_csrf(),
        ]);
        break;

    /* ==================== 站点增删改 ==================== */

    case 'site_save':
        require_admin();
        $in   = body();
        $id   = i($in, 'id');
        $name = s($in, 'name');
        $url  = nav_normalize_url(s($in, 'url'));
        $cat  = i($in, 'category_id');

        if ($name === '') {
            json_err('站点名称不能为空');
        }
        if (mb_strlen($name, 'UTF-8') > 40) {
            json_err('站点名称过长（最多 40 字）');
        }
        if ($url === '') {
            json_err('网址不合法，请填 http(s):// 开头的完整地址');
        }
        $chk = db()->prepare('SELECT 1 FROM categories WHERE id = ?');
        $chk->execute([$cat]);
        if (!$chk->fetchColumn()) {
            json_err('请选择有效的分类');
        }

        $fields = [
            'category_id' => $cat,
            'name'        => $name,
            'url'         => $url,
            'descr'       => mb_substr(s($in, 'descr'), 0, 120, 'UTF-8'),
            'icon'        => s($in, 'icon'),
            'initial'     => mb_substr(s($in, 'initial'), 0, 2, 'UTF-8'),
            'tags'        => s($in, 'tags'),
            'badge'       => mb_substr(s($in, 'badge'), 0, 6, 'UTF-8'),
            'highlight'   => i($in, 'highlight') ? 1 : 0,
            'visible'     => i($in, 'visible', 1) ? 1 : 0,
        ];

        if ($id > 0) {
            $sets = [];
            foreach (array_keys($fields) as $k) {
                $sets[] = "$k = :$k";
            }
            $fields['id']         = $id;
            $fields['updated_at'] = nav_now();
            $sql = 'UPDATE sites SET ' . implode(', ', $sets) . ', updated_at = :updated_at WHERE id = :id';
            db()->prepare($sql)->execute($fields);
            json_ok(['id' => $id, 'mode' => 'update']);
        }

        $max = (int) db()->query('SELECT COALESCE(MAX(sort_order),0) FROM sites')->fetchColumn();
        $fields['sort_order'] = $max + 10;
        $fields['created_at'] = nav_now();
        $fields['updated_at'] = nav_now();
        $cols = implode(', ', array_keys($fields));
        $vals = ':' . implode(', :', array_keys($fields));
        db()->prepare("INSERT INTO sites ($cols) VALUES ($vals)")->execute($fields);
        json_ok(['id' => (int) db()->lastInsertId(), 'mode' => 'insert']);
        break;

    case 'site_delete':
        require_admin();
        $id = i(body(), 'id');
        if ($id <= 0) {
            json_err('参数错误');
        }
        db()->prepare('DELETE FROM sites WHERE id = ?')->execute([$id]);
        json_ok();
        break;

    case 'site_toggle':
        require_admin();
        $in = body();
        $id = i($in, 'id');
        db()->prepare('UPDATE sites SET visible = 1 - visible, updated_at = ? WHERE id = ?')
            ->execute([nav_now(), $id]);
        json_ok();
        break;

    /* ==================== 分类增删改 ==================== */

    case 'cat_save':
        require_admin();
        $in   = body();
        $id   = i($in, 'id');
        $name = s($in, 'name');
        if ($name === '') {
            json_err('分类名称不能为空');
        }
        if (mb_strlen($name, 'UTF-8') > 20) {
            json_err('分类名称过长（最多 20 字）');
        }
        $data = [
            'name'    => $name,
            'icon'    => mb_substr(s($in, 'icon'), 0, 4, 'UTF-8'),
            'accent'  => s($in, 'accent'),
            'visible' => i($in, 'visible', 1) ? 1 : 0,
        ];
        if ($id > 0) {
            $data['id'] = $id;
            db()->prepare(
                'UPDATE categories SET name = :name, icon = :icon, accent = :accent, visible = :visible WHERE id = :id'
            )->execute($data);
            json_ok(['id' => $id]);
        }
        $max = (int) db()->query('SELECT COALESCE(MAX(sort_order),0) FROM categories')->fetchColumn();
        $data['sort_order'] = $max + 10;
        $data['created_at'] = nav_now();
        db()->prepare(
            'INSERT INTO categories (name, icon, accent, visible, sort_order, created_at)
             VALUES (:name, :icon, :accent, :visible, :sort_order, :created_at)'
        )->execute($data);
        json_ok(['id' => (int) db()->lastInsertId()]);
        break;

    case 'cat_delete':
        require_admin();
        $in  = body();
        $id  = i($in, 'id');
        $cnt = db()->prepare('SELECT COUNT(*) FROM sites WHERE category_id = ?');
        $cnt->execute([$id]);
        $n = (int) $cnt->fetchColumn();
        if ($n > 0) {
            json_err("该分类下还有 {$n} 个站点，请先移走或删除它们");
        }
        db()->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
        json_ok();
        break;

    /* ==================== 排序 ==================== */

    case 'reorder':
        require_admin();
        $in    = body();
        $type  = s($in, 'type');
        $ids   = $in['ids'] ?? [];
        if (!is_array($ids) || empty($ids)) {
            json_err('参数错误');
        }
        $table = $type === 'cat' ? 'categories' : 'sites';
        $stmt  = db()->prepare("UPDATE {$table} SET sort_order = ? WHERE id = ?");
        $pdo   = db();
        $pdo->beginTransaction();
        try {
            $n = 0;
            foreach ($ids as $sid) {
                $n += 10;
                $stmt->execute([$n, (int) $sid]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_err('排序保存失败');
        }
        json_ok(['count' => count($ids)]);
        break;

    /* ==================== 设置 ==================== */

    case 'settings_save':
        require_admin();
        $in = body();

        if (isset($in['title'])) {
            nav_set('site_title', mb_substr(s($in, 'title'), 0, 30, 'UTF-8') ?: '站点导航');
        }
        if (isset($in['subtitle'])) {
            nav_set('site_subtitle', mb_substr(s($in, 'subtitle'), 0, 60, 'UTF-8'));
        }
        if (isset($in['owner'])) {
            nav_set('owner', mb_substr(s($in, 'owner'), 0, 20, 'UTF-8'));
        }
        if (isset($in['footer'])) {
            nav_set('footer_note', mb_substr(s($in, 'footer'), 0, 80, 'UTF-8'));
        }
        if (isset($in['theme']) && in_array($in['theme'], [
            'dark', 'light', 'chabai', 'forest', 'ocean', 'violet', 'mono', 'rose', 'terminal',
        ], true)) {
            nav_set('theme', (string) $in['theme']);
        }
        if (isset($in['bg']) && in_array($in['bg'], [
            'aurora', 'grid', 'dots', 'mesh', 'bamboo', 'paper', 'plain', 'photo',
        ], true)) {
            nav_set('bg', (string) $in['bg']);
        }
        if (isset($in['bg_image'])) {
            $img = s($in, 'bg_image');
            // 只接受 http(s) 绝对地址，避免把 javascript: / data: 塞进 CSS
            if ($img !== '' && (!preg_match('#^https?://#i', $img) || mb_strlen($img) > 500)) {
                json_err('背景图必须是 http(s) 开头的图片地址');
            }
            nav_set('bg_image', $img);
        }
        if (isset($in['bg_dim'])) {
            nav_set('bg_dim', (string) max(0, min(100, i($in, 'bg_dim', 42))));
        }
        if (isset($in['access']) && in_array($in['access'], ['public', 'pin'], true)) {
            // 切到 pin 模式必须已有 pin_hash，否则会被锁在门外
            if ($in['access'] === 'pin' && nav_get('pin_hash') === '') {
                json_err('请先设置访问码，再切换到「访问码保护」模式');
            }
            nav_set('access_mode', (string) $in['access']);
        }
        json_ok();
        break;

    case 'pin_save':
        require_admin();
        $in  = body();
        $pin = (string) ($in['pin'] ?? '');
        if (strlen($pin) < 4) {
            json_err('访问码至少 4 位');
        }
        if (strlen($pin) > 64) {
            json_err('访问码过长');
        }
        nav_set('pin_hash', password_hash($pin, PASSWORD_DEFAULT));
        json_ok();
        break;

    case 'password_save':
        require_admin();
        $in   = body();
        $old  = (string) ($in['old'] ?? '');
        $new  = (string) ($in['new'] ?? '');
        $user = s($in, 'user');

        if (!password_verify($old, nav_get('admin_hash'))) {
            json_err('当前密码不正确');
        }
        if (strlen($new) < 8) {
            json_err('新密码至少 8 位');
        }
        if ($user !== '') {
            nav_set('admin_user', mb_substr($user, 0, 32, 'UTF-8'));
        }
        nav_set('admin_hash', password_hash($new, PASSWORD_DEFAULT));
        json_ok();
        break;

    /* ==================== 导入导出 ==================== */

    case 'export':
        require_admin();
        $cats  = nav_categories(false);
        $sites = nav_sites(0, false);
        $byId  = [];
        foreach ($cats as $c) {
            $byId[(int) $c['id']] = $c['name'];
        }
        $rows = [];
        foreach ($sites as $s) {
            $rows[] = [
                'category'  => $byId[(int) $s['category_id']] ?? '',
                'name'      => $s['name'],
                'url'       => $s['url'],
                'descr'     => $s['descr'],
                'tags'      => $s['tags'],
                'badge'     => $s['badge'],
                'highlight' => (int) $s['highlight'],
                'visible'   => (int) $s['visible'],
            ];
        }
        json_out([
            'ok'       => true,
            'version'  => NAV_VERSION,
            'exported' => nav_now(),
            'categories' => array_map(fn($c) => [
                'name' => $c['name'], 'icon' => $c['icon'], 'accent' => $c['accent'],
            ], $cats),
            'sites'    => $rows,
        ]);
        break;

    case 'import':
        require_admin();
        $in   = body();
        $rows = $in['sites'] ?? [];
        if (!is_array($rows) || empty($rows)) {
            json_err('没有可导入的数据');
        }
        if (count($rows) > 1000) {
            json_err('单次最多导入 1000 条');
        }

        $pdo = db();
        // 分类名 => id 映射；不存在的分类自动创建
        $map = [];
        foreach (nav_categories(false) as $c) {
            $map[$c['name']] = (int) $c['id'];
        }
        $addCat = $pdo->prepare(
            'INSERT INTO categories (name, icon, accent, sort_order, visible, created_at) VALUES (?, ?, ?, ?, 1, ?)'
        );

        $added = 0;
        $skip  = 0;
        $max   = (int) $pdo->query('SELECT COALESCE(MAX(sort_order),0) FROM sites')->fetchColumn();

        $pdo->beginTransaction();
        try {
            foreach ($rows as $r) {
                $name = trim((string) ($r['name'] ?? ''));
                $url  = nav_normalize_url((string) ($r['url'] ?? ''));
                if ($name === '' || $url === '') {
                    $skip++;
                    continue;
                }
                $catName = trim((string) ($r['category'] ?? '未分类')) ?: '未分类';
                if (!isset($map[$catName])) {
                    $addCat->execute([$catName, '📁', '', 9999, nav_now()]);
                    $map[$catName] = (int) $pdo->lastInsertId();
                }
                // 同分类下同名去重
                $dup = $pdo->prepare('SELECT 1 FROM sites WHERE category_id = ? AND name = ?');
                $dup->execute([$map[$catName], $name]);
                if ($dup->fetchColumn()) {
                    $skip++;
                    continue;
                }
                $max += 10;
                $pdo->prepare(
                    'INSERT INTO sites (category_id, name, url, descr, icon, initial, tags, badge,
                                        highlight, sort_order, visible, hit_count, created_at, updated_at)
                     VALUES (?, ?, ?, ?, "", ?, ?, ?, ?, ?, ?, 0, ?, ?)'
                )->execute([
                    $map[$catName],
                    $name,
                    $url,
                    mb_substr(trim((string) ($r['descr'] ?? '')), 0, 120, 'UTF-8'),
                    mb_substr($name, 0, 1, 'UTF-8'),
                    trim((string) ($r['tags'] ?? '')),
                    trim((string) ($r['badge'] ?? '')),
                    !empty($r['highlight']) ? 1 : 0,
                    $max,
                    !empty($r['visible']) ? 1 : 0,
                    nav_now(),
                    nav_now(),
                ]);
                $added++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_err('导入失败：' . $e->getMessage(), 500);
        }
        json_ok(['added' => $added, 'skipped' => $skip]);
        break;

    default:
        json_err('未知操作', 404);
}
