<?php
declare(strict_types=1);

require __DIR__ . '/inc/layout.php';

$d    = pl_boot();
$seo  = $d['seo'];
$b    = $d['blocks'];
$h    = $d['home'];
$n    = $d['counts'];
$base = 'https://poliform.pages.dev/';

$heroTitle = blk($b, 'heroTitle', 'Пластиковые изделия для питомцев');
$heroLead  = blk($b, 'heroLead', 'Лотки и туалеты для кошек, миски одинарные и двойные из плотного пластика. Каждая позиция отлита на собственной форме: от 33,5×25 см до 36×26 см, от 200 мл до 2×200 мл. Продаём оптом от одного бокса и в розницу поштучно.');

$heroSku = trim((string)($b['heroVisualSku'] ?? '1430475'));

/* Карусель главной: товары, отмеченные в админке галочкой «Показывать на главной».
   Показывается только первое фото карточки товара. */
$heroDims = static function (array $p): string {
    $out = [];
    foreach (['sizeL', 'sizeW', 'sizeH'] as $k) {
        $v = (float)($p[$k] ?? 0);
        if ($v <= 0) continue;
        $out[] = rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',');
    }
    return implode('×', $out);
};
$heroSlide = static function (array $p) use ($heroDims): array {
    return [
        'sku'  => (string)$p['sku'],
        'img'  => (string)($p['images'][0] ?? ''),
        'name' => (string)($p['shortTitle'] ?: $p['title']),
        'dims' => trim($heroDims($p), '×'),
    ];
};

$slides = [];
foreach ($d['products'] as $p) {
    if (empty($p['onHome'])) continue;
    $s = $heroSlide($p);
    if ($s['img'] === '') continue;
    $slides[] = $s;
}

/* Запасной вариант — товар из настройки «Артикул (карусель)», затем первый товар. */
if (!$slides) {
    foreach ($d['products'] as $p) {
        if ((string)$p['sku'] === $heroSku && !empty($p['images'])) {
            $slides[] = $heroSlide($p);
            break;
        }
    }
}
if (!$slides) {
    foreach ($d['products'] as $p) {
        $s = $heroSlide($p);
        if ($s['img'] === '') continue;
        $slides[] = $s;
        break;
    }
}
$heroImg = $slides[0]['img'] ?? '';
/* Подпись из настроек имеет смысл только для одиночного слайда:
   в карусели подпись должна соответствовать текущему товару. */
