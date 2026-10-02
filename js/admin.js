/* ==========================================================================
   ПОЛИФОРМ — админка: каталог, заявки, тексты, контакты, пользователи
   ========================================================================== */
(function () {
  'use strict';

  var API = 'api.php';
  var OPT = 'opt';

  var S = {
    user: null,
    csrf: '',
    products: [], categories: [], colors: [],
    settings: {}, orders: [], counts: {},
    view: 'products',
    edit: null,
    dirty: false
  };

  /* ---------- утилиты ---------- */
  function $(s, r) { return (r || document).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }
  function money(v) {
    var p = Number(v || 0).toFixed(2).split('.');
    var w = p[0].replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    return (p[1] === '00' ? w : w + ',' + p[1]) + ' ₽';
  }
  function num(v) { return String(v == null ? '' : v).replace(',', '.'); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function when(s) {
    if (!s) return '';
    var t = String(s).replace(' ', 'T');
    var d = new Date(t);
    if (isNaN(d)) return s;
    var p = function (x) { return x < 10 ? '0' + x : '' + x; };
    return p(d.getDate()) + '.' + p(d.getMonth() + 1) + '.' + d.getFullYear() + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
  }

  /* ---------- сообщения ---------- */
  var toastT = null;
  function toast(msg, err) {
    var t = $('#toast');
    t.textContent = msg;
    t.className = 'ab-toast is-on' + (err ? ' ab-toast--err' : '');
    clearTimeout(toastT);
    toastT = setTimeout(function () { t.className = 'ab-toast'; }, 2800);
  }
  function msgBox(host, text, kind) {
    host.innerHTML = '';
    if (!text) return;
    host.appendChild(el('div', 'ab-msg ab-msg--' + (kind || 'info'), text));
  }

  /* ---------- API ---------- */
  function api(action, data) {
    var opt = { method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } };
    if (S.csrf) opt.headers['X-CSRF-Token'] = S.csrf;
    if (data instanceof FormData) {
      opt.body = data;
    } else if (data !== undefined) {
      opt.headers['Content-Type'] = 'application/json';
      opt.body = JSON.stringify(data);
    }
    return fetch(API + '?action=' + encodeURIComponent(action), opt)
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'HTTP ' + r.status }; }); })
      .then(function (j) {
        if (j && j.csrf) S.csrf = j.csrf;
        if (j && j.ok === false && r401(j)) return Promise.reject(j);
        return j;
      });
  }
  var was401 = false;
  function r401(j) {
    if (j && /вход/i.test(j.error || '') && !was401) { was401 = true; showLogin(); }
    return false;
  }
  function need(j) {
    if (!j || j.ok !== true) {
      var e = (j && j.error) || 'Неизвестная ошибка';
      toast(e, true);
      throw new Error(e);
    }
    return j;
  }

  /* ---------- поле формы ---------- */
  function field(label, name, value, opts) {
    opts = opts || {};
    var w = el('div', 'ab-field');
    var id = 'f-' + name.replace(/[^\w]/g, '-');
    w.appendChild(el('label', null, label));
    var n;
    if (opts.type === 'textarea') {
      n = el('textarea');
      n.rows = opts.rows || 3;
      n.value = value == null ? '' : value;
    } else if (opts.type === 'select') {
      n = el('select');
      (opts.options || []).forEach(function (o) {
        var op = el('option', null, o.label != null ? o.label : o);
        op.value = o.value != null ? o.value : o;
        if (String(op.value) === String(value)) op.selected = true;
        n.appendChild(op);
      });
    } else {
      n = el('input');
      n.type = opts.type || 'text';
      if (opts.step) n.step = opts.step;
      if (opts.min != null) n.min = opts.min;
      if (opts.placeholder) n.placeholder = opts.placeholder;
      if (opts.readonly) n.readOnly = true;
      if (opts.type === 'number' || opts.type === 'checkbox') n.value = num(value);
      else n.value = value == null ? '' : value;
    }
    n.id = id;
    n.name = name;
    n.dataset.key = name;
    w.appendChild(n);
    return w;
  }
  /* ---------- выбор цвета: список с образцами ----------
       Нативный <select> не умеет рисовать кружок внутри варианта, поэтому
       свой список на <ul role="listbox">. Значение лежит в скрытом
       input[data-key], поэтому collect() подхватывает его как обычно.
       Список живёт в слое на <body>: карточка товара лежит внутри
       .ab-panel с overflow:hidden, и позиционированный список обрезался. */
    var PICKERS = [];
    document.addEventListener('mousedown', function (e) {
      PICKERS = PICKERS.filter(function (p) { return document.body.contains(p.wrap); });
      PICKERS.forEach(function (p) { if (!p.wrap.contains(e.target) && !p.list.contains(e.target)) p.close(); });
    });
    window.addEventListener('resize', function () { PICKERS.forEach(function (p) { p.close(); }); });
    document.addEventListener('scroll', function () { PICKERS.forEach(function (p) { p.close(); }); }, true);

    function colorPicker(label, name, value, items) {
      var w = el('div', 'ab-field');
      w.appendChild(el('label', null, label));

      var wrap = el('div', 'ab-picker');
      var btn = el('button', 'ab-picker__btn');
      btn.type = 'button';
      btn.setAttribute('aria-haspopup', 'listbox');
      btn.setAttribute('aria-expanded', 'false');
      var bDot = el('span', 'ab-color-dot ab-color-dot--sm');
      var bTxt = el('span', 'ab-picker__txt');
      btn.appendChild(bDot);
      btn.appendChild(bTxt);
      btn.appendChild(el('span', 'ab-picker__chev', '▾'));
      var list = el('ul', 'ab-picker__list');
      list.setAttribute('role', 'listbox');
      wrap.appendChild(btn);
      document.body.appendChild(list);

      var hidden = el('input');
      hidden.type = 'hidden';
      hidden.name = name;
      hidden.id = 'f-' + name.replace(/[^\w]/g, '-');
      hidden.dataset.key = name;
      hidden.value = value == null ? '' : value;

      w.appendChild(wrap);
      w.appendChild(hidden);

      function tint(node, hex) {
        node.classList.toggle('is-empty', !hex);
        node.style.background = hex || '';
      }

      (items || []).forEach(function (o) {
        var li = el('li', 'ab-picker__opt');
        li.setAttribute('role', 'option');
        li.tabIndex = -1;
        li.dataset.value = o.value;
        li.dataset.hex = o.hex || '';
        var d = el('span', 'ab-color-dot ab-color-dot--sm');
        tint(d, o.hex);
        li.appendChild(d);
        li.appendChild(el('span', 'ab-picker__name', o.label));
        li.addEventListener('mousedown', function (ev) { ev.preventDefault(); });
        li.addEventListener('click', function () { choose(o.value); });
        list.appendChild(li);
      });

      function paint() {
        var txt = '— не указан —';
        $$('.ab-picker__opt', list).forEach(function (li) {
          var on = li.dataset.value === hidden.value;
          li.classList.toggle('is-on', on);
          li.setAttribute('aria-selected', on ? 'true' : 'false');
          if (on) {
            txt = $('.ab-picker__name', li).textContent;
            tint(bDot, li.dataset.hex);
          }
        });
        if (!hidden.value) tint(bDot, '');
        bTxt.textContent = txt;
      }

      function place() {
        var r = btn.getBoundingClientRect();
        var vh = window.innerHeight || document.documentElement.clientHeight;
        var need = list.offsetHeight || 264;
        var below = vh - r.bottom - 8;
        var above = r.top - 8;
        list.style.left = Math.round(r.left) + 'px';
        list.style.minWidth = Math.round(r.width) + 'px';
        list.style.maxWidth = Math.round(r.width) + 'px';
        if (below < need && above > below) {
          list.style.top = '';
          list.style.bottom = Math.round(vh - r.top + 4) + 'px';
          list.style.maxHeight = Math.max(120, Math.round(above)) + 'px';
        } else {
          list.style.bottom = '';
          list.style.top = Math.round(r.bottom + 4) + 'px';
          list.style.maxHeight = Math.max(120, Math.round(below)) + 'px';
        }
      }

      function open() {
        wrap.classList.add('is-open');
        btn.setAttribute('aria-expanded', 'true');
        list.classList.add('is-open');
        place();
      }
      function close() {
        wrap.classList.remove('is-open');
        btn.setAttribute('aria-expanded', 'false');
        list.classList.remove('is-open');
      }
      function choose(v) {
        hidden.value = v;
        paint();
        close();
        btn.focus();
        hidden.dispatchEvent(new Event('change', { bubbles: true }));
      }

      btn.addEventListener('click', function () {
        if (wrap.classList.contains('is-open')) close(); else open();
      });
      btn.addEventListener('keydown', function (e) {
        if (e.key !== 'ArrowDown' && e.key !== 'Enter' && e.key !== ' ') return;
        e.preventDefault();
        open();
        var on = $('.ab-picker__opt.is-on', list) || $('.ab-picker__opt', list);
        if (on) on.focus();
      });
      list.addEventListener('keydown', function (e) {
        var os = $$('.ab-picker__opt', list);
        var i = os.indexOf(document.activeElement);
        if (e.key === 'Escape') { close(); btn.focus(); }
        else if (e.key === 'ArrowDown') { e.preventDefault(); if (os[i + 1]) os[i + 1].focus(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); if (os[i - 1]) os[i - 1].focus(); }
        else if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); if (os[i]) choose(os[i].dataset.value); }
        else return;
        e.stopPropagation();
      });

      paint();
      PICKERS.push({ wrap: wrap, list: list, close: close });
      return { wrap: w, input: hidden, node: wrap, list: list };
    }

    function checkbox(label, name, checked) {
    var w = el('div', 'ab-field');
    var l = el('label', 'ab-row');
    var i = el('input');
    i.type = 'checkbox';
    i.name = name;
    i.dataset.key = name;
    i.checked = !!checked;
    i.style.width = 'auto';
    l.appendChild(i);
    l.appendChild(el('span', null, label));
    w.appendChild(l);
    return w;
  }
  function grid(cols) {
    return el('div', 'ab-grid-' + cols);
  }

  /* ---------- поле из списка строк ---------- */
  function listEdit(host, items, placeholder, onAdd) {
    host.innerHTML = '';
    function addRow(val, val2) {
      var row = el('div', 'ab-list-edit__row');
      var a = el('input');
      a.placeholder = placeholder;
      a.value = val || '';
      a.className = 'ab-list-a';
      var b = el('input');
      b.type = 'number';
      b.step = 'any';
      b.value = val2 == null ? '' : val2;
      b.className = 'ab-list-b';
      b.placeholder = '₽';
      var del = el('button', 'ab-btn ab-btn--sm ab-btn--danger', '×');
      del.type = 'button';
      del.addEventListener('click', function () { row.remove(); });
      row.appendChild(a); row.appendChild(b); row.appendChild(del);
      host.appendChild(row);
    }
    items.forEach(function (i) { addRow(i); });
    var add = el('button', 'ab-btn ab-btn--sm', '+ добавить');
    add.type = 'button';
    add.addEventListener('click', function () { addRow(); if (onAdd) onAdd(); });
    host.appendChild(add);
  }
  function readList(host) {
    return $$( '.ab-list-edit__row', host).map(function (r) {
      return $('.ab-list-a', r).value.trim();
    }).filter(Boolean);
  }

  /* ================================================================ вход */

  function showLogin() {
    S.user = null;
    $('#app').hidden = true;
    $('#login').hidden = false;
    $('#lg-user').focus();
  }

  function showApp() {
    $('#login').hidden = true;
    $('#app').hidden = false;
    $$('#nav [data-admin-only]').forEach(function (b) { b.hidden = S.user.role !== 'admin'; });
    $('#who').textContent = S.user.username + ' · ' + (S.user.role === 'admin' ? 'админ' : 'оператор');
    var badge = $('[data-orders-badge]');
    var n = S.counts.new || 0;
    badge.hidden = !n;
    badge.textContent = n;
    var def = S.user.role === 'admin' ? 'products' : 'orders';
    go(S.view || def);
  }

  function bindLogin() {
    var eye = $('#lg-eye');
    var pass = $('#lg-pass');
    if (eye && pass) {
      eye.addEventListener('click', function () {
        var shown = pass.type === 'text';
        pass.type = shown ? 'password' : 'text';
        eye.setAttribute('aria-pressed', shown ? 'false' : 'true');
        eye.setAttribute('aria-label', shown ? 'Показать пароль' : 'Скрыть пароль');
        eye.querySelector('.ab-eye-open').hidden = !shown;
        eye.querySelector('.ab-eye-off').hidden = shown;
        var at = pass.value.length;
        pass.focus();
        try { pass.setSelectionRange(at, at); } catch (err) {}
      });
    }
    $('#login-form').addEventListener('submit', function (e) {
      e.preventDefault();
      var box = $('#login-msg');
      msgBox(box, 'Проверяем…', 'info');
      api('login', {
        username: $('#lg-user').value.trim(),
        password: $('#lg-pass').value
      }).then(function (j) {
        if (!j || j.ok !== true) { msgBox(box, (j && j.error) || 'Ошибка входа', 'err'); return; }
        S.user = j.user;
        msgBox(box, '');
        $('#lg-pass').value = '';
        loadAll().then(showApp).catch(function () { showLogin(); });
      }).catch(function (err) { msgBox(box, err.message, 'err'); });
    });
    $('#logout').addEventListener('click', function () {
      api('logout').then(function () { showLogin(); });
    });
  }

  /* ================================================================ загрузка */

  function loadAll() {
    return api('products').then(function (j) {
      need(j);
      S.products = j.products || [];
      S.categories = j.categories || [];
      S.colors = j.colors || [];
      S.nextSku = j.nextSku || '';
      return api('settings');
    }).then(function (j) {
      need(j);
      S.settings = j.settings || {};
      if (S.user.role === 'admin') return api('orders', { status: 'all' });
      return api('orders', { status: 'all' });
    }).then(function (j) {
      need(j);
      S.orders = j.orders || [];
      S.counts = j.counts || {};
    });
  }

  function bySku(sku) {
    for (var i = 0; i < S.products.length; i++) if (S.products[i].sku === sku) return S.products[i];
    return null;
  }
  function catName(id) {
    for (var i = 0; i < S.categories.length; i++) if (S.categories[i].id === id) return S.categories[i].short || S.categories[i].name;
    return id || '—';
  }

  /* ================================================================ навигация */

  var TITLES = {
    products: ['Каталог', 'Товары, цены, фото и порядок вывода'],
    orders: ['Заявки', 'Обращения с сайта: опт, розница, уточнение наличия'],
    categories: ['Категории и цвета', 'Справочники каталога'],
    texts: ['Тексты сайта', 'Главная, подвал и тексты страниц'],
    contacts: ['Контакты и SEO', 'Реквизиты, способы связи, метаданные'],
    users: ['Пользователи', 'Учётные записи администраторов и операторов'],
    service: ['Резервная копия', 'Выгрузка базы и каталога']
  };

  function go(view) {
    if (view === 'users' && S.user.role !== 'admin') return;
    S.view = view;
    $$('#nav button').forEach(function (b) { b.classList.toggle('is-active', b.dataset.view === view); });
    S.edit = null;
    S.dirty = false;
    var t = TITLES[view] || ['', ''];
    $('#view-title').textContent = t[0];
    $('#view-sub').textContent = t[1];
    $('#view-actions').innerHTML = '';
    $('#view').innerHTML = '';
    ({
      products: viewProducts, orders: viewOrders, categories: viewCategories,
      texts: viewTexts, contacts: viewContacts, users: viewUsers, service: viewService
    }[view] || function () { })();
  }

  /* ================================================================ каталог */

  function viewProducts() {
    var actions = $('#view-actions');
    var add = el('button', 'ab-btn ab-btn--primary', '+ Новый товар');
    add.type = 'button';
    add.addEventListener('click', function () { editor(null); });
    actions.appendChild(add);

    var panel = el('div', 'ab-panel');
    var body = el('div', 'ab-panel__body ab-panel__body--flush');

    /* ---------- фильтры (только по колонкам таблицы) ---------- */
    var fHost = el('div', 'ab-filters');

    var ALL = { value: '', label: '— все —' };

    var search = field('Артикул или название', 'q', '', { placeholder: '100001 или лоток' });

    var fr1 = grid(3);
    fr1.appendChild(search);
    fr1.appendChild(field('Категория', 'categoryId', '', {
      type: 'select',
      options: [ALL].concat(S.categories.map(function (c) { return { value: String(c.id), label: c.name }; }))
    }));
    fr1.appendChild(field('Статус', 'inStock', '', {
      type: 'select',
      options: [ALL, { value: '1', label: 'в наличии' }, { value: '0', label: 'под заказ' }]
    }));
    fHost.appendChild(fr1);

    /* ---------- сортировка по колонкам ---------- */
    var sortKey = null;
    var sortDir = 0;

    var SORTS = {
      photo: { label: 'Фото', num: function (p) { return p.images ? p.images.length : 0; } },
      title: { label: 'названию', str: function (p) { return p.shortTitle || p.title || ''; } },
      cat: { label: 'категории', str: function (p) { return catName(p.categoryId); } },
      priceRetail: { label: 'рознице', num: function (p) { return parseFloat(p.priceRetail) || 0; } },
      priceOpt: { label: 'опту', num: function (p) { return parseFloat(p.priceOpt) || 0; } },
      packCount: { label: 'боксу', num: function (p) { return parseFloat(p.packCount) || 0; } },
      status: { label: 'статусу', num: function (p) { return p.inStock === false ? 0 : 1; } }
    };

    function sortList(list) {
      if (!sortKey || !sortDir) return list;
      var s = SORTS[sortKey], dir = sortDir;
      return list.slice().sort(function (a, b) {
        var r = s.num ? s.num(a) - s.num(b) : s.str(a).localeCompare(s.str(b), 'ru');
        if (!r) r = String(a.sku).localeCompare(String(b.sku), 'ru', { numeric: true });
        return dir > 0 ? r : -r;
      });
    }

    var fActs = el('div', 'ab-row');
    var reset = el('button', 'ab-btn', 'Сбросить фильтры');
    reset.type = 'button';
    fActs.appendChild(reset);
    var found = el('span', 'ab-muted');
    fActs.appendChild(found);
    fHost.appendChild(fActs);

    body.appendChild(fHost);

    var scroll = el('div', 'ab-scroll');
    var table = el('table', 'ab-table');
    var thead = el('thead');
    var hr = el('tr');

    function paintSort() {
      $$('th[data-sort]', hr).forEach(function (th) {
        var on = th.dataset.sort === sortKey && sortDir !== 0;
        th.classList.toggle('is-active', on);
        th.setAttribute('aria-sort', on ? (sortDir > 0 ? 'ascending' : 'descending') : 'none');
        th.firstChild.nextSibling.textContent = on ? (sortDir > 0 ? '↑' : '↓') : '↕';
      });
    }

    var COLS = [{ t: 'Фото', k: 'photo' }, { t: 'Артикул / название', k: 'title' }, { t: 'Категория', k: 'cat' },
     { t: 'Цвет', k: '' },
     { t: 'Розница', k: 'priceRetail' }, { t: 'Опт', k: 'priceOpt' }, { t: 'Бокс', k: 'packCount' },
     { t: 'Статус', k: 'status' }, { t: '', k: '' }, { t: '', k: '' }];

    COLS.forEach(function (c) {
      var th = el('th');
      if (c.k) {
        th.classList.add('ab-th--sort');
        th.dataset.sort = c.k;
        th.tabIndex = 0;
        th.title = 'Сортировать по «' + SORTS[c.k].label + '» — клик меняет направление';
        var txt = el('span', null, c.t);
        var mark = el('span', 'ab-sort', '↕');
        th.appendChild(txt);
        th.appendChild(mark);
        var act = function () {
          if (sortKey === c.k) sortDir = sortDir === 1 ? -1 : (sortDir === -1 ? 0 : 1);
          else { sortKey = c.k; sortDir = 1; }
          paintSort();
          applyFilters();
        };
        th.addEventListener('click', act);
        th.addEventListener('keydown', function (e) {
          if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); act(); }
        });
      } else th.textContent = c.t;
      hr.appendChild(th);
    });
    paintSort();

    thead.appendChild(hr);
    table.appendChild(thead);

    var tb = el('tbody');
    table.appendChild(tb);

    /* ---------- показ товара на главной ---------- */
    function toggleHome(p, btn) {
      var next = !p.onHome;
      var copy = JSON.parse(JSON.stringify(p));
      copy.onHome = next;
      btn.disabled = true;
      api('product_save', { product: copy }).then(function () {
        p.onHome = next;
        btn.classList.toggle('is-on', next);
        btn.setAttribute('aria-pressed', next ? 'true' : 'false');
        btn.title = next ? 'Убрать с главной страницы' : 'Показать на главной странице';
        toast(next ? (p.shortTitle || p.sku) + ' — на главной' : (p.shortTitle || p.sku) + ' — убран с главной');
      }).catch(function () {
        toast('Не удалось сохранить', true);
      }).then(function () {
        btn.disabled = false;
      });
    }

    /* ---------- отрисовка строк ---------- */
    function productRow(p) {
      var tr = el('tr');

      /* --- фото --- */
      var ph = el('td');
      if (p.images && p.images.length) {
        var im = el('img');
        im.src = thumb(p.images[0]);
        im.alt = '';
        im.loading = 'lazy';
        ph.appendChild(im);
        ph.appendChild(el('div', 'ab-muted ab-mono', (p.images.length) + ' фото'));
      } else ph.appendChild(el('span', 'ab-muted', '—'));
      tr.appendChild(ph);

      /* --- артикул и название --- */
      var td = el('td');
      var nm = el('div', 'ab-nm');
      var star = el('button', 'ab-star' + (p.onHome ? ' is-on' : ''));
      star.type = 'button';
      star.textContent = '★';
      star.title = p.onHome
        ? 'Убрать с главной страницы'
        : 'Показать на главной странице';
      star.setAttribute('aria-pressed', p.onHome ? 'true' : 'false');
      star.setAttribute('aria-label', 'Показывать на главной странице');
      star.addEventListener('click', function () { toggleHome(p, star); });
      nm.appendChild(star);
      var tx = el('div');
      tx.appendChild(el('div', 'ab-mono ab-muted', p.sku));
      var a = el('a', null, p.shortTitle || p.title);
      a.href = '#';
      a.addEventListener('click', function (e) { e.preventDefault(); editor(p.sku); });
      tx.appendChild(a);
      nm.appendChild(tx);
      td.appendChild(nm);
      tr.appendChild(td);

      tr.appendChild(el('td', 'ab-muted', catName(p.categoryId)));

      var cl = el('td');
      var cdot = el('span', 'ab-color-dot');
      var chex = String(p.colorHex || '');
      var cok = /^#[0-9a-f]{6}$/i.test(chex);
      cdot.classList.toggle('is-empty', !cok);
      if (cok) cdot.style.background = chex;
      cdot.title = p.color
        ? p.color + (cok ? ' · ' + chex : ' · HEX не задан')
        : 'Цвет не указан';
      cl.appendChild(cdot);
      tr.appendChild(cl);

      tr.appendChild(el('td', 'ab-mono', money(p.priceRetail)));
      tr.appendChild(el('td', 'ab-mono', money(p.priceOpt)));
      tr.appendChild(el('td', 'ab-mono', p.packCount + ' шт.'));

      var st = el('td');
      st.appendChild(el('span', 'ab-tag ' + (p.inStock === false ? 'ab-tag--inwork' : 'ab-tag--done'),
        p.inStock === false ? 'под заказ' : 'в наличии'));
      tr.appendChild(st);

      /* --- порядок --- */
      var th = el('td');
      var up = el('button', 'ab-btn ab-btn--sm ab-btn--icon', '↑');
      up.type = 'button'; up.title = 'Выше';
      up.addEventListener('click', function () {
        api('product_move', { sku: p.sku, dir: 'up' }).then(function () {
          return loadAll().then(function () { go('products'); });
        });
      });
      var dn = el('button', 'ab-btn ab-btn--sm ab-btn--icon ab-right', '↓');
      dn.type = 'button'; dn.title = 'Ниже';
      dn.addEventListener('click', function () {
        api('product_move', { sku: p.sku, dir: 'down' }).then(function () {
          return loadAll().then(function () { go('products'); });
        });
      });
      var bx = el('div', 'ab-row');
      bx.appendChild(up); bx.appendChild(dn);
      th.appendChild(bx);
      tr.appendChild(th);

      var act = el('td');
      var del = el('button', 'ab-btn ab-btn--sm ab-btn--danger', 'Удалить');
      del.type = 'button';
      del.addEventListener('click', function () {
        if (!confirm('Удалить «' + (p.shortTitle || p.title) + '» (' + p.sku + ')?\nФайлы фото останутся в папке.')) return;
        api('product_del', { sku: p.sku }).then(need).then(function () {
          toast('Товар удалён');
          return loadAll().then(function () { go('products'); });
        });
      });
      act.appendChild(del);
      tr.appendChild(act);

      return tr;
    }

    /* ---------- корзина ---------- */
    var CART_KEY = 'poliform.cart';

    function cartRead() {
      try {
        var c = JSON.parse(localStorage.getItem(CART_KEY));
        return Array.isArray(c) ? c : [];
      } catch (e) { return []; }
    }

    function cartCount() {
      return cartRead().reduce(function (a, it) { return a + (parseInt(it.qty, 10) || 0); }, 0);
    }

    function paintCartLink() {
      if (!cartLink) return;
      var n = cartCount();
      cartLink.textContent = n ? 'Корзина: ' + n + ' шт.' : 'Корзина пуста';
      cartLink.classList.toggle('ab-btn--primary', n > 0);
    }

    var cartLink = el('a', 'ab-btn', 'Корзина пуста');
    cartLink.href = 'cart.php';
    cartLink.target = '_blank';
    cartLink.rel = 'noopener';
    actions.appendChild(cartLink);

    /* ---------- применение фильтра ---------- */
    function readFilters() {
      var f = {};
      $$('[data-key]', fHost).forEach(function (n) { f[n.dataset.key] = String(n.value).trim(); });
      return f;
    }

