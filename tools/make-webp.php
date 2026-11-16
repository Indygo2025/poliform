<?php
declare(strict_types=1);

/**
 * Догенерация отсутствующих WebP для всех фото товаров.
 *
 * Фронтенд (product.php, index.php, inc/layout.php, js/shop.js) подставляет
 * .webp / -t.webp по расширению исходника. Загрузка через админку раньше
 * создавала только .jpg + -t.jpg, поэтому часть картинок отдавала 404.
 *
 * Скрипт НЕ удаляет и НЕ перезаписывает существующие файлы — только создаёт
 * недостающие .webp и -t.webp для каждого изображения из базы.
 *
 * Использование:
 *   php tools/make-webp.php            создать недостающие
 *   php tools/make-webp.php --dry-run  только показать, что будет создано
 *   php tools/make-webp.php --force    пересоздать даже существующие webp
 *
 * Требуется GD с поддержкой WebP.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once dirname(__DIR__) . '/lib/image.php';

if (!function_exists('imagewebp')) {
    fwrite(STDERR, "GD без поддержки WebP — включите php_gd2 с libwebp\n");
    exit(1);
}

$dry  = false;
$force = false;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--dry-run') $dry = true;
    if ($a === '--force')   $force = true;
    if ($a === '--help' || $a === '-h') {
        fwrite(STDOUT, "php tools/make-webp.php [--dry-run] [--force]\n");
        exit(0);
    }
}

$root = dirname(__DIR__);
$dbPath = getenv('SITE_DB') ?: ($root . '/site.db');
if (!is_file($dbPath)) {
    fwrite(STDERR, "База не найдена: {$dbPath}\n");
    exit(1);
}
$db = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$rows = $db->query("SELECT sku, images FROM products ORDER BY sku")->fetchAll();

$seen = [];
$jobs = [];
foreach ($rows as $r) {
    $imgs = json_decode((string)$r['images'], true) ?: [];
    foreach ($imgs as $src) {
        $src = (string)$src;
        if ($src === '' || isset($seen[$src])) continue;
        $seen[$src] = true;

        $abs = $root . '/' . $src;
        if (!is_file($abs)) {
            echo "ПРОПУСК (нет исходника): {$src}\n";
            continue;
        }

        $isRaster = (bool)preg_match('~\.(jpe?g|png)$~i', $src);
        $webp      = $isRaster ? plWebpName($src) : $src;
        $thumbWebp = (string)preg_replace('~\.(jpe?g|png|webp)$~i', '-t.webp', $src);
        $thumbJpg  = plThumbJpgName($src);

        $jobs[] = [
            'src'          => $src,
            'abs'          => $abs,
            'isRaster'     => $isRaster,
            'webp'         => $webp,
            'webpAbs'      => $root . '/' . $webp,
            'thumbWebp'    => $thumbWebp,
            'thumbWebpAbs' => $root . '/' . $thumbWebp,
            'thumbJpgAbs'  => $root . '/' . $thumbJpg,
        ];
    }
}

$madeMain = 0; $madeThumb = 0; $skipMain = 0; $skipThumb = 0; $fail = 0;

foreach ($jobs as $j) {
    // Основной .webp — только для jpg/png (у webp он уже есть).
    if (!$j['isRaster']) {
        $skipMain++;
    } elseif (!$force && is_file($j['webpAbs'])) {
        $skipMain++;
    } else {
        if ($dry) {
            echo "создать {$j['webp']}\n";
            $madeMain++;
        } elseif (plToWebp($j['abs'], $j['webpAbs'])) {
            echo "+ {$j['webp']}\n";
            $madeMain++;
        } else {
            echo "ОШИБКА {$j['webp']}\n";
            $fail++;
        }
    }

    // Миниатюра -t.webp: предпочитаем готовую -t.jpg, иначе ресайзим исходник.
    if (!$force && is_file($j['thumbWebpAbs'])) {
        $skipThumb++;
    } else {
        if ($dry) {
            echo "создать {$j['thumbWebp']}\n";
            $madeThumb++;
        } else {
            $from = is_file($j['thumbJpgAbs']) ? $j['thumbJpgAbs'] : $j['abs'];
            if (plToWebpThumb($from, $j['thumbWebpAbs'], 400)) {
                echo "+ {$j['thumbWebp']}\n";
                $madeThumb++;
            } else {
                echo "ОШИБКА {$j['thumbWebp']}\n";
                $fail++;
            }
        }
    }
}

printf(
    "\nГотово: уникальных исходников %d | создано .webp %d, -t.webp %d | уже было %d / %d | ошибок %d%s\n",
    count($jobs), $madeMain, $madeThumb, $skipMain, $skipThumb, $fail,
    $dry ? ' (dry-run, файлы не изменены)' : ''
);
