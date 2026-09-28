(function () {
  'use strict';

  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* Прячем блоки только если JS работает: без скрипта контент остаётся видимым */
  document.documentElement.classList.add('has-js');

  /* ---------- Мобильное меню ---------- */
  var burger = document.querySelector('.burger');
  var nav = document.getElementById('nav');

  if (burger && nav) {
    burger.addEventListener('click', function () {
      var open = nav.classList.toggle('open');
      burger.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    nav.addEventListener('click', function (e) {
      if (e.target.tagName === 'A') {
        nav.classList.remove('open');
        burger.setAttribute('aria-expanded', 'false');
      }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && nav.classList.contains('open')) {
        nav.classList.remove('open');
        burger.setAttribute('aria-expanded', 'false');
        burger.focus();
      }
    });
  }

  /* ---------- Прогресс прокрутки ---------- */
  var progress = document.querySelector('.progress');
  if (progress) {
    var raf = null;
    var updateProgress = function () {
      var h = document.documentElement;
      var max = h.scrollHeight - h.clientHeight;
      var pct = max > 0 ? (h.scrollTop / max) * 100 : 0;
      progress.style.width = Math.min(100, Math.max(0, pct)) + '%';
      raf = null;
    };
    window.addEventListener('scroll', function () {
      if (!raf) raf = window.requestAnimationFrame(updateProgress);
    }, { passive: true });
    updateProgress();
  }

  /* ---------- Появление блоков ---------- */
  var revealables = document.querySelectorAll('[data-reveal]');
  function revealAll() {
    Array.prototype.forEach.call(revealables, function (el) { el.classList.add('revealed'); });
  }
  if ('IntersectionObserver' in window && revealables.length) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('revealed');
          io.unobserve(entry.target);
        }
      });
    }, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });

    Array.prototype.forEach.call(revealables, function (el, i) {
      if (!reduceMotion) el.style.transitionDelay = (i % 4) * 75 + 'ms';
      io.observe(el);
    });

    /* Страховка: если что-то не сработало — показываем всё через 3 секунды */
    window.setTimeout(function () {
      Array.prototype.forEach.call(revealables, function (el) {
        if (!el.classList.contains('revealed')) el.classList.add('revealed');
      });
    }, 3000);
  } else {
    revealAll();
  }

  /* ---------- Счётчики ---------- */
  var counters = document.querySelectorAll('[data-count]');
  function runCount(el) {
    var target = parseFloat(el.getAttribute('data-count'));
    var suffix = el.getAttribute('data-suffix') || '';
    var decimals = (String(target).split('.')[1] || '').length;
    if (reduceMotion) { el.textContent = target.toFixed(decimals) + suffix; return; }
    var start = null;
    var dur = 1200;
    function step(ts) {
      if (start === null) start = ts;
      var p = Math.min(1, (ts - start) / dur);
      var eased = 1 - Math.pow(1 - p, 3);
      el.textContent = (target * eased).toFixed(decimals).replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + suffix;
      if (p < 1) window.requestAnimationFrame(step);
    }
    window.requestAnimationFrame(step);
  }
  if (counters.length) {
    if ('IntersectionObserver' in window) {
      var cio = new IntersectionObserver(function (entries) {
        entries.forEach(function (e) {
          if (e.isIntersecting) { runCount(e.target); cio.unobserve(e.target); }
        });
      }, { threshold: 0.5 });
      Array.prototype.forEach.call(counters, function (el) { cio.observe(el); });
    } else {
      Array.prototype.forEach.call(counters, runCount);
    }
  }

  /* ---------- Параллакс героя ---------- */
  var heroVisual = document.querySelector('.hero-visual img');
  if (heroVisual && !reduceMotion) {
    var ticking = false;
    window.addEventListener('scroll', function () {
      if (ticking) return;
      ticking = true;
      window.requestAnimationFrame(function () {
        var y = window.scrollY;
        if (y < window.innerHeight * 1.2) {
          heroVisual.style.translate = '0 ' + (y * 0.06).toFixed(1) + 'px';
        }
        ticking = false;
      });
    }, { passive: true });
  }

  /* ---------- Наклон карточек за курсором ---------- */
  if (window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches && !reduceMotion) {
    var tiltables = document.querySelectorAll('.card, .kpi, .use-card');
    Array.prototype.forEach.call(tiltables, function (card) {
      var frame = null;
      card.addEventListener('mousemove', function (e) {
        if (frame) return;
        frame = window.requestAnimationFrame(function () {
          var r = card.getBoundingClientRect();
          var px = (e.clientX - r.left) / r.width - 0.5;
          var py = (e.clientY - r.top) / r.height - 0.5;
          card.style.transform =
            'perspective(900px) rotateX(' + (-py * 4).toFixed(2) + 'deg) rotateY(' +
            (px * 5).toFixed(2) + 'deg) translate(4px, 4px) scale(1.012)';
          frame = null;
        });
      });
      card.addEventListener('mouseleave', function () {
        card.style.transform = '';
      });
    });
  }

  /* ---------- Пауза бегущей строки ---------- */
  var ticks = document.querySelectorAll('.ticker');
  Array.prototype.forEach.call(ticks, function (t) {
    var track = t.querySelector('.ticker-track');
    if (!track) return;
    t.addEventListener('mouseenter', function () { track.style.animationPlayState = 'paused'; });
    t.addEventListener('mouseleave', function () { track.style.animationPlayState = 'running'; });
  });

  /* ---------- Подсветка текущего раздела ---------- */
  var navLinks = document.querySelectorAll('.nav a[href]');
  var sections = [];
  Array.prototype.forEach.call(navLinks, function (a) {
    var href = a.getAttribute('href');
    var match = href && href.indexOf('#') > -1 && document.querySelector(href);
    if (match) sections.push({ link: a, el: match });
  });
  if (sections.length && 'IntersectionObserver' in window) {
    var sio = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        Array.prototype.forEach.call(navLinks, function (l) { l.classList.remove('active'); });
        sections.forEach(function (s) {
          if (s.el === entry.target) s.link.classList.add('active');
        });
      });
    }, { threshold: 0.35, rootMargin: '-80px 0px -40% 0px' });
    sections.forEach(function (s) { sio.observe(s.el); });
  }
})();