function match(p, f) {
        if (f.q) {
          var hay = [p.sku, p.shortTitle, p.title].join(' ').toLowerCase();
          var words = f.q.toLowerCase().split(/\s+/).filter(Boolean);
          if (!words.every(function (w) { return hay.indexOf(w) > -1; })) return false;
        }
        if (f.categoryId && String(p.categoryId) !== f.categoryId) return false;
        if (f.inStock === '1' && p.inStock === false) return false;
        if (f.inStock === '0' && p.inStock !== false) return false;
        return true;
      }

    function applyFilters() {
      var f = readFilters();
      var list = sortList(S.products.filter(function (p) { return match(p, f); }));

      tb.innerHTML = '';
      list.forEach(function (p) { tb.appendChild(productRow(p)); });

      if (!list.length) {
        var tr = el('tr');
        var td = el('td', 'ab-muted', 'Ничего не найдено — измените условия фильтра.');
td.colSpan = COLS.length;
        td.style.padding = '18px 12px';
        tr.appendChild(td);
        tb.appendChild(tr);
      }

      var active = Object.keys(f).filter(function (k) { return f[k] !== ''; }).length;
      var txt = 'Показано ' + list.length + ' из ' + S.products.length +
        (active ? ' · условий: ' + active : ' · фильтры не заданы');
      if (sortKey && sortDir) {
        txt += ' · сортировка: ' + SORTS[sortKey].label + ' ' + (sortDir > 0 ? '↑' : '↓');
      }
      found.textContent = txt;
    }

    fHost.addEventListener('input', applyFilters);
    fHost.addEventListener('change', applyFilters);
    reset.addEventListener('click', function () {
      $$('[data-key]', fHost).forEach(function (n) {
        n.value = '';
        if (n.tagName === 'SELECT') n.selectedIndex = 0;
      });
      applyFilters();
    });

    scroll.appendChild(table);
    body.appendChild(scroll);
    panel.appendChild(body);
    $('#view').appendChild(panel);
    paintCartLink();
    applyFilters();
  }

  function thumb(src) {
    return String(src).replace(/\.jpe?g$/i, '-t.jpg');
  }

  /* ---------- редактор товара ---------- */
  function editor(sku) {
    S.edit = sku;
    S.dirty = false;
    var p = sku ? bySku(sku) : null;
    if (sku && !p) { toast('Товар не найден', true); return; }

    if (!p) {
      p = {
        sku: S.nextSku || '', title: '', shortTitle: '', categoryId: (S.categories[0] || {}).id || '',
        art: '', form: 'Прямоугольная', sizeL: 0, sizeW: 0, sizeH: 0, gridGap: 0,
        color: '', colorHex: '', packCount: 1, packNote: '', weightG: 0,
        priceRetail: 0, priceOpt: 0, volumeMl: 0, material: 'Пластик',
        country: 'Россия', cert: 'Не подлежит сертификации', description: '',
        features: [], images: [], inStock: true, stockNote: '', sima: '', pos: 0
      };
    }
    var draft = JSON.parse(JSON.stringify(p));

    $('#view-title').textContent = sku ? 'Товар ' + sku : 'Новый товар';
    $('#view-sub').textContent = sku ? 'Изменения применятся на витрине сразу после сохранения' : 'Заполните карточку и сохраните';
    $('#view-actions').innerHTML = '';
    var back = el('button', 'ab-btn', '← К списку');
    back.type = 'button';
    back.addEventListener('click', function () {
      if (S.dirty && !confirm('Есть несохранённые изменения. Выйти без сохранения?')) return;
      go('products');
    });
    $('#view-actions').appendChild(back);
    $('#view').innerHTML = '';

    var wrap = el('div', 'ab-editor');
    var main = el('div');
    var side = el('div');
    wrap.appendChild(main);
    wrap.appendChild(side);
    $('#view').appendChild(wrap);

    var errBox = el('div');
    main.appendChild(errBox);

    /* --- фото --- */
    var pPanel = el('div', 'ab-panel');
    pPanel.appendChild(panelHead('Фотографии', 'Первое фото — главное на витрине. Пропорции 3:4.'));
    var pBody = el('div', 'ab-panel__body');
    var photos = el('div', 'ab-photos');
    var up = el('label', 'ab-upload', '＋\nзагрузить');
    var file = el('input');
    file.type = 'file';
    file.accept = 'image/jpeg,image/png,image/webp';
    file.multiple = true;
    file.style.display = 'none';
    up.appendChild(file);
    file.addEventListener('change', function () {
      Array.prototype.slice.call(file.files).forEach(function (f) { send(f); });
      file.value = '';
    });
    photos.appendChild(up);

    function drawPhotos() {
      $$('.ab-photo', photos).forEach(function (n) { n.remove(); });
      draft.images.forEach(function (src, i) {
        var w = el('div', 'ab-photo');
        var im = el('img');
        im.src = src;
        im.alt = '';
        w.appendChild(im);
        var x = el('button', 'ab-photo__x', '×');
        x.type = 'button';
        x.title = 'Удалить фото';
        x.addEventListener('click', function () {
          if (!confirm('Удалить файл ' + src + ' с сервера?')) return;
          api('photo_del', { path: src }).then(need).then(function () {
            draft.images.splice(i, 1);
            S.dirty = true;
            drawPhotos();
            toast('Фото удалено');
          });
        });
        w.appendChild(x);
        var left = el('button', 'ab-photo__up', '←');
        left.type = 'button';
        left.title = 'Первой';
        left.disabled = i === 0;
        left.addEventListener('click', function () {
          draft.images.unshift(draft.images.splice(i, 1)[0]);
          S.dirty = true;
          drawPhotos();
        });
        w.appendChild(left);
        photos.insertBefore(w, up);
      });
    }
    drawPhotos();

    function send(f) {
      var fd = new FormData();
      fd.append('sku', draft.sku || sku);
      fd.append('file', f);
      if (!fd.get('sku')) { toast('Сначала укажите артикул и сохраните товар', true); return; }
      api('upload', fd).then(need).then(function (j) {
        draft.images.push(j.path);
        S.dirty = true;
        drawPhotos();
        toast('Фото загружено');
      }).catch(function () { toast('Не удалось загрузить файл', true); });
    }

    pBody.appendChild(photos);
    pPanel.appendChild(pBody);
    main.appendChild(pPanel);

    /* --- основные поля --- */
    var mPanel = el('div', 'ab-panel');
    mPanel.appendChild(panelHead('Карточка', null));
    var mBody = el('div', 'ab-panel__body');

    var gFlags = grid(2);
    gFlags.appendChild(checkbox('В наличии', 'inStock', draft.inStock));
    gFlags.appendChild(checkbox('Показывать на главной', 'onHome', draft.onHome));
    mBody.appendChild(gFlags);
    mBody.appendChild(el('p', 'ab-hint', 'На главной показывается только первое фото товара. Отметьте несколько товаров — они покажутся по очереди в карусели.'));

    var g1 = grid(2);
    g1.appendChild(field('Артикул', 'sku', draft.sku, { readonly: true }));
    g1.appendChild(field('Категория', 'categoryId', draft.categoryId, {
      type: 'select',
      options: S.categories.map(function (c) { return { value: c.id, label: c.name }; })
    }));
    mBody.appendChild(g1);

    mBody.appendChild(field('Название (каталог)', 'shortTitle', draft.shortTitle, { placeholder: 'Лоток средний 36×26×6,5, с сеткой' }));
    mBody.appendChild(field('Полное название (SEO, карточка)', 'title', draft.title));

    var g2 = grid(3);
    g2.appendChild(field('Длина, см', 'sizeL', draft.sizeL, { type: 'number', step: '0.1' }));
    g2.appendChild(field('Ширина, см', 'sizeW', draft.sizeW, { type: 'number', step: '0.1' }));
    g2.appendChild(field('Высота, см', 'sizeH', draft.sizeH, { type: 'number', step: '0.1' }));
    mBody.appendChild(g2);

    var g3 = grid(3);
    g3.appendChild(field('Розничная цена, ₽/шт', 'priceRetail', draft.priceRetail, { type: 'number', step: '0.01', min: 0 }));
    g3.appendChild(field('Оптовая цена, ₽/шт', 'priceOpt', draft.priceOpt, { type: 'number', step: '0.01', min: 0 }));
    g3.appendChild(field('Фасовка в боксе, шт', 'packCount', draft.packCount, { type: 'number', step: '1', min: 1 }));
    mBody.appendChild(g3);

    var g4 = grid(3);
    var colorItems = [{ value: '', label: '— не указан —', hex: '' }];
    S.colors.forEach(function (c) {
      colorItems.push({ value: c.name, label: c.name, hex: c.hex || '' });
    });
    if (draft.color && !colorItems.some(function (o) { return o.value === draft.color; })) {
      colorItems.push({ value: draft.color, label: draft.color + ' — нет в справочнике', hex: '' });
    }
    var cPick = colorPicker('Цвет', 'color', draft.color, colorItems);
    var hexWrap = field('HEX цвета', 'colorHex', draft.colorHex, { placeholder: '#2E9C9B' });
    var cHex = hexWrap.querySelector('input');
    hexWrap.classList.add('ab-field--color');
    var swatch = el('span', 'ab-color-dot');
    swatch.setAttribute('role', 'img');
    hexWrap.appendChild(swatch);

    function paintSwatch() {
      var v = String(cHex.value || '').trim();
      var ok = /^#[0-9a-f]{6}$/i.test(v);
      swatch.classList.toggle('is-empty', !ok);
      swatch.style.background = ok ? v : '';
      swatch.title = ok ? 'Образец цвета: ' + v : 'HEX не заполнен или неверный — нужен вид #RRGGBB';
    }
    cHex.addEventListener('input', paintSwatch);
    cPick.input.addEventListener('change', function () {
      var m = S.colors.filter(function (c) { return c.name === cPick.input.value; })[0];
      if (m && m.hex) {
        cHex.value = m.hex;
        cHex.dispatchEvent(new Event('input', { bubbles: true }));
      }
    });
    paintSwatch();

    g4.appendChild(cPick.wrap);
    g4.appendChild(hexWrap);
    g4.appendChild(field('Вес брутто, г', 'weightG', draft.weightG, { type: 'number', step: '1' }));
    mBody.appendChild(g4);

    var g5 = grid(3);
    g5.appendChild(field('Форма', 'form', draft.form));
    g5.appendChild(field('Конструкция', 'art', draft.art, { placeholder: 'Сетка' }));
    g5.appendChild(field('Объём, мл', 'volumeMl', draft.volumeMl, { type: 'number', step: '1' }));
    mBody.appendChild(g5);

    var g6 = grid(2);
    g6.appendChild(field('Фасовка (текст)', 'packNote', draft.packNote, { placeholder: 'Фасовка по 1 шт., в боксе 24 шт.' }));
    g6.appendChild(field('Заметка по наличию', 'stockNote', draft.stockNote, { placeholder: 'Под заказ, срок 2 недели' }));
    mBody.appendChild(g6);

    var g7 = grid(3);
    g7.appendChild(field('Материал', 'material', draft.material));
    g7.appendChild(field('Страна', 'country', draft.country));
    g7.appendChild(field('Сертификация', 'cert', draft.cert));
    mBody.appendChild(g7);

    mBody.appendChild(field('Описание', 'description', draft.description, { type: 'textarea', rows: 5 }));

    var fWrap = el('div', 'ab-field');
    fWrap.appendChild(el('label', null, 'Особенности — по одной в строке'));
    var fHost = el('div');
    fWrap.appendChild(fHost);
    mBody.appendChild(fWrap);
    listEdit(fHost, draft.features, 'Сухие лапы: зазор 0,8 см');

    mPanel.appendChild(mBody);
    main.appendChild(mPanel);

    /* --- предпросмотр --- */
    var pv = el('div', 'ab-preview');
    pv.appendChild(el('h3', null, 'Предпросмотр'));
    var pvImg = el('div', 'ab-preview__img');
    var pvPic = el('img');
    pvImg.appendChild(pvPic);
    pv.appendChild(pvImg);
    var pvBody = el('div');
    pv.appendChild(pvBody);

    function drawPreview() {
      pvPic.src = draft.images[0] || 'images/favicon.svg';
      pvBody.innerHTML = '';
      var cat = el('div', 'ab-muted', catName(draft.categoryId));
      pvBody.appendChild(cat);
      pvBody.appendChild(el('div', null, draft.shortTitle || 'Без названия'));
      pvBody.appendChild(el('div', 'ab-mono ab-muted', 'Арт. ' + (draft.sku || '—')));
      var d = [draft.sizeL, draft.sizeW, draft.sizeH].join('×') + ' см';
      pvBody.appendChild(el('div', 'ab-muted', d));

      var pct = draft.priceRetail > 0
        ? Math.round((1 - draft.priceOpt / draft.priceRetail) * 100)
        : 0;
      [['Розница / шт.', money(draft.priceRetail)],
       ['Опт / шт.', money(draft.priceOpt)],
       ['1 бокс (' + (draft.packCount || 1) + ' шт.)', money(draft.priceOpt * (draft.packCount || 1))],
       ['Выгода', pct > 0 ? pct + '%' : '—']].forEach(function (r) {
        var l = el('div', 'ab-priceline');
        l.appendChild(el('span', null, r[0]));
        l.appendChild(el('b', null, r[1]));
        pvBody.appendChild(l);
      });
      pvBody.appendChild(el('div', 'ab-muted', draft.inStock ? '● в наличии' : '● под заказ'));
    }

    /* --- сбор и сохранение --- */
    function collect() {
      $$('.ab-field [data-key]', main).forEach(function (n) {
        var k = n.dataset.key;
        if (n.type === 'checkbox') draft[k] = n.checked ? 1 : 0;
        else if (n.type === 'number') draft[k] = parseFloat(String(n.value).replace(',', '.')) || 0;
        else draft[k] = n.value;
      });
      draft.features = readList(fHost);
      S.dirty = true;
      drawPreview();
    }
    main.addEventListener('input', collect);
    main.addEventListener('change', collect);

    var acts = el('div', 'ab-sticky-actions');
    var save = el('button', 'ab-btn ab-btn--primary', 'Сохранить');
    save.type = 'button';
    save.addEventListener('click', function () {
      if (!String(draft.sku || '').trim()) { msgBox(errBox, 'Укажите артикул', 'err'); return; }
      if (!String(draft.shortTitle || '').trim()) { msgBox(errBox, 'Укажите название для каталога', 'err'); return; }
      if (!(draft.priceOpt > 0) || !(draft.priceRetail > 0)) { msgBox(errBox, 'Укажите обе цены', 'err'); return; }
      if (draft.priceOpt >= draft.priceRetail) { msgBox(errBox, 'Оптовая цена должна быть ниже розничной', 'err'); return; }
      if (!(draft.packCount >= 1)) { msgBox(errBox, 'Фасовка в боксе — минимум 1 шт.', 'err'); return; }
      msgBox(errBox, 'Сохраняем…', 'info');
      save.disabled = true;
      api('product_save', { product: draft }).then(need).then(function () {
        S.dirty = false;
        msgBox(errBox, 'Сохранено. Витрина обновится при следующей загрузке страницы.', 'ok');
        toast('Товар сохранён');
        save.disabled = false;
        return loadAll();
      }).catch(function (e) {
        save.disabled = false;
        msgBox(errBox, e.message, 'err');
      });
    });
    acts.appendChild(save);
    var cancel = el('button', 'ab-btn', 'Отмена');
    cancel.type = 'button';
    cancel.addEventListener('click', function () { go('products'); });
    acts.appendChild(cancel);
    var vis = el('a', 'ab-btn ab-right', 'Открыть на сайте');
    vis.target = '_blank';
    vis.href = 'product.php?sku=' + encodeURIComponent(draft.sku);
    acts.appendChild(vis);
    $('#view').appendChild(acts);

    drawPreview();
  }

  function panelHead(title, note) {
    var h = el('div', 'ab-panel__head');
    h.appendChild(el('h2', null, title));
    if (note) h.appendChild(el('span', 'ab-muted', note));
    return h;
  }

  function subHead(title) {
    return el('h3', 'ab-subhead', title);
  }

  /* ================================================================ заявки */

  var ORDER_TABS = [
    { key: 'all', label: 'Все' },
    { key: 'new', label: 'Новые' },
    { key: 'inwork', label: 'В работе' },
    { key: 'done', label: 'Выполнены' },
    { key: 'spam', label: 'Спам' }
  ];
  var ORDER_STATUS = {
    new: ['Новая', 'ab-tag--new'],
    inwork: ['В работе', 'ab-tag--inwork'],
    done: ['Выполнена', 'ab-tag--done'],
    spam: ['Спам', 'ab-tag--spam']
  };
  S.orderTab = 'all';

  function viewOrders() {
    var actions = $('#view-actions');
    var refresh = el('button', 'ab-btn', 'Обновить');
    refresh.type = 'button';
    refresh.addEventListener('click', function () {
      api('orders', { status: S.orderTab }).then(need).then(function (j) {
        S.orders = j.orders || [];
        S.counts = j.counts || {};
        var b = $('[data-orders-badge]');
        b.hidden = !S.counts.new;
        b.textContent = S.counts.new;
        go('orders');
      });
    });
    actions.appendChild(refresh);

    var panel = el('div', 'ab-panel');
    var head = panelHead('Заявки', 'Нажмите на строку, чтобы раскрыть состав и изменить статус');
    panel.appendChild(head);
    var tabs = el('div', 'ab-tabs');
    ORDER_TABS.forEach(function (t) {
      var n = t.key === 'all' ? (S.counts.all || 0) : (S.counts[t.key] || 0);
      var b = el('button', (S.orderTab === t.key ? 'is-active' : ''), t.label + ' · ' + n);
      b.type = 'button';
      b.addEventListener('click', function () {
        S.orderTab = t.key;
        api('orders', { status: t.key }).then(need).then(function (j) {
          S.orders = j.orders || [];
          S.counts = j.counts || {};
          go('orders');
        });
      });
      tabs.appendChild(b);
    });
    panel.appendChild(tabs);

    var body = el('div', 'ab-panel__body ab-panel__body--flush');
    if (!S.orders.length) {
      var e = el('div', 'ab-empty');
      e.appendChild(el('b', null, 'Заявок пока нет'));
      e.appendChild(el('span', null, 'Они появятся здесь сразу после отправки формы на сайте.'));
      body.appendChild(e);
    } else {
      var scroll = el('div', 'ab-scroll');
      var tbl = el('table', 'ab-table');
      var tr0 = el('tr');
      ['№', 'Дата', 'Клиент', 'Телефон', 'Тип', 'Сумма', 'Позиций', 'Статус', ''].forEach(function (h) {
        tr0.appendChild(el('th', null, h));
      });
      tbl.appendChild(el('thead').appendChild(tr0).parentNode);

      var tb = el('tbody');
      S.orders.forEach(function (o) { tb.appendChild(orderRow(o)); });
      tbl.appendChild(tb);
      scroll.appendChild(tbl);
      body.appendChild(scroll);
    }
    panel.appendChild(body);
    $('#view').appendChild(panel);
  }

  function orderRow(o) {
    var st = ORDER_STATUS[o.status] || ORDER_STATUS.new;
    var tr = el('tr');
    tr.style.cursor = 'pointer';
    tr.appendChild(el('td', 'ab-mono', '#' + o.id));
    tr.appendChild(el('td', 'ab-muted ab-mono', when(o.created)));

    var c = el('td');
    c.appendChild(el('div', null, o.name));
    if (o.city) c.appendChild(el('div', 'ab-muted', o.city));
    if (o.source) c.appendChild(el('div', 'ab-muted ab-mono', o.source));
    tr.appendChild(c);

    var ph = el('td');
    var a = el('a', 'ab-mono', o.phone);
    a.href = 'tel:' + String(o.phone).replace(/[^\d+]/g, '');
    ph.appendChild(a);
    if (o.email) ph.appendChild(el('div', 'ab-muted', o.email));
    tr.appendChild(ph);

    tr.appendChild(el('td', null, o.kind || '—'));
    tr.appendChild(el('td', 'ab-mono', money(o.total)));
    tr.appendChild(el('td', 'ab-mono', (o.items || []).length + ' поз.'));

    var s = el('td');
    s.appendChild(el('span', 'ab-tag ' + st[1], st[0]));
    tr.appendChild(s);

    var act = el('td');
    var del = el('button', 'ab-btn ab-btn--sm ab-btn--danger', '×');
    del.type = 'button';
    del.title = 'Удалить заявку';
    del.addEventListener('click', function (e) {
      e.stopPropagation();
      if (!confirm('Удалить заявку #' + o.id + '?')) return;
      api('order_del', { id: o.id }).then(need).then(function () {
        toast('Заявка удалена');
        return api('orders', { status: S.orderTab }).then(need).then(function (j) {
          S.orders = j.orders || [];
          S.counts = j.counts || {};
          go('orders');
        });
      });
    });
    act.appendChild(del);
    tr.appendChild(act);

    var detail = el('tr');
    detail.hidden = true;
    var td = el('td');
    td.colSpan = 9;
    td.style.background = '#fafcfd';
    var box = el('div');

    var items = o.items || [];
    if (items.length) {
      var ul = el('ul', 'ab-order-items');
      items.forEach(function (it) {
        ul.appendChild(el('li', null,
          it.sku + ' · ' + (it.title || '') + ' · ' + (it.mode === OPT ? 'опт' : 'розница') +
          ' · ' + it.qty + ' шт. · ' + money(it.lineTotal)));
      });
      box.appendChild(ul);
    } else box.appendChild(el('div', 'ab-muted', 'Без позиций — нужен расчёт.'));

    if (o.comment) {
      var cbox = el('div');
      cbox.style.marginTop = '10px';
      cbox.appendChild(el('div', 'ab-muted', 'Комментарий клиента'));
      cbox.appendChild(el('div', null, o.comment));
      box.appendChild(cbox);
    }

    var form = el('div', 'ab-row');
    form.style.marginTop = '12px';
    var sel = el('select');
    sel.style.width = 'auto';
    Object.keys(ORDER_STATUS).forEach(function (k) {
      var op = el('option', null, ORDER_STATUS[k][0]);
      op.value = k;
      if (k === o.status) op.selected = true;
      sel.appendChild(op);
    });
    var note = el('input');
    note.type = 'text';
    note.placeholder = 'Внутренний комментарий';
    note.value = o.note || '';
    note.style.maxWidth = '340px';
    var sv = el('button', 'ab-btn ab-btn--primary ab-btn--sm', 'Сохранить');
    sv.type = 'button';
    sv.addEventListener('click', function (e) {
      e.stopPropagation();
      sv.disabled = true;
      api('order_save', { id: o.id, status: sel.value, note: note.value }).then(need).then(function () {
        toast('Заявка #' + o.id + ' обновлена');
        return api('orders', { status: S.orderTab }).then(need).then(function (j) {
          S.orders = j.orders || [];
          S.counts = j.counts || {};
          go('orders');
        });
      });
    });
    form.appendChild(sel);
    form.appendChild(note);
    form.appendChild(sv);
    box.appendChild(form);

    td.appendChild(box);
    detail.appendChild(td);
    tr.addEventListener('click', function () { detail.hidden = !detail.hidden; });
    tr.addEventListener('keydown', function (e) { if (e.key === 'Enter') detail.hidden = !detail.hidden; });
    tr.tabIndex = 0;

    var frag = document.createDocumentFragment();
    frag.appendChild(tr);
    frag.appendChild(detail);
    return frag;
  }

  /* ================================================================ справочники */

  function viewCategories() {
    var wrap = el('div', 'ab-editor');

    /* категории */
    var cPanel = el('div', 'ab-panel');
    cPanel.appendChild(panelHead('Категории', 'Порядок = порядок на витрине. ID менять нельзя после создания.'));
    var cBody = el('div', 'ab-panel__body');
    var cHost = el('div');
    cBody.appendChild(cHost);

    function drawCats() {
      cHost.innerHTML = '';
      S.categories.forEach(function (c) {
        var n = S.products.filter(function (p) { return p.categoryId === c.id; }).length;
        var card = el('div');
        card.style.cssText = 'padding:12px 0;border-bottom:1px solid var(--ab-line)';
        var top = el('div', 'ab-row');
        top.appendChild(el('span', 'ab-tag ab-mono', c.id));
        top.appendChild(el('b', 'ab-grow', c.name));
        top.appendChild(el('span', 'ab-muted', n + ' поз.'));
        card.appendChild(top);
        card.appendChild(el('div', 'ab-muted', 'Витрина: ' + (c.short || '—') + ' · ' + (c.desc || '')));

        var acts = el('div', 'ab-row');
        acts.style.marginTop = '8px';
        var edit = el('button', 'ab-btn ab-btn--sm', 'Изменить');
        edit.type = 'button';
        edit.addEventListener('click', function () {
          cHost.innerHTML = '';
          var f = el('div');
          var g = grid(2);
          g.appendChild(field('Название', 'name', c.name));
          g.appendChild(field('Короткое (каталог, плитки)', 'short', c.short));
          f.appendChild(g);
          f.appendChild(field('Описание', 'desc', c.desc, { type: 'textarea', rows: 2 }));
          var row = el('div', 'ab-row');
          var ok = el('button', 'ab-btn ab-btn--primary ab-btn--sm', 'Сохранить');
          ok.type = 'button';
          ok.addEventListener('click', function () {
            var v = {};
            $$('[data-key]', f).forEach(function (n) { v[n.dataset.key] = n.value; });
            api('cat_save', { category: { id: c.id, name: v.name, short: v.short, desc: v.desc, pos: c.pos } })
              .then(need).then(function (j) {
                S.categories = j.categories;
                toast('Категория сохранена');
                go('categories');
              });
          });
          var cancel = el('button', 'ab-btn ab-btn--sm', 'Отмена');
          cancel.type = 'button';
          cancel.addEventListener('click', drawCats);
          row.appendChild(ok); row.appendChild(cancel);
          f.appendChild(row);
          cHost.appendChild(f);
        });
        var del = el('button', 'ab-btn ab-btn--sm ab-btn--danger', 'Удалить');
        del.type = 'button';
        del.addEventListener('click', function () {
          if (!confirm('Удалить категорию «' + c.name + '»?')) return;
          api('cat_del', { id: c.id }).then(need).then(function (j) {
            S.categories = j.categories;
            toast('Категория удалена');
            go('categories');
          });
        });
        acts.appendChild(edit);
        acts.appendChild(del);
        card.appendChild(acts);
        cHost.appendChild(card);
      });

      var add = el('button', 'ab-btn ab-btn--sm ab-btn--primary', '+ Категория');
      add.type = 'button';
      add.addEventListener('click', function () {
        cHost.innerHTML = '';
        var f = el('div');
        var g = grid(2);
        g.appendChild(field('ID (латиницей)', 'id', '', { placeholder: 'miski' }));
        g.appendChild(field('Название', 'name', ''));
        f.appendChild(g);
        f.appendChild(field('Короткое', 'short', ''));
        f.appendChild(field('Описание', 'desc', '', { type: 'textarea', rows: 2 }));
        var row = el('div', 'ab-row');
        var ok = el('button', 'ab-btn ab-btn--primary ab-btn--sm', 'Создать');
        ok.type = 'button';
        ok.addEventListener('click', function () {
          var v = {};
          $$('[data-key]', f).forEach(function (n) { v[n.dataset.key] = n.value; });
          if (!v.id || !v.name) { toast('Нужны ID и название', true); return; }
          api('cat_save', { category: { id: v.id, name: v.name, short: v.short, desc: v.desc, pos: 999 } })
            .then(need).then(function (j) {
              S.categories = j.categories;
              toast('Категория создана');
              go('categories');
            });
        });
        row.appendChild(ok);
        f.appendChild(row);
        cHost.appendChild(f);
      });
      cHost.appendChild(add);
    }
    drawCats();
    cPanel.appendChild(cBody);
    wrap.appendChild(cPanel);

    /* цвета */
    var lPanel = el('div', 'ab-panel');
    lPanel.appendChild(panelHead('Цвета', 'Используются в фильтре каталога'));
    var lBody = el('div', 'ab-panel__body');
    var lHost = el('div');
    lBody.appendChild(lHost);

    function drawColors() {
      lHost.innerHTML = '';
      S.colors.forEach(function (c) {
        var row = el('div', 'ab-list-edit__row');
        row.style.marginBottom = '8px';
        var dot = el('span', 'ab-color-dot');
        dot.style.background = c.hex || '#dfe4e6';
        row.appendChild(dot);
        var nm = el('input');
        nm.value = c.name;
        nm.addEventListener('change', function () {
          api('color_save', { color: { id: c.id, name: nm.value, hex: c.hex, pos: c.pos } })
            .then(need).then(function (j) { S.colors = j.colors; toast('Сохранено'); });
        });
        var hx = el('input');
        hx.type = 'color';
        hx.value = /^#[0-9a-f]{6}$/i.test(c.hex) ? c.hex : '#dfe4e6';
        hx.addEventListener('change', function () {
          api('color_save', { color: { id: c.id, name: nm.value, hex: hx.value, pos: c.pos } })
            .then(need).then(function (j) { S.colors = j.colors; });
        });
        var del = el('button', 'ab-btn ab-btn--sm ab-btn--danger', '×');
        del.type = 'button';
        del.addEventListener('click', function () {
          if (!confirm('Удалить цвет «' + c.name + '»?')) return;
          api('color_del', { id: c.id }).then(need).then(function (j) {
            S.colors = j.colors;
            toast('Цвет удалён');
            go('categories');
          });
        });
        row.appendChild(nm);
        row.appendChild(hx);
        row.appendChild(del);
        lHost.appendChild(row);
      });
      var add = el('button', 'ab-btn ab-btn--sm', '+ Цвет');
      add.type = 'button';
      add.addEventListener('click', function () {
        var row = el('div', 'ab-list-edit__row');
        var dot = el('span', 'ab-color-dot');
        row.appendChild(dot);
        var nm = el('input');
        nm.placeholder = 'Название цвета';
        var hx = el('input');
        hx.type = 'color';
        hx.value = '#dfe4e6';
        var add2 = el('button', 'ab-btn ab-btn--sm ab-btn--ok', '+');
        add2.type = 'button';
        add2.addEventListener('click', function () {
          if (!nm.value.trim()) return;
          api('color_save', { color: { id: '', name: nm.value.trim(), hex: hx.value } })
            .then(need).then(function (j) {
              S.colors = j.colors;
              toast('Цвет добавлен');
              go('categories');
            });
        });
        row.appendChild(nm);
        row.appendChild(hx);
        row.appendChild(add2);
        lHost.appendChild(row);
      });
      lHost.appendChild(add);
    }
    drawColors();
    lPanel.appendChild(lBody);
    wrap.appendChild(lPanel);

    $('#view').appendChild(wrap);
  }

  /* ================================================================ тексты */

  function viewTexts() {
    var b = S.settings.blocks || {};
    var h = S.settings.home || {};
    var o = S.settings.order || {};
    var panel = el('div', 'ab-panel');
    panel.appendChild(panelHead('Тексты главной и подвала', 'Пустое поле — на сайте подставится значение по умолчанию.'));
    var body = el('div', 'ab-panel__body');

    body.appendChild(subHead('Первый экран'));
    var draft = JSON.parse(JSON.stringify(b));
    body.appendChild(field('Заголовок <title> для соцсетей', 'heroTitle', draft.heroTitle));
    body.appendChild(field('Подзаголовок на главной', 'heroLead', draft.heroLead, { type: 'textarea', rows: 3 }));
    var g = grid(2);
    g.appendChild(field('Запасной артикул для главной', 'heroVisualSku', draft.heroVisualSku, { placeholder: '100004' }));
    g.appendChild(field('Подпись под фото', 'heroFigcaption', draft.heroFigcaption, { placeholder: 'Арт. 100004 · 36×25×9 см' }));
    body.appendChild(g);
    body.appendChild(el('p', 'ab-hint', 'Эти два поля нужны только если на главной нет ни одного товара, отмеченного галочкой «Показывать на главной». Карусель строится из отмеченных товаров и показывает первое фото каждого.'));
    body.appendChild(field('alt-текст для фото, если подпись пустая', 'heroAlt', h.heroAlt));

    var gh = grid(2);
    gh.appendChild(field('Надпись над заголовком', 'heroEyebrow', h.heroEyebrow));
    gh.appendChild(field('Первая строка заголовка', 'heroH1a', h.heroH1a));
    body.appendChild(gh);
    var gh2 = grid(2);
    gh2.appendChild(field('Вторая строка заголовка (выделена)', 'heroH1b', h.heroH1b));
    body.appendChild(gh2);
    var gb = grid(4);
    gb.appendChild(field('Кнопка 1', 'heroBtn1', h.heroBtn1));
    gb.appendChild(field('Кнопка 2', 'heroBtn2', h.heroBtn2));
    gb.appendChild(field('Подпись «артикулов»', 'kpi1', h.kpi1));
    gb.appendChild(field('Подпись «категории»', 'kpi2', h.kpi2));
    body.appendChild(gb);
    var gk = grid(2);
    gk.appendChild(field('Подпись «мин. опт»', 'kpi3', h.kpi3));
    gk.appendChild(field('Подпись «шт. в боксе»', 'kpi4', h.kpi4));
    body.appendChild(gk);

    body.appendChild(subHead('Блок «Ассортимент»'));
    var gc = grid(2);
    gc.appendChild(field('Надпись над заголовком', 'catEyebrow', h.catEyebrow));
    gc.appendChild(field('Заголовок, обычная часть', 'catTitleA', h.catTitleA));
    body.appendChild(gc);
    var gc2 = grid(2);
    gc2.appendChild(field('Заголовок, выделенная часть', 'catTitleB', h.catTitleB));
    body.appendChild(gc2);
    body.appendChild(field('Текст под заголовком', 'catSub', h.catSub, { type: 'textarea', rows: 3 }));

    body.appendChild(subHead('Блок «О производстве»'));
    var gab = grid(3);
    gab.appendChild(field('Надпись над заголовком', 'aboutEyebrow', h.aboutEyebrow));
    gab.appendChild(field('Заголовок, обычная часть', 'aboutTitleA', h.aboutTitleA));
    gab.appendChild(field('Заголовок, выделенная часть', 'aboutTitleB', h.aboutTitleB));
    body.appendChild(gab);
    body.appendChild(field('Текст «О производстве»', 'homeAbout', h.homeAbout || b.homeAbout, { type: 'textarea', rows: 5 }));
    body.appendChild(field('Кнопка «Подробнее о компании»', 'aboutBtn', h.aboutBtn));

    body.appendChild(subHead('Блок «Витрина»'));
    var gp = grid(2);
    gp.appendChild(field('Надпись над заголовком', 'popEyebrow', h.popEyebrow));
    gp.appendChild(field('Заголовок, обычная часть', 'popTitleA', h.popTitleA));
    body.appendChild(gp);
    var gp2 = grid(2);
    gp2.appendChild(field('Заголовок, выделенная часть', 'popTitleB', h.popTitleB));
    gp2.appendChild(field('Кнопка «весь каталог»', 'popBtnAll', h.popBtnAll));
    body.appendChild(gp2);
    body.appendChild(field('Текст под заголовком', 'popSub', h.popSub, { type: 'textarea', rows: 3 }));

    body.appendChild(subHead('Блок «Как оформить заказ»'));
    var gw = grid(2);
    gw.appendChild(field('Надпись над заголовком', 'howEyebrow', h.howEyebrow));
    gw.appendChild(field('Заголовок, обычная часть', 'howTitleA', h.howTitleA));
    body.appendChild(gw);
    var gw2 = grid(2);
    gw2.appendChild(field('Заголовок, выделенная часть', 'howTitleB', h.howTitleB));
    body.appendChild(gw2);
    body.appendChild(field('Текст под заголовком', 'howSub', h.howSub, { type: 'textarea', rows: 3 }));

    var st = grid(2);
    st.appendChild(field('Шаг 1 — заголовок', 'step1Title', h.step1Title));
    st.appendChild(field('Шаг 2 — заголовок', 'step2Title', h.step2Title));
    body.appendChild(st);
    body.appendChild(field('Шаг 3 — заголовок', 'step3Title', h.step3Title));
    body.appendChild(field('Шаг 1 — текст', 'step1Text', h.step1Text, { type: 'textarea', rows: 3 }));
    body.appendChild(field('Шаг 2 — текст', 'step2Text', h.step2Text, { type: 'textarea', rows: 3 }));
    body.appendChild(field('Шаг 3 — текст', 'step3Text', h.step3Text, { type: 'textarea', rows: 3 }));

    body.appendChild(subHead('Блок про боксы'));
    body.appendChild(field('Заголовок', 'boxesTitle', h.boxesTitle));
    body.appendChild(field('Текст', 'boxesText', h.boxesText, { type: 'textarea', rows: 5 }));

    body.appendChild(subHead('Блок «Заявка на партию»'));
    var ga = grid(2);
    ga.appendChild(field('Надпись над заголовком', 'ctaEyebrow', h.ctaEyebrow));
    ga.appendChild(field('Заголовок, обычная часть', 'ctaTitleA', h.ctaTitleA));
    body.appendChild(ga);
    var ga2 = grid(3);
    ga2.appendChild(field('Заголовок, выделенная часть', 'ctaTitleB', h.ctaTitleB));
    ga2.appendChild(field('Кнопка «Оформить заявку»', 'ctaBtn1', h.ctaBtn1));
    ga2.appendChild(field('Кнопка «Написать в Telegram»', 'ctaBtn2', h.ctaBtn2));
    body.appendChild(ga2);
    body.appendChild(field('Текст под заголовком', 'ctaSub', h.ctaSub, { type: 'textarea', rows: 3 }));

    body.appendChild(subHead('Шапка, подвал и другие страницы'));
    body.appendChild(field('Текст о компании (страница «О компании»)', 'aboutText', draft.aboutText, { type: 'textarea', rows: 6 }));
    var g2 = grid(2);
    g2.appendChild(field('Слоган в шапке и подвале', 'tagline', draft.tagline, { placeholder: 'пластиковые изделия · опт и розница' }));
    g2.appendChild(field('Текст в подвале', 'footerAbout', draft.footerAbout, { type: 'textarea', rows: 4 }));
    body.appendChild(g2);
    body.appendChild(field('Как оформить заказ (сводный текст)', 'howToOrder', draft.howToOrder, { type: 'textarea', rows: 4 }));

    var g3 = grid(2);
    g3.appendChild(field('Текст над формой заявки', 'orderLead', o.lead, { type: 'textarea', rows: 3 }));
    g3.appendChild(field('Сообщение после отправки заявки', 'orderSuccess', o.success, { type: 'textarea', rows: 3 }));
    body.appendChild(g3);

    var acts = el('div', 'ab-row');
    var save = el('button', 'ab-btn ab-btn--primary', 'Сохранить тексты');
    save.type = 'button';
    save.addEventListener('click', function () {
      var BLOCK_KEYS = ['heroTitle', 'heroLead', 'heroVisualSku', 'heroFigcaption', 'homeAbout', 'aboutText', 'tagline', 'footerAbout', 'howToOrder'];
      var HOME_KEYS = ['heroEyebrow', 'heroH1a', 'heroH1b', 'heroBtn1', 'heroBtn2', 'kpi1', 'kpi2', 'kpi3', 'kpi4', 'heroAlt',
        'catEyebrow', 'catTitleA', 'catTitleB', 'catSub',
        'popEyebrow', 'popTitleA', 'popTitleB', 'popSub', 'popBtnAll',
        'howEyebrow', 'howTitleA', 'howTitleB', 'howSub',
        'step1Title', 'step1Text', 'step2Title', 'step2Text', 'step3Title', 'step3Text',
        'boxesTitle', 'boxesText',
        'ctaEyebrow', 'ctaTitleA', 'ctaTitleB', 'ctaSub', 'ctaBtn1', 'ctaBtn2',
        'aboutEyebrow', 'aboutTitleA', 'aboutTitleB', 'aboutBtn'];

      function pick(keys) {
        var out = {};
        keys.forEach(function (k) {
          var n = $('[data-key="' + k + '"]', body);
          if (n) out[k] = n.value;
        });
        return out;
      }

      var blocks = Object.assign({}, S.settings.blocks, pick(BLOCK_KEYS));
      var home = Object.assign({}, S.settings.home, pick(HOME_KEYS));
      var order = Object.assign({}, S.settings.order);
      var lead = $('[data-key="orderLead"]', body);
      var succ = $('[data-key="orderSuccess"]', body);
      if (lead) order.lead = lead.value;
      if (succ) order.success = succ.value;

      api('settings_save', { settings: { blocks: blocks, home: home, order: order } }).then(need).then(function (j) {
        S.settings = j.settings;
        toast('Тексты сохранены');
      });
    });
    acts.appendChild(save);
    body.appendChild(acts);

    panel.appendChild(body);
    $('#view').appendChild(panel);
  }

  /* ================================================================ контакты */

  function viewContacts() {
    var s = S.settings;
    var panel = el('div', 'ab-panel');
    panel.appendChild(panelHead('Контакты, реквизиты и SEO', 'Значения подставляются в шапку, подвал, форму заявки и метатеги сайта'));
    var body = el('div', 'ab-panel__body');
    var draft = JSON.parse(JSON.stringify({
      site: s.site || {}, contacts: s.contacts || {}, seo: s.seo || {}
    }));

    var GROUP = {
      phone: 'contacts', phoneHref: 'contacts', email: 'contacts', telegram: 'contacts',
      tgLabel: 'contacts', hours: 'contacts',
      company: 'site', director: 'site', ogrn: 'site', inn: 'site', kpp: 'site',
      okved: 'site', madeIn: 'site', cert: 'site', address: 'site',
      title: 'seo', description: 'seo', keywords: 'seo', ogImage: 'seo'
    };

    var g1 = grid(3);
    g1.appendChild(field('Телефон (текст)', 'phone', draft.contacts.phone, { placeholder: '+7 (900) 000-00-00' }));
    g1.appendChild(field('Телефон (для ссылки tel:)', 'phoneHref', draft.contacts.phoneHref, { placeholder: '+79000000000' }));
    g1.appendChild(field('E-mail', 'email', draft.contacts.email));
    body.appendChild(g1);

    var g2 = grid(3);
    g2.appendChild(field('Telegram (ссылка)', 'telegram', draft.contacts.telegram));
    g2.appendChild(field('Telegram (подпись)', 'tgLabel', draft.contacts.tgLabel));
    g2.appendChild(field('Часы работы', 'hours', draft.contacts.hours));
    body.appendChild(g2);

    var g3 = grid(2);
    g3.appendChild(field('Юрлицо', 'company', draft.site.company));
    g3.appendChild(field('Директор', 'director', draft.site.director));
    body.appendChild(g3);

    var g4 = grid(3);
    g4.appendChild(field('ОГРН', 'ogrn', draft.site.ogrn));
    g4.appendChild(field('ИНН', 'inn', draft.site.inn));
    g4.appendChild(field('КПП', 'kpp', draft.site.kpp));
    body.appendChild(g4);

    var g5 = grid(3);
    g5.appendChild(field('ОКВЭД', 'okved', draft.site.okved));
    g5.appendChild(field('Страна производства', 'madeIn', draft.site.madeIn));
    g5.appendChild(field('Сертификация', 'cert', draft.site.cert));
    body.appendChild(g5);

    body.appendChild(field('Адрес', 'address', draft.site.address, { type: 'textarea', rows: 2 }));

    body.appendChild(field('<title> главной', 'title', draft.seo.title));
    body.appendChild(field('description главной', 'description', draft.seo.description, { type: 'textarea', rows: 3 }));
    var g6 = grid(2);
    g6.appendChild(field('keywords', 'keywords', draft.seo.keywords));
    g6.appendChild(field('Картинка для соцсетей', 'ogImage', draft.seo.ogImage));
    body.appendChild(g6);

    var acts = el('div', 'ab-row');
    var save = el('button', 'ab-btn ab-btn--primary', 'Сохранить');
    save.type = 'button';
    save.addEventListener('click', function () {
      var byGroup = {};
      $$('[data-key]', body).forEach(function (n) {
        var k = n.dataset.key;
        var g = GROUP[k] || 'contacts';
        byGroup[g] = byGroup[g] || {};
        byGroup[g][k] = n.value;
      });
      api('settings_save', { settings: byGroup }).then(need).then(function (j) {
        S.settings = j.settings;
        toast('Сохранено');
      });
    });
    acts.appendChild(save);
    body.appendChild(acts);

    panel.appendChild(body);
    $('#view').appendChild(panel);
  }

  /* ================================================================ пользователи */

  function viewUsers() {
    var panel = el('div', 'ab-panel');
    panel.appendChild(panelHead('Пользователи', 'Роль admin даёт доступ ко всем разделам, operator — только к заявкам'));
    var body = el('div', 'ab-panel__body');
    var users = [];

    var list = el('div');
    body.appendChild(list);

    function draw() {
      list.innerHTML = '';
      users.forEach(function (u) {
        var row = el('div', 'ab-list-edit__row');
        row.style.marginBottom = '10px';
        var nm = el('b', 'ab-grow ab-mono', u.username);
        row.appendChild(nm);
        var role = el('select');
        role.style.width = 'auto';
        [['operator', 'оператор'], ['admin', 'админ']].forEach(function (r) {
          var op = el('option', null, r[1]);
          op.value = r[0];
          if (u.role === r[0]) op.selected = true;
          role.appendChild(op);
        });
        var pass = el('input');
        pass.type = 'password';
        pass.placeholder = 'новый пароль';
        pass.style.maxWidth = '150px';
        var sv = el('button', 'ab-btn ab-btn--sm ab-btn--primary', 'Сохранить');
        sv.type = 'button';
        sv.addEventListener('click', function () {
          var p = { username: u.username, role: role.value };
          if (pass.value) p.password = pass.value;
          api('user_save', p).then(need).then(function (j) {
            users = j.users;
            toast('Сохранено');
            draw();
          });
        });
        row.appendChild(role);
        row.appendChild(pass);
        row.appendChild(sv);
        if (u.username !== S.user.username) {
          var del = el('button', 'ab-btn ab-btn--sm ab-btn--danger', '×');
          del.type = 'button';
          del.addEventListener('click', function () {
            if (!confirm('Удалить пользователя ' + u.username + '?')) return;
            api('user_del', { username: u.username }).then(need).then(function (j) {
              users = j.users;
              toast('Удалён');
              draw();
            });
          });
          row.appendChild(del);
        }
        list.appendChild(row);
        var meta = el('div', 'ab-muted ab-mono', 'создан ' + when(u.created) + ' · последний вход ' + (u.lastLogin ? when(u.lastLogin) : '—'));
        meta.style.cssText = 'margin:-6px 0 10px 0';
        list.appendChild(meta);
      });

      var add = el('div', 'ab-row');
      add.style.marginTop = '12px';
      var un = el('input');
      un.placeholder = 'логин';
      var up2 = el('input');
      up2.type = 'password';
      up2.placeholder = 'пароль (мин. 6)';
      var ur = el('select');
      ur.style.width = 'auto';
      [['operator', 'оператор'], ['admin', 'админ']].forEach(function (r) {
        var op = el('option', null, r[1]);
        op.value = r[0];
        ur.appendChild(op);
      });
      var ab = el('button', 'ab-btn ab-btn--primary ab-btn--sm', '+ Добавить');
      ab.type = 'button';
      ab.addEventListener('click', function () {
        api('user_add', { username: un.value.trim(), password: up2.value, role: ur.value })
          .then(need).then(function (j) {
            users = j.users;
            toast('Пользователь добавлен');
            draw();
          });
      });
      add.appendChild(un); add.appendChild(up2); add.appendChild(ur); add.appendChild(ab);
      list.appendChild(add);

      if (S.attempts && S.attempts.length) {
        list.appendChild(el('h3', null, 'Заблокированные входы'));
        var t = el('table', 'ab-table');
        var tr = el('tr');
        ['Логин', 'Попыток', 'IP', 'Последняя', 'Блокировка до', ''].forEach(function (h) {
          tr.appendChild(el('th', null, h));
        });
        t.appendChild(el('thead').appendChild(tr).parentNode);
        var tb = el('tbody');
        S.attempts.forEach(function (a) {
          var r = el('tr');
          r.appendChild(el('td', 'ab-mono', a.username));
          r.appendChild(el('td', 'ab-mono', a.cnt));
          r.appendChild(el('td', 'ab-mono ab-muted', a.ip));
          r.appendChild(el('td', 'ab-muted ab-mono', when(a.last_attempt)));
          r.appendChild(el('td', 'ab-muted ab-mono', a.locked_until ? when(a.locked_until) : '—'));
          var c = el('td');
          var un2 = el('button', 'ab-btn ab-btn--sm', 'Снять');
          un2.type = 'button';
          un2.addEventListener('click', function () {
            api('user_unlock', { username: a.username }).then(need).then(function () { loadUsers(); });
          });
          c.appendChild(un2);
          r.appendChild(c);
          tb.appendChild(r);
        });
        t.appendChild(tb);
        list.appendChild(t);
      }
    }

    function loadUsers() {
      return api('users').then(need).then(function (j) {
        users = j.users;
        S.attempts = j.attempts;
        draw();
      });
    }
    loadUsers();
    panel.appendChild(body);
    $('#view').appendChild(panel);
  }

  /* ================================================================ сервис */

  function viewService() {
    var panel = el('div', 'ab-panel');
    panel.appendChild(panelHead('Резервная копия', 'Храните копию вне сервера — в ней лежат товары, заявки и настройки'));
    var body = el('div', 'ab-panel__body');

    var dl = el('a', 'ab-btn ab-btn--primary', 'Скачать site.db');
    dl.href = API + '?action=backup';
    body.appendChild(dl);

    body.appendChild(el('h3', null, 'Каталог в JSON'));
    body.appendChild(el('p', 'ab-muted', 'Формат data/products.json — для резервной копии каталога без заявок.'));
    var ex = el('a', 'ab-btn', 'Скачать products.json');
    ex.href = API + '?action=export';
    body.appendChild(ex);

    body.appendChild(el('h3', null, 'Чек-лист перед публикацией'));
    var ul = el('ul', 'ab-order-items');
    [
      'Смените пароль admin (Пользователи) и удалите тестовые учётные записи.',
      'Укажите настоящие телефон, e-mail и Telegram — они стоят в шапке, подвале и форме заявки.',
      'Проверьте цены, наличие, вес и габариты каждого товара.',
      'Подтвердите права на фотографии.',
      'Убедитесь, что каталог data/ и site.db закрыты от скачивания правилами сервера.',
      'Отправьте тестовую заявку и убедитесь, что она появилась в разделе «Заявки».'
    ].forEach(function (t) { ul.appendChild(el('li', null, t)); });
    body.appendChild(ul);

    panel.appendChild(body);
    $('#view').appendChild(panel);
  }

  /* ================================================================ старт */

  function init() {
    bindLogin();
    $$('#nav button').forEach(function (b) {
      b.addEventListener('click', function () { go(b.dataset.view); });
    });
    window.addEventListener('beforeunload', function (e) {
      if (!S.dirty) return;
      e.preventDefault();
      e.returnValue = '';
    });

    api('session').then(function (j) {
      if (j && j.ok && j.user) {
        S.user = j.user;
        loadAll().then(showApp).catch(showLogin);
      } else showLogin();
    }).catch(showLogin);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
