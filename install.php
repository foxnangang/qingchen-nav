<?php
declare(strict_types=1);

/**
 * 卿辰导航 — 安装向导
 *
 * 首次部署后访问 /install.php，填好站点信息和管理员账号即可。
 * 装完请立刻删除本文件。
 *
 * 判定依据：data/nav.db 是否存在。
 *
 * @since  2026-10-19
 */

require __DIR__ . '/lib.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$base      = nav_base();
$installed = file_exists(NAV_DB_PATH);
$err       = '';
$done      = false;

// 目录可写性预检
$dataDir = NAV_ROOT . '/data';
$writable = is_dir($dataDir) ? is_writable($dataDir) : is_writable(NAV_ROOT);

if (!$installed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $title  = trim((string) ($_POST['title'] ?? ''));
    $sub    = trim((string) ($_POST['subtitle'] ?? ''));
    $owner  = trim((string) ($_POST['owner'] ?? ''));
    $user   = trim((string) ($_POST['user'] ?? ''));
    $pass   = (string) ($_POST['pass'] ?? '');
    $pass2  = (string) ($_POST['pass2'] ?? '');

    if (!$writable) {
        $err = 'data 目录不可写，请先给它写权限（如 chown -R www:www data）';
    } elseif ($title === '') {
        $err = '请填写站点名称';
    } elseif (!preg_match('/^[A-Za-z0-9_-]{3,32}$/', $user)) {
        $err = '用户名需为 3–32 位字母、数字、下划线或短横线';
    } elseif (strlen($pass) < 8) {
        $err = '密码至少 8 位';
    } elseif ($pass !== $pass2) {
        $err = '两次输入的密码不一致';
    } else {
        try {
            nav_boot();                                    // 建表 + 导入示例站点
            nav_set('site_title',    mb_substr($title, 0, 30, 'UTF-8'));
            nav_set('site_subtitle', mb_substr($sub, 0, 60, 'UTF-8'));
            nav_set('owner',         mb_substr($owner, 0, 20, 'UTF-8'));
            nav_set('admin_user',    $user);
            nav_set('admin_hash',    password_hash($pass, PASSWORD_DEFAULT));
            if (!is_dir($dataDir . '/fav')) {
                @mkdir($dataDir . '/fav', 0755, true);
            }
            $done = true;
        } catch (Throwable $e) {
            $err = '初始化失败：' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="dark" data-bg="aurora">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive">
<title>安装 · 卿辰导航</title>
<link rel="icon" type="image/svg+xml" href="<?= $base ?>/favicon.svg">
<link rel="stylesheet" href="<?= $base ?>/assets/theme.css">
<link rel="stylesheet" href="<?= $base ?>/assets/admin.css">
<style>
  .install-card { max-width: 440px; }
  .install-head { margin-bottom: 22px; text-align: center; }
  .install-title { margin: 14px 0 6px; font-size: 20px; font-weight: 600; letter-spacing: -0.01em; }
  .install-sub { margin: 0; font-size: 12.5px; color: var(--muted); letter-spacing: 0.03em; }
  .install-card .field { margin-bottom: 12px; }
  .steps { margin: 18px 0 0; padding: 0; list-style: none; text-align: left; }
  .steps li {
    position: relative; margin-top: 9px; padding-left: 22px;
    font-size: 12px; color: var(--text-soft); line-height: 1.6;
  }
  .steps li::before {
    content: ""; position: absolute; left: 4px; top: 7px;
    width: 6px; height: 6px; border-radius: 50%; background: var(--gold);
  }
  .ok-mark { font-size: 30px; color: var(--gold); line-height: 1; }
  .warn-box {
    margin-top: 16px; padding: 11px 13px; border-radius: 10px;
    border: 1px solid var(--danger-soft); background: var(--danger-soft);
    color: var(--danger); font-size: 12px; line-height: 1.6; text-align: left;
  }
  .btn-row { display: flex; gap: 10px; margin-top: 18px; }
  .btn-row .btn { flex: 1; }
</style>
</head>
<body>

<div class="bg-glow" aria-hidden="true"></div>
<div class="bg-grain" aria-hidden="true"></div>

<div class="login-screen">
  <div class="login-card install-card">

    <?php if ($done): ?>
      <!-- ===== 安装成功 ===== -->
      <div class="ok-mark">◈</div>
      <h1 class="install-title">安装完成</h1>
      <p class="install-sub">数据库已初始化，示例站点也已导入</p>

      <div class="warn-box">
        <strong>下一步很重要：</strong>请立刻删除服务器上的 <code>install.php</code>，
        否则别人也能看到安装页（虽然无法重复安装，但没必要留着）。
      </div>

      <ul class="steps">
        <li>删掉 install.php</li>
        <li>建议给 data 目录设成仅程序可读写</li>
        <li>进后台把「我的站点」换成你自己的</li>
      </ul>

      <div class="btn-row">
        <a class="btn btn-primary" href="<?= $base ?>/admin/">去后台登录</a>
        <a class="btn btn-ghost" href="<?= $base ?>/">看前台</a>
      </div>

    <?php elseif ($installed): ?>
      <!-- ===== 已安装 ===== -->
      <div class="ok-mark">◈</div>
      <h1 class="install-title">已经安装过了</h1>
      <p class="install-sub">检测到 data/nav.db 已存在</p>

      <div class="warn-box">
        如果这是你刚装的，请删除服务器上的 <code>install.php</code>。<br>
        如果忘了密码，删掉 <code>data/nav.db</code> 再访问本页可以重装（会丢数据）。
      </div>

      <div class="btn-row">
        <a class="btn btn-primary" href="<?= $base ?>/admin/">去后台</a>
        <a class="btn btn-ghost" href="<?= $base ?>/">看前台</a>
      </div>

    <?php else: ?>
      <!-- ===== 安装表单 ===== -->
      <div class="install-head">
        <div class="pin-mark" style="font-size:24px;color:var(--gold)">◈</div>
        <h1 class="install-title">安装 卿辰导航</h1>
        <p class="install-sub">填好下面几项就能用了，1 分钟</p>
      </div>

      <form method="post" autocomplete="off">
        <label class="field">
          <span class="field-label">站点名称</span>
          <input type="text" name="title" class="input" maxlength="30" value="卿辰导航" required>
        </label>

        <label class="field">
          <span class="field-label">副标题（可留空）</span>
          <input type="text" name="subtitle" class="input" maxlength="60" value="" placeholder="例如：我的工作台">
        </label>

        <label class="field">
          <span class="field-label">左上角署名（可留空）</span>
          <input type="text" name="owner" class="input" maxlength="20" value="">
        </label>

        <label class="field">
          <span class="field-label">管理员用户名</span>
          <input type="text" name="user" class="input" maxlength="32" value="admin" required>
        </label>

        <label class="field">
          <span class="field-label">管理员密码（至少 8 位）</span>
          <input type="password" name="pass" class="input" minlength="8" required autocomplete="new-password">
        </label>

        <label class="field">
          <span class="field-label">再输一次密码</span>
          <input type="password" name="pass2" class="input" minlength="8" required autocomplete="new-password">
        </label>

        <?php if (!$writable): ?>
          <p class="form-err" style="color:var(--danger);font-size:12px">
            目录不可写，安装前请先执行：chown -R www:www <?= esc(NAV_ROOT) ?>/data
          </p>
        <?php endif; ?>

        <p class="form-err"><?= esc($err) ?></p>

        <button type="submit" class="btn btn-primary btn-block">开始安装</button>
      </form>
    <?php endif; ?>

  </div>
</div>

</body>
</html>
