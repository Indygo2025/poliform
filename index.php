<?php
declare(strict_types=1);

require __DIR__ . '/inc/layout.php';

$d    = pl_boot();
$seo  = $d['seo'];
$b    = $d['blocks'];
$n    = $d['counts'];
$base = 'https://poliform.pages.dev/';

$heroTitle = blk($b, 'heroTitle', 'Пластиковые изделия для питомцев');
$heroLead  = blk($b, 'heroLead', 'Лотки и туалеты для кошек, миски одинарные и двойные из плотного пластика. Каждая позиция отлита на собственной форме: от 33,5×25 см до 36×26 см, от 200 мл до 2×200 мл. Продаём оптом от одного бокса и в розницу поштучно.');

$heroSku = trim((string)($b['heroVisualSku'] ?? '1430475'));
$heroImg = null;
$heroCap = '';
foreach ($d['products'] as $p) {
    if ((string)$p['sku'] === $heroSku && !empty($p['images'])) {
        $heroImg = (string)$p['images'][0];
        $heroCap = 'Арт. ' . $p['sku'] . ' · ' . $p['sizeL'] . '×' . $p['sizeW'] . '×' . $p['sizeH'] . ' см';
        break;
    }
}
if ($heroImg === null && $d['products']) {
    $p0 = $d['products'][0];
    $heroImg = (string)($p0['images'][0] ?? '');
    $heroCap = 'Арт. ' . $p0['sku'];
}
if (!empty($b['heroFigcaption'])) $heroCap = (string)$b['heroFigcaption'];

$title = (string)($seo['title'] ?? 'Полиформ');
$desc  = (string)($seo['description'] ?? '');

pl_head($d, [
    'title'       => $title,
    'description' => $desc,
    'base'        => $base,
]);
pl_header($d, 'index');
?>

<section class="hero">
  <div class="container hero-grid">
    <div>
      <span class="eyebrow">Собственное производство · Смоленская обл.</span>
      <h1>
        <span class="row">Пластиковые изделия</span>
        <span class="row"><span class="mark mark--tight">которые мы делаем сами</span></span>
      </h1>
      <p class="hero-lead"><?= e($heroLead) ?></p>
      <div class="hero-actions">
        <a class="btn btn-signal" href="products.php">Открыть каталог</a>
        <a class="btn btn-ghost" href="#how">Как оформить заказ</a>
      </div>
      <div class="hero-facts" data-home-kpi>
        <div class="hero-fact"><b><?= (int)$n['products'] ?></b><span>артикулов в каталоге</span></div>
        <div class="hero-fact"><b><?= (int)$n['cats'] ?></b><span>категории изделий</span></div>
        <div class="hero-fact"><b><?= (int)$n['optMin'] ?></b><span>₽ за шт. — мин. опт</span></div>
        <div class="hero-fact"><b><?= (int)$n['packMin'] ?>–<?= (int)$n['packMax'] ?></b><span>шт. в боксе</span></div>
      </div>
    </div>
    <?php if ($heroImg !== ''): ?>
    <div class="hero-visual">
      <img src="<?= e($heroImg) ?>" alt="<?= e($heroCap !== '' ? $heroCap : 'Пластиковое изделие собственного производства') ?>" width="1000" height="1333" fetchpriority="high">
      <?php if ($heroCap !== ''): ?><figcaption><?= e($heroCap) ?></figcaption><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</section>

<section id="catalog">
  <div class="container">
    <div class="sec-head" data-reveal>
      <div>
        <span class="eyebrow">Ассортимент</span>
        <h2 class="sec-title">Производственные <em>линейки</em></h2>
        <p class="sec-sub">Каждая категория — своя пресс-форма и своя фасовка. Внутри категории позиции отличаются размером, высотой борта и цветом, поэтому удобнее выбирать по артикулу.</p>
      </div>
    </div>
    <div class="cat-grid" data-home-cats></div>
  </div>
</section>

<section class="section-alt">
  <div class="container">
    <div class="sec-head" data-reveal>
      <div>
        <span class="eyebrow">Витрина</span>
        <h2 class="sec-title">Популярные <em>позиции</em></h2>
        <p class="sec-sub">Цены переключаются вместе с режимом в шапке. В оптовом режиме шаг количества равен боксу — добавить можно только целое число коробов.</p>
      </div>
      <a class="btn btn-ghost" href="products.php">Весь каталог · <?= (int)$n['products'] ?> позиций</a>
    </div>
    <div class="shop-grid" data-home-grid></div>
  </div>
</section>

<section id="how">
  <div class="container">
    <div class="sec-head" data-reveal>
      <div>
        <span class="eyebrow">Опт и розница</span>
        <h2 class="sec-title">Как устроена <em>продажа</em></h2>
        <p class="sec-sub">Мы отгружаем и по одной штуке, и целыми боксами. Разница только в цене и кратности — сам порядок одинаковый.</p>
      </div>
    </div>
    <div class="steps">
      <div class="block--tint" data-reveal>
        <span class="step-num">1</span>
        <h3>Выберите режим</h3>
        <p>Переключатель в шапке меняет все цены на сайте: <b>розница</b> — шаг 1 шт., <b>опт</b> — шаг равен фасовке бокса. Выбор сохраняется при переходе между страницами.</p>
      </div>
      <div class="block--tint" data-reveal>
        <span class="step-num">2</span>
        <h3>Соберите заказ</h3>
        <p>Кладёте позиции в корзину в нужном режиме. Можно смешивать: например, пять боксов лотков и отдельно розничные миски — итог посчитается двумя строками.</p>
      </div>
      <div class="block--tint" data-reveal>
        <span class="step-num">3</span>
        <h3>Отправьте заявку</h3>
        <p>В корзине заполните имя и телефон. Заявка попадает менеджеру, он подтверждает наличие, считает доставку и присылает счёт. Оплата — по договору или по счёту для юрлиц.</p>
      </div>
    </div>

    <div class="block--tint" style="margin-top:34px" data-reveal>
      <h3>Почему опт считается боксами</h3>
      <p style="color:var(--ink-2);line-height:1.6;margin-bottom:0">Изделия отгружаются в гофрокоробах: лотки — по 20–60 шт., миски — по 300–600 шт. Оптовая цена действует от одного полного бокса, поэтому количество в оптовом режиме автоматически округляется вверх до кратного фасовке. Так вы всегда получаете ровно закрытую партию без «неполных коробок» на складе.</p>
    </div>
  </div>
</section>

<section id="contacts">
  <div class="container">
    <div class="sec-head" data-reveal>
      <div>
        <span class="eyebrow">Контакты</span>
        <h2 class="sec-title">Заявка <em>на партию</em></h2>
        <p class="sec-sub">Пришлите перечень артикулов с количеством в боксах — ответим по наличию, срокам и стоимости доставки.</p>
      </div>
    </div>

    <?php
    pl_contact_block(
        $d,
        'cart.php#order',
        'Оформить заявку',
        (string)($d['contacts']['telegram'] ?? ''),
        'Написать в Telegram'
    );
    ?>
  </div>
</section>

<?php
pl_footer($d);
