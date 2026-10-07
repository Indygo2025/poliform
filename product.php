<?php
declare(strict_types=1);

require __DIR__ . '/inc/layout.php';

$d     = pl_boot();
$base  = 'https://poliform.pages.dev/';
$sku   = trim((string)($_GET['sku'] ?? ''));
$catBy = [];
foreach ($d['categories'] as $c) $catBy[(string)$c['id']] = $c;

$product = null;
if ($sku !== '') {
    foreach ($d['products'] as $p) {
        if ((string)$p['sku'] === $sku) { $product = $p; break; }
    }
}

if ($product === null) {
    http_response_code(404);
    pl_head($d, [
        'title' => 'Артикул не найден — Полиформ',
        'description' => 'Запрошенный артикул не найден в каталоге.',
        'base'  => $base,
    ]);
    pl_header($d, 'products');
    echo '<section><div class="container">';
    echo '<nav class="crumbs" aria-label="Хлебные крошки"><a href="index.php">Главная</a> / <a href="products.php">Каталог</a> / <span>Не найдено</span></nav>';
    echo '<div class="empty-state"><b>Артикул не найден</b><span>Вернитесь в каталог и выберите позицию.</span>';
    echo '<a class="btn" href="products.php" style="margin-top:16px">В каталог</a></div>';
    echo '</div></section>';
    pl_footer($d);
    exit;
}

$p     = $product;
$cat   = $catBy[(string)$p['categoryId']] ?? null;
$site     = (array)($d['site'] ?? []);
$contacts = (array)($d['contacts'] ?? []);
$order    = (array)($d['order'] ?? []);
$title = (string)$p['title'] . ' — Полиформ';
$desc  = mb_substr((string)$p['description'], 0, 300, 'UTF-8');

pl_head($d, ['title' => $title, 'description' => $desc, 'base' => $base]);
pl_header($d, 'products');
pl_breadcrumbs_jsonld($base, [
    'Главная'  => 'index.php',
    'Каталог'  => 'products.php',
    (string)($cat['short'] ?? $cat['name'] ?? 'Каталог') => 'products.php?cat=' . rawurlencode((string)$p['categoryId']),
]);
pl_product_jsonld($p, $base);
?>

