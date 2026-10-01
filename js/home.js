/* ==========================================================================
   Карусель товаров на главной странице.
   Разметка строится в index.php; здесь только переключение слайдов.
   ========================================================================== */
(function () {
  'use strict';

  function initCarousel(root) {
    var slides = [].slice.call(root.querySelectorAll('.hero-carousel__slide'));
    if (slides.length < 2) return;

    var dots = [].slice.call(root.querySelectorAll('[data-carousel-dot]'));
    var link = root.querySelector('[data-carousel-link]');
    var capName = root.querySelector('.hero-carousel__name');
    var capDims = root.querySelector('.hero-carousel__dims');
    var autoplay = parseInt(root.getAttribute('data-carousel-autoplay'), 10) || 0;

    var cur = 0;
    var timer = null;

    function captionFor(i) {
      var fig = slides[i];
      if (!fig) return;
      var name = fig.getAttribute('data-name') || '';
      var dims = fig.getAttribute('data-dims') || '';
      if (capName && name) capName.textContent = name;
      if (capDims) {
        capDims.textContent = dims ? dims + ' см' : '';
        capDims.style.display = dims ? '' : 'none';
      }
      if (link) link.setAttribute('href', 'product.php?sku=' + encodeURIComponent(fig.getAttribute('data-sku') || ''));
    }

    function show(i) {
      i = ((i % slides.length) + slides.length) % slides.length;
      if (i === cur && slides[i].classList.contains('is-active')) return;
      slides.forEach(function (s, n) {
        s.classList.toggle('is-active', n === i);
        s.setAttribute('aria-hidden', n === i ? 'false' : 'true');
      });
      dots.forEach(function (d, n) {
        d.classList.toggle('is-active', n === i);
        d.setAttribute('aria-selected', n === i ? 'true' : 'false');
      });
      cur = i;
      captionFor(i);
    }

    function start() {
      if (!autoplay) return;
      stop();
      timer = setInterval(function () { show(cur + 1); }, autoplay);
    }
    function stop() {
      if (timer) { clearInterval(timer); timer = null; }
    }

    root.addEventListener('click', function (e) {
      var t = e.target.closest('[data-carousel-next],[data-carousel-prev],[data-carousel-dot]');
      if (!t) return;
      e.preventDefault();
      if (t.hasAttribute('data-carousel-next')) show(cur + 1);
      else if (t.hasAttribute('data-carousel-prev')) show(cur - 1);
      else show(parseInt(t.getAttribute('data-carousel-dot'), 10) || 0);
      start();
    });

    root.addEventListener('mouseenter', stop);
    root.addEventListener('mouseleave', start);
    root.addEventListener('focusin', stop);
    root.addEventListener('focusout', start);

    document.addEventListener('visibilitychange', function () {
      if (document.hidden) stop(); else start();
    });

    root.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowLeft') { e.preventDefault(); show(cur - 1); start(); }
      if (e.key === 'ArrowRight') { e.preventDefault(); show(cur + 1); start(); }
    });

    captionFor(0);
    start();
  }

  function boot() {
    [].slice.call(document.querySelectorAll('[data-carousel]')).forEach(initCarousel);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();