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
foreach (['css', 'js', 'images', 'data', 'fonts'] as $d) {
    if (!is_dir("$dist/$d")) @mkdir("$dist/$d", 0777, true);
}

/* ---- Рендер страниц ---- */
function renderPage(string $root, string $page, array $get = []): string {
    $json = json_encode($get, JSON_UNESCAPED_SLASHES);
    $cmd = '"' . PHP_BINARY . '" -d display_errors=0 -d log_errors=0 '
         . '"' . $root . '/tools/render-page.php" '
         . escapeshellarg($page);
    /* JSON передаём через окружение: escapeshellarg на Windows ломает кавычки. */
    putenv('PAGE_JSON=' . $json);
    $out = shell_exec($cmd);
    putenv('PAGE_JSON');
    return is_string($out) ? $out : '';
}

function rewriteLinks(string $html): string {
    // Карточки товара: product.php?sku=100001 -> product-100001.html
    $html = preg_replace('~product\.php\?sku=([A-Za-z0-9_-]+)~', 'product-$1.html', $html);
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
    'shop'     => (array)($s['shop'] ?? []),
];
file_put_contents(
    "$dist/data/products.json",
    json_encode($cat, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
);
echo "… data/products.json\n";

/* ---- sitemap.xml ---- */
$base = PL_SITE_BASE;
$urls = [
    [$base, 'weekly', '1.0'],
    [$base . 'products.html', 'weekly', '0.9'],
    [$base . 'about.html', 'yearly', '0.6'],
];
foreach ($skus as $sku) {
    $urls[] = [$base . 'product-' . $sku . '.html', 'monthly', '0.8'];
}
$sitemap = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $u) {
    $sitemap .= '  <url><loc>' . $u[0] . '</loc><changefreq>' . $u[1] . '</changefreq><priority>' . $u[2] . '</priority></url>' . "\n";
}
$sitemap .= '</urlset>' . "\n";
file_put_contents("$dist/sitemap.xml", $sitemap);
echo "… sitemap.xml\n";

/* ---- Ассеты ---- */
/* CSS и JS кладём в dist минифицированными: исходники остаются читаемыми,
   а Pages отдаёт меньше байт. Минификация консервативная — не трогает
   содержимое строк, data-URI, calc() и переводы строк в JS (ради ASI). */
foreach (['css', 'images', 'fonts'] as $d) {
    if (is_dir("$root/$d")) { copyAssets("$root/$d", "$dist/$d", $d === 'css'); }
    echo "… $d/\n";
}
foreach (glob("$root/js/*.js") ?: [] as $f) {
    $body = (string)file_get_contents($f);
    if (basename($f) === 'shop.js') $body = patchShopJs($body);
    file_put_contents("$dist/js/" . basename($f), minifyCode($body, false));
}
echo "… js/ (minified)\n";

foreach (['favicon.svg'] as $f) {
    if (is_file("$root/$f")) copy("$root/$f", "$dist/$f");
}
file_put_contents("$dist/.nojekyll", '');
file_put_contents("$dist/robots.txt", "User-agent: *\nAllow: /\nDisallow: /admin.php\nDisallow: /admin.html\nDisallow: /api.php\nDisallow: /tools/\nDisallow: /inc/\nDisallow: /lib/\n\nSitemap: " . PL_SITE_BASE . "sitemap.xml\n");

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
 * Копирует папку ассетов; CSS по пути минифицируется.
 * Изображения и шрифты копируются как есть (бинарные).
 */
function copyAssets(string $from, string $to, bool $minify): void {
    foreach (scandir($from) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $src = "$from/$entry";
        $dst = "$to/$entry";
        if (is_dir($src)) { @mkdir($dst, 0777, true); copyAssets($src, $dst, $minify); continue; }
        if ($minify && preg_match('~\.css$~i', $entry)) {
            file_put_contents($dst, minifyCode((string)file_get_contents($src), true));
        } else {
            copy($src, $dst);
        }
    }
}

/**
 * Консервативная минификация CSS/JS без внешних зависимостей.
 *
 * Идём построчно и NEVER трогаем содержимое строковых литералов: внутри
 * кавычек лежат data:image/svg+xml, URL и текст разметки — их портить нельзя.
 *
 * CSS: убираем /* * /, склеиваем строки в один пробел, сжимаем пробелы
 *      вокруг { } : ; , > ~ +  (знаки +,-,%,/ НЕ трогаем — это calc()).
 * JS:  убираем /* * / и // комментарии, срезаем отступы, пустые строки.
 *      ОБЯЗАТЕЛЬНО сохраняем \n между строками: в коде нет `;` в конце
 *      части выражений, поэтому работает ASI (авто-вставка ; по переводу
 *      строки). Пробелы вокруг операторов НЕ сжимаем: иначе `return -1`
 *      станет `return-1`, а `a - -b` — инкрементом.
 */