<section class="page-top">
  <div class="container">
    <nav class="crumbs" aria-label="Хлебные крошки">
      <a href="index.php">Главная</a> /
      <a href="products.php">Каталог</a> /
      <a href="products.php?cat=<?= rawurlencode((string)$p['categoryId']) ?>"><?= e((string)($cat['short'] ?? $cat['name'] ?? '')) ?></a> /
      <span><?= e((string)$p['shortTitle']) ?></span>
    </nav>

    <div class="sec-head">
      <div>
        <span class="eyebrow">Товар №<?= e((string)$p['sku']) ?></span>
        <div class="page-top__title"><?= e((string)$p['shortTitle']) ?></div>
      </div>
    </div>

    <div data-product><?php /* наполняет shop.js; без JS покажем запасной блок */ ?>
      <div class="product-layout">
        <div class="gallery">
          <div class="gallery__main">
            <img src="<?= e((string)($p['images'][0] ?? '')) ?>" alt="<?= e((string)$p['title']) ?>" width="1000" height="1333">
          </div>
          <?php if (count($p['images']) > 1): ?>
          <div class="gallery__thumbs">
            <?php foreach ($p['images'] as $src): ?>
            <img src="<?= e($src) ?>" alt="" loading="lazy">
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>

        <div class="product-info">
          <div class="product-head">
            <h1 class="product-title"><?= e((string)$p['title']) ?></h1>
            <div class="product-sku">Артикул <b><?= e((string)$p['sku']) ?></b><?= (int)$p['inStock'] === 0 ? ' <span class="pbadge pbadge--out">Под заказ</span>' : '' ?></div>
            <p class="product-desc"><?= e((string)$p['description']) ?></p>
          </div>

          <div class="quick-specs">
            <div class="quick-specs__row"><span class="q-key">Габариты</span><span class="q-dots"></span><span class="q-val"><?= e((string)$p['sizeL']) ?>×<?= e((string)$p['sizeW']) ?>×<?= e((string)$p['sizeH']) ?> см</span></div>
            <div class="quick-specs__row"><span class="q-key">Материал</span><span class="q-dots"></span><span class="q-val"><?= e((string)$p['material']) ?></span></div>
            <div class="quick-specs__row"><span class="q-key">Фасовка</span><span class="q-dots"></span><span class="q-val">в боксе <?= (int)$p['packCount'] ?> шт.</span></div>
          </div>

          <div class="product-specs">
            <div class="block block--tint">
              <h2>Характеристики</h2>
              <table class="spec-table">
                <tr><th>Артикул</th><td><?= e((string)$p['sku']) ?></td></tr>
                <tr><th>Габариты</th><td><?= e((string)$p['sizeL']) ?>×<?= e((string)$p['sizeW']) ?>×<?= e((string)$p['sizeH']) ?> см</td></tr>
                <tr><th>Фасовка</th><td>по 1 шт., в боксе <?= (int)$p['packCount'] ?> шт.</td></tr>
                <tr><th>Материал</th><td><?= e((string)$p['material']) ?></td></tr>
                <tr><th>Страна производства</th><td><?= e((string)$p['country']) ?></td></tr>
              </table>
            </div>
          </div>
        </div>

        <div class="product-buy">
          <div class="product-price-box">
            <div class="price-now">
              <b><?= e((string)$p['priceRetail']) ?> ₽</b><span>/ шт.</span>
            </div>
            <div class="price-was">Опт от <?= e((string)$p['priceOpt']) ?> ₽ / шт. при заказе бокса <?= (int)$p['packCount'] ?> шт.</div>
            <div class="pcard__unit" style="margin-top:8px"><b>1 шт.</b> — фасовка по 1 шт., в боксе <?= (int)$p['packCount'] ?> шт.</div>
            <p class="ppb__note">Включите JavaScript, чтобы выбрать количество и добавить в корзину.</p>
            <p><a class="btn btn-signal" href="cart.php#order">Оформить заявку</a></p>
          </div>

          <div class="buy-facts">
            <div class="buy-facts__row"><div>
              <div class="buy-facts__t">Доставка</div>
              <div class="buy-facts__d"><?= e((string)($order['lead'] ?? 'Стоимость доставки рассчитывает менеджер.')) ?></div>
            </div></div>
            <div class="buy-facts__row"><div>
              <div class="buy-facts__t">Продавец</div>
              <div class="buy-facts__d"><?= e((string)($site['company'] ?? 'ООО «ПОЛИФОРМ КОМПАНИ»')) ?> · собственное производство, <?= e((string)($site['madeIn'] ?? 'Россия')) ?></div>
            </div></div>
            <div class="buy-facts__row"><div>
              <div class="buy-facts__t">Оплата</div>
              <div class="buy-facts__d">По договору или по счёту для юрлиц.</div>
            </div></div>
            <div class="buy-facts__row"><div>
              <div class="buy-facts__t">График работы</div>
              <div class="buy-facts__d"><?= e(trim(($contacts['hours'] ?? '') . ' · ' . ($contacts['phone'] ?? ''), ' ·')) ?></div>
            </div></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="section-alt">
  <div class="container">
    <div class="sec-head" data-reveal>
      <div>
        <span class="eyebrow">Ещё в каталоге</span>
        <h2 class="sec-title">Смотрите <em>также</em></h2>
      </div>
      <a class="btn btn-ghost" href="products.php">Весь каталог</a>
    </div>
    <div class="shop-grid" data-home-grid data-limit="4"></div>
  </div>
</section>

<?php
pl_footer($d);
