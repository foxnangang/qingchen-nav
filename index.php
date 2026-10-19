<?php
declare(strict_types=1);

/**
 * 卿辰导航 — 前端导航页
 *
 * 数据直接内嵌进 HTML（省一次往返，首屏更快）；
 * PIN 保护模式下只输出验证界面，不下发任何站点数据。
 *
 * @since  2026-10-19
 */

require __DIR__ . '/lib.php';
nav_require_installed();
nav_boot();

header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-cache, must-revalidate');

$pinMode = nav_get('access_mode') === 'pin';
$needPin = $pinMode
    && empty($_SESSION['nav_pin_ok'])
    && (($_COOKIE['qcnav_pin'] ?? '') !== nav_get('pin_hash'));

$boot    = $needPin ? null : nav_bootstrap();
$csrf    = nav_csrf();
$theme   = nav_get('theme', 'dark');
$bgStyle = nav_get('bg', 'aurora');
$bgImage = nav_get('bg_image', '');
$bgDim   = (int) nav_get('bg_dim', '42');
$title   = nav_get('site_title', '站点导航');
$sub     = nav_get('site_subtitle', '');
$owner   = nav_get('owner', '');
$footer  = nav_get('footer_note', '');
$customCss = nav_get('custom_css', '');
$customJs  = nav_get('custom_js', '');
$ver     = NAV_VERSION;
$base    = nav_base();

// 只有 http(s) 的图片才允许进 CSS，防止 javascript: 之类
$bgImageOk = $bgImage !== '' && preg_match('#^https?://#i', $bgImage) === 1;

$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP;
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="<?= esc($theme) ?>" data-bg="<?= esc($bgStyle) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow, noarchive">
<meta name="referrer" content="strict-origin-when-cross-origin">
<meta name="theme-color" content="#0A0C0B">
<title><?= esc($title) ?><?= $sub !== '' ? ' · ' . esc($sub) : '' ?></title>
<link rel="icon" type="image/svg+xml" href="<?= $base ?>/favicon.svg?v=<?= esc($ver) ?>">
<link rel="alternate icon" href="<?= $base ?>/favicon.svg">
<link rel="stylesheet" href="<?= $base ?>/assets/theme.css?v=<?= esc($ver) ?>">
<link rel="stylesheet" href="<?= $base ?>/assets/style.css?v=<?= esc($ver) ?>">
<?php if ($bgImageOk || $bgDim !== 42): ?>
<style>:root{<?= $bgImageOk ? '--photo-url:url("' . esc($bgImage) . '");' : '' ?>--photo-dim:<?= max(0, min(100, $bgDim)) / 100 ?>;}</style>
<?php endif; ?>
</head>
<body>

<!-- 背景层：图片层 + 图案层 + 噪点，营造深度 -->
<div class="bg-photo" aria-hidden="true"></div>
<div class="bg-glow" aria-hidden="true"></div>
<div class="bg-grain" aria-hidden="true"></div>

<?php if ($needPin): ?>
<!-- ============ PIN 验证 ============ -->
<div class="bg-photo" aria-hidden="true"></div>
<div class="bg-glow" aria-hidden="true"></div>
<div class="bg-grain" aria-hidden="true"></div>

<div class="pin-screen">
  <div class="pin-card">
    <div class="pin-mark">◈</div>
    <h1 class="pin-title"><?= esc($title) ?></h1>
    <p class="pin-sub">请输入访问码</p>
    <form id="pinForm" autocomplete="off">
      <input type="password" id="pinInput" class="pin-input" placeholder="访问码"
             autocomplete="current-password" inputmode="numeric">
      <button type="submit" class="pin-btn">进入</button>
    </form>
    <p class="pin-err" id="pinErr"></p>
  </div>
