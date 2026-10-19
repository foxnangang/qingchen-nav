<?php
declare(strict_types=1);

/**
 * 卿辰导航 — 后台管理
 *
 * 未登录只输出登录表单，登录后才渲染管理界面。
 * 所有数据读写走 /api.php，前端只负责界面。
 *
 * @since  2026-10-19
 */

require dirname(__DIR__) . '/lib.php';
nav_require_installed();
nav_boot();

header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store');

$logged = nav_is_logged_in();
$csrf   = nav_csrf();
$ver    = NAV_VERSION;
$base   = nav_base();
$title  = nav_get('site_title', '站点导航');
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="<?= esc(nav_get('theme', 'dark')) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive">
<meta name="theme-color" content="#0A0C0B">
<title>管理后台 · <?= esc($title) ?></title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='14' fill='%230A0C0B'/%3E%3Cpath d='M32 14l6.5 11.5L50 32l-11.5 6.5L32 50l-6.5-11.5L14 32l11.5-6.5z' fill='%23C9A227'/%3E%3C/svg%3E">
<link rel="stylesheet" href="<?= $base ?>/assets/theme.css?v=<?= esc($ver) ?>">
<link rel="stylesheet" href="<?= $base ?>/assets/admin.css?v=<?= esc($ver) ?>">
</head>
<body>

<?php if (!$logged): ?>
<!-- ==================== 登录 ==================== -->
<div class="login-screen">
  <form class="login-card" id="loginForm" autocomplete="off">
    <div class="login-mark">◈</div>
    <h1 class="login-title">管理后台</h1>
    <p class="login-sub"><?= esc($title) ?></p>

    <label class="field">
      <span class="field-label">用户名</span>
      <input type="text" id="loginUser" class="input" value="admin" autocomplete="username" required>
    </label>

    <label class="field">
      <span class="field-label">密码</span>
      <input type="password" id="loginPass" class="input" autocomplete="current-password" required autofocus>
    </label>

    <button type="submit" class="btn btn-primary btn-block" id="loginBtn">登录</button>
    <p class="login-err" id="loginErr"></p>
    <p class="login-foot">失败 5 次将锁定 15 分钟</p>
  </form>
</div>

<script>
window.__BASE__ = <?= json_encode($base) ?>;
window.__CSRF__ = <?= json_encode($csrf) ?>;
window.__LOGGED__ = false;
</script>
<script src="<?= $base ?>/assets/admin.js?v=<?= esc($ver) ?>"></script>
</body></html>
<?php exit; endif; ?>

<!-- ==================== 管理界面 ==================== -->
<div class="shell">

  <!-- 侧栏 -->
  <aside class="side">
    <div class="side-head">
      <span class="side-mark">◈</span>
      <div class="side-title">
        <strong><?= esc($title) ?></strong>
        <small>管理后台</small>
      </div>
    </div>

    <nav class="side-nav" id="catList">
      <button type="button" class="cat-item active" data-cat="0">
        <span class="cat-name">全部站点</span>
        <span class="cat-n" id="nAll">0</span>
      </button>
      <!-- 分类由 JS 渲染 -->
    </nav>

    <div class="side-foot">
      <button type="button" class="btn btn-ghost btn-block" id="newCatBtn">＋ 新建分类</button>
      <div class="side-stats" id="sideStats"></div>
      <a href="<?= $base ?>/" class="side-link" target="_blank" rel="noopener">← 返回前台</a>
      <button type="button" class="side-link danger" id="logoutBtn">退出登录</button>
      <span class="side-ver">卿辰导航 v<?= NAV_VERSION ?></span>
    </div>
  </aside>

  <!-- 主区 -->
  <main class="main">

    <header class="main-head">
      <div class="main-title">
        <h1 id="viewTitle">全部站点</h1>
        <p class="main-desc" id="viewDesc">拖拽左侧圆形手柄可调整顺序，改动自动保存</p>
      </div>
      <div class="main-actions">
        <input type="search" id="filter" class="input input-sm" placeholder="筛选…">
        <button type="button" class="btn btn-ghost" id="settingsBtn">设置</button>
        <button type="button" class="btn btn-primary" id="newSiteBtn">＋ 新增站点</button>
      </div>
    </header>

    <div class="table-wrap">
      <div class="table-head">
        <span class="col-drag"></span>
        <span class="col-name">站点</span>
        <span class="col-cat">分类</span>
        <span class="col-tag">标签</span>
        <span class="col-op">操作</span>
      </div>
      <div class="rows" id="rows"></div>
      <p class="rows-empty" id="rowsEmpty" hidden>这里还没有站点，点右上角「新增站点」开始</p>
    </div>

  </main>
</div>

