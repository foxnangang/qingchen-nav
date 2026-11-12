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

  /** 后台是否配了背景图（--photo-url 由 PHP 内联注入） */
  function photoReady() {
    var v = '';
    try { v = (getComputedStyle(htmlEl).getPropertyValue('--photo-url') || '').trim(); } catch (e) {}
    return v !== '' && v !== 'none' && v.indexOf('url(') === 0;
  }

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
        var on = n.getAttribute('data-k') === currentTheme();
        n.classList.toggle('is-on', on);
        n.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
    }
    if (bgListEl) {
      Array.prototype.forEach.call(bgListEl.children, function (n) {
        var on = n.getAttribute('data-k') === currentBg();
        n.classList.toggle('is-on', on);
        n.setAttribute('aria-pressed', on ? 'true' : 'false');
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
      // 没在后台配背景图时，「图片」是个点了没反应的空功能，直接不显示
      if (b.k === 'photo' && !photoReady()) return;
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
    if (scrimEl) scrimEl.hidden = !open;
    var btn = document.getElementById('themeBtn');
    if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    // 关闭时把焦点还给触发按钮，键盘用户不会掉到页面开头
    if (!open && btn && document.activeElement && popEl.contains(document.activeElement)) btn.focus();
  }

  // 遮罩：面板打开时挡住底下的卡片，点空白只关面板，不会再顺手打开链接
  var scrimEl = el('button', {
    type: 'button',
    class: 'pop-scrim',
    hidden: 'hidden',
    'aria-label': '关闭主题面板',
    tabindex: '-1'
  });
  scrimEl.addEventListener('click', function () { openPop(false); });
  var heroEl = document.querySelector('.hero');
  if (heroEl) heroEl.appendChild(scrimEl);

  buildPanel();

  // 之前选了「图片」但后台没配图 → 回到服务端默认背景，避免停在一个空背景上
  if (currentBg() === 'photo' && !photoReady()) applyBg(DEF_BG, false);

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
    var port = '';
    try {
      var u = new URL(site.url);
      if (/^https?:$/.test(u.protocol)) {
        host = u.hostname;
        origin = u.origin;
        port = u.port;
      }
    } catch (e) {}

    // 非标准端口（h.example.com:1666 这类）代理取不到：favicon.php 只按域名走 80/443，
    // 直接交给浏览器按 origin 去取（自带端口），否则必然 404。
    var primary = site.icon
      ? site.icon
      : (port ? (origin + '/favicon.ico')
              : (host ? BASE + '/favicon.php?d=' + encodeURIComponent(host) : ''));
    var direct = origin ? origin + '/favicon.ico' : '';

    if (!primary) return box;

    var img = new Image();
    img.alt = '';
    img.width = 21;
    img.height = 21;
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
      if (!img.dataset.retry && direct && !site.icon && primary !== direct) {
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

    // 保存文字节点，搜索高亮时原位替换（清除搜索要能还原）
    var nameText = el('span', { text: site.name, style: 'overflow:hidden;text-overflow:ellipsis' });

    var nameRow = el('div', { class: 'card-name' }, [
      nameText,
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

    card.__fx = { name: nameText, descr: descr };
    return card;
  }

  function build() {
    if (!app) return;

    groups.forEach(function (g) {
      var accent = g.accent || '#C9A227';
      var secId = 'cat-' + g.id;

      var countEl = el('span', { class: 'sec-count', text: String(g.sites.length).padStart(2, '0') });

      var head = el('div', { class: 'sec-head' }, [
        el('span', { class: 'sec-bar', 'aria-hidden': 'true', style: 'background:' + accent + ';box-shadow:0 0 12px -2px ' + accent }),
        g.icon ? el('span', { class: 'sec-ico', text: g.icon, 'aria-hidden': 'true' }) : null,
        el('h2', { class: 'sec-name', id: secId + '-title', text: g.name }),
        countEl
      ]);

      var grid = el('div', { class: 'grid' });

      // 分类用 section + h2 + aria-labelledby，读屏能按标题跳转
      var sec = el('section', {
        class: 'sec',
        id: secId,
        'aria-labelledby': secId + '-title',
        style: '--accent:' + accent
      }, [head, grid]);

      var cards = [];

      g.sites.forEach(function (s, si) {
        var card = cardNode(s, accent);
        card.style.setProperty('--i', String(Math.min(si, 18)));
        card.style.setProperty('--accent', accent);
        grid.appendChild(card);

        var rec = {
          node: card,
          grid: grid,
          countEl: countEl,
          total: g.sites.length,
          order: si,
          score: 1,
          rawName: s.name || '',
          rawDescr: s.descr || '',
          name: (s.name || '').toLowerCase(),
          descr: (s.descr || '').toLowerCase(),
          tags: (s.tags || []).join(' ').toLowerCase(),
          url: (s.url || '').toLowerCase(),
          hay: ((s.name || '') + ' ' + (s.url || '') + ' ' +
                (s.descr || '') + ' ' + ((s.tags || []).join(' '))).toLowerCase()
        };
        cards.push(rec);
        allCards.push(rec);
      });

      app.appendChild(sec);
      allSecs.push({
        node: sec, id: g.id, name: g.name, count: g.sites.length,
        icon: g.icon, accent: accent, cards: cards, grid: grid
      });
    });

    // 分类快捷标签（emoji 标记 aria-hidden，不读进链接名）
    if (chipsEl) {
      allSecs.forEach(function (s) {
        var chip = el('a', { class: 'chip', href: '#cat-' + s.id }, [
          el('span', {}, [
            s.icon ? el('span', { text: s.icon + ' ', 'aria-hidden': 'true' }) : null,
            document.createTextNode(s.name)
          ]),
          el('span', { class: 'chip-n', text: String(s.count), 'aria-hidden': 'true' })
        ]);
        chipsEl.appendChild(chip);
      });

      // 搜索时 chips 原地换成「找到 N 个站点 · 清除」，高度不变，内容不上跳
      chipsNote = el('div', { class: 'chips-note', hidden: 'hidden' }, [
        el('span', {}, [document.createTextNode('找到 '), el('b', { text: '0' }), document.createTextNode(' 个站点')]),
        el('button', { type: 'button', text: '清除' })
      ]);
      chipsNote.querySelector('button').addEventListener('click', function () { clearSearch(); });
      chipsEl.appendChild(chipsNote);
    }

    if (footStat) {
      var n = (BOOT && BOOT.total) || allCards.length;
      footStat.textContent = n + ' 个站点 · ' + allSecs.length + ' 个分类';
    }
  }

  /* ------------------------------------------------------------------ 搜索 */

  var searchTimer = null;
  var chipsNote = null;
  var statusEl = null;
  var lastBest = null;

  function clearSearch() {
    if (qEl) {
      qEl.value = '';
      qEl.focus();
    }
    doFilter('');
  }

  /** 把 text 里命中的 kw 包进 <mark>；kw 为空时还原纯文本 */
  function markText(node, text, kw) {
    if (!node) return;
    node.textContent = '';
    if (!kw) {
      node.textContent = text;
      return;
    }
    var lower = text.toLowerCase();
    var at = lower.indexOf(kw);
    if (at === -1) {
      node.textContent = text;
      return;
    }
    var from = 0;
    while (at !== -1) {
      if (at > from) node.appendChild(document.createTextNode(text.slice(from, at)));
      var mk = document.createElement('mark');
      mk.className = 'fx-hit';
      mk.textContent = text.slice(at, at + kw.length);
      node.appendChild(mk);
      from = at + kw.length;
      at = lower.indexOf(kw, from);
    }
    if (from < text.length) node.appendChild(document.createTextNode(text.slice(from)));
  }

  /** 打分：名称完全匹配 > 前缀 > 包含 > 标签 > 描述 > URL > 其它字段 */
  function scoreOf(c, kw) {
    if (!kw) return 1;
    if (c.name === kw) return 100;
    if (c.name.indexOf(kw) === 0) return 80;
    if (c.name.indexOf(kw) !== -1) return 60;
    if (c.tags.indexOf(kw) !== -1) return 50;
    if (c.descr.indexOf(kw) !== -1) return 30;
    if (c.url.indexOf(kw) !== -1) return 20;
    if (c.hay.indexOf(kw) !== -1) return 10;
    return 0;
  }

  function sortCards(sec, kw) {
    sec.cards.slice().sort(function (a, b) {
      if (kw) return (b.score - a.score) || (a.order - b.order);
      return a.order - b.order;
    }).forEach(function (c) { sec.grid.appendChild(c.node); });
  }

  /** 过滤 + 计数 + 排序 + 高亮；返回当前最佳结果（回车用） */
  function doFilter(q) {
    var kw = (q || '').trim().toLowerCase();
    var visible = 0;
    var best = null;
    var bestScore = -1;

    allCards.forEach(function (c) {
      var sc = scoreOf(c, kw);
      c.score = sc;
      c.node.style.display = sc ? '' : 'none';
      markText(c.node.__fx.name, c.rawName, kw);
      if (c.node.__fx.descr) markText(c.node.__fx.descr, c.rawDescr, kw);
      if (sc) {
        visible++;
        if (sc > bestScore) {
          bestScore = sc;
          best = c.node;
        }
      }
    });

    allSecs.forEach(function (s) {
      var shown = 0;
      s.cards.forEach(function (c) { if (c.score) shown++; });
      s.node.style.display = shown ? '' : 'none';
      var cnt = s.cards.length ? s.cards[0].countEl : null;
      if (cnt) {
        cnt.textContent = kw
          ? String(shown).padStart(2, '0') + ' / ' + String(s.cards.length).padStart(2, '0')
          : String(s.cards.length).padStart(2, '0');
      }
      sortCards(s, kw);
    });

    if (emptyEl) emptyEl.hidden = visible !== 0;

    if (chipsEl) {
      chipsEl.classList.toggle('is-filtered', !!kw);
      if (chipsNote) {
        chipsNote.hidden = !kw;
        var num = chipsNote.querySelector('b');
        if (num) num.textContent = String(visible);
      }
    }

    if (statusEl) statusEl.textContent = kw ? ('找到 ' + visible + ' 个站点') : '';
    if (clearBtn) clearBtn.hidden = !kw;

    lastBest = best;
    return best;
  }

  // 清空按钮（原生 search 的 cancel 被 CSS 隐藏了，移动端只能一个个退格）
  var clearBtn = null;
  if (qEl) {
    var shell = qEl.parentNode;
    clearBtn = el('button', {
      type: 'button', class: 'search-clear', 'aria-label': '清空搜索', title: '清空搜索',
      hidden: 'hidden', text: '×'
    });
    clearBtn.addEventListener('click', clearSearch);
    shell.appendChild(clearBtn);
  }

  // 读屏用的实时播报区
  statusEl = el('p', { class: 'sr-only', role: 'status', 'aria-live': 'polite' });
  document.body.appendChild(statusEl);

  if (qEl) {
    qEl.addEventListener('input', function () {
      clearTimeout(searchTimer);
      var v = qEl.value;
      searchTimer = setTimeout(function () { doFilter(v); }, 70);
    });
  }

  /* ------------------------------------------------------------------ 快捷键 */

  document.addEventListener('keydown', function (e) {
    var tag = (e.target && e.target.tagName) || '';
    var typing = tag === 'INPUT' || tag === 'TEXTAREA' || (e.target && e.target.isContentEditable);

    // Esc：先关面板，再清搜索
    if (e.key === 'Escape') {
      if (popEl && !popEl.hidden) { openPop(false); return; }
      if (qEl && qEl.value) { clearSearch(); return; }
    }

    // 焦点在输入框里时不抢按键（否则输入 t / 回车会误触）
    if (typing) return;

    // "/" 聚焦搜索
    if (e.key === '/') {
      e.preventDefault();
      if (qEl) qEl.focus();
      return;
    }
    // "T" 循环切换配色
    if ((e.key === 't' || e.key === 'T') && !e.ctrlKey && !e.metaKey && !e.altKey) {
      var idx = 0;
      for (var pi = 0; pi < PALETTES.length; pi++) {
        if (PALETTES[pi].k === currentTheme()) { idx = pi; break; }
      }
      applyTheme(PALETTES[(idx + 1) % PALETTES.length].k);
    }
  });

  // 搜索框里回车：打开得分最高的那个，而不是 DOM 里第一个
  if (qEl) {
    qEl.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        var target = lastBest || null;
        if (!target) {
          for (var i = 0; i < allCards.length; i++) {
            if (allCards[i].node.style.display !== 'none') { target = allCards[i].node; break; }
          }
        }
        if (target && target.href) window.open(target.href, '_blank', 'noopener');
      }
    });
  }

  // 触屏设备不显示 "/" 提示
  if (kbdEl && window.matchMedia && window.matchMedia('(hover: none)').matches) {
    kbdEl.style.display = 'none';
  }

  /* ------------------------------------------------- 前台新增站点（仅管理员） */

  var addMask = document.getElementById('addMask');
  var addBusy = false;

  function addEl(id) { return document.getElementById(id); }

  function addOpen() {
    if (!addMask) return;

    // 分类下拉：优先全量分类（含暂时没有站点的），老数据回落到有站点的分组
    var cats = (BOOT.cats && BOOT.cats.length) ? BOOT.cats : (BOOT.groups || []);
    var sel = addEl('addCat');
    sel.innerHTML = '';
    cats.forEach(function (g) {
      var o = document.createElement('option');
      o.value = g.id;
      o.textContent = g.name;
      sel.appendChild(o);
    });
    if (cats.length) sel.value = cats[0].id;

    addEl('addName').value = '';
    addEl('addUrl').value = '';
    addEl('addDescr').value = '';
    addEl('addBadge').value = '';
    addEl('addHl').checked = false;
    addEl('addMsg').textContent = '';

    addMask.hidden = false;
    document.body.classList.add('em-open');
    setTimeout(function () { addEl('addName').focus(); }, 30);
  }

  function addClose() {
    if (!addMask) return;
    addMask.hidden = true;
    document.body.classList.remove('em-open');
  }

  function addSave() {
    if (addBusy) return;

    var name = addEl('addName').value.trim();
    var url = addEl('addUrl').value.trim();
    if (!name) { addEl('addMsg').textContent = '名称不能为空'; return; }
    if (!url) { addEl('addMsg').textContent = '网址不能为空'; return; }

    var btn = addEl('addSave');
    addBusy = true;
    btn.disabled = true;
    addEl('addMsg').textContent = '保存中…';

    post('site_save', {
      id: 0,                                  // 0 = 新增
      category_id: +addEl('addCat').value,
      name: name,
      url: url,
      descr: addEl('addDescr').value.trim(),
      icon: '',                               // 留空，自动抓 favicon
      initial: '',
      tags: '',
      badge: addEl('addBadge').value.trim(),
      highlight: addEl('addHl').checked ? 1 : 0,
      visible: 1
    }).then(function (res) {
      if (!res || res.ok === false) throw new Error((res && res.error) || '保存失败');
      addEl('addMsg').textContent = '已添加，正在刷新…';
      setTimeout(function () { location.reload(); }, 400);
    }).catch(function (err) {
      addEl('addMsg').textContent = err.message || '保存失败';
      btn.disabled = false;
      addBusy = false;
    });
  }

  function initAddSite() {
    var btn = document.getElementById('addSiteBtn');
    if (!btn || !addMask) return;   // 未登录时这两个元素根本不会输出

    btn.hidden = false;
    btn.addEventListener('click', addOpen);
    addEl('addClose').addEventListener('click', addClose);
    addEl('addCancel').addEventListener('click', addClose);
    addEl('addSave').addEventListener('click', addSave);
    addMask.addEventListener('click', function (e) {
      if (e.target === addMask) addClose();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !addMask.hidden) addClose();
    });
  }

  /* ------------------------------------------------------------------ 启动 */

  build();
  doFilter('');
  initAddSite();
})();