</div>
<script>
window.__NAV__ = { needPin: true };
window.__BASE__ = <?= json_encode($base) ?>;
window.__CSRF__ = <?= json_encode($csrf) ?>;
</script>
<script src="<?= $base ?>/assets/app.js?v=<?= esc($ver) ?>"></script>
</body></html>
<?php exit; endif; ?>

<!-- ============ 顶部 ============ -->
<header class="hero">
  <div class="hero-bar">
    <div class="brand">
      <span class="brand-mark" aria-hidden="true">◈</span>
      <span class="brand-text"><?= esc($owner !== '' ? $owner : 'Workspace') ?></span>
    </div>
    <div class="hero-tools">
      <button type="button" id="themeBtn" class="tool-btn" title="主题与背景 (T)" aria-label="主题与背景"
              aria-haspopup="dialog" aria-expanded="false">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
          <path d="M12 3a9 9 0 1 0 0 18h1.5a2 2 0 0 0 1.6-3.2 2 2 0 0 1 1.6-3.2H19a3 3 0 0 0 3-3A9 9 0 0 0 12 3z"/>
          <circle cx="8" cy="9" r="1.1" fill="currentColor" stroke="none"/>
          <circle cx="12.5" cy="7" r="1.1" fill="currentColor" stroke="none"/>
          <circle cx="7.2" cy="14" r="1.1" fill="currentColor" stroke="none"/>
        </svg>
      </button>
      <a href="<?= $base ?>/admin/" class="tool-btn" title="后台管理" aria-label="后台管理">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round">
          <path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z"/>
          <path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>
        </svg>
      </a>

      <!-- 主题面板 -->
      <div class="theme-pop" id="themePop" hidden role="dialog" aria-label="主题设置">
        <p class="tp-label">配色</p>
        <div class="tp-swatches" id="tpPalette"></div>
        <div class="tp-group">
          <p class="tp-label">背景</p>
          <div class="tp-bgs" id="tpBg"></div>
        </div>
        <div class="tp-foot">
          <span>T 键切换配色</span>
          <button type="button" class="tp-reset" id="tpReset">恢复默认</button>
        </div>
      </div>
    </div>
  </div>

  <div class="hero-center">
    <h1 class="hero-title"><?= esc($title) ?></h1>
    <?php if ($sub !== ''): ?>
    <p class="hero-sub"><?= esc($sub) ?></p>
    <?php endif; ?>

    <div class="search-shell">
      <svg class="search-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
        <circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/>
      </svg>
      <input id="q" class="search-input" type="search" placeholder="搜索站点、域名或标签…"
             autocomplete="off" spellcheck="false" aria-label="搜索站点">
      <kbd class="search-kbd" id="searchKbd">/</kbd>
    </div>

    <nav class="chips" id="chips" aria-label="分类快捷跳转"></nav>
  </div>
</header>

<!-- ============ 主体 ============ -->
<main class="wrap" id="app"></main>

<p class="empty" id="empty" hidden>
  <span class="empty-mark">◌</span>
  <span>没有匹配的站点</span>
</p>

<footer class="foot">
  <span class="foot-line"></span>
  <span id="footStat"></span>
  <?php if ($footer !== ''): ?>
  <span class="foot-dot">·</span><span><?= $footer /* 页脚内容，仅管理员可设置，允许 HTML */ ?></span>
  <?php endif; ?>
</footer>

<?php if ($customCss !== ''): ?>
<style id="custom-css"><?= $customCss /* 管理员自定义 CSS */ ?></style>
<?php endif; ?>
<?php if ($customJs !== ''): ?>
<script id="custom-js"><?= $customJs /* 管理员自定义 JS */ ?></script>
<?php endif; ?>

<script>window.__NAV__ = <?= json_encode($boot, $jsonFlags) ?>;</script>
<script>window.__BASE__ = <?= json_encode($base) ?>;</script>
<script>window.__CSRF__ = <?= json_encode($csrf) ?>;</script>
<script src="<?= $base ?>/assets/app.js?v=<?= esc($ver) ?>"></script>
</body>
</html>
