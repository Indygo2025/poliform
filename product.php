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

<section>
  <div class="container">
    <nav class="crumbs" aria-label="Хлебные крошки">
      <a href="index.php">Главная</a> /
      <a href="products.php">Каталог</a> /
      <a href="products.php?cat=<?= rawurlencode((string)$p['categoryId']) ?>"><?= e((string)($cat['short'] ?? $cat['name'] ?? '')) ?></a> /
      <span><?= e((string)$p['shortTitle']) ?></span>
    </nav>

    <div data-product><?php /* наполняет shop.js; без JS покажем запасной блок */ ?>
      <div class="product-layout">
        <div class="gallery">
          <div class="gallery__main">
            <img src="<?= e((string)($p['images'][0] ?? '')) ?>" alt="<?= e((string)$p['title']) ?>" width="1000" height="1333">
          </div>
        </div>
        <div>
          <h1 class="product-title"><?= e((string)$p['title']) ?></h1>
          <p class="product-desc"><?= e((string)$p['description']) ?></p>
          <p><a class="btn" href="cart.php#order">Уточнить наличие</a></p>
        </div>
      </div>
    </div>

    <noscript>
      <div class="block block--tint" style="margin-top:30px">
        <h2>Характеристики</h2>
        <table class="spec-table">
          <tr><th>Артикул</th><td><?= e((string)$p['sku']) ?></td></tr>
          <tr><th>Габариты</th><td><?= e((string)$p['sizeL']) ?>×<?= e((string)$p['sizeW']) ?>×<?= e((string)$p['sizeH']) ?> см</td></tr>
          <tr><th>Розница</th><td><?= e((string)$p['priceRetail']) ?> ₽ / шт.</td></tr>
          <tr><th>Опт</th><td><?= e((string)$p['priceOpt']) ?> ₽ / шт. при заказе бокса <?= (int)$p['packCount'] ?> шт.</td></tr>
        </table>
      </div>
    </noscript>
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