<!-- ==================== 站点编辑弹窗 ==================== -->
<div class="modal-mask" id="siteModal" hidden>
  <div class="modal">
    <header class="modal-head">
      <h2 id="siteModalTitle">新增站点</h2>
      <button type="button" class="modal-x" data-close="siteModal">×</button>
    </header>
    <div class="modal-body">
      <input type="hidden" id="sId">
      <div class="grid2">
        <label class="field">
          <span class="field-label">名称 <em>*</em></span>
          <input type="text" id="sName" class="input" maxlength="40" placeholder="例如：我的博客">
        </label>
        <label class="field">
          <span class="field-label">分类 <em>*</em></span>
          <select id="sCat" class="input"></select>
        </label>
      </div>
      <label class="field">
        <span class="field-label">网址 <em>*</em></span>
        <input type="text" id="sUrl" class="input" placeholder="https://example.com 或 example.com">
      </label>
      <label class="field">
        <span class="field-label">简介</span>
        <input type="text" id="sDescr" class="input" maxlength="120" placeholder="一句话说明，显示在名称下方">
      </label>
      <div class="grid2">
        <label class="field">
          <span class="field-label">标签（逗号分隔）</span>
          <input type="text" id="sTags" class="input" placeholder="工具,AI">
        </label>
        <label class="field">
          <span class="field-label">角标</span>
          <input type="text" id="sBadge" class="input" maxlength="6" placeholder="后台 / 新">
        </label>
      </div>
      <label class="field">
        <span class="field-label">自定义图标 URL（留空自动抓 favicon）</span>
        <input type="text" id="sIcon" class="input" placeholder="https://…/icon.png">
      </label>
      <div class="checks">
        <label class="check"><input type="checkbox" id="sHl"> 置顶显示（图标带金圈）</label>
        <label class="check"><input type="checkbox" id="sVisible" checked> 在前台显示</label>
      </div>
      <p class="form-err" id="siteErr"></p>
    </div>
    <footer class="modal-foot">
      <button type="button" class="btn btn-ghost" data-close="siteModal">取消</button>
      <button type="button" class="btn btn-primary" id="siteSaveBtn">保存</button>
    </footer>
  </div>
</div>

<!-- ==================== 分类编辑弹窗 ==================== -->
<div class="modal-mask" id="catModal" hidden>
  <div class="modal modal-sm">
    <header class="modal-head">
      <h2 id="catModalTitle">新建分类</h2>
      <button type="button" class="modal-x" data-close="catModal">×</button>
    </header>
    <div class="modal-body">
      <input type="hidden" id="cId">
      <label class="field">
        <span class="field-label">分类名称 <em>*</em></span>
        <input type="text" id="cName" class="input" maxlength="20" placeholder="例如：AI 对话">
      </label>
      <div class="grid2">
        <label class="field">
          <span class="field-label">图标（emoji）</span>
          <input type="text" id="cIcon" class="input" maxlength="4" placeholder="💬">
        </label>
        <label class="field">
          <span class="field-label">强调色</span>
          <input type="color" id="cAccent" class="input input-color" value="#C9A227">
        </label>
      </div>
      <label class="check"><input type="checkbox" id="cVisible" checked> 在前台显示</label>
      <p class="form-err" id="catErr"></p>
    </div>
    <footer class="modal-foot">
      <button type="button" class="btn btn-ghost danger" id="catDeleteBtn" hidden>删除分类</button>
      <span class="spacer"></span>
      <button type="button" class="btn btn-ghost" data-close="catModal">取消</button>
      <button type="button" class="btn btn-primary" id="catSaveBtn">保存</button>
    </footer>
  </div>
</div>