$heroCap = (count($slides) === 1 && !empty($b['heroFigcaption'])) ? (string)$b['heroFigcaption'] : '';
$heroAlt = blk($h, 'heroAlt', 'Пластиковое изделие собственного производства');

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
      <span class="eyebrow"><?= e(blk($h, 'heroEyebrow', 'Собственное производство · Смоленская обл.')) ?></span>
      <h1>
        <span class="row"><?= e(blk($h, 'heroH1a', 'Пластиковые изделия')) ?></span>
        <span class="row"><span class="mark mark--tight"><?= e(blk($h, 'heroH1b', 'которые мы делаем сами')) ?></span></span>
      </h1>
      <p class="hero-lead"><?= e($heroLead) ?></p>
      <div class="hero-actions">
        <a class="btn btn-signal" href="products.php"><?= e(blk($h, 'heroBtn1', 'Открыть каталог')) ?></a>
        <a class="btn btn-ghost" href="#how"><?= e(blk($h, 'heroBtn2', 'Как оформить заказ')) ?></a>
      </div>
      <div class="hero-facts" data-home-kpi>
        <div class="hero-fact"><b><?= (int)$n['products'] ?></b><span><?= e(blk($h, 'kpi1', 'артикулов в каталоге')) ?></span></div>
        <div class="hero-fact"><b><?= (int)$n['cats'] ?></b><span><?= e(blk($h, 'kpi2', 'категории изделий')) ?></span></div>
        <div class="hero-fact"><b><?= (int)$n['optMin'] ?></b><span><?= e(blk($h, 'kpi3', '₽ за шт. — мин. опт')) ?></span></div>
        <div class="hero-fact"><b><?= (int)$n['packMin'] ?>–<?= (int)$n['packMax'] ?></b><span><?= e(blk($h, 'kpi4', 'шт. в боксе')) ?></span></div>
      </div>
    </div>
    <?php if ($heroImg !== ''): ?>
    <div class="hero-visual hero-carousel<?= count($slides) > 1 ? ' hero-carousel--multi' : '' ?>"
         data-carousel<?= count($slides) > 1 ? ' data-carousel-autoplay="6000"' : '' ?>>
      <div class="hero-carousel__track">
        <?php foreach ($slides as $i => $s): ?>
        <figure class="hero-carousel__slide<?= $i === 0 ? ' is-active' : '' ?>"
                data-sku="<?= e($s['sku']) ?>"
                data-name="<?= e($s['name']) ?>"
                data-dims="<?= e($s['dims']) ?>"
                <?= $i === 0 ? ' aria-hidden="false"' : ' aria-hidden="true"' ?>>
          <img src="<?= e($s['img']) ?>"
               alt="<?= e($s['name'] !== '' ? $s['name'] : $heroAlt) ?>"
               width="1000" height="1333"
               <?= $i === 0 ? ' fetchpriority="high"' : ' loading="lazy"' ?>>
        </figure>
        <?php endforeach; ?>
      </div>

      <figcaption class="hero-carousel__cap">
        <span class="hero-carousel__name"><?= e($heroCap !== '' ? $heroCap : $slides[0]['name']) ?></span>
        <?php if ($heroCap === '' && $slides[0]['dims'] !== ''): ?>
        <span class="hero-carousel__dims"><?= e($slides[0]['dims']) ?> см</span>
        <?php endif; ?>
      </figcaption>

      <?php if (count($slides) > 1): ?>
      <button class="hero-carousel__nav hero-carousel__nav--prev" type="button"
              data-carousel-prev aria-label="Предыдущий товар">&#8249;</button>
      <button class="hero-carousel__nav hero-carousel__nav--next" type="button"
              data-carousel-next aria-label="Следующий товар">&#8250;</button>
      <div class="hero-carousel__dots" role="tablist" aria-label="Товары на главной">
        <?php foreach ($slides as $i => $s): ?>
        <button class="hero-carousel__dot<?= $i === 0 ? ' is-active' : '' ?>" type="button"
                role="tab" data-carousel-dot="<?= $i ?>"
                aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"
                aria-label="<?= e($s['name'] !== '' ? $s['name'] : ('Товар ' . ($i + 1))) ?>"
                title="<?= e($s['sku']) ?>"></button>
        <?php endforeach; ?>
      </div>
      <a class="hero-carousel__link" href="product.php?sku=<?= e(urlencode($slides[0]['sku'])) ?>"
         data-carousel-link>Подробнее</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</section>

<section id="catalog">
  <div class="container">
    <div class="sec-head" data-reveal>
      <div>
        <span class="eyebrow"><?= e(blk($h, 'catEyebrow', 'Ассортимент')) ?></span>
        <h2 class="sec-title"><?= e(blk($h, 'catTitleA', 'Производственные')) ?> <em><?= e(blk($h, 'catTitleB', 'линейки')) ?></em></h2>
        <p class="sec-sub"><?= e(blk($h, 'catSub', 'Каждая категория — своя пресс-форма и своя фасовка.')) ?></p>
      </div>
    </div>
    <div class="cat-grid" data-home-cats></div>
  </div>
</section>

<section>
  <div class="container">
    <div class="about-band" data-reveal>
      <div>
        <span class="eyebrow"><?= e(blk($h, 'aboutEyebrow', 'О производстве')) ?></span>
        <h2 class="sec-title"><?= e(blk($h, 'aboutTitleA', 'Собственное производство')) ?> <em><?= e(blk($h, 'aboutTitleB', 'в Смоленской области')) ?></em></h2>
      </div>
      <p><?= e(blk($b, 'homeAbout', 'Полиформ — производство пластмассовых изделий в Смоленской области. Лотки, туалеты и миски отливаются на собственных пресс-формах, поэтому размеры и толщина стенок повторяются от партии к партии.')) ?></p>
      <a class="btn btn-ghost" href="about.php"><?= e(blk($h, 'aboutBtn', 'Подробнее о компании')) ?></a>
    </div>
  </div>
