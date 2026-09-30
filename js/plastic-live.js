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
})();