<!-- ==================== 设置弹窗 ==================== -->
<div class="modal-mask" id="setModal" hidden>
  <div class="modal">
    <header class="modal-head">
      <h2>站点设置</h2>
      <button type="button" class="modal-x" data-close="setModal">×</button>
    </header>
    <div class="modal-body">

      <section class="set-sec">
        <h3 class="set-h">外观</h3>
        <div class="grid2">
          <label class="field">
            <span class="field-label">站点标题</span>
            <input type="text" id="setTitle" class="input" maxlength="30">
          </label>
          <label class="field">
            <span class="field-label">副标题</span>
            <input type="text" id="setSubtitle" class="input" maxlength="60">
          </label>
        </div>
        <div class="grid2">
          <label class="field">
            <span class="field-label">署名（左上角）</span>
            <input type="text" id="setOwner" class="input" maxlength="20">
          </label>
          <label class="field">
            <span class="field-label">页脚内容（支持 HTML，可放备案号/链接等）</span>
            <textarea id="setFooter" class="input" rows="2" maxlength="500" placeholder="例如：&lt;a href=&quot;https://beian.miit.gov.cn&quot;&gt;粤ICP备xxxxxx号&lt;/a&gt;"></textarea>
          </label>
        </div>

        <div class="field">
          <span class="field-label">自定义 CSS（注入前台 &lt;style&gt;，可深度改样式）</span>
          <textarea id="setCustomCss" class="input mono" rows="4" maxlength="20000" placeholder="例如：.card { border-radius: 16px; }"></textarea>
        </div>
        <div class="field">
          <span class="field-label">自定义 JS（注入前台 &lt;script&gt;，可加统计代码等）</span>
          <textarea id="setCustomJs" class="input mono" rows="4" maxlength="20000" placeholder="例如：console.log('hello')"></textarea>
        </div>

        <div class="field">
          <span class="field-label">默认配色（访客首次访问看到的样子）</span>
          <div class="pick-row" id="pickPalette"></div>
        </div>

        <div class="field">
          <span class="field-label">背景样式</span>
          <div class="pick-row pick-row-bg" id="pickBg"></div>
        </div>

        <div class="grid2">
          <label class="field">
            <span class="field-label">背景图 URL（选「图片」时生效）</span>
            <input type="text" id="setBgImage" class="input" placeholder="https://example.com/bg.jpg">
          </label>
          <label class="field">
            <span class="field-label">图片浓度 <b class="dim-val" id="dimVal">42%</b></span>
            <input type="range" id="setBgDim" class="range" min="0" max="100" step="4" value="42">
          </label>
        </div>
        <p class="hint">访客在本机手动选过的主题会覆盖这里的默认值；「恢复默认」按钮可清掉。</p>
      </section>

      <section class="set-sec">
        <h3 class="set-h">访问控制</h3>
        <label class="field">
          <span class="field-label">前台访问方式</span>
          <select id="setAccess" class="input">
            <option value="public">公开访问（仅靠域名隐私）</option>
            <option value="pin">访问码保护（首次输入，记住 30 天）</option>
          </select>
        </label>
        <div class="grid2">
          <label class="field">
            <span class="field-label">设置访问码（至少 4 位）</span>
            <input type="password" id="setPin" class="input" placeholder="留空则不修改" autocomplete="new-password">
          </label>
          <div class="field field-btn">
            <button type="button" class="btn btn-ghost" id="pinSaveBtn">保存访问码</button>
          </div>
        </div>
        <p class="hint">提示：切到「访问码保护」前必须先设好访问码，否则会被锁在外面。</p>
      </section>

      <section class="set-sec">
        <h3 class="set-h">修改登录密码</h3>
        <div class="grid2">
          <label class="field">
            <span class="field-label">当前密码</span>
            <input type="password" id="pwOld" class="input" autocomplete="current-password">
          </label>
          <label class="field">
            <span class="field-label">新密码（至少 8 位）</span>
            <input type="password" id="pwNew" class="input" autocomplete="new-password">
          </label>
        </div>
        <div class="grid2">
          <label class="field">
            <span class="field-label">用户名</span>
            <input type="text" id="pwUser" class="input" maxlength="32">
          </label>
          <div class="field field-btn">
            <button type="button" class="btn btn-ghost" id="pwSaveBtn">修改密码</button>
          </div>
        </div>
      </section>

      <section class="set-sec">
        <h3 class="set-h">数据</h3>
        <div class="data-ops">
          <button type="button" class="btn btn-ghost" id="exportBtn">导出 JSON</button>
          <label class="btn btn-ghost" for="importFile">导入 JSON</label>
          <input type="file" id="importFile" accept=".json,application/json" hidden>
        </div>
        <p class="hint">导入按「分类名 + 站点名」去重，不会产生重复项；不存在的分类会自动创建。</p>
      </section>

      <section class="set-sec">
        <h3 class="set-h">版本与更新</h3>
        <div class="upd-row">
          <span class="upd-cur">当前版本 <b id="updCur">—</b></span>
          <button type="button" class="btn btn-ghost btn-sm2" id="updCheckBtn">检查更新</button>
        </div>
        <div id="updBox" class="upd-box" hidden></div>
        <label class="field" style="margin-top:10px">
          <span class="field-label">更新镜像（连不上 GitHub 时填）</span>
          <input type="text" id="updMirror" class="input" placeholder="https://ghfast.top">
        </label>
        <p class="hint">「立即升级」会从 GitHub 拉最新源码覆盖程序文件，<b>不碰 data/ 目录</b>（站点数据不受影响），覆盖前自动备份。Docker 部署也能用。</p>
      </section>

      <p class="form-err" id="setErr"></p>
      <p class="form-ok" id="setOk"></p>
    </div>
    <footer class="modal-foot">
      <button type="button" class="btn btn-ghost" data-close="setModal">关闭</button>
      <button type="button" class="btn btn-primary" id="setSaveBtn">保存设置</button>
    </footer>
  </div>
</div>

<script>
window.__BASE__ = <?= json_encode($base) ?>;
window.__CSRF__ = <?= json_encode($csrf) ?>;
window.__LOGGED__ = true;
</script>
<script src="<?= $base ?>/assets/admin.js?v=<?= esc($ver) ?>"></script>
</body>
</html>
