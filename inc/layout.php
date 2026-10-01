<?php
declare(strict_types=1);

/**
 * ПОЛИФОРМ — общий каркас страниц.
 * Подключается витриной: require __DIR__ . '/inc/layout.php';
 * Подставляет контакты, реквизиты и SEO из настроек SQLite.
 */

require_once __DIR__ . '/../db.php';

function pl_boot(): array {
    $db = db();
    $s  = dbAllSettings($db);
    $site     = (array)($s['site'] ?? []);
    $contacts = (array)($s['contacts'] ?? []);
    $seo      = (array)($s['seo'] ?? []);
    $blocks   = (array)($s['blocks'] ?? []);
    $home     = (array)($s['home'] ?? []);
    $order    = (array)($s['order'] ?? []);

    $products = dbAllProducts($db);
    $cats     = dbAllCategories($db);

    $packs = array_map('intval', array_column($products, 'packCount'));
    $opts  = array_map('floatval', array_column($products, 'priceOpt'));

    return [
        'site'     => $site,
        'contacts' => $contacts,
        'seo'      => $seo,
        'blocks'   => $blocks,
        'home'     => $home,
        'order'    => $order,
        'products' => $products,
        'categories' => $cats,
        'counts'   => [
            'products' => count($products),
            'cats'     => count($cats),
            'packMin'  => $packs ? min($packs) : 0,
            'packMax'  => $packs ? max($packs) : 0,
            'optMin'   => $opts ? (int)min($opts) : 0,
        ],
    ];
}

