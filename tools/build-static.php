<?php
declare(strict_types=1);

/**
 * Сборка статического снимка сайта для GitHub Pages.
 *
 * Запуск:  php tools/build-static.php
 * Результат: папка dist/ у корня проекта (главная страница Pages).
 * Каждая страница рендерится в отдельном PHP-процессе, чтобы конфликты
 * повторных require не мешали.
 */

error_reporting(E_ALL & ~E_DEPRECATED);

$root = dirname(__DIR__);
$dist = $root . '/dist';

require_once $root . '/db.php';
db()->query('PRAGMA wal_checkpoint(TRUNCATE)');

/* ---- Очистка dist ---- */
if (is_dir($dist)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dist, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
}
foreach (['css', 'js', 'images', 'data'] as $d) {
    if (!is_dir("$dist/$d")) @mkdir("$dist/$d", 0777, true);
}

/* ---- Рендер страниц ---- */
function renderPage(string $root, string $page, array $get = []): string {
    $json = json_encode($get, JSON_UNESCAPED_SLASHES);
    $cmd = '"' . PHP_BINARY . '" -d display_errors=0 -d log_errors=0 '
         . '"' . $root . '/tools/render-page.php" '
         . $page . ' ' . escapeshellarg($json);
    $out = shell_exec($cmd);
    return is_string($out) ? $out : '';
}

function rewriteLinks(string $html): string {
    return preg_replace('~\.php(?=["?#])~', '.html', $html);
}

$skus = array_column(dbAllProducts(db()), 'sku');

$pages = [
    ['index.php',    'index.html',    []],
    ['products.php', 'products.html', []],
    ['cart.php',     'cart.html',     []],
    ['about.php',    'about.html',    []],
];
foreach ($skus as $sku) {
    $pages[] = ['product.php', 'product-' . $sku . '.html', ['sku' => $sku]];
}

foreach ($pages as [$src, $out, $get]) {
    file_put_contents("$dist/$out", rewriteLinks(renderPage($root, $src, $get, )));
    echo "… $out\n";
}

/* ---- Каталог products.json со настройками (фолбэк витрины без PHP) ---- */
$db = db();
$cat = dbCatalog($db);
$s = dbAllSettings($db);
$cat['settings'] = [
    'order'    => (array)($s['order'] ?? []),
    'contacts' => (array)($s['contacts'] ?? []),
];
file_put_contents(
    "$dist/data/products.json",
    json_encode($cat, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
);
echo "… data/products.json\n";

/* ---- Ассеты ---- */
foreach (['css', 'images'] as $d) {
    copyDir("$root/$d", "$dist/$d");
    echo "… $d/\n";
}
foreach (glob("$root/js/*.js") ?: [] as $f) {
    $body = (string)file_get_contents($f);
    if (basename($f) === 'shop.js') $body = patchShopJs($body);
    file_put_contents("$dist/js/" . basename($f), $body);
}
echo "… js/\n";

foreach (['favicon.svg'] as $f) {
    if (is_file("$root/$f")) copy("$root/$f", "$dist/$f");
}
file_put_contents("$dist/.nojekyll", '');
file_put_contents("$dist/robots.txt", "User-agent: *\nAllow: /\n");

/* ---- Вспомогательное ---- */
function copyDir(string $from, string $to): void {
    foreach (scandir($from) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $src = "$from/$entry";
        $dst = "$to/$entry";
        if (is_dir($src)) { @mkdir($dst, 0777, true); copyDir($src, $dst); }
        else copy($src, $dst);
    }
}

/**
 * Статические ссылки: артикулы — в product-<sku>.html, страницы — в .html.
 * Вызовы api.php оставляем: витрина сама падает на данных из products.json.
 */
function patchShopJs(string $js): string {
    $map = [
        "'product.php?sku=' + p.sku" => "'product-' + p.sku + '.html'",
        "'products.php?cat=' + c.id" => "'products.html?cat=' + c.id",
        "'cart.php#order'"           => "'cart.html#order'",
        "'products.php'"             => "'products.html'",
        "'cart.php'"                 => "'cart.html'",
        "'index.php'"                => "'index.html'",
    ];
    foreach ($map as $from => $to) {
        $js = str_replace($from, $to, $js);
    }
    return $js;
}

echo "OK: снимок собран в dist/\n";