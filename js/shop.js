/* ==========================================================================
   ПОЛИФОРМ — витрина пластиковых изделий
   Каталог, режим Опт/Розница, фильтры, корзина
   ========================================================================== */
(function () {
  'use strict';

  var API_URL = 'api.php?action=catalog';
  var FALLBACK_URL = 'data/products.json';
  var MODE_KEY = 'poliform.mode';
  var CART_KEY = 'poliform.cart';
  var OPT = 'opt', RET = 'ret';

  var catalog = null, products = [], categories = [], cart = [], settings = {};
  var state = { cat: 'all', colors: [], pack: null, onlyStock: false, sort: 'default' };

  /* ---------- Утилиты ---------- */
  function $(s, r) { return (r || document).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }
  function money(v) {
    var p = Number(v).toFixed(2).split('.');
    var w = p[0].replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    return (p[1] === '00' ? w : w + ',' + p[1]) + ' ₽';
  }
  function plural(n, one, few, many) {
    var a = n % 10, b = n % 100;
    if (a === 1 && b !== 11) return one;
    if (a >= 2 && a <= 4 && (b < 10 || b >= 20)) return few;
    return many;
  }
  function dim(p) { return p.size ? [p.size.l, p.size.w, p.size.h].join('×') + ' см' : '—'; }
  function savePct(p) { return Math.round((1 - p.priceOpt / p.priceRetail) * 100); }
  function priceOf(p) { return getMode() === OPT ? p.priceOpt : p.priceRetail; }
  function catName(id) {
    for (var i = 0; i < categories.length; i++) if (categories[i].id === id) return categories[i].short || categories[i].name;
    return '';
  }
  function bySku(sku) {
    for (var i = 0; i < products.length; i++) if (products[i].sku === sku) return products[i];
    return null;
  }

  /* ---------- Режим Опт / Розница ---------- */
  function getMode() { return localStorage.getItem(MODE_KEY) === OPT ? OPT : RET; }
  function syncModeUI() {
    var m = getMode();
    document.documentElement.setAttribute('data-price-mode', m);
    $$('[data-mode-switch] button').forEach(function (b) {
      b.setAttribute('aria-pressed', b.getAttribute('data-mode') === m ? 'true' : 'false');
    });
    $$('.pricing-bar__note').forEach(function (n) {
      n.classList.toggle('is-opt', m === OPT);
      n.innerHTML = m === OPT
        ? 'Оптовый режим: <b>шаг = один бокс</b>. Цена за 1 шт. при отгрузке кратно фасовке.'
        : 'Розничный режим: <b>шаг = 1 шт.</b> Цена за 1 шт. Оптовая цена — в переключателе.';
    });
  }

  /* ---------- Корзина ---------- */
  function loadCart() {
    try { cart = JSON.parse(localStorage.getItem(CART_KEY)) || []; } catch (e) { cart = []; }
    if (!Array.isArray(cart)) cart = [];
  }
  function saveCart() {
    localStorage.setItem(CART_KEY, JSON.stringify(cart));
    badge();
  }
  function ckey(sku, mode) { return sku + '|' + mode; }
  function badge() {
    var n = cart.reduce(function (a, it) { return a + it.qty; }, 0);
    $$('.cart-btn__count').forEach(function (x) { x.textContent = n; });
    $$('.cart-btn').forEach(function (b) { b.classList.toggle('is-empty', n === 0); });
  }
  function normQty(p, qty, mode) {
    if (mode === RET) return Math.max(1, Math.round(qty || 1));
    var step = p.packCount || 1;
    return Math.max(step, Math.round((qty || step) / step) * step);
  }
  function addToCart(sku, mode, qty) {
    var p = bySku(sku);
    if (!p || p.inStock === false) return;
    var k = ckey(sku, mode), found = null, i;
    for (i = 0; i < cart.length; i++) if (ckey(cart[i].sku, cart[i].mode) === k) found = cart[i];
    if (found) found.qty = normQty(p, found.qty + qty, mode);
    else cart.push({ sku: sku, mode: mode, qty: normQty(p, qty, mode) });
    saveCart();
    toast(p, mode);
  }
  function setCartQty(sku, mode, qty) {
    var p = bySku(sku), i;
    if (!p) return;
    if (qty <= 0) {
      cart = cart.filter(function (it) { return ckey(it.sku, it.mode) !== ckey(sku, mode); });
    } else {
      for (i = 0; i < cart.length; i++) {
        if (ckey(cart[i].sku, cart[i].mode) === ckey(sku, mode)) cart[i].qty = normQty(p, qty, mode);
      }
    }
    saveCart();
  }

  /* ---------- Уведомление ---------- */
  var toastEl = null, toastT = null;
  function toast(p, mode) {
    if (!toastEl) {
      toastEl = el('div', 'toast');
      toastEl.setAttribute('role', 'status');
      document.body.appendChild(toastEl);
    }
    toastEl.innerHTML = '';
    var s = el('span');
    s.innerHTML = '<b>' + p.sku + '</b> · ' + p.shortTitle + ' — ' + (mode === OPT ? 'опт' : 'розница');
    var a = el('a', null, 'В корзину');
    a.href = 'cart.php';
    toastEl.appendChild(s);
    toastEl.appendChild(a);
    toastEl.classList.add('is-open');
    clearTimeout(toastT);
    toastT = setTimeout(function () { toastEl.classList.remove('is-open'); }, 3200);
  }

  /* ---------- Карточка товара ---------- */
  function qtyBlock(p, mode, onAdd) {
    var step = mode === OPT ? p.packCount : 1;
    var row = el('div', 'pcard__qty');
    var box = el('div', 'qty');
    var minus = el('button', null, '−');
    minus.type = 'button';
    minus.setAttribute('aria-label', 'Уменьшить количество');
    var inp = el('input');
    inp.type = 'number';
    inp.value = step;
    inp.min = step;
    inp.step = step;
    inp.setAttribute('aria-label', mode === OPT ? 'Количество, кратно ' + step : 'Количество, шт.');
    var plus = el('button', null, '+');
    plus.type = 'button';
    plus.setAttribute('aria-label', 'Увеличить количество');
    box.appendChild(minus); box.appendChild(inp); box.appendChild(plus);
    function setVal(v) { inp.value = normQty(p, v, mode); }
    minus.addEventListener('click', function () { setVal(parseInt(inp.value, 10) - step); });
    plus.addEventListener('click', function () { setVal(parseInt(inp.value, 10) + step); });
    inp.addEventListener('change', function () { setVal(parseInt(inp.value, 10) || step); });

    var add = el('button', 'btn pcard__add', 'В корзину');
    add.type = 'button';
    add.addEventListener('click', function () {
      onAdd(parseInt(inp.value, 10) || step);
      add.classList.add('is-added');
      add.textContent = 'Добавлено';
      setTimeout(function () { add.classList.remove('is-added'); add.textContent = 'В корзину'; }, 1400);
    });
    row.appendChild(box);
    row.appendChild(add);
    return row;
  }

  function buildCard(p) {
    var mode = getMode();
    var card = el('article', 'pcard');
    card.setAttribute('data-sku', p.sku);
    card.setAttribute('data-mode', mode);

    var media = el('a', 'pcard__media');
    media.href = 'product.php?sku=' + p.sku;
    var img = el('img');
    img.src = p.thumbs[0];
    img.alt = p.title;
    img.loading = 'lazy';
    media.appendChild(img);

    var badges = el('div', 'pcard__badges');
    if (p.inStock === false) badges.appendChild(el('span', 'pbadge pbadge--out', 'Под заказ'));
    else if (mode === OPT) badges.appendChild(el('span', 'pbadge pbadge--opt', 'Опт'));
    if (mode === OPT && p.priceRetail > p.priceOpt && savePct(p) >= 1)
      badges.appendChild(el('span', 'pbadge pbadge--disc', '−' + savePct(p) + '%'));
    badges.appendChild(el('span', 'pbadge pbadge--pack', 'Бокс ' + p.packCount + ' шт.'));
    media.appendChild(badges);

    var sw = el('span', 'pcard__swatch');
    sw.style.background = p.colorHex;
    sw.title = p.color;
    media.appendChild(sw);

    /* Галерея на карточке: при наведении на фото появляются остальные фото.
       Движение мыши по горизонтали или клик по миниатюре меняют главное фото. */
    var ph = el('div', 'pcard__ph');
    ph.appendChild(media);
    var thumbs = p.thumbs && p.thumbs.length ? p.thumbs : null;
    if (thumbs && thumbs.length > 1) {
      var cur = 0;
      var set = function (i) {
        if (i === cur) return;
        cur = i;
        img.src = thumbs[i];
        $$('.pcard__shot', ph).forEach(function (b, k) { b.classList.toggle('is-active', k === i); });
      };
      var shots = el('div', 'pcard__shots');
      thumbs.forEach(function (t, i) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'pcard__shot' + (i === 0 ? ' is-active' : '');
        b.setAttribute('aria-label', 'Фото ' + (i + 1));
        var ti = el('img');
        ti.src = t;
        ti.alt = '';
        ti.loading = 'lazy';
        b.appendChild(ti);
        b.addEventListener('click', function (e) {
          e.preventDefault();
          e.stopPropagation();
          set(i);
        });
        shots.appendChild(b);
      });
      ph.appendChild(shots);
      media.addEventListener('mouseenter', function () {
        shots.classList.add('is-open');
        set(0);
      });
      media.addEventListener('mousemove', function (e) {
        var r = media.getBoundingClientRect();
        var ratio = (e.clientX - r.left) / r.width;
        var i = Math.max(0, Math.min(thumbs.length - 1, Math.floor(ratio * thumbs.length)));
        set(i);
      });
      ph.addEventListener('mouseleave', function () {
        shots.classList.remove('is-open');
        set(0);
      });
    }
    card.appendChild(ph);

    var body = el('div', 'pcard__body');
    body.appendChild(el('span', 'pcard__cat', catName(p.categoryId)));

    var h3 = el('h3', 'pcard__title');
    var a = el('a', null, p.shortTitle);
    a.href = 'product.php?sku=' + p.sku;
    h3.appendChild(a);
    body.appendChild(h3);
    body.appendChild(el('span', 'pcard__sku', 'Арт. ' + p.sku));

    var attrs = el('ul', 'pcard__attrs');
    attrs.appendChild(el('li', null, dim(p)));
    if (p.volumeMl) attrs.appendChild(el('li', null, p.volumeMl + ' мл'));
    attrs.appendChild(el('li', null, p.art));
    body.appendChild(attrs);

    var price = el('div', 'pcard__price');
    var now = el('div', 'price-now');
    now.appendChild(el('b', null, money(priceOf(p))));
    now.appendChild(el('span', null, '/ шт.'));
    price.appendChild(now);

    var unit = el('div', 'pcard__unit');
    if (mode === OPT) {
      if (p.priceRetail > p.priceOpt) price.appendChild(el('div', 'price-was', money(p.priceRetail)));
      unit.innerHTML = 'Опт от <b>1 бокса</b>: ' + p.packCount + ' шт. на <b>' + money(p.priceOpt * p.packCount) + '</b>';
    } else {
      unit.innerHTML = 'Фасовка по 1 шт., в боксе <b>' + p.packCount + ' шт.</b>';
    }
    price.appendChild(unit);
    body.appendChild(price);

    if (p.inStock !== false) {
      body.appendChild(qtyBlock(p, mode, function (q) { addToCart(p.sku, mode, q); }));
      if (mode === OPT) body.appendChild(el('div', 'pcard__opt-hint', 'Кратно боксу: шаг +' + p.packCount + ' шт.'));
    } else {
      var ask = el('a', 'btn pcard__add', 'Уточнить наличие');
      ask.href = 'cart.php#order';
      ask.style.textAlign = 'center';
      body.appendChild(ask);
      if (p.stockNote) body.appendChild(el('div', 'pcard__opt-hint', p.stockNote));
    }
    card.appendChild(body);
    return card;
  }

  /* ---------- Главная ---------- */
  function renderHome() {
    var cw = $('[data-home-cats]');
    if (cw) {
      cw.innerHTML = '';
      categories.forEach(function (c) {
        var items = products.filter(function (p) { return p.categoryId === c.id; });
        if (!items.length) return;
        var a = el('a', 'cat-card');
        a.href = 'products.php?cat=' + c.id;
        var img = el('img');
        img.src = items[0].thumbs[0];
        img.alt = c.name;
        img.loading = 'lazy';
        a.appendChild(img);
        var b = el('div', 'cat-card__body');
        b.appendChild(el('h3', null, c.short || c.name));
        b.appendChild(el('p', null, c.desc));
        b.appendChild(el('span', 'cat-card__count', items.length + ' ' + plural(items.length, 'артикул', 'артикула', 'артикулов')));
        a.appendChild(b);
        cw.appendChild(a);
      });
    }

    var g = $('[data-home-grid]');
    if (g) {
      g.innerHTML = '';
      var limit = parseInt(g.getAttribute('data-limit'), 10) || 8;
      var skip = new URLSearchParams(location.search).get('sku');
      var list = products.filter(function (p) { return p.sku !== skip; });
      list.slice(0, limit).forEach(function (p) { g.appendChild(buildCard(p)); });
    }

    var k = $('[data-home-kpi]');
    if (k) {
      var packs = products.map(function (p) { return p.packCount; });
      var minPack = Math.min.apply(null, packs);
      var maxPack = Math.max.apply(null, packs);
      var cheapest = Math.min.apply(null, products.map(function (p) { return p.priceOpt; }));
      k.innerHTML = '';
      [
        [String(products.length), 'артикулов в каталоге'],
        [String(categories.length), 'категории изделий'],
        [String(cheapest), '₽ за шт. — минимальная оптовая цена'],
        [minPack + '–' + maxPack, 'шт. в боксе']
      ].forEach(function (row) {
        var d = el('div', 'hero-fact');
        d.appendChild(el('b', null, row[0]));
        d.appendChild(el('span', null, row[1]));
        k.appendChild(d);
      });
    }
  }

  /* ---------- Фильтры ---------- */
  var packGroups = [
    { key: 's', label: 'до 50 шт.', test: function (p) { return p.packCount <= 50; } },
    { key: 'm', label: '100–300 шт.', test: function (p) { return p.packCount > 50 && p.packCount <= 300; } },
    { key: 'l', label: 'от 500 шт.', test: function (p) { return p.packCount >= 500; } }
  ];

  function colorName(c) {
    if (c == null) return '';
    return String(typeof c === 'object' ? (c.name != null ? c.name : '') : c).trim();
  }

  function colorHex(c) {
    var h = c != null && typeof c === 'object' ? String(c.hex || '') : '';
    return /^#[0-9a-f]{6}$/i.test(h) ? h : '';
  }

  function colorTokens(v) {
    return String(v || '').toLowerCase().split(',')
      .map(function (t) { return t.trim(); })
      .filter(Boolean);
  }

  function filtered() {
    /* Цвет товара может быть перечнем: «Розовый, голубой».
       Совпадение — по отдельным названиям, а не по подстроке,
       иначе «Голубой перламутр» захватывает «Розовый, голубой». */
    var sel = state.colors.map(function (c) { return colorName(c).toLowerCase(); }).filter(Boolean);

    var list = products.filter(function (p) {
      if (state.cat !== 'all' && p.categoryId !== state.cat) return false;
      if (state.onlyStock && p.inStock === false) return false;
      if (sel.length) {
        var t = colorTokens(p.color);
        if (!sel.some(function (n) { return t.indexOf(n) > -1; })) return false;
      }
      if (state.pack) {
        var g = packGroups.filter(function (x) { return x.key === state.pack; })[0];
        if (g && !g.test(p)) return false;
      }
      return true;
    });
    if (state.sort === 'cheap') list.sort(function (a, b) { return priceOf(a) - priceOf(b); });
    else if (state.sort === 'expensive') list.sort(function (a, b) { return priceOf(b) - priceOf(a); });
    else if (state.sort === 'pack') list.sort(function (a, b) { return a.packCount - b.packCount; });
    else if (state.sort === 'name') list.sort(function (a, b) { return a.title.localeCompare(b.title, 'ru'); });
    return list;
  }

  function buildFilters() {
    var wrap = $('[data-filters]');
    if (!wrap) return;
    wrap.innerHTML = '';

    var g1 = el('div', 'fgroup');
    g1.appendChild(el('h4', null, 'Категория'));
    [{ id: 'all', name: 'Все категории' }].concat(categories).forEach(function (c) {
      var n = c.id === 'all' ? products.length : products.filter(function (p) { return p.categoryId === c.id; }).length;
      var l = el('label', 'fopt' + (state.cat === c.id ? ' is-active' : ''));
      var i = el('input');
      i.type = 'radio';
      i.name = 'fcat';
      i.checked = state.cat === c.id;
      l.appendChild(i);
      l.appendChild(el('span', null, c.short || c.name));
      l.appendChild(el('span', 'fopt__count', n));
      i.addEventListener('change', function () { state.cat = c.id; renderCatalog(); });
      g1.appendChild(l);
    });
    wrap.appendChild(g1);

    var g2 = el('div', 'fgroup');
    g2.appendChild(el('h4', null, 'Цвет'));
    var chips = el('div', 'chips');
    (catalog.colorOptions || []).forEach(function (raw) {
      var c = colorName(raw);
      if (!c) return;
      var active = state.colors.some(function (s) { return colorName(s).toLowerCase() === c.toLowerCase(); });
      var b = el('button', 'chip' + (active ? ' is-active' : ''));
      var hex = colorHex(raw);
      if (hex) {
        var dot = el('span', 'chip__dot');
        dot.style.background = hex;
        b.appendChild(dot);
      }
      b.appendChild(document.createTextNode(c));
      b.type = 'button';
      b.setAttribute('data-color', c);
      b.addEventListener('click', function () {
        var i = state.colors.findIndex(function (s) { return colorName(s).toLowerCase() === c.toLowerCase(); });
        if (i > -1) state.colors.splice(i, 1); else state.colors.push(c);
        renderCatalog();
      });
      chips.appendChild(b);
    });
    g2.appendChild(chips);
    wrap.appendChild(g2);

    var g3 = el('div', 'fgroup');
    g3.appendChild(el('h4', null, 'Фасовка в боксе'));
    var pc = el('div', 'chips');
    packGroups.forEach(function (g) {
      var n = products.filter(g.test).length;
      var b = el('button', 'chip' + (state.pack === g.key ? ' is-active' : ''), g.label + ' · ' + n);
      b.type = 'button';
      b.setAttribute('data-pack', g.key);
      b.addEventListener('click', function () {
        state.pack = state.pack === g.key ? null : g.key;
        renderCatalog();
      });
      pc.appendChild(b);
    });
    g3.appendChild(pc);
    wrap.appendChild(g3);

    var g4 = el('div', 'fgroup');
    var l4 = el('label', 'fopt' + (state.onlyStock ? ' is-active' : ''));
    var i4 = el('input');
    i4.type = 'checkbox';
    i4.checked = state.onlyStock;
    l4.appendChild(i4);
    l4.appendChild(el('span', null, 'Только в наличии'));
    i4.addEventListener('change', function () { state.onlyStock = i4.checked; renderCatalog(); });
    g4.appendChild(l4);
    wrap.appendChild(g4);

    var reset = el('button', 'filters__reset', 'Сбросить фильтры');
    reset.type = 'button';
    reset.addEventListener('click', function () {
      state = { cat: 'all', colors: [], pack: null, onlyStock: false, sort: 'default' };
      var s = $('[data-sort]');
      if (s) s.value = 'default';
      renderCatalog();
    });
    wrap.appendChild(reset);
  }

  function renderCatalog() {
    var grid = $('[data-grid]');
    if (!grid) return;
    buildFilters();
    var list = filtered();
    grid.innerHTML = '';
    var c = $('[data-count]');
    if (c) c.textContent = list.length + ' ' + plural(list.length, 'позиция', 'позиции', 'позиций');
    if (!list.length) {
      var e = el('div', 'empty-state');
      e.appendChild(el('b', null, 'Ничего не найдено'));
      e.appendChild(el('span', null, 'Сбросьте фильтры или уточните запрос у менеджера.'));
      grid.appendChild(e);
      return;
    }
    list.forEach(function (p) { grid.appendChild(buildCard(p)); });
  }

  /* ---------- Страница товара ---------- */
  function renderProduct() {
    var host = $('[data-product]');
    if (!host) return;
    var p = bySku(new URLSearchParams(location.search).get('sku'));
    host.innerHTML = '';
    if (!p) {
      var e = el('div', 'empty-state');
      e.appendChild(el('b', null, 'Артикул не найден'));
      e.appendChild(el('span', null, 'Вернитесь в каталог и выберите позицию.'));
      var b = el('a', 'btn', 'В каталог');
      b.href = 'products.php';
      b.style.marginTop = '16px';
      e.appendChild(b);
      host.appendChild(e);
      return;
    }

    document.title = p.title + ' — Полиформ';
    var d = $('meta[name="description"]');
    if (d) d.setAttribute('content', p.description.slice(0, 158));

    var crumbs = $('[data-crumbs]');
    if (crumbs) {
      crumbs.innerHTML = '';
      var c1 = el('a', null, 'Главная');
      c1.href = 'index.php';
      var c2 = el('a', null, 'Каталог');
      c2.href = 'products.php';
      var c3 = el('a', null, catName(p.categoryId));
      c2.href = 'products.php?cat=' + p.categoryId;
      crumbs.appendChild(c1);
      crumbs.appendChild(document.createTextNode(' / '));
      crumbs.appendChild(c2);
      crumbs.appendChild(document.createTextNode(' / '));
      crumbs.appendChild(c3);
      crumbs.appendChild(document.createTextNode(' / '));
      crumbs.appendChild(el('span', null, p.shortTitle));
    }

    var layout = el('div', 'product-layout');

    /* галерея */
    var g = el('div', 'gallery');
    var main = el('div', 'gallery__main');
    var big = el('img');
    big.src = p.images[0];
    big.alt = p.title;
    main.appendChild(big);
    g.appendChild(main);

    var nimg = p.images.length;
    var gi = 0;
    var thEl = null;
    function showPhoto(i) {
      if (i < 0) i = nimg - 1;
      if (i >= nimg) i = 0;
      gi = i;
      big.src = p.images[i];
      var cnt = g.querySelector('.gallery__count');
      if (cnt) cnt.textContent = (i + 1) + ' / ' + nimg;
      if (thEl) $$('button', thEl).forEach(function (x, k) { x.classList.toggle('is-active', k === i); });
    }
    if (nimg > 1) {
      var navPrev = el('button', 'gallery__nav gallery__nav--prev', '‹');
      navPrev.type = 'button';
      navPrev.setAttribute('aria-label', 'Предыдущее фото');
      navPrev.addEventListener('click', function () { showPhoto(gi - 1); });
      main.appendChild(navPrev);
      var navNext = el('button', 'gallery__nav gallery__nav--next', '›');
      navNext.type = 'button';
      navNext.setAttribute('aria-label', 'Следующее фото');
      navNext.addEventListener('click', function () { showPhoto(gi + 1); });
      main.appendChild(navNext);
      main.appendChild(el('div', 'gallery__count', '1 / ' + nimg));

      thEl = el('div', 'gallery__thumbs');
      p.images.forEach(function (src, i) {
        var btn = el('button', i === 0 ? 'is-active' : '');
        btn.type = 'button';
        btn.setAttribute('aria-label', 'Фото ' + (i + 1));
        var im = el('img');
        im.src = p.thumbs[i];
        im.alt = '';
        im.loading = 'lazy';
        btn.appendChild(im);
        btn.addEventListener('click', function () { showPhoto(i); });
        btn.addEventListener('mouseenter', function () { showPhoto(i); });
        thEl.appendChild(btn);
      });
      g.appendChild(thEl);
    }
    layout.appendChild(g);

    /* инфо */
    var info = el('div');
    info.appendChild(el('h1', 'product-title', p.title));

    var skuRow = el('div', 'product-sku');
    skuRow.innerHTML = 'Артикул <b>' + p.sku + '</b>';
    if (p.inStock === false) {
      skuRow.innerHTML += ' <span class="pbadge pbadge--out">Под заказ</span>';
    }
    info.appendChild(skuRow);

    /* цена */
    var box = el('div', 'product-price-box');
    var pack = el('div', 'ppb__pack');
    pack.innerHTML = '<b>Фасовка:</b> по 1 шт., в боксе <b>' + p.packCount + ' шт.</b> Опт отгружается кратно боксу.';
    box.appendChild(pack);

    var mode = getMode();
    var cur = { mode: mode, big: null, qty: null, step: mode === OPT ? p.packCount : 1 };

    var rows = el('div', 'ppb__rows');
    [
      { key: RET, label: 'Розница', price: p.priceRetail, hint: 'от 1 шт.' },
      { key: OPT, label: 'Опт', price: p.priceOpt, hint: 'кратно ' + p.packCount + ' шт.' }
    ].forEach(function (o) {
      var r = el('div', 'ppb__row' + (o.key === cur.mode ? ' is-active' : ''));
      r.setAttribute('data-mode', o.key);
      r.appendChild(el('span', 'ppb__mode', o.label));
      var hint = el('span', 'ppb__hint', o.hint);
      r.appendChild(hint);
      r.appendChild(el('b', null, money(o.price) + ' / шт.'));
      r.addEventListener('click', function () {
        cur.mode = o.key;
        localStorage.setItem(MODE_KEY, o.key);
        syncModeUI();
        $$('.ppb__row', box).forEach(function (x) { x.classList.remove('is-active'); });
        r.classList.add('is-active');
        cur.step = o.key === OPT ? p.packCount : 1;
        if (cur.qty) {
          cur.qty.min = cur.step;
          cur.qty.step = cur.step;
          cur.qty.value = cur.step;
        }
        updatePrice(p, cur, box);
      });
      rows.appendChild(r);
    });
    box.appendChild(rows);

    var now = el('div', 'price-now');
    cur.big = el('b', null, money(priceOf(p)));
    now.appendChild(cur.big);
    now.appendChild(el('span', null, '/ шт.'));
    var disc = el('span', 'pbadge pbadge--disc');
    disc.style.display = 'none';
    now.appendChild(disc);
    box.appendChild(now);

    var was = el('div', 'price-was');
    box.appendChild(was);
    var unit = el('div', 'pcard__unit');
    unit.style.marginTop = '8px';
    box.appendChild(unit);

    if (p.inStock !== false) {
      var actions = el('div', 'ppb__actions');
      var step = cur.step;
      var qbox = el('div', 'qty');
      var minus = el('button', null, '−');
      minus.type = 'button';
      minus.setAttribute('aria-label', 'Уменьшить количество');
      cur.qty = el('input');
      cur.qty.type = 'number';
      cur.qty.value = step;
      cur.qty.min = step;
      cur.qty.step = step;
      cur.qty.setAttribute('aria-label', 'Количество');
      var plus = el('button', null, '+');
      plus.type = 'button';
      plus.setAttribute('aria-label', 'Увеличить количество');
      qbox.appendChild(minus); qbox.appendChild(cur.qty); qbox.appendChild(plus);
      minus.addEventListener('click', function () { cur.qty.value = normQty(p, parseInt(cur.qty.value, 10) - cur.step, cur.mode); });
      plus.addEventListener('click', function () { cur.qty.value = normQty(p, parseInt(cur.qty.value, 10) + cur.step, cur.mode); });
      cur.qty.addEventListener('change', function () { cur.qty.value = normQty(p, parseInt(cur.qty.value, 10) || cur.step, cur.mode); });
      actions.appendChild(qbox);

      var add = el('button', 'btn btn-signal', 'В корзину');
      add.type = 'button';
      add.addEventListener('click', function () {
        addToCart(p.sku, cur.mode, parseInt(cur.qty.value, 10) || cur.step);
        add.textContent = 'Добавлено';
        setTimeout(function () { add.textContent = 'В корзину'; }, 1500);
      });
      actions.appendChild(add);
      box.appendChild(actions);
    } else {
      var ask = el('a', 'btn btn-signal', 'Уточнить наличие');
      ask.href = 'cart.php#order';
      box.appendChild(ask);
    }

    var phint = el('div', 'ppb__note');
    box.appendChild(phint);
    info.appendChild(box);
    updatePrice(p, cur, box);

    var desc = el('p', 'product-desc', p.description);
    info.appendChild(desc);

    var st = el('div', 'block block--tint');
    st.appendChild(el('h2', null, 'Характеристики'));
    var tbl = el('table', 'spec-table');
    [
      ['Артикул', p.sku],
      ['Габариты', dim(p)],
      ['Форма', p.form],
      ['Конструкция', p.art],
      ['Цвет', p.color],
      p.volumeMl ? ['Объём', p.volumeMl + ' мл'] : null,
      p.gridGap ? ['Зазор между дном и сеткой', p.gridGap + ' см'] : null,
      ['Фасовка', 'по 1 шт., в боксе ' + p.packCount + ' шт.'],
      ['Вес брутто', p.weightG + ' г'],
      ['Материал', p.material],
      ['Страна производства', p.country],
      ['Сертификация', p.cert]
    ].filter(Boolean).forEach(function (r) {
      var tr = el('tr');
      tr.appendChild(el('th', null, r[0]));
      tr.appendChild(el('td', null, r[1]));
      tbl.appendChild(tr);
    });
    st.appendChild(tbl);
    info.appendChild(st);

    var fb = el('div', 'block block--tint');
    fb.appendChild(el('h2', null, 'Особенности'));
    var ul = el('ul', 'feature-list');
    p.features.forEach(function (f) { ul.appendChild(el('li', null, f)); });
    fb.appendChild(ul);
    info.appendChild(fb);

    layout.appendChild(info);
    host.appendChild(layout);

    var ld = el('script');
    ld.type = 'application/ld+json';
    ld.textContent = JSON.stringify({
      '@context': 'https://schema.org',
      '@type': 'Product',
      name: p.title,
      sku: p.sku,
      image: p.images,
      description: p.description,
      material: p.material,
      color: p.color,
      brand: { '@type': 'Brand', name: 'Полиформ' },
      countryOfOrigin: { '@type': 'Country', name: p.country },
      offers: {
        '@type': 'AggregateOffer',
        priceCurrency: 'RUB',
        lowPrice: Math.min(p.priceOpt, p.priceRetail),
        highPrice: Math.max(p.priceOpt, p.priceRetail),
        offerCount: 2,
        availability: p.inStock === false ? 'https://schema.org/PreOrder' : 'https://schema.org/InStock'
      },
      additionalProperty: [
        { '@type': 'PropertyValue', name: 'Габариты', value: dim(p) },
        { '@type': 'PropertyValue', name: 'Фасовка', value: 'В боксе ' + p.packCount + ' шт.' }
      ]
    });
    document.head.appendChild(ld);
  }

  function updatePrice(p, cur, box) {
    cur.big.textContent = money(cur.mode === OPT ? p.priceOpt : p.priceRetail);
    var disc = box.querySelector('.price-now .pbadge--disc');
    if (disc) {
      if (cur.mode === OPT && p.priceRetail > p.priceOpt && savePct(p) >= 1) {
        disc.textContent = '−' + savePct(p) + '%';
        disc.style.display = '';
      } else {
        disc.style.display = 'none';
      }
    }
    var unit = box.querySelector('.pcard__unit');
    var was = box.querySelector('.price-was');
    var hint = box.querySelector('.ppb__note');
    if (cur.mode === OPT) {
      unit.innerHTML = '<b>1 бокс</b> = ' + p.packCount + ' шт. на <b>' + money(p.priceOpt * p.packCount) + '</b>';
      was.textContent = 'Розница ' + money(p.priceRetail) + ' · выгода ' + savePct(p) + '%';
      hint.textContent = 'Кратно боксу: шаг +' + p.packCount + ' шт. Минимум — 1 бокс.';
    } else {
      unit.innerHTML = '<b>1 шт.</b> — фасовка по 1 шт., в боксе ' + p.packCount + ' шт.';
      was.textContent = 'Опт от 1 бокса — ' + money(p.priceOpt) + ' / шт. (выгода ' + savePct(p) + '%)';
      hint.textContent = 'Оптовая цена действует от 1 бокса (' + p.packCount + ' шт.) — выберите режим «Опт» выше.';
    }
  }

  /* ---------- Корзина ---------- */
  function priceFor(p, mode, qty) { return (mode === OPT ? p.priceOpt : p.priceRetail) * qty; }

  function renderCart() {
    var host = $('[data-cart]');
    if (!host) return;
    host.innerHTML = '';
    var summary = $('[data-cart-summary]');
    var form = $('[data-order-form]');
    var forceOrder = location.hash === '#order';
    if (form) form.style.display = (cart.length || forceOrder) ? '' : 'none';
    if (forceOrder && form) {
      setTimeout(function () { form.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 60);
    }

    if (!cart.length) {
      var e = el('div', 'empty-state');
      e.appendChild(el('b', null, 'Корзина пуста'));
      e.appendChild(el('span', null, 'Добавьте позиции из каталога — оптом или в розницу.'));
      var a = el('a', 'btn', 'Перейти в каталог');
      a.href = 'products.php';
      a.style.marginTop = '16px';
      e.appendChild(a);
      host.appendChild(e);
      if (summary) summary.style.display = 'none';
      return;
    }

    var wrap = el('div', 'cart-table');
    cart.forEach(function (it) {
      var p = bySku(it.sku);
      if (!p) return;
      var row = el('div', 'cart-row');
      var ib = el('div', 'cart-row__img');
      var im = el('img');
      im.src = p.thumbs[0];
      im.alt = p.shortTitle;
      im.loading = 'lazy';
      ib.appendChild(im);
      row.appendChild(ib);

      var mid = el('div');
      var nm = el('div', 'cart-row__name');
      var link = el('a', null, p.shortTitle);
      link.href = 'product.php?sku=' + p.sku;
      nm.appendChild(link);
      mid.appendChild(nm);
      mid.appendChild(el('div', 'cart-row__meta',
        'Арт. ' + p.sku + ' · ' + dim(p) + ' · ' + (it.mode === OPT ? 'опт, кратно боксу ' + p.packCount + ' шт.' : 'розница')));
      row.appendChild(mid);

      var right = el('div', 'cart-row__right');
      var step = it.mode === OPT ? p.packCount : 1;
      var q = el('div', 'qty');
      var minus = el('button', null, '−');
      minus.type = 'button';
      minus.setAttribute('aria-label', 'Уменьшить');
      var inp = el('input');
      inp.type = 'number';
      inp.value = it.qty;
      inp.min = step;
      inp.step = step;
      inp.setAttribute('aria-label', 'Количество');
      var plus = el('button', null, '+');
      plus.type = 'button';
      plus.setAttribute('aria-label', 'Увеличить');
      q.appendChild(minus); q.appendChild(inp); q.appendChild(plus);
      minus.addEventListener('click', function () { setCartQty(it.sku, it.mode, it.qty - step); renderCart(); });
      plus.addEventListener('click', function () { setCartQty(it.sku, it.mode, it.qty + step); renderCart(); });
      inp.addEventListener('change', function () { setCartQty(it.sku, it.mode, parseInt(inp.value, 10) || step); renderCart(); });
      right.appendChild(q);

      right.appendChild(el('div', 'cart-row__sum', money(priceFor(p, it.mode, it.qty))));

      var del = el('button', 'cart-row__del', '×');
      del.type = 'button';
      del.setAttribute('aria-label', 'Убрать артикул ' + p.sku);
      del.addEventListener('click', function () { setCartQty(it.sku, it.mode, 0); renderCart(); });
      right.appendChild(del);

      row.appendChild(right);
      wrap.appendChild(row);
    });
    host.appendChild(wrap);

    if (!summary) return;
    summary.style.display = '';
    summary.innerHTML = '';
    summary.appendChild(el('h3', null, 'Итог по заказу'));

    var retSum = 0, optSum = 0, units = 0, boxes = 0, boxLines = [];
    cart.forEach(function (it) {
      var p = bySku(it.sku);
      if (!p) return;
      units += it.qty;
      if (it.mode === OPT) {
        optSum += p.priceOpt * it.qty;
        boxes += it.qty / p.packCount;
        boxLines.push(p.sku + ' — ' + (it.qty / p.packCount) + ' бокс. по ' + p.packCount + ' шт.');
      } else {
        retSum += p.priceRetail * it.qty;
      }
    });

    if (optSum) {
      var ob = el('div', 'sum-line');
      ob.innerHTML = '<span>Оптовая часть</span><b>' + money(optSum) + '</b>';
      summary.appendChild(ob);
      summary.appendChild(el('div', 'sum-note', 'Боксов: ' + boxes + '. ' + boxLines.join('; ') + '.'));
    }
    if (retSum) {
      var rl = el('div', 'sum-line');
      rl.innerHTML = '<span>Розничная часть</span><b>' + money(retSum) + '</b>';
      summary.appendChild(rl);
    }
    var u = el('div', 'sum-line');
    u.innerHTML = '<span>Всего единиц</span><b>' + units + ' шт.</b>';
    summary.appendChild(u);
    var t = el('div', 'sum-line sum-line--total');
    t.innerHTML = '<span>Сумма</span><b>' + money(retSum + optSum) + '</b>';
    summary.appendChild(t);
    summary.appendChild(el('div', 'sum-note',
      'Цены указаны за 1 шт. Оптовая часть отгружается кратно боксу. Доставку рассчитывает менеджер.'));

    var ta = $('[data-order-items]');
    if (ta) {
      ta.value = cart.map(function (it) {
        var p = bySku(it.sku);
        if (!p) return '';
        return it.sku + ' · ' + p.shortTitle + ' · ' + (it.mode === OPT ? 'ОПТ' : 'розн.') + ' · ' + it.qty + ' шт. · ' + money(priceFor(p, it.mode, it.qty));
      }).filter(Boolean).join('\n');
    }
  }

  /* ---------- Форма заявки ---------- */
  function initForm() {
    var form = document.getElementById('order-form');
    if (!form) return;
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var ok = true, first = null;
      $$('[required]', form).forEach(function (input) {
        var bad = !input.value.trim() || (input.type === 'tel' && !/^[\d\s\-+()]{10,}$/.test(input.value.trim()));
        input.classList.toggle('is-error', bad);
        if (bad && !first) first = input;
        if (bad) ok = false;
      });
      var msg = $('[data-form-msg]');
      if (!ok) {
        if (msg) {
          msg.className = 'form-msg is-err';
          msg.textContent = 'Проверьте отмеченные поля: имя и телефон обязательны.';
        }
        if (first) first.focus();
        return;
      }
      var name = form.elements.name.value.trim();
      var phone = form.elements.phone.value.trim();
      var lines = cart.map(function (it) {
        var p = bySku(it.sku);
        if (!p) return '';
        return '- ' + it.sku + ' ' + p.shortTitle + ' (' + (it.mode === OPT ? 'опт' : 'розница') + ', ' + it.qty + ' шт.)';
      }).filter(Boolean);
      var kind = cart.some(function (i) { return i.mode === OPT; }) && cart.some(function (i) { return i.mode === RET; })
        ? 'опт + розница'
        : (cart[0] && cart[0].mode === OPT ? 'опт' : 'розница');
      var text = 'Заявка с сайта poliform\n\nИмя: ' + name + '\nТелефон: ' + phone +
        '\nE-mail: ' + (form.elements.email.value.trim() || '—') +
        '\nГород/регион: ' + (form.elements.city.value.trim() || '—') +
        '\nТип: ' + kind +
        '\n\nСостав заказа:\n' + (lines.join('\n') || 'без позиций — нужен расчёт') +
        (form.elements.comment.value.trim() ? '\n\nКомментарий: ' + form.elements.comment.value.trim() : '');

      var payload = {
        name: name,
        phone: phone,
        email: form.elements.email.value.trim(),
        city: form.elements.city.value.trim(),
        comment: form.elements.comment.value.trim(),
        kind: kind,
        source: location.pathname.replace(/^.*\//, '') || 'site',
        website: form.elements.website ? form.elements.website.value : '',
        items: cart.map(function (it) {
          var p = bySku(it.sku);
          if (!p) return null;
          var unit = it.mode === OPT ? p.priceOpt : p.priceRetail;
          return {
            sku: p.sku,
            title: p.shortTitle || p.title,
            mode: it.mode,
            qty: it.qty,
            unit: unit,
            lineTotal: Math.round(unit * it.qty * 100) / 100
          };
        }).filter(Boolean)
      };

      var btn = form.querySelector('button[type="submit"]');
      if (btn) btn.disabled = true;
      if (msg) { msg.className = 'form-msg'; msg.textContent = 'Отправляем заявку…'; }

      fetch('api.php?action=order_add', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      })
        .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'HTTP ' + r.status }; }); })
        .then(function (j) {
          if (btn) btn.disabled = false;
          if (j && j.ok) {
            cart = [];
            saveCart();
            if (msg) {
              msg.className = 'form-msg is-ok';
              msg.textContent = (settings.order && settings.order.success) ||
                ('Заявка принята. Номер ' + (j.id || '') + '. Менеджер свяжется по телефону ' + phone + '.');
            }
            if (form.elements.agree) form.elements.agree.checked = false;
            if ($('[data-cart]')) renderCart();
            return;
          }
          if (msg) {
            msg.className = 'form-msg is-err';
            msg.textContent = ((j && j.error) || 'Не удалось отправить заявку') + ' Попробуйте скопировать текст ниже или позвоните нам.';
          }
          showOrderText(text);
        })
        .catch(function () {
          if (btn) btn.disabled = false;
          if (msg) {
            msg.className = 'form-msg is-err';
            msg.textContent = 'Сервер недоступен. Скопируйте текст заявки ниже или позвоните нам.';
          }
          showOrderText(text);
        });
    });
  }

  function showOrderText(text) {
    var link = $('[data-telegram-link]');
    if (link) link.href = 'https://t.me/share/url?url=' + encodeURIComponent(location.href) + '&text=' + encodeURIComponent(text);
    var ta = $('[data-order-text]');
    if (ta) {
      ta.value = text;
      ta.style.display = 'block';
      ta.select();
      try { document.execCommand('copy'); } catch (err) { /* копирование недоступно */ }
    }
  }

  /* ---------- Инициализация ---------- */
  function renderAll() {
    if (!catalog) return;
    if ($('[data-grid]')) renderCatalog();
    if ($('[data-home-grid]') || $('[data-home-cats]')) renderHome();
    if ($('[data-product]')) renderProduct();
    if ($('[data-cart]')) renderCart();
  }

  function bindModeSwitch() {
    $$('[data-mode-switch] button').forEach(function (b) {
      b.addEventListener('click', function () {
        localStorage.setItem(MODE_KEY, b.getAttribute('data-mode'));
        syncModeUI();
        renderAll();
      });
    });
  }

  function initSort() {
    var s = $('[data-sort]');
    if (!s) return;
    s.addEventListener('change', function () { state.sort = s.value; renderCatalog(); });
  }

  function loadJson(url) {
    return fetch(url, { cache: 'no-cache' })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .catch(function (e) {
        /* Расширения и политики браузера часто режут именно fetch, считая его
           способом отслеживания. Обычный XHR через такие правила проходит. */
        return loadJsonXhr(url).catch(function () { throw e; });
      });
  }

  function loadJsonXhr(url) {
    return new Promise(function (resolve, reject) {
      var x = new XMLHttpRequest();
      x.open('GET', url, true);
      x.timeout = 15000;
      x.onload = function () {
        if (x.status >= 200 && x.status < 300) {
          try { resolve(JSON.parse(x.responseText)); }
          catch (err) { reject(new Error('не удалось разобрать JSON')); }
        } else reject(new Error('HTTP ' + x.status));
      };
      x.onerror = function () { reject(new Error('сеть недоступна')); };
      x.ontimeout = function () { reject(new Error('превышено время ожидания')); };
      x.send();
    });
  }

  function init() {
    loadCart();
    syncModeUI();
    badge();
    bindModeSwitch();
    initSort();
    initForm();
    renderAll();
    loadCatalog();
    pinFilters();
  }

  /* Панель фильтров выше окна (стикер на ширине >1080px).
     При скролле ВНИЗ панель едет с карточками и прилипает, когда низ
     (кнопка «Сбросить фильтр») доходит до нижнего края окна; при скролле
     ВВЕРХ поднимается и прилипает к верхней части окна под шапкой. */
  function pinFilters() {
    var f = $('[data-filters]');
    if (!f) return;
    var doc = document.documentElement;
    var lastS = -1;
    var ticking = false;

    function stick(onTop) {
      f.classList.toggle('pin-top', onTop);
    }
    function measure() {
      doc.style.setProperty('--filters-h', f.offsetHeight + 'px');
      doc.classList.add('js-sticky-filters');
    }
    function tick() {
      ticking = false;
      var s = Math.max(0, window.pageYOffset || window.scrollY || 0);
      var delta = lastS >= 0 ? s - lastS : 0;
      lastS = s;
      if (delta < 0) stick(true);       /* скролл вверх: прилипаем к верху */
      else if (delta > 0) stick(false); /* скролл вниз: прилипаем к низу */
    }
    function onScroll() {
      if (ticking) return;
      ticking = true;
      if (window.requestAnimationFrame) requestAnimationFrame(tick);
      else tick();
    }
    measure();
    if (!pinFilters._watching) {
      pinFilters._watching = true;
      if (window.ResizeObserver) new ResizeObserver(measure).observe(f);
      else window.addEventListener('resize', measure);
    }
    window.addEventListener('scroll', onScroll, { passive: true });
  }

  function loadCatalog() {
    var tried = [];
    function step(i, list) {
      if (i >= list.length) return Promise.reject(new Error(tried.join(' · ')));
      return loadJson(list[i]).then(
        function (data) { return data; },
        function (e) {
          tried.push(list[i] + ' — ' + ((e && e.message) || 'причина неизвестна'));
          return step(i + 1, list);
        }
      );
    }
    return step(0, [API_URL, FALLBACK_URL])
      .then(function (data) {
        catalog = data;
        products = data.products || [];
        categories = data.categories || [];
        settings = data.settings || {};
        applySettings();
        var qs = new URLSearchParams(location.search);
        if (qs.get('cat') && categories.some(function (c) { return c.id === qs.get('cat'); })) state.cat = qs.get('cat');
        if (qs.get('color')) state.colors = [qs.get('color')];
        renderAll();
        pinFilters();
      })
      .catch(function (err) {
        showCatalogError(err && err.message ? err.message : 'причина неизвестна');
        if (window.console) console.error('Каталог:', err);
      });
  }

  function showCatalogError(reason) {
    var tel = ((settings.contacts || {}).phone || '');
    $$('[data-grid], [data-home-grid], [data-home-cats]').forEach(function (n) {
      n.innerHTML = '';
      var e = el('div', 'empty-state');
      e.appendChild(el('b', null, 'Каталог не загрузился'));
      e.appendChild(el('span', null, tel ? 'Позвоните менеджеру: ' + tel : 'Проверьте подключение или позвоните менеджеру'));
      var why = el('span', 'empty-state__why', 'Причина: ' + reason);
      e.appendChild(why);
      var again = el('button', 'empty-state__retry', 'Повторить загрузку');
      again.type = 'button';
      again.addEventListener('click', function () {
        again.disabled = true;
        again.textContent = 'Загружаю…';
        loadCatalog();
      });
      e.appendChild(again);
      n.appendChild(e);
    });
  }

  /* ---------- Настройки с сервера ---------- */
  function applySettings() {
    var o = settings.order || {};
    if (o.lead) {
      var lead = $('[data-order-lead]');
      if (lead) lead.textContent = o.lead;
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