function e(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function nl2br_e(?string $s): string {
    return nl2br(e($s), false);
}

/** Значение блока с запасным вариантом из старой вёрстки. */
function blk(array $blocks, string $key, string $fallback = ''): string {
    $v = trim((string)($blocks[$key] ?? ''));
    return $v !== '' ? $v : $fallback;
}

function pl_og_placeholder(string $base): string {
    return $base;
}

/* ------------------------------------------------------------------ шапка */

function pl_head(array $d, array $opts = []): void {
    $site = $d['site'];
    $seo  = $d['seo'];
    $title = (string)($opts['title'] ?? $seo['title'] ?? 'Полиформ');
    $desc  = (string)($opts['description'] ?? $seo['description'] ?? '');
    $og    = (string)($seo['ogImage'] ?? 'images/products/1430471-0.jpg');
$keys  = trim((string)($seo['keywords'] ?? ''));
$ogTitle = trim((string)($opts['ogTitle'] ?? ''));
if ($ogTitle === '') $ogTitle = trim((string)($d['blocks']['heroTitle'] ?? ''));
    $base  = pl_og_placeholder(rtrim((string)($opts['base'] ?? 'https://poliform.pages.dev/'), '/') . '/');
    ?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<?php if ($desc !== ''): ?>
<meta name="description" content="<?= e($desc) ?>">
<?php endif; ?>
<?php if ($keys !== ''): ?>
<meta name="keywords" content="<?= e($keys) ?>">
<?php endif; ?>
<link rel="icon" href="images/favicon.svg" type="image/svg+xml">
<meta name="theme-color" content="#e7e5e0">
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($ogTitle !== '' ? $ogTitle : $title) ?>">
<?php if ($desc !== ''): ?>
<meta property="og:description" content="<?= e($desc) ?>">
<?php endif; ?>
<meta property="og:image" content="<?= e($base . ltrim($og, '/')) ?>">
<meta property="og:url" content="<?= e($base) ?>">
<meta property="og:locale" content="ru_RU">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/style.css">
<link rel="stylesheet" href="css/plastic-live.css">
<link rel="stylesheet" href="css/shop.css">
</head>
<body>

<div class="progress" role="presentation"></div>
<?php
}

function pl_header(array $d, string $active = ''): void {
    $c = $d['contacts'];
    $phoneText = (string)($c['phone'] ?? '');
    $phoneHref = (string)($c['phoneHref'] ?? '');
    $pages = [
        'index'    => ['index.php', 'Главная'],
        'products' => ['products.php', 'Каталог'],
        'about'    => ['about.php', 'О компании'],
        'cart'     => ['cart.php', 'Заявка'],
    ];
    ?>
<header class="header">
  <div class="container header-inner">
    <a class="logo" href="index.php">
      <span class="logo-mark">
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 19 L12 4 L20 19 Z" stroke="#161616" stroke-width="2.6" stroke-linejoin="miter"/></svg>
      </span>
      <span class="logo-text">
        <span class="logo-name">ПОЛИФОРМ</span>
        <span class="logo-sub"><?= e(blk($d['blocks'], 'tagline', 'пластиковые изделия · опт и розница')) ?></span>
      </span>
    </a>
    <button class="burger" type="button" aria-label="Меню" aria-expanded="false" aria-controls="nav"><span></span><span></span><span></span></button>
    <nav class="nav" id="nav">
      <?php foreach ($pages as $key => [$href, $label]): ?>
      <a href="<?= e($href) ?>"<?= $key === $active ? ' class="active"' : '' ?>><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <a class="cart-btn" href="cart.php" aria-label="Корзина">
      <svg viewBox="0 0 24 24" width="17" height="17" fill="none" aria-hidden="true"><path d="M3 4h2l2.4 11.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.5L21 8H6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="10" cy="20" r="1.5" fill="currentColor"/><circle cx="17" cy="20" r="1.5" fill="currentColor"/></svg>
      Корзина <span class="cart-btn__count">0</span>
    </a>
    <div class="header-tel">
      <?php if ($phoneText !== ''): ?><b><?= e($phoneText) ?></b><?php endif; ?>
      <span>заявки · опт</span>
    </div>
  </div>
</header>

<div class="pricing-bar">
  <div class="container">
    <span class="pricing-bar__label">Режим цен</span>
    <div class="pricing-switch" data-mode-switch role="group" aria-label="Режим цены: розница или опт">
      <button type="button" data-mode="ret" aria-pressed="true">Розница · от 1 шт.</button>
      <button type="button" data-mode="opt" aria-pressed="false">Опт · от 1 бокса</button>
    </div>
    <p class="pricing-bar__note">Розничный режим: <b>шаг = 1 шт.</b> Цена за 1 шт. Оптовая цена — в переключателе.</p>
  </div>
</div>

<main>
<?php
}

/* ------------------------------------------------------------------ подвал */

function pl_footer(array $d): void {
    $c = $d['contacts'];
    $s = $d['site'];
    $n = $d['counts'];
    $tg = (string)($c['telegram'] ?? '');
    $tgLabel = (string)($c['tgLabel'] ?? 'Telegram');
    ?>
</main>

<footer class="footer">
  <div class="container">
    <div class="footer-grid">
      <div>
        <a class="logo" href="index.php">
          <span class="logo-mark"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 19 L12 4 L20 19 Z" stroke="#161616" stroke-width="2.6" stroke-linejoin="miter"/></svg></span>
          <span class="logo-text"><span class="logo-name">ПОЛИФОРМ</span><span class="logo-sub"><?= e(blk($d['blocks'], 'tagline', 'пластиковые изделия · опт и розница')) ?></span></span>
        </a>
        <p class="footer-about"><?= e(blk($d['blocks'], 'footerAbout', 'Собственное производство пластиковых изделий: лотки и туалеты для кошек, миски одинарные и двойные. Опт от одного бокса и розница поштучно.')) ?></p>
      </div>
      <div>
        <h4>Разделы</h4>
        <div class="footer-links">
          <a href="index.php">Главная</a>
          <a href="products.php">Каталог</a>
          <a href="cart.php">Корзина и заявка</a>
          <a href="about.php">О компании</a>
        </div>
      </div>
      <div>
        <h4>Связь</h4>
        <div class="footer-links">
          <?php if (!empty($c['phoneHref'])): ?><a href="tel:<?= e((string)$c['phoneHref']) ?>"><?= e((string)($c['phone'] ?? '')) ?></a><?php endif; ?>
          <?php if (!empty($c['email'])): ?><a href="mailto:<?= e((string)$c['email']) ?>"><?= e((string)$c['email']) ?></a><?php endif; ?>
          <?php if ($tg !== ''): ?><a href="<?= e($tg) ?>" rel="nofollow noopener" target="_blank">Telegram <?= e($tgLabel) ?></a><?php endif; ?>
        </div>
      </div>
    </div>
    <div class="footer-bottom">
      <span>© <?= e((string)($s['company'] ?? '')) ?><?= !empty($s['ogrn']) ? ' · ОГРН ' . e((string)$s['ogrn']) : '' ?></span>
      <span><?= (int)$n['products'] ?> артикулов · <?= (int)$n['cats'] ?> категории</span>
      <span>Сделано без CMS</span>
    </div>
  </div>
</footer>

<script src="js/main.js"></script>
<script src="js/plastic-live.js"></script>
<script src="js/shop.js"></script>
<script src="js/home.js"></script>
</body>
</html>
<?php
}

/* ------------------------------------------------------- блок контактов */

function pl_contact_block(array $d, string $primaryHref, string $primaryLabel, string $secondaryHref = '', string $secondaryLabel = ''): void {
    $c = $d['contacts'];
    $s = $d['site'];
    $tg = (string)($c['telegram'] ?? '');
    $tgLabel = (string)($c['tgLabel'] ?? 'Telegram');
    ?>
    <div class="contact-grid">
      <div class="contact-card" data-reveal>
        <h3>Отдел продаж</h3>
        <dl class="contact-list">
          <?php if (!empty($c['phoneHref'])): ?>
          <div class="contact-row"><dt>Телефон</dt><dd><a href="tel:<?= e((string)$c['phoneHref']) ?>"><?= e((string)($c['phone'] ?? '')) ?></a></dd></div>
          <?php endif; ?>
          <?php if ($tg !== ''): ?>
          <div class="contact-row"><dt>Заявки в Telegram</dt><dd><a href="<?= e($tg) ?>" rel="nofollow noopener" target="_blank"><?= e($tgLabel) ?></a></dd></div>
          <?php endif; ?>
          <?php if (!empty($c['email'])): ?>
          <div class="contact-row"><dt>E-mail</dt><dd><a href="mailto:<?= e((string)$c['email']) ?>"><?= e((string)$c['email']) ?></a></dd></div>
          <?php endif; ?>
          <?php if (!empty($c['hours'])): ?>
          <div class="contact-row"><dt>Часы работы</dt><dd><?= e((string)$c['hours']) ?></dd></div>
          <?php endif; ?>
          <?php if (!empty($s['address'])): ?>
          <div class="contact-row"><dt>Адрес</dt><dd><?= e((string)$s['address']) ?></dd></div>
          <?php endif; ?>
        </dl>
        <div class="contact-actions">
          <a class="btn btn-signal" href="<?= e($primaryHref) ?>"><?= e($primaryLabel) ?></a>
          <?php if ($secondaryHref !== ''): ?>
          <a class="btn btn-ghost btn-sm" href="<?= e($secondaryHref) ?>"<?= str_starts_with($secondaryHref, 'http') ? ' rel="nofollow noopener" target="_blank"' : '' ?>><?= e($secondaryLabel) ?></a>
          <?php endif; ?>
        </div>
      </div>

      <div class="requisites" data-reveal>
        <h3>Реквизиты</h3>
        <dl>
          <dt>Полное</dt><dd><?= e((string)($s['company'] ?? '')) ?></dd>
          <?php if (!empty($s['ogrn'])): ?><dt>ОГРН</dt><dd><?= e((string)$s['ogrn']) ?></dd><?php endif; ?>
          <?php if (!empty($s['inn'])): ?><dt>ИНН</dt><dd><?= e((string)$s['inn']) ?></dd><?php endif; ?>
          <?php if (!empty($s['kpp'])): ?><dt>КПП</dt><dd><?= e((string)$s['kpp']) ?></dd><?php endif; ?>
          <?php if (!empty($s['okved'])): ?><dt>ОКВЭД</dt><dd><?= e((string)$s['okved']) ?></dd><?php endif; ?>
          <?php if (!empty($s['address'])): ?><dt>Юр. адрес</dt><dd><?= nl2br_e((string)$s['address']) ?></dd><?php endif; ?>
          <?php if (!empty($s['director'])): ?><dt>Руководитель</dt><dd><?= e((string)$s['director']) ?></dd><?php endif; ?>
        </dl>
        <p class="warn"><?= e(blk($s, 'cert', 'Изделия не подлежат сертификации, изготовлены из пластика, страна производства — ' . (string)($s['madeIn'] ?? 'Россия') . '. Наличие по конкретному артикулу и цвету подтверждает менеджер.')) ?></p>
      </div>
    </div>
<?php
}

/* ------------------------------------------------------- товар для schema.org */

function pl_product_jsonld(array $p, string $base): void {
    $offers = [
        '@type' => 'AggregateOffer',
        'priceCurrency' => 'RUB',
        'lowPrice' => min((float)$p['priceOpt'], (float)$p['priceRetail']),
        'highPrice' => max((float)$p['priceOpt'], (float)$p['priceRetail']),
        'offerCount' => 2,
        'availability' => ((int)$p['inStock'] === 0)
            ? 'https://schema.org/PreOrder'
            : 'https://schema.org/InStock',
    ];
    $data = [
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => (string)$p['title'],
        'sku' => (string)$p['sku'],
        'image' => array_map(static fn(string $i): string => $base . ltrim($i, '/'), $p['images']),
        'description' => (string)$p['description'],
        'material' => (string)$p['material'],
        'color' => (string)$p['color'],
        'brand' => ['@type' => 'Brand', 'name' => 'Полиформ'],
        'countryOfOrigin' => ['@type' => 'Country', 'name' => (string)$p['country']],
        'offers' => $offers,
        'additionalProperty' => [
            ['@type' => 'PropertyValue', 'name' => 'Габариты',
             'value' => $p['sizeL'] . '×' . $p['sizeW'] . '×' . $p['sizeH'] . ' см'],
            ['@type' => 'PropertyValue', 'name' => 'Фасовка', 'value' => 'В боксе ' . (int)$p['packCount'] . ' шт.'],
        ],
    ];
    echo '<script type="application/ld+json">' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
}

function pl_breadcrumbs_jsonld(string $base, array $items): void {
    $list = [];
    $i = 1;
    foreach ($items as $name => $path) {
        $list[] = [
            '@type' => 'ListItem',
            'position' => $i++,
            'name' => $name,
            'item' => $base . ltrim($path, '/'),
        ];
    }
    $data = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $list];
    echo '<script type="application/ld+json">' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
}
