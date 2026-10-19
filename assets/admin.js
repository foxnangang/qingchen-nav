/* ==========================================================================
   卿辰导航 — 后台逻辑
   纯原生 JS，无依赖
@since 2026-10-19
   ========================================================================== */
(function () {
  'use strict';

  var CSRF = window.__CSRF__ || '';
  // 支持子目录部署：由 PHP 注入 __BASE__（装在根目录时为空串）
  var BASE = window.__BASE__ || '';
  var API  = BASE + '/api.php?action=';
  var LOGGED = window.__LOGGED__ === true;

  function $(id) { return document.getElementById(id); }

  function el(tag, attrs, children) {
    var n = document.createElement(tag);
    if (attrs) {
      for (var k in attrs) {
        if (!Object.prototype.hasOwnProperty.call(attrs, k)) continue;
        var v = attrs[k];
        if (v === null || v === undefined || v === false) continue;
        if (k === 'class') n.className = v;
        else if (k === 'text') n.textContent = v;
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

  function req(action, payload) {
    return fetch(API + action, {
      method: payload === undefined ? 'GET' : 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': CSRF
      },
      credentials: 'same-origin',
      body: payload === undefined ? undefined : JSON.stringify(payload)
    }).then(function (r) {
      return r.json().then(function (j) {
        if (!r.ok || j.ok === false) {
          var e = new Error(j.error || ('HTTP ' + r.status));
          e.status = r.status;
          throw e;
        }
        return j;
      });
    });
  }

  /* ================================================================ 登录 */

  if (!LOGGED) {
    var form = $('loginForm');
    var errEl = $('loginErr');
    var btn = $('loginBtn');

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      errEl.textContent = '';
      btn.disabled = true;
      btn.textContent = '登录中…';

      fetch(API + 'login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({
          username: $('loginUser').value.trim(),
          password: $('loginPass').value
        })
      }).then(function (r) { return r.json(); }).then(function (j) {
        if (j.ok) {
          location.reload();
        } else {
          errEl.textContent = j.error || '登录失败';
          $('loginPass').value = '';
          $('loginPass').focus();
        }
      }).catch(function () {
        errEl.textContent = '网络异常，请重试';
      }).finally(function () {
        btn.disabled = false;
        btn.textContent = '登录';
      });
    });

    return;
  }

  /* ================================================================ 状态 */

  var state = {
    cats: [],
    sites: {},      // { catId: [site,...] }
    config: {},
    stats: {},
    curCat: 0,      // 0 = 全部
    keyword: ''
  };

  var rowsEl = $('rows');
  var rowsEmpty = $('rowsEmpty');
  var catListEl = $('catList');
  var viewTitle = $('viewTitle');
  var viewDesc = $('viewDesc');
  var filterEl = $('filter');

  /* ================================================================ 加载 */

  /**
   * 把后端返回的 sites 统一归一成 { catId: [site,...] }
   * 后端正常返回对象（键为分类 id 字符串）；万一返回扁平数组也能兜住。
   */
  function normalizeSites(raw) {
    if (!raw) return {};
    if (!Array.isArray(raw)) return raw;
    var by = {};
    raw.forEach(function (s) {
      if (!s) return;
      var k = +s.category_id;
      if (!by[k]) by[k] = [];
      by[k].push(s);
    });
    return by;
  }

  /**
   * 取某分类下的站点。键可能被存成数字或字符串，两种都试一遍，
   * 避免出现「侧栏有数字、点进去空白」这种键类型不一致的坑。
   */
  function catSites(catId) {
    var s = state.sites;
    if (!s) return [];
    if (Array.isArray(s)) {
      return s.filter(function (x) { return +x.category_id === +catId; });
    }
    var v = s[catId];
    if (Array.isArray(v)) return v;
    v = s[String(catId)];
    if (Array.isArray(v)) return v;
    return [];
  }

  function load() {
    return req('admin_data').then(function (j) {
      state.cats = j.cats || [];
      state.sites = normalizeSites(j.sites);
      state.config = j.config || {};
      state.stats = j.stats || {};
      if (j.csrf) CSRF = j.csrf;
      renderSide();
      renderRows();
      renderStats();
    }).catch(function (e) {
      if (e.status === 401) location.reload();
      else alert('加载失败：' + e.message);
    });
  }

  function catNameById(id) {
    for (var i = 0; i < state.cats.length; i++) {
      if (+state.cats[i].id === +id) return state.cats[i].name;
    }
    return '未分类';
  }

  function catAccentById(id) {
    for (var i = 0; i < state.cats.length; i++) {
      if (+state.cats[i].id === +id) return state.cats[i].accent || 'var(--gold)';
    }
    return 'var(--gold)';
  }

  function totalSites() {
    var n = 0;
    for (var k in state.sites) {
      if (Array.isArray(state.sites[k])) n += state.sites[k].length;
    }
    return n;
  }

  /* ================================================================ 侧栏 */

  function renderSide() {
    // 保留「全部站点」按钮，清掉其余
    while (catListEl.children.length > 1) {
      catListEl.removeChild(catListEl.lastChild);
    }

    $('nAll').textContent = String(totalSites());

    state.cats.forEach(function (c) {
      var n = catSites(c.id).length;
      var item = el('button', {
        type: 'button',
        class: 'cat-item' + (state.curCat === +c.id ? ' active' : ''),
        'data-cat': c.id
      }, [
        el('span', { class: 'cat-name', text: (c.icon ? c.icon + ' ' : '') + c.name }),
        el('span', { class: 'cat-n', text: String(n) })
      ]);

      // 双击进入分类编辑
      item.addEventListener('click', function () {
        state.curCat = +c.id;
        renderSide();
        renderRows();
      });
      item.addEventListener('dblclick', function (e) {
        e.preventDefault();
        openCatModal(c);
      });

      catListEl.appendChild(item);
    });

    catListEl.children[0].classList.toggle('active', state.curCat === 0);
  }

  function renderStats() {
    var s = $('sideStats');
    if (s) {
      s.textContent = state.stats.sites + ' 站点 · ' + state.stats.cats + ' 分类';
    }
  }

  /* ================================================================ 列表 */

  function currentSites() {
    if (state.curCat === 0) {
      var out = [];
      state.cats.forEach(function (c) {
        catSites(c.id).forEach(function (s) { out.push(s); });
      });
      return out;
    }
    return catSites(state.curCat).slice();
  }

  function iconFor(site) {
    var box = el('div', { class: 'r-ico' });
    var txt = el('span', { text: (site.initial || site.name || '?').charAt(0) });
    box.appendChild(txt);

    var host = '';
    var origin = '';
    try {
      var u = new URL(site.url);
      if (/^https?:$/.test(u.protocol)) {
        host = u.hostname;
        origin = u.origin;
      }
    } catch (e) {}

    var src = site.icon || (host ? BASE + '/favicon.php?d=' + encodeURIComponent(host) : '');
    if (!src) return box;

    var direct = origin ? origin + '/favicon.ico' : '';

    var img = new Image();
    img.alt = '';
    img.referrerPolicy = 'no-referrer';
    img.addEventListener('load', function () {
      // load 即有效（404 走 error）。SVG 的 naturalWidth 常为 0，不能用它判断
      if (txt.parentNode) txt.parentNode.removeChild(txt);
      box.appendChild(img);
    });
    img.addEventListener('error', function () {
      // 代理没拿到 → 浏览器直连再试一次
      if (!img.dataset.retry && direct && !site.icon) {
        img.dataset.retry = '1';
        img.src = direct;
        return;
      }
      if (img.parentNode) img.parentNode.removeChild(img);
    });
    img.src = src;
    return box;
  }

  function renderRows() {
    rowsEl.innerHTML = '';

    var list = currentSites();
    var kw = state.keyword.trim().toLowerCase();

    if (kw) {
      list = list.filter(function (s) {
        return ((s.name || '') + ' ' + (s.url || '') + ' ' + (s.descr || '')).toLowerCase().indexOf(kw) !== -1;
      });
    }

    // 标题
    if (state.curCat === 0) {
      viewTitle.textContent = '全部站点';
      viewDesc.textContent = '共 ' + list.length + ' 个站点。点左侧分类可单独管理并拖拽排序';
    } else {
      var cat = state.cats.filter(function (c) { return +c.id === state.curCat; })[0];
      viewTitle.textContent = (cat && cat.icon ? cat.icon + ' ' : '') + (cat ? cat.name : '');
      viewDesc.textContent = list.length + ' 个站点 · 拖拽左侧圆形手柄调整顺序，改动自动保存';
    }

    rowsEmpty.hidden = list.length !== 0;

    var draggable = state.curCat !== 0 && !kw;

    list.forEach(function (s) {
      var hidden = +s.visible === 0;

      var handle = el('button', {
        type: 'button',
        class: 'r-drag',
        title: draggable ? '拖动调整顺序' : '在具体分类下才能拖拽排序',
        text: '⠿'
      });
      if (!draggable) handle.style.opacity = '0.28';

      var nameRow = el('div', { class: 'r-name' }, [
        el('span', { text: s.name, style: 'overflow:hidden;text-overflow:ellipsis' }),
        s.badge ? el('span', { class: 'pill', text: s.badge }) : null,
        hidden ? el('span', { class: 'pill off', text: '已隐藏' }) : null
      ]);

      var row = el('div', {
        class: 'row' + (hidden ? ' is-hidden' : ''),
        'data-id': s.id
      }, [
        handle,
        el('div', { class: 'r-site' }, [
          iconFor(s),
          el('div', { class: 'r-meta' }, [
            nameRow,
            el('div', { class: 'r-url', text: s.url })
          ])
        ]),
        el('div', { class: 'r-cat', text: catNameById(s.category_id) }),
        el('div', { class: 'r-tag', text: s.tags || '—' }),
        el('div', { class: 'r-op' }, [
          el('button', {
            type: 'button', class: 'btn btn-ghost small', 'data-act': 'edit', text: '编辑'
          }),
          el('button', {
            type: 'button', class: 'btn btn-ghost small',
            'data-act': 'toggle', text: hidden ? '显示' : '隐藏'
          }),
          el('button', {
            type: 'button', class: 'btn btn-ghost small danger',
            'data-act': 'del', text: '删除'
          })
        ])
      ]);

      row.addEventListener('click', function (e) {
        var act = e.target && e.target.getAttribute('data-act');
        if (!act) return;
        e.preventDefault();
        if (act === 'edit') openSiteModal(s);
        else if (act === 'toggle') toggleSite(s);
        else if (act === 'del') delSite(s);
      });

      if (draggable) bindDrag(row);

      rowsEl.appendChild(row);
    });
  }

  /* ================================================================ 拖拽排序 */

  var dragEl = null;

  function bindDrag(row) {
    row.setAttribute('draggable', 'true');

    row.addEventListener('dragstart', function (e) {
      // 只允许从手柄发起，避免误拖
      dragEl = row;
      row.classList.add('dragging');
      try {
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', row.dataset.id);
      } catch (err) {}
    });

    row.addEventListener('dragend', function () {
      row.classList.remove('dragging');
      document.querySelectorAll('.row.drag-over').forEach(function (n) {
        n.classList.remove('drag-over');
      });
      dragEl = null;
    });

    row.addEventListener('dragover', function (e) {
      if (!dragEl || dragEl === row) return;
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      row.classList.add('drag-over');
    });

    row.addEventListener('dragleave', function () {
      row.classList.remove('drag-over');
    });

    row.addEventListener('drop', function (e) {
      e.preventDefault();
      row.classList.remove('drag-over');
      if (!dragEl || dragEl === row) return;

      var rect = row.getBoundingClientRect();
      var after = (e.clientY - rect.top) > rect.height / 2;

      if (after) row.parentNode.insertBefore(dragEl, row.nextSibling);
      else row.parentNode.insertBefore(dragEl, row);

      saveOrder();
    });
  }

  var orderTimer = null;

  function saveOrder() {
    clearTimeout(orderTimer);
    orderTimer = setTimeout(function () {
      var ids = [];
      Array.prototype.forEach.call(rowsEl.children, function (n) {
        ids.push(+n.dataset.id);
      });
      req('reorder', { type: 'site', ids: ids }).catch(function (e) {
        alert('排序保存失败：' + e.message);
        load();
      });
    }, 260);
  }

  /* ================================================================ 站点操作 */

  function toggleSite(s) {
    req('site_toggle', { id: +s.id }).then(load)
      .catch(function (e) { alert('操作失败：' + e.message); });
  }

  function delSite(s) {
    if (!confirm('确认删除「' + s.name + '」？\n\n删除后不可恢复。')) return;
    req('site_delete', { id: +s.id }).then(load)
      .catch(function (e) { alert('删除失败：' + e.message); });
  }

  function fillCatSelect(selected) {
    var sel = $('sCat');
    sel.innerHTML = '';
    state.cats.forEach(function (c) {
      var o = el('option', { value: c.id, text: (c.icon ? c.icon + ' ' : '') + c.name });
      if (+c.id === +selected) o.selected = true;
      sel.appendChild(o);
    });
  }

  function openSiteModal(site) {
    $('siteErr').textContent = '';
    $('siteModalTitle').textContent = site ? '编辑站点' : '新增站点';
    $('sId').value = site ? site.id : '';
    $('sName').value = site ? site.name : '';
    $('sUrl').value = site ? site.url : '';
    $('sDescr').value = site ? site.descr : '';
    $('sTags').value = site ? site.tags : '';
    $('sBadge').value = site ? site.badge : '';
    $('sIcon').value = site ? site.icon : '';
    $('sHl').checked = site ? +site.highlight === 1 : false;
    $('sVisible').checked = site ? +site.visible === 1 : true;

    var defCat = site ? site.category_id : (state.curCat || (state.cats[0] && state.cats[0].id));
    fillCatSelect(defCat);

    openModal('siteModal');
    setTimeout(function () { $('sName').focus(); }, 60);
  }

  function saveSite() {
    var errEl = $('siteErr');
    errEl.textContent = '';

    var payload = {
      id: +$('sId').value || 0,
      name: $('sName').value.trim(),
      url: $('sUrl').value.trim(),
      descr: $('sDescr').value.trim(),
      category_id: +$('sCat').value,
      tags: $('sTags').value.trim(),
      badge: $('sBadge').value.trim(),
      icon: $('sIcon').value.trim(),
      highlight: $('sHl').checked ? 1 : 0,
      visible: $('sVisible').checked ? 1 : 0
    };

    if (!payload.name) { errEl.textContent = '请填写站点名称'; return; }
    if (!payload.url) { errEl.textContent = '请填写网址'; return; }

    var b = $('siteSaveBtn');
    b.disabled = true;
    b.textContent = '保存中…';

    req('site_save', payload).then(function () {
      closeModal('siteModal');
      load();
    }).catch(function (e) {
      errEl.textContent = e.message;
    }).finally(function () {
      b.disabled = false;
      b.textContent = '保存';
    });
  }

  /* ================================================================ 分类操作 */

  function openCatModal(cat) {
    $('catErr').textContent = '';
    $('catModalTitle').textContent = cat ? '编辑分类' : '新建分类';
    $('cId').value = cat ? cat.id : '';
    $('cName').value = cat ? cat.name : '';
    $('cIcon').value = cat ? cat.icon : '';
    $('cAccent').value = (cat && cat.accent) ? cat.accent : '#C9A227';
    $('cVisible').checked = cat ? +cat.visible === 1 : true;
    $('catDeleteBtn').hidden = !cat;
    openModal('catModal');
    setTimeout(function () { $('cName').focus(); }, 60);
  }

  function saveCat() {
    var errEl = $('catErr');
    errEl.textContent = '';
    var payload = {
      id: +$('cId').value || 0,
      name: $('cName').value.trim(),
      icon: $('cIcon').value.trim(),
      accent: $('cAccent').value,
      visible: $('cVisible').checked ? 1 : 0
    };
    if (!payload.name) { errEl.textContent = '请填写分类名称'; return; }

    req('cat_save', payload).then(function () {
      closeModal('catModal');
      load();
    }).catch(function (e) { errEl.textContent = e.message; });
  }

  function delCat() {
    var id = +$('cId').value;
    if (!id) return;
    if (!confirm('确认删除该分类？分类下必须没有站点。')) return;
    req('cat_delete', { id: id }).then(function () {
      closeModal('catModal');
      if (state.curCat === id) state.curCat = 0;
      load();
    }).catch(function (e) { $('catErr').textContent = e.message; });
  }

  /* ================================================================ 设置 */

  // 与前台 app.js 里的两份定义保持一致
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

  var picked = { theme: 'dark', bg: 'aurora' };

  function renderPickers() {
    var palEl = $('pickPalette');
    if (palEl) {
      palEl.innerHTML = '';
      PALETTES.forEach(function (p) {
        var b = el('button', { type: 'button', class: 'pick pick-palette' + (picked.theme === p.k ? ' is-on' : ''), 'data-k': p.k }, [
          el('i', {
            style: 'background:linear-gradient(135deg,' +
                   p.c[0] + ' 0%,' + p.c[0] + ' 49%,' + p.c[1] + ' 51%,' + p.c[1] + ' 100%)'
          }),
          document.createTextNode(p.n)
        ]);
        b.addEventListener('click', function () {
          picked.theme = p.k;
          renderPickers();
          // 顺手把后台自己也换过去，所见即所得
          document.documentElement.setAttribute('data-theme', p.k);
        });
        palEl.appendChild(b);
      });
    }

    var bgEl = $('pickBg');
    if (bgEl) {
      bgEl.innerHTML = '';
      BACKDROPS.forEach(function (g) {
        var b = el('button', {
          type: 'button',
          class: 'pick' + (picked.bg === g.k ? ' is-on' : ''),
          'data-k': g.k,
          'data-bg': g.k
        }, [el('i'), document.createTextNode(g.n)]);
        b.addEventListener('click', function () {
          picked.bg = g.k;
          renderPickers();
        });
        bgEl.appendChild(b);
      });
    }
  }

  function openSettings() {
    $('setErr').textContent = '';
    $('setOk').textContent = '';
    $('setTitle').value = state.config.title || '';
    $('setSubtitle').value = state.config.subtitle || '';
    $('setOwner').value = state.config.owner || '';
    $('setFooter').value = state.config.footer || '';
    $('setAccess').value = state.config.access || 'public';
    $('setPin').value = '';
    $('pwOld').value = '';
    $('pwNew').value = '';
    $('pwUser').value = state.config.user || 'admin';

    picked.theme = state.config.theme || 'dark';
    picked.bg = state.config.bg || 'aurora';
    $('setBgImage').value = state.config.bg_image || '';

    var dim = parseInt(state.config.bg_dim, 10);
    if (isNaN(dim)) dim = 42;
    $('setBgDim').value = dim;
    $('dimVal').textContent = dim + '%';

    renderPickers();
    openModal('setModal');
  }

  function saveSettings() {
    var errEl = $('setErr');
    var okEl = $('setOk');
    errEl.textContent = '';
    okEl.textContent = '';

    req('settings_save', {
      title: $('setTitle').value.trim(),
      subtitle: $('setSubtitle').value.trim(),
      owner: $('setOwner').value.trim(),
      theme: picked.theme,
      bg: picked.bg,
      bg_image: $('setBgImage').value.trim(),
      bg_dim: parseInt($('setBgDim').value, 10) || 0,
      footer: $('setFooter').value.trim(),
      access: $('setAccess').value
    }).then(function () {
      okEl.textContent = '设置已保存';
      return load();
    }).catch(function (e) { errEl.textContent = e.message; });
  }

  function savePin() {
    var errEl = $('setErr');
    var okEl = $('setOk');
    errEl.textContent = '';
    okEl.textContent = '';
    var pin = $('setPin').value;
    if (pin.length < 4) { errEl.textContent = '访问码至少 4 位'; return; }
    req('pin_save', { pin: pin }).then(function () {
      okEl.textContent = '访问码已更新';
      $('setPin').value = '';
    }).catch(function (e) { errEl.textContent = e.message; });
  }

  function savePassword() {
    var errEl = $('setErr');
    var okEl = $('setOk');
    errEl.textContent = '';
    okEl.textContent = '';
    var oldPw = $('pwOld').value;
    var newPw = $('pwNew').value;
    if (!oldPw || !newPw) { errEl.textContent = '请填写当前密码和新密码'; return; }
    if (newPw.length < 8) { errEl.textContent = '新密码至少 8 位'; return; }

    req('password_save', { old: oldPw, new: newPw, user: $('pwUser').value.trim() })
      .then(function () {
        okEl.textContent = '密码已修改，请重新登录';
        setTimeout(function () { location.reload(); }, 1200);
      })
      .catch(function (e) { errEl.textContent = e.message; });
  }

  /* ================================================================ 导入导出 */

  function doExport() {
    req('export').then(function (j) {
      var blob = new Blob([JSON.stringify(j, null, 2)], { type: 'application/json' });
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'qcnav-' + new Date().toISOString().slice(0, 10) + '.json';
      document.body.appendChild(a);
      a.click();
      setTimeout(function () {
        URL.revokeObjectURL(a.href);
        a.remove();
      }, 400);
    }).catch(function (e) { alert('导出失败：' + e.message); });
  }

  function doImport(file) {
    var reader = new FileReader();
    reader.onload = function () {
      var data;
      try {
        data = JSON.parse(String(reader.result));
      } catch (e) {
        alert('JSON 解析失败：' + e.message);
        return;
      }
      var sites = data.sites || (Array.isArray(data) ? data : null);
      if (!sites || !sites.length) {
        alert('文件里没有找到 sites 数组');
        return;
      }
      if (!confirm('准备导入 ' + sites.length + ' 条站点，已存在的（同分类同名称）会跳过。继续？')) return;

      req('import', { sites: sites }).then(function (j) {
        alert('导入完成：新增 ' + j.added + ' 条，跳过 ' + j.skipped + ' 条');
        load();
      }).catch(function (e) { alert('导入失败：' + e.message); });
    };
    reader.readAsText(file);
  }

  /* ================================================================ 弹窗 */

  function openModal(id) { $(id).hidden = false; }
  function closeModal(id) { $(id).hidden = true; }

  document.querySelectorAll('[data-close]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      closeModal(btn.getAttribute('data-close'));
    });
  });

  document.querySelectorAll('.modal-mask').forEach(function (mask) {
    mask.addEventListener('click', function (e) {
      if (e.target === mask) mask.hidden = true;
    });
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      document.querySelectorAll('.modal-mask').forEach(function (m) { m.hidden = true; });
    }
  });

  /* ================================================================ 事件绑定 */

  $('newSiteBtn').addEventListener('click', function () { openSiteModal(null); });
  $('siteSaveBtn').addEventListener('click', saveSite);

  $('newCatBtn').addEventListener('click', function () { openCatModal(null); });
  $('catSaveBtn').addEventListener('click', saveCat);
  $('catDeleteBtn').addEventListener('click', delCat);

  $('settingsBtn').addEventListener('click', openSettings);
  $('setSaveBtn').addEventListener('click', saveSettings);

  var dimEl = $('setBgDim');
  if (dimEl) {
    dimEl.addEventListener('input', function () {
      $('dimVal').textContent = dimEl.value + '%';
    });
  }
  $('pinSaveBtn').addEventListener('click', savePin);
  $('pwSaveBtn').addEventListener('click', savePassword);

  $('exportBtn').addEventListener('click', doExport);
  $('importFile').addEventListener('change', function (e) {
    if (e.target.files && e.target.files[0]) doImport(e.target.files[0]);
    e.target.value = '';
  });

  $('logoutBtn').addEventListener('click', function () {
    req('logout', {}).then(function () { location.href = BASE + '/'; })
      .catch(function () { location.href = BASE + '/'; });
  });

  filterEl.addEventListener('input', function () {
    state.keyword = filterEl.value;
    renderRows();
  });

  // 「全部站点」按钮
  catListEl.children[0].addEventListener('click', function () {
    state.curCat = 0;
    renderSide();
    renderRows();
  });

  /* ================================================================ 启动 */

  load();
})();
