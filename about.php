<?php
declare(strict_types=1);

require __DIR__ . '/inc/layout.php';

$d     = pl_boot();
$base  = 'https://poliform.pages.dev/';
$b     = $d['blocks'];
$s     = $d['site'];

$about = blk($b, 'aboutText', 'Полиформ — производство пластмассовых изделий в Смоленской области. Лотки, туалеты и миски отливаются на собственных пресс-формах, поэтому размеры, толщина стенок и цвет повторяются от партии к партии.');

pl_head($d, [
    'title' => 'О компании — Полиформ | Собственное производство пластиковых изделий',
    'description' => 'ООО «ПОЛИФОРМ КОМПАНИ», Смоленская область — собственное производство пластиковых изделий: лотки и туалеты для кошек, миски одинарные и двойные.',
    'base'  => $base,
]);
pl_header($d, 'about');
pl_breadcrumbs_jsonld($base, ['Главная' => 'index.php', 'О компании' => 'about.php']);
?>

<section class="page-top">
  <div class="container">
    <nav class="crumbs" aria-label="Хлебные крошки">
      <a href="index.php">Главная</a> / <span>О компании</span>
    </nav>
    <div class="sec-head">
      <div>
        <span class="eyebrow">Производитель</span>
        <h1 class="sec-title" style="font-size:clamp(1.8rem,4vw,2.6rem)">Мы делаем пластик, который <em>работает каждый день</em></h1>
        <p class="sec-sub"><?= e(blk($b, 'heroLead', 'Лотки и туалеты для кошек, миски одинарные и двойные из плотного пластика. Каждая позиция отлита на собственной форме.')) ?></p>
      </div>
    </div>
  </div>
</section>

<section>
  <div class="container">
    <div class="sec-head" data-reveal>
      <div>
        <span class="eyebrow">Что именно мы производим</span>
        <h2 class="sec-title">Ассортимент <em>из одной компетенции</em></h2>
        <p class="sec-sub">Весь каталог — изделия из пластика с понятной геометрией: лотки с бортами и съёмной сеткой, округлые компактные формы, а также миски для корма и воды. Ассортимент держим сфокусированным, чтобы качество оставалось стабильным.</p>
      </div>
    </div>
    <div class="cards">
      <article class="card" data-reveal>
        <div class="card-body">
          <h3>Лотки и туалеты прямоугольные</h3>
          <p>Средние и глубокие лотки для кошек: со съёмной сеткой и различной высотой борта. Сетка поднимает наполнитель над поддоном на 0,8 см — лапы остаются сухими, а расход наполнителя снижается.</p>
          <ul class="card-specs">
            <li>36×26×6,5 см</li>
            <li>36×25×9 см</li>
            <li>Бокс 20–24 шт.</li>
          </ul>
        </div>
      </article>
      <article class="card" data-reveal>
        <div class="card-body">
          <h3>Лотки округлые компактные</h3>
          <p>Меньший формат со скруглёнными стенками — помещается под раковину, в нишу или на балконе. Двухцветное исполнение, съёмная сетка с тем же зазором 0,8 см.</p>
          <ul class="card-specs">
            <li>33,5×25×6 см</li>
            <li>Скруглённая форма</li>
            <li>Бокс 50 шт.</li>
          </ul>
        </div>
      </article>
      <article class="card" data-reveal>
        <div class="card-body">
          <h3>Миски одинарные и двойные</h3>
          <p>Миска «Нулевка» 200 мл, двойная миска 2×200 мл. Устойчивая форма, подходящая для кошек, мелких собак, грызунов и птиц.</p>
          <ul class="card-specs">
            <li>200 мл и 2×200 мл</li>
            <li>Бокс 300–600 шт.</li>
            <li>Устойчивая, прочная</li>
          </ul>
        </div>
      </article>
    </div>
  </div>
</section>

<section class="section-alt">
  <div class="container">
    <div class="sec-head" data-reveal>
      <div>
        <span class="eyebrow">О компании</span>
        <h2 class="sec-title">Собственное <em>производство</em></h2>
      </div>
    </div>
    <div class="prose" data-reveal>
      <?= nl2br_e($about) ?>
    </div>
  </div>
</section>

<section>
  <div class="container">
    <div class="sec-head" data-reveal>
      <div>
        <span class="eyebrow">Реквизиты и контакты</span>
        <h2 class="sec-title">Как с нами <em>связаться</em></h2>
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

<section>
  <div class="container">
    <div class="cta" data-reveal>
      <div>
        <h2>Нужна партия под ваш объём?</h2>
        <p>Пришлите перечень артикулов с количеством в боксах — посчитаем партию, предложим формат отгрузки и зафиксируем цену.</p>
      </div>
      <div class="cta-actions">
        <a class="btn" href="cart.php#order">Оптовая партия</a>
        <?php if (!empty($d['contacts']['phoneHref'])): ?>
        <a class="btn btn-ghost" href="tel:<?= e((string)$d['contacts']['phoneHref']) ?>">Позвонить в отдел продаж</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<?php
pl_footer($d);
