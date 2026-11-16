<?php
declare(strict_types=1);

/** Рендер одной страницы в отдельном PHP-процессе.
 *  Вызов: php tools/render-page.php <page> <json-GET>
 */
$argv = $_SERVER['argv'] ?? [];
$_GET = [];
/* JSON с GET-параметрами приходит через окружение (putenv в build-static.php),
   чтобы не зависеть от escapeshellarg — на Windows он ломает кавычки. */
$envJson = getenv('PAGE_JSON');
if (is_string($envJson) && $envJson !== '') {
    $decoded = json_decode($envJson, true);
    if (is_array($decoded)) $_GET = $decoded;
} elseif (isset($argv[2])) {
    $decoded = json_decode((string)$argv[2], true);
    if (is_array($decoded)) $_GET = $decoded;
}

/* Белый список страниц: include по произвольному пути был бы LFI. */
$page = basename((string)($argv[1] ?? ''));
$allowedPages = ['index.php', 'products.php', 'product.php', 'about.php', 'cart.php'];
if (!in_array($page, $allowedPages, true)) {
    fwrite(STDERR, "render-page: неизвестная страница\n");
    exit(1);
}

/* В CLI нет REQUEST_URI, поэтому canonical/og:url собирались как «/».
   Подставляем путь страницы — pl_canonicalPath() построит корректный URL. */
$qs = http_build_query($_GET);
$_SERVER['REQUEST_URI'] = '/' . $page . ($qs !== '' ? '?' . $qs : '');

include __DIR__ . '/../' . $page;