/* ==========================================================================
   卿辰导航 — 前端逻辑
   纯原生，无依赖。数据由 PHP 内嵌到 window.__NAV__
@since 2026-10-19
   ========================================================================== */
(function () {
  'use strict';

  var BOOT = window.__NAV__ || {};
  var CSRF = window.__CSRF__ || '';
  // 支持子目录部署：由 PHP 注入 __BASE__（装在根目录时为空串）
  var BASE = window.__BASE__ || '';
  var API  = BASE + '/api.php?action=';

  /* ------------------------------------------------------------------ 工具 */

  function el(tag, attrs, children) {
    var n = document.createElement(tag);
    if (attrs) {
      for (var k in attrs) {
        if (!Object.prototype.hasOwnProperty.call(attrs, k)) continue;
        var v = attrs[k];
        if (v === null || v === undefined || v === false) continue;
        if (k === 'class') n.className = v;
        else if (k === 'text') n.textContent = v;
        else if (k === 'style') n.setAttribute('style', v);
        else n.setAttribute(k, v);
      }
    }
    if (children) {
      (Array.isArray(children) ? children : [children]).forEach(function (c) {
        if (c === null || c === undefined) return;
        n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
      });
    }
    return n;
  }

  function post(action, payload) {
    return fetch(API + action, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': CSRF
      },
      credentials: 'same-origin',
      body: JSON.stringify(payload || {})
    }).then(function (r) { return r.json(); });
  }

  /* ------------------------------------------------------------------ 主题 */

  var htmlEl = document.documentElement;

  // 配色：k = data-theme 值，c = [底色, 强调色] 用于色卡预览
  var PALETTES = [
    { k: 'dark',     n: '墨金', c: ['#090B0A', '#C9A227'] },
    { k: 'light',    n: '宣纸', c: ['#F7F5F0', '#A8841A'] },
    { k: 'chabai',   n: '茶白', c: ['#F3F1E9', '#3E6B52'] },
    { k: 'forest',   n: '苔青', c: ['#081210', '#7FBFA9'] },
    { k: 'ocean',    n: '深海', c: ['#060B14', '#6EA8F0'] },
    { k: 'violet',   n: '夜紫', c: ['#0A0714', '#A78BFA'] },
    { k: 'mono',     n: '极简', c: ['#0B0B0B', '#EDEDED'] },
    { k: 'rose',     n: '樱砂', c: ['#FBF5F2', '#C4717A'] },
    { k: 'terminal', n: '终端', c: ['#04090A', '#4ADE80'] }
  ];

  var BACKDROPS = [
    { k: 'aurora', n: '极光' },
    { k: 'grid',   n: '网格' },
    { k: 'dots',   n: '点阵' },
    { k: 'mesh',   n: '云斑' },
    { k: 'bamboo', n: '竹影' },
    { k: 'paper',  n: '纸纹' },
    { k: 'plain',  n: '纯色' },
    { k: 'photo',  n: '图片' }
  ];

  // 服务端下发的默认值（后台设置里配的），「恢复默认」就回到这里
  var DEF_THEME = htmlEl.getAttribute('data-theme') || 'dark';
  var DEF_BG = htmlEl.getAttribute('data-bg') || 'aurora';

  function hasKey(list, k) {
    for (var i = 0; i < list.length; i++) if (list[i].k === k) return true;
    return false;
  }

  function syncMetaColor() {
    var meta = document.querySelector('meta[name="theme-color"]');
    if (!meta) return;
    var v = '';
    try { v = (getComputedStyle(htmlEl).getPropertyValue('--meta-color') || '').trim(); } catch (e) {}
    if (v) meta.setAttribute('content', v);
  }

  // remember=false 时只应用不写 localStorage（用于首屏还原用户本机选择）
  function applyTheme(t, remember) {
    if (!hasKey(PALETTES, t)) t = DEF_THEME;
    htmlEl.setAttribute('data-theme', t);
    if (remember !== false) {
      try { localStorage.setItem('qcnav_theme', t); } catch (e) {}
    }
    syncMetaColor();
    markActive();
  }

  function applyBg(b, remember) {
    if (!hasKey(BACKDROPS, b)) b = DEF_BG;
    htmlEl.setAttribute('data-bg', b);
    if (remember !== false) {
      try { localStorage.setItem('qcnav_bg', b); } catch (e) {}
    }
    markActive();
  }

  function currentTheme() { return htmlEl.getAttribute('data-theme'); }
  function currentBg() { return htmlEl.getAttribute('data-bg'); }

  // 本机选择优先于后台默认值
  try {
    var savedT = localStorage.getItem('qcnav_theme');
    if (savedT && hasKey(PALETTES, savedT)) htmlEl.setAttribute('data-theme', savedT);
    var savedB = localStorage.getItem('qcnav_bg');
    if (savedB && hasKey(BACKDROPS, savedB)) htmlEl.setAttribute('data-bg', savedB);
  } catch (e) {}
  syncMetaColor();

  /* ---- 面板 ---- */

  var popEl = document.getElementById('themePop');
  var palEl = document.getElementById('tpPalette');
  var bgListEl = document.getElementById('tpBg');

  function markActive() {
    if (palEl) {
      Array.prototype.forEach.call(palEl.children, function (n) {
        n.classList.toggle('is-on', n.getAttribute('data-k') === currentTheme());
      });
    }
    if (bgListEl) {
      Array.prototype.forEach.call(bgListEl.children, function (n) {
        n.classList.toggle('is-on', n.getAttribute('data-k') === currentBg());
      });
    }
  }

  function buildPanel() {
    if (!palEl || !bgListEl) return;

    PALETTES.forEach(function (p) {
      var swatch = el('button', { type: 'button', class: 'tp-swatch', 'data-k': p.k, title: p.n }, [
        el('i', {
          style: 'background:linear-gradient(135deg,' +
                 p.c[0] + ' 0%,' + p.c[0] + ' 49%,' + p.c[1] + ' 51%,' + p.c[1] + ' 100%)'
        }),
        document.createTextNode(p.n)
      ]);
      swatch.addEventListener('click', function () { applyTheme(p.k); });
      palEl.appendChild(swatch);
    });

    BACKDROPS.forEach(function (b) {
      var item = el('button', { type: 'button', class: 'tp-bg', 'data-k': b.k, 'data-bg': b.k }, [
        el('i'),
        document.createTextNode(b.n)
      ]);
      item.addEventListener('click', function () { applyBg(b.k); });
      bgListEl.appendChild(item);
    });

    markActive();
  }

  function openPop(open) {
    if (!popEl) return;
    popEl.hidden = !open;
    var btn = document.getElementById('themeBtn');
    if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  buildPanel();

  var themeBtn = document.getElementById('themeBtn');
  if (themeBtn) {
    themeBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      openPop(popEl.hidden);
    });
  }

  // 点面板内部不关，点外面关
  if (popEl) {
    popEl.addEventListener('click', function (e) { e.stopPropagation(); });
    document.addEventListener('click', function () { openPop(false); });
  }

  var resetBtn = document.getElementById('tpReset');
  if (resetBtn) {
    resetBtn.addEventListener('click', function () {
      try {
        localStorage.removeItem('qcnav_theme');
        localStorage.removeItem('qcnav_bg');
      } catch (e) {}
      applyTheme(DEF_THEME, false);
      applyBg(DEF_BG, false);
    });
  }

  /* ------------------------------------------------------------------ PIN */

  var pinForm = document.getElementById('pinForm');
  if (pinForm) {
    var pinInput = document.getElementById('pinInput');
    var pinErr = document.getElementById('pinErr');
    pinForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var v = (pinInput.value || '').trim();
      if (!v) return;
      pinErr.textContent = '';
      post('pin_verify', { pin: v }).then(function (r) {
        if (r.ok) {
          location.reload();
        } else {
          pinErr.textContent = r.error || '访问码不正确';
          pinInput.value = '';
          pinInput.focus();
        }
      }).catch(function () {
        pinErr.textContent = '网络异常，请重试';
      });
    });
    if (pinInput) pinInput.focus();
    return; // PIN 模式下不再渲染其他内容
  }

  /* ------------------------------------------------------------------ 渲染 */

  var app = document.getElementById('app');
  var chipsEl = document.getElementById('chips');
  var emptyEl = document.getElementById('empty');
  var footStat = document.getElementById('footStat');
  var qEl = document.getElementById('q');
  var kbdEl = document.getElementById('searchKbd');

  var groups = (BOOT && BOOT.groups) || [];
  var allCards = [];   // { node, sec, haystack }
  var allSecs = [];    // { node, catId }

  /** 取 favicon 地址；走本站代理 + 服务端缓存，避免跨域与 /favicon.ico 404 */
  function faviconOf(url) {
    try {
      var u = new URL(url);
      if (!/^https?:$/.test(u.protocol)) return '';
      return BASE + '/favicon.php?d=' + encodeURIComponent(u.hostname);
    } catch (e) {
      return '';
    }
  }

  /**
   * 图标节点
   *
   * 三级兜底：
   *   1. 自定义 icon URL
   *   2. 本站 favicon 代理（服务端抓取 + 缓存，解决 /favicon.ico 404 与跨域）
   *   3. 代理失败时由浏览器直连原站 /favicon.ico —— 服务器在国内够不到的海外站，
   *      访客自己的网络往往能拿到
   *   4. 全失败才显示首字方块
   */
  function iconNode(site, accent) {
    var box = el('div', {
      class: 'card-icon',
      style: accent ? 'color:' + accent : null
    });

    var text = el('span', { text: site.init || (site.name || '?').charAt(0) });
    box.appendChild(text);

    var host = '';
    var origin = '';
    try {
      var u = new URL(site.url);
      if (/^https?:$/.test(u.protocol)) {
        host = u.hostname;
        origin = u.origin;
      }
    } catch (e) {}

    var primary = site.icon || (host ? BASE + '/favicon.php?d=' + encodeURIComponent(host) : '');
    var direct = origin ? origin + '/favicon.ico' : '';

    if (!primary) return box;

    var img = new Image();
    img.alt = '';
    // ⚠️ 不能设 loading="lazy"：图片此时还未插入 DOM，lazy 会让浏览器
    // 等到进入视口才加载，而本函数要等 load 之后才把 img 放进 DOM —— 死锁。
    // 图标很小且服务端已缓存 30 天，无需懒加载。
    img.decoding = 'async';
    img.referrerPolicy = 'no-referrer';

    img.addEventListener('load', function () {
      // load 已触发即说明是有效图片（404 会走 error）。
      // 不能用 naturalWidth 判断：SVG 常常没有固有尺寸，naturalWidth 为 0。
      if (text.parentNode) text.parentNode.removeChild(text);
      box.appendChild(img);
    });

    img.addEventListener('error', function () {
      // 代理没拿到 → 让浏览器直连试一次
      if (!img.dataset.retry && direct && !site.icon) {
        img.dataset.retry = '1';
        img.src = direct;
        return;
      }
      if (img.parentNode) img.parentNode.removeChild(img);
    });

    img.src = primary;
    return box;
  }

  function cardNode(site, accent) {
    var badge = null;
    if (site.badge) {
      badge = el('span', {
        class: 'badge' + (site.badge === '新' ? ' new' : ''),
        text: site.badge
      });
    }

    var nameRow = el('div', { class: 'card-name' }, [
      el('span', { text: site.name, style: 'overflow:hidden;text-overflow:ellipsis' }),
      badge
    ]);

    var descr = site.descr ? el('div', { class: 'card-descr', text: site.descr }) : null;

    var card = el('a', {
      class: 'card' + (site.hl ? ' is-hl' : ''),
      href: site.url,
      target: '_blank',
      rel: 'noopener noreferrer',
      'data-id': site.id
    }, [
      iconNode(site, accent),
      el('div', { class: 'card-body' }, [nameRow, descr])
    ]);

    // 记录点击（不阻塞跳转，失败静默）
    card.addEventListener('click', function () {
      try {
        fetch(API + 'hit', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
          credentials: 'same-origin',
          keepalive: true,
          body: JSON.stringify({ id: site.id })
        }).catch(function () {});
      } catch (e) {}
    });

    return card;
  }

  function build() {
    if (!app) return;

    groups.forEach(function (g, gi) {
      var accent = g.accent || '#C9A227';

      var head = el('div', { class: 'sec-head' }, [
        el('span', { class: 'sec-bar', style: 'background:' + accent + ';box-shadow:0 0 12px -2px ' + accent }),
        g.icon ? el('span', { class: 'sec-ico', text: g.icon }) : null,
        el('span', { class: 'sec-name', text: g.name }),
        el('span', { class: 'sec-count', text: String(g.sites.length).padStart(2, '0') })
      ]);

      var grid = el('div', { class: 'grid' });

      g.sites.forEach(function (s, si) {
        var card = cardNode(s, accent);
        card.style.setProperty('--i', String(Math.min(si, 18)));
        card.style.setProperty('--accent', accent);
        grid.appendChild(card);

        allCards.push({
          node: card,
          sec: null,
          hay: (
            (s.name || '') + ' ' + (s.url || '') + ' ' +
            (s.descr || '') + ' ' + ((s.tags || []).join(' '))
          ).toLowerCase()
        });
      });

      var sec = el('section', {
        class: 'sec',
        id: 'cat-' + g.id,
        style: '--accent:' + accent
      }, [head, grid]);

      app.appendChild(sec);
      allSecs.push({ node: sec, id: g.id, name: g.name, count: g.sites.length, icon: g.icon, accent: accent });

      // 回填 section 引用
      for (var i = allCards.length - g.sites.length; i < allCards.length; i++) {
        allCards[i].sec = sec;
      }
    });

    // 分类快捷标签
    if (chipsEl) {
      allSecs.forEach(function (s) {
        var chip = el('a', {
          class: 'chip',
          href: '#cat-' + s.id
        }, [
          el('span', { text: (s.icon ? s.icon + ' ' : '') + s.name }),
          el('span', { class: 'chip-n', text: String(s.count) })
        ]);
        chipsEl.appendChild(chip);
      });
    }

    if (footStat) {
      var n = (BOOT && BOOT.total) || allCards.length;
      footStat.textContent = n + ' 个站点 · ' + allSecs.length + ' 个分类';
    }
  }

  /* ------------------------------------------------------------------ 搜索 */

  var searchTimer = null;

  function doFilter(q) {
    var kw = (q || '').trim().toLowerCase();
    var visibleCount = 0;

    allCards.forEach(function (c) {
      var hit = !kw || c.hay.indexOf(kw) !== -1;
      c.node.style.display = hit ? '' : 'none';
      if (hit) visibleCount++;
    });

    // 分类内无可见卡片则整块隐藏
    allSecs.forEach(function (s) {
      var inner = s.node.querySelectorAll('.card');
      var any = false;
      for (var i = 0; i < inner.length; i++) {
        if (inner[i].style.display !== 'none') { any = true; break; }
      }
      s.node.style.display = any ? '' : 'none';
    });

    if (emptyEl) emptyEl.hidden = visibleCount !== 0;
    if (chipsEl) chipsEl.style.display = kw ? 'none' : '';
  }

  if (qEl) {
    qEl.addEventListener('input', function () {
      clearTimeout(searchTimer);
      var v = qEl.value;
      searchTimer = setTimeout(function () { doFilter(v); }, 70);
    });

    qEl.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        qEl.value = '';
        doFilter('');
        qEl.blur();
      }
      // 回车跳到第一个可见结果
      if (e.key === 'Enter') {
        var first = null;
        for (var i = 0; i < allCards.length; i++) {
          if (allCards[i].node.style.display !== 'none') { first = allCards[i].node; break; }
        }
        if (first && first.href) window.open(first.href, '_blank', 'noopener');
      }
    });
  }

  /* ------------------------------------------------------------------ 快捷键 */

  document.addEventListener('keydown', function (e) {
    var tag = (e.target && e.target.tagName) || '';
    var typing = tag === 'INPUT' || tag === 'TEXTAREA' || (e.target && e.target.isContentEditable);

    // "/" 聚焦搜索
    if (e.key === '/' && !typing) {
      e.preventDefault();
      if (qEl) qEl.focus();
      return;
    }
    // "T" 循环切换配色
    if ((e.key === 't' || e.key === 'T') && !typing && !e.ctrlKey && !e.metaKey && !e.altKey) {
      var idx = 0;
      for (var pi = 0; pi < PALETTES.length; pi++) {
        if (PALETTES[pi].k === currentTheme()) { idx = pi; break; }
      }
      applyTheme(PALETTES[(idx + 1) % PALETTES.length].k);
      return;
    }
    // "Esc"：先关主题面板，再清空搜索
    if (e.key === 'Escape') {
      if (popEl && !popEl.hidden) { openPop(false); return; }
      if (qEl && qEl.value) {
        qEl.value = '';
        doFilter('');
      }
    }
  });

  // 触屏设备不显示 "/" 提示
  if (kbdEl && window.matchMedia && window.matchMedia('(hover: none)').matches) {
    kbdEl.style.display = 'none';
  }

  /* ------------------------------------------------------------------ 启动 */

  build();
  doFilter('');
})();
