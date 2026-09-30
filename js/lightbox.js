/* ==========================================================================
   ПОЛИФОРМ — просмотр фото (лайтбокс)
   Клик по снимку с data-zoom открывает его на весь экран.
   Стрелки влево/вправо листают, Esc и крестик закрывают, свайп на телефоне.
   Без зависимостей.
   ========================================================================== */
(function () {
  'use strict';

  var items = [].slice.call(document.querySelectorAll('[data-zoom]'));
  if (!items.length) return;

  var overlay = document.createElement('div');
  overlay.className = 'pl-lb';
  overlay.setAttribute('role', 'dialog');
  overlay.setAttribute('aria-modal', 'true');
  overlay.setAttribute('aria-label', 'Просмотр фотографии');
  overlay.hidden = true;
  overlay.innerHTML =
    '<div class="pl-lb__backdrop" data-lb-close></div>' +
    '<button class="pl-lb__btn pl-lb__close" type="button" data-lb-close aria-label="Закрыть">' +
      '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>' +
    '</button>' +
    '<button class="pl-lb__btn pl-lb__nav pl-lb__prev" type="button" aria-label="Предыдущее фото">' +
      '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg>' +
    '</button>' +
    '<button class="pl-lb__btn pl-lb__nav pl-lb__next" type="button" aria-label="Следующее фото">' +
      '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 5l7 7-7 7"/></svg>' +
    '</button>' +
    '<figure class="pl-lb__figure">' +
      '<img class="pl-lb__img" alt="">' +
      '<figcaption class="pl-lb__cap"></figcaption>' +
    '</figure>' +
    '<p class="pl-lb__count" aria-live="polite"></p>';
  document.body.appendChild(overlay);

  var big = overlay.querySelector('.pl-lb__img');
  var cap = overlay.querySelector('.pl-lb__cap');
  var count = overlay.querySelector('.pl-lb__count');
  var closeBtn = overlay.querySelector('.pl-lb__close');

  var current = -1;
  var lastFocus = null;
  var touchX = 0;

  function label(el) {
    return el.getAttribute('data-caption') || el.getAttribute('alt') || '';
  }

  function preload(i) {
    var it = items[(i + items.length) % items.length];
    if (!it) return;
    var img = new Image();
    img.src = it.getAttribute('data-zoom');
  }

  function show(i) {
    current = (i + items.length) % items.length;
    var el = items[current];
    big.src = el.getAttribute('data-zoom');
    big.alt = label(el);
    cap.textContent = label(el);
    count.textContent = items.length > 1 ? (current + 1) + ' / ' + items.length : '';
    preload(current + 1);
    preload(current - 1);
  }

  function open(i) {
    lastFocus = document.activeElement;
    overlay.hidden = false;
    document.documentElement.classList.add('pl-lb-open');
    show(i);
    closeBtn.focus();
  }

  function close() {
    overlay.hidden = true;
    document.documentElement.classList.remove('pl-lb-open');
    big.removeAttribute('src');
    if (lastFocus) {
      var back = lastFocus.focus ? lastFocus : lastFocus.parentElement;
      if (back && back.focus) back.focus();
    }
    current = -1;
  }

  function step(d) { if (current >= 0) show(current + d); }

  document.addEventListener('click', function (e) {
    var trigger = e.target.closest ? e.target.closest('[data-zoom]') : null;
    if (trigger) {
      var i = items.indexOf(trigger);
      if (i > -1) { e.preventDefault(); open(i); }
      return;
    }
    if (e.target.closest && e.target.closest('[data-lb-close]')) close();
  });

  /* Фото открывается и с клавиатуры: Enter или Пробел */
  document.addEventListener('keydown', function (e) {
    if (!overlay.hidden) return;
    var t = e.target && e.target.closest ? e.target.closest('[data-zoom]') : null;
    if (!t || (e.key !== 'Enter' && e.key !== ' ' && e.key !== 'Spacebar')) return;
    var i = items.indexOf(t);
    if (i > -1) { e.preventDefault(); open(i); }
  });

  overlay.addEventListener('click', function (e) {
    if (e.target === big || e.target.closest('.pl-lb__cap')) close();
  });

  overlay.addEventListener('keydown', function (e) {
    var k = e.key;
    if (k === 'Escape') { close(); return; }
    if (k === 'ArrowRight') { step(1); return; }
    if (k === 'ArrowLeft') { step(-1); return; }
    if (k === 'Home') { show(0); return; }
    if (k === 'End') { show(items.length - 1); return; }
    if (k !== 'Tab') return;
    /* фокус не должен уходить за пределы окна просмотра */
    var focusable = overlay.querySelectorAll('button:not([hidden])');
    if (!focusable.length) return;
    var first = focusable[0], last = focusable[focusable.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  });

  overlay.addEventListener('touchstart', function (e) { touchX = e.changedTouches[0].clientX; }, { passive: true });
  overlay.addEventListener('touchend', function (e) {
    var dx = e.changedTouches[0].clientX - touchX;
    if (Math.abs(dx) > 50) step(dx < 0 ? 1 : -1);
  }, { passive: true });
})();