</section>

<section class="section-alt">
  <div class="container">
    <div class="sec-head" data-reveal>
      <div>
        <span class="eyebrow"><?= e(blk($h, 'popEyebrow', 'Витрина')) ?></span>
        <h2 class="sec-title"><?= e(blk($h, 'popTitleA', 'Популярные')) ?> <em><?= e(blk($h, 'popTitleB', 'позиции')) ?></em></h2>
        <p class="sec-sub"><?= e(blk($h, 'popSub', 'Цены переключаются вместе с режимом в шапке.')) ?></p>
      </div>
      <a class="btn btn-ghost" href="products.php"><?= e(blk($h, 'popBtnAll', 'Весь каталог')) ?> · <?= (int)$n['products'] ?> позиций</a>
    </div>
    <div class="shop-grid" data-home-grid></div>
  </div>
</section>

<section id="how">
  <div class="container">
    <div class="sec-head" data-reveal>
      <div>
        <span class="eyebrow"><?= e(blk($h, 'howEyebrow', 'Опт и розница')) ?></span>
        <h2 class="sec-title"><?= e(blk($h, 'howTitleA', 'Как устроена')) ?> <em><?= e(blk($h, 'howTitleB', 'продажа')) ?></em></h2>
        <p class="sec-sub"><?= e(blk($h, 'howSub', blk($b, 'howToOrder', 'Мы отгружаем и по одной штуке, и целыми боксами.'))) ?></p>
      </div>
    </div>
    <div class="steps">
      <div class="block--tint" data-reveal>
        <span class="step-num">1</span>
        <h3><?= e(blk($h, 'step1Title', 'Выберите режим')) ?></h3>
        <p><?= e(blk($h, 'step1Text', 'Переключатель в шапке меняет все цены на сайте.')) ?></p>
      </div>
      <div class="block--tint" data-reveal>
        <span class="step-num">2</span>
        <h3><?= e(blk($h, 'step2Title', 'Соберите заказ')) ?></h3>
        <p><?= e(blk($h, 'step2Text', 'Кладёте позиции в корзину в нужном режиме.')) ?></p>
      </div>
      <div class="block--tint" data-reveal>
        <span class="step-num">3</span>
        <h3><?= e(blk($h, 'step3Title', 'Отправьте заявку')) ?></h3>
        <p><?= e(blk($h, 'step3Text', 'В корзине заполните имя и телефон.')) ?></p>
      </div>
    </div>

    <div class="block--tint" style="margin-top:34px" data-reveal>
      <h3><?= e(blk($h, 'boxesTitle', 'Почему опт считается боксами')) ?></h3>
      <p style="color:var(--ink-2);line-height:1.6;margin-bottom:0"><?= e(blk($h, 'boxesText', 'Изделия отгружаются в гофрокоробах.')) ?></p>
    </div>
  </div>
</section>

<section id="contacts">
  <div class="container">
    <div class="sec-head" data-reveal>
      <div>
        <span class="eyebrow"><?= e(blk($h, 'ctaEyebrow', 'Контакты')) ?></span>
        <h2 class="sec-title"><?= e(blk($h, 'ctaTitleA', 'Заявка')) ?> <em><?= e(blk($h, 'ctaTitleB', 'на партию')) ?></em></h2>
        <p class="sec-sub"><?= e(blk($h, 'ctaSub', 'Пришлите перечень артикулов с количеством в боксах.')) ?></p>
      </div>
    </div>

    <?php
    pl_contact_block(
        $d,
        'cart.php#order',
        blk($h, 'ctaBtn1', 'Оформить заявку'),
        (string)($d['contacts']['telegram'] ?? ''),
        blk($h, 'ctaBtn2', 'Написать в Telegram')
    );
    ?>
  </div>
</section>

<?php
pl_footer($d);
