/* ==========================================================================
   ПОЛИФОРМ — «живая пластмасса»: свет за курсором и 3D-сцена в герое.
   Без зависимостей. Уважает prefers-reduced-motion и не крутит rAF
   вне экрана и при скрытой вкладке.
   ========================================================================== */

(function () {
  'use strict';

  var root = document.documentElement;
  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var finePointer = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;

  /* ---------- Свет следует за курсором по глянцевой поверхности ---------- */
  var lit = '.card, .use-card, .kpi, .reason, .opening, .step, .wrap, .contact-card, ' +
            '.requisites, .shot, .card-figure, .use-fig, .cta, .hero-visual';

  if (finePointer) {
    var pending = null;
    var lastEvent = null;

    var paint = function () {
      pending = null;
      var e = lastEvent;
      if (!e) return;
      var el = e.target.closest ? e.target.closest(lit) : null;
      if (!el) return;
      var r = el.getBoundingClientRect();
      if (!r.width || !r.height) return;
      var x = (e.clientX - r.left) / r.width;
      var y = (e.clientY - r.top) / r.height;
      el.style.setProperty('--px', (x * 100).toFixed(1) + '%');
      el.style.setProperty('--py', (y * 100).toFixed(1) + '%');
      el.style.setProperty('--dx', ((x - 0.5) * 12).toFixed(1) + 'px');
      el.style.setProperty('--dy', ((y - 0.5) * 12).toFixed(1) + 'px');
    };

    document.addEventListener('pointermove', function (e) {
      if (e.pointerType === 'touch') return;
      lastEvent = e;
      if (!pending) pending = window.requestAnimationFrame(paint);
    }, { passive: true });
  }

  /* ---------- 3D-сцена в герое: пружина + инерция ---------- */
  var stage = document.querySelector('[data-pl-stage]');
  var cluster = stage && stage.querySelector('.pl-cl');

  if (stage && cluster) {
    var host = stage.closest('.hero-visual') || stage;

    var tX = -12, tY = -20;
    var cX = -12, cY = -20;
    var t0 = 0, moving = false, frame = null;

    var tick = function (now) {
      if (!t0) t0 = now;
      var sec = (now - t0) / 1000;

      if (!moving) {
        tX = -12 + Math.sin(sec * 0.34) * 4.5;
        tY = Math.sin(sec * 0.26 + 1.1) * 15;
      }

      cX += (tX - cX) * 0.11;
      cY += (tY - cY) * 0.11;
      cluster.style.setProperty('--rx', cX.toFixed(2) + 'deg');
      cluster.style.setProperty('--ry', cY.toFixed(2) + 'deg');

      frame = window.requestAnimationFrame(tick);
    };

    var start = function () {
      if (frame) return;
      if (!t0) t0 = window.performance ? performance.now() : Date.now();
      frame = window.requestAnimationFrame(tick);
    };
    var stop = function () {
      if (frame) window.cancelAnimationFrame(frame);
      frame = null;
    };

    if (finePointer && !reduceMotion) start();

    if (finePointer && !reduceMotion) {
      host.addEventListener('pointermove', function (e) {
        var r = host.getBoundingClientRect();
        if (!r.width || !r.height) return;
        var x = (e.clientX - r.left) / r.width;
        var y = (e.clientY - r.top) / r.height;
        tY = (x - 0.5) * 46;
        tX = -12 - (y - 0.5) * 30;
        moving = true;
        start();
      }, { passive: true });

      host.addEventListener('pointerleave', function () { moving = false; }, { passive: true });
    }

    /* Параллакс: сцена чуть отстаёт от прокрутки, но не выходит за рамку */
    if (!reduceMotion) {
      var parRaf = null;
      var par = function () {
        parRaf = null;
        var y = window.scrollY;
        if (y < window.innerHeight * 1.4) {
          stage.style.setProperty('--par', Math.max(-16, -y * 0.03).toFixed(1) + 'px');
        }
      };
      window.addEventListener('scroll', function () {
        if (!parRaf) parRaf = window.requestAnimationFrame(par);
      }, { passive: true });
      par();
    }

    /* Сцена спит, когда её не видно или вкладка неактивна */
    if ('IntersectionObserver' in window) {
      new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (reduceMotion) return;
          if (entry.isIntersecting) start(); else stop();
        });
      }, { threshold: 0 }).observe(stage);
    }
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) stop(); else start();
    });
  }
})();