function minifyCode(string $src, bool $isCss): string {
    $src = str_replace(["\r\n", "\r"], "\n", $src);
    $lines = explode("\n", $src);
    $kept = [];
    $inBlock = false;

    foreach ($lines as $line) {
        $clean = minifyStripLine($line, $inBlock, $isCss);
        $t = trim($clean);
        if ($t !== '') $kept[] = $t;
    }

    if ($isCss) {
        $code = implode(' ', $kept);
        $code = preg_replace('~\s+~', ' ', $code) ?? $code;
        $code = preg_replace('~\s*([{}:;,>])\s*~', '$1', $code) ?? $code;
        $code = str_replace(';}', '}', $code);
        $code = preg_replace('~;{2,}~', ';', $code) ?? $code;
        return trim($code);
    }

    /* JS: одна строка кода на строку — и ASI, и читаемость стека ошибок. */
    return implode("\n", $kept);
}

/** Чистит одну строку: режет комментарии, но не заходит внутрь кавычек. */
function minifyStripLine(string $line, bool &$inBlock, bool $isCss): string {
    $out = '';
    $n = strlen($line);
    $i = 0;
    while ($i < $n) {
        $c = $line[$i];

        /* Строковый литерал — копируем дословно, вместе с экранированиями. */
        if ($c === '"' || $c === "'" || $c === '`') {
            $quote = $c;
            $out .= $c;
            $i++;
            while ($i < $n) {
                $ch = $line[$i];
                if ($ch === '\\' && $i + 1 < $n) { $out .= $ch . $line[$i + 1]; $i += 2; continue; }
                $out .= $ch;
                $i++;
                if ($ch === $quote) break;
            }
            continue;
        }

        /* Продолжение многострочного /* ... * / с прошлой строки. */
        if ($inBlock) {
            $end = strpos($line, '*/', $i);
            if ($end === false) { return $out; }
            $i = $end + 2;
            $inBlock = false;
            continue;
        }

        /* Начало блочного комментария. /*! ... * / сохраняем (лицензии). */
        if ($c === '/' && $i + 1 < $n && $line[$i + 1] === '*') {
            $bang = ($i + 2 < $n && $line[$i + 2] === '!');
            $end = strpos($line, '*/', $i + 2);
            if ($bang) {
                $stop = $end === false ? $n : $end + 2;
                $out .= substr($line, $i, $stop - $i);
                $i = $stop;
                continue;
            }
            if ($end === false) { $inBlock = true; return $out; }
            $i = $end + 2;
            continue;
        }

        /* // комментарий — только в JS; в CSS это часть url() и не трогаем.
           Если первый слэш экранирован (\/ в регулярке), это не комментарий:
           иначе строка вида location.pathname.replace(/^.*\//, '') обрезалась. */
        if (!$isCss && $c === '/' && $i + 1 < $n && $line[$i + 1] === '/') {
            $prev = $out === '' ? '' : substr($out, -1);
            if ($prev !== '\\') {
                return $out;
            }
        }

        $out .= $c;
        $i++;
    }
    return $out;
}

/**
 * Статические ссылки: артикулы — в product-<sku>.html, страницы — в .html.
 * На GitHub Pages нет PHP, поэтому api.php заменяем на заранее собранный
 * data/products.json — иначе витрина каждый раз ловит 404 и только потом
 * падает на резервный источник.
 */
function patchShopJs(string $js): string {
    $map = [
        "var API_URL = 'api.php?action=catalog';" => "var API_URL = 'data/products.json';",
        "'product.php?sku=' + p.sku" => "'product-' + p.sku + '.html'",
        "'product.php?sku=' + v.sku" => "'product-' + v.sku + '.html'",
        "'product.php?sku=' + encodeURIComponent(v.sku)" => "'product-' + encodeURIComponent(v.sku) + '.html'",
        "'product.php?sku=' + encodeURIComponent(p.sku)" => "'product-' + encodeURIComponent(p.sku) + '.html'",
        "'products.php?cat=' + c.id" => "'products.html?cat=' + c.id",
        "'products.php?cat=' + p.categoryId" => "'products.html?cat=' + p.categoryId",
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