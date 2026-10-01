<?php
declare(strict_types=1);

require __DIR__ . '/inc/layout.php';

$d    = pl_boot();
$n    = $d['counts'];
$base = 'https://poliform.pages.dev/';
$s    = $d['site'];

pl_head($d, [
    'title' => 'Каталог пластиковых изделий — Полиформ',
    'description' => 'Каталог собственного производства Полиформ: лотки и туалеты для кошек, миски одинарные и двойные. Опт от 1 бокса и розница, фильтры по цвету и фасовке.',
    'base'  => $base,
]);
pl_header($d, 'products');
pl_breadcrumbs_jsonld($base, ['Главная' => 'index.php', 'Каталог' => 'products.php']);
?>

<section>
  <div class="container">
    <nav class="crumbs" aria-label="Хлебные крошки">
      <a href="index.php">Главная</a> / <span>Каталог</span>
    </nav>

    <div class="sec-head" style="margin-bottom:26px">
      <div>
        <span class="eyebrow">Собственное производство</span>
        <h1 class="sec-title" style="font-size:clamp(1.8rem,4vw,2.6rem)">Каталог <em>изделий</em></h1>
        <p class="sec-sub"><?= (int)$n['products'] ?> артикулов в <?= (int)$n['cats'] ?> линейках. В оптовом режиме количество добавляется кратно боксу, в розничном — поштучно. Фасовка каждой позиции указана в карточке.</p>
      </div>
    </div>

    <div class="catalog-layout">
      <aside class="filters" data-filters aria-label="Фильтры каталога"></aside>

      <div>
        <div class="catalog-toolbar">
          <span class="catalog-toolbar__count">Найдено: <b data-count>—</b></span>
          <label class="catalog-toolbar__sort">
            Сортировка
            <select data-sort>
              <option value="default">По умолчанию</option>
              <option value="cheap">Сначала дешевле</option>
              <option value="expensive">Сначала дороже</option>
              <option value="name">По названию</option>
              <option value="pack">По фасовке</option>
            </select>
          </label>
        </div>
        <div class="shop-grid" data-grid></div>
      </div>
    </div>
  </div>
</section>

<section class="section-alt">
  <div class="container">
    <div class="block--tint" data-reveal>
      <h3 style="margin-top:0">Про фасовку и оптовые условия</h3>
      <p style="color:var(--ink-2);line-height:1.6">Лотки отгружаются боксами по 20–60 шт., миски — по 300–600 шт. Оптовая цена в каталоге указана <b>за 1 шт.</b> при заказе целого бокса. Минимальный оптовый заказ — один бокс выбранной позиции. Розничные позиции можно класть в тот же заказ: они считаются отдельной строкой, поэтому общая сумма остаётся прозрачной.</p>
      <p style="color:var(--ink-2);line-height:1.6;margin-bottom:0">Товар не подлежит сертификации, изготовлен из пластика, страна производства — <?= e((string)($s['madeIn'] ?? 'Россия')) ?>. Изделия поставляются без индивидуальной упаковки, цвет и наличие подтверждает менеджер при оформлении заявки.</p>
    </div>
  </div>
</section>

<?php
pl_footer($d);
