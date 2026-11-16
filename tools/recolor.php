<?php
declare(strict_types=1);

/**
 * Перекраска цветных элементов фотографии (товар + надпись), без изменения фона.
 *
 * Перекрашиваются только пиксели, попадающие в цветовой диапазон исходного
 * цвета (по умолчанию — доминирующий насыщенный цвет кадра). Яркость
 * (светотень, блики, тени) сохраняется, меняются только тон и насыщенность —
 * поэтому фон, стол, тени и нейтрально-серые детали остаются как были.
 *
 * Использование:
 *   php tools/recolor.php <src> <out> <#hex> [опции]
 *
 * Опции:
 *   --hue=auto|0..360     исходный тон; auto — доминирующий цвет кадра
 *   --tol=28              допуск по тону, градусы
 *   --smin=0.28           минимальная насыщенность исходника (0..1)
 *   --strength=1          0..1, сила эффекта
 *   --sat=1               множитель насыщенности результата
 *   --light=1             множитель светлоты результата
 *   --box=x0,y0,x1,y1     перекрашивать только внутри области
 *   --feather=14          мягкость границы области, px
 *   --save-mask=<file>    сохранить результат перекраски маски (отладка)
 *
 * Примеры:
 *   php tools/recolor.php images/products/100018-xxxxxxxx.png \
 *       images/products/100018-yyyyyyyy.png "#7A2430"
 *
 * Требуется расширение GD.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
if (!extension_loaded('gd')) {
    fwrite(STDERR, "GD extension is required\n");
    exit(1);
}

$args = array_slice($argv, 1);
if (count($args) < 3) {
    fwrite(STDERR, "Usage: php tools/recolor.php <src> <out> <#hex> [--hue=auto] [--tol=28] [--smin=0.28] [--strength=1]\n");
    exit(1);
}

$src  = $args[0];
$dst  = $args[1];
$hex  = ltrim($args[2], '#');
$opts = [
    'hue'       => 'auto',
    'tol'       => 28.0,
    'smin'      => 0.28,
    'strength'  => 1.0,
    'sat'       => 1.0,
    'light'     => 1.0,
    'box'       => null,
    'feather'   => 14.0,
    'save-mask' => null,
];
foreach (array_slice($args, 3) as $a) {
    if (!preg_match('~^--([a-z-]+)=(.*)$~', $a, $m)) continue;
    $key = $m[1];
    $val = $m[2];
    if ($key === 'box') {
        $parts = array_map('intval', explode(',', $val));
        if (count($parts) === 4) $opts['box'] = $parts;
        continue;
    }
    if (!array_key_exists($key, $opts)) continue;
    $opts[$key] = is_numeric($val) ? (float)$val : $val;
}

if (!preg_match('~^[0-9a-f]{6}$~i', $hex)) {
    fwrite(STDERR, "Bad hex color: {$hex}\n");
    exit(1);
}
$tr = hexdec(substr($hex, 0, 2));
$tg = hexdec(substr($hex, 2, 2));
$tb = hexdec(substr($hex, 4, 2));

if (!is_file($src)) {
    fwrite(STDERR, "Source not found: {$src}\n");
    exit(1);
}
$info = @getimagesize($src);
if ($info === false) {
    fwrite(STDERR, "Unsupported image: {$src}\n");
    exit(1);
}
$im = match ($info[2]) {
    IMAGETYPE_PNG  => imagecreatefrompng($src),
    IMAGETYPE_JPEG => imagecreatefromjpeg($src),
    IMAGETYPE_WEBP => imagecreatefromwebp($src),
    default        => false,
};
if (!$im) {
    fwrite(STDERR, "Cannot read image: {$src}\n");
    exit(1);
}

$w = imagesx($im);
$h = imagesy($im);

/* ---------- 1. Собираем пиксели и ищем доминирующий насыщенный тон ---------- */

$pixels = [];
$hueHist = [];
$satMax = 0.0;
for ($y = 0; $y < $h; $y++) {
    for ($x = 0; $x < $w; $x++) {
        $c = imagecolorat($im, $x, $y);
        $r = ($c >> 16) & 0xFF;
        $g = ($c >> 8) & 0xFF;
        $b = $c & 0xFF;
        $pixels[$y * $w + $x] = [$r, $g, $b, ($c >> 24) & 0x7F];

        $hsl = rgb2hsl($r, $g, $b);
        if ($hsl[1] >= 0.30 && $hsl[2] > 0.10 && $hsl[2] < 0.92) {
            $bin = (int)round($hsl[0] / 10) * 10 % 360;
            $hueHist[$bin] = ($hueHist[$bin] ?? 0) + 1;
            $satMax = max($satMax, $hsl[1]);
        }
    }
}

$baseHue = null;
if (is_string($opts['hue']) && $opts['hue'] === 'auto') {
    if ($hueHist) {
        arsort($hueHist);
        $baseHue = (float)array_key_first($hueHist);
    }
} else {
    $baseHue = (float)$opts['hue'];
}
if ($baseHue === null) {
    fwrite(STDERR, "No dominant color found; pass --hue=...\n");
    exit(1);
}
if ($satMax < 0.3) $satMax = 0.85;

$tHsl   = rgb2hsl($tr, $tg, $tb);
$tHue   = $tHsl[0];
$tSat   = $tHsl[1];

/* ---------- 2. Перекрашиваем только пиксели нужного тона ---------- */

$out = imagecreatetruecolor($w, $h);
imagealphablending($out, false);
imagesavealpha($out, true);

$tol      = max(1.0, (float)$opts['tol']);
$smin     = max(0.0, min(0.99, (float)$opts['smin']));
$strength = max(0.0, min(1.0, (float)$opts['strength']));
$satMul   = max(0.0, (float)$opts['sat']);
$lightMul = max(0.0, (float)$opts['light']);
$box      = $opts['box'];
$feather  = max(0.5, (float)$opts['feather']);

$changed = 0;
$maskImg = $opts['save-mask'] !== null ? imagecreatetruecolor($w, $h) : null;
if ($maskImg) {
    imagefill($maskImg, 0, 0, imagecolorallocate($maskImg, 0, 0, 0));
}

for ($y = 0; $y < $h; $y++) {
    for ($x = 0; $x < $w; $x++) {
        [$r, $g, $b, $a] = $pixels[$y * $w + $x];
        [$ph, $ps, $pl] = rgb2hsl($r, $g, $b);

        $dh = abs($ph - $baseHue);
        if ($dh > 180) $dh = 360 - $dh;

        // Мягкая маска: по тону и по насыщенности.
        $mHue = 1.0 - smooth($tol, $tol * 1.9, $dh);
        $mSat = smooth($smin * 0.6, $smin + 0.12, $ps);
        $m    = min($mHue, $mSat);

        if ($box !== null) {
            [$x0, $y0, $x1, $y1] = $box;
            $d = min($x - $x0, $x1 - $x, $y - $y0, $y1 - $y);
            $m *= smooth(0.0, $feather, (float)$d);
        }
        $m *= $strength;

        if ($m <= 0.002) {
            imagesetpixel($out, $x, $y, ($a << 24) | ($r << 16) | ($g << 8) | $b);
            continue;
        }

        // Сохраняем светотень: берём исходную светлоту, меняем тон/насыщенность.
        $newSat = $tSat * ($ps / $satMax) * $satMul;
        $newSat = max(0.0, min(1.0, $newSat));
        $newLig = max(0.0, min(1.0, $pl * $lightMul));
        [$nr, $ng, $nb] = hsl2rgb($tHue, $newSat, $newLig);

        $nr = (int)round($r + ($nr - $r) * $m);
        $ng = (int)round($g + ($ng - $g) * $m);
        $nb = (int)round($b + ($nb - $b) * $m);
        $changed++;

        imagesetpixel($out, $x, $y, ($a << 24) | ($nr << 16) | ($ng << 8) | $nb);
        if ($maskImg) {
            imagesetpixel($maskImg, $x, $y, imagecolorallocate($maskImg, $nr, $ng, $nb));
        }
    }
}

$dir = dirname($dst);
if (!is_dir($dir)) @mkdir($dir, 0775, true);

$ext = strtolower(pathinfo($dst, PATHINFO_EXTENSION));
$ok = match ($ext) {
    'webp'        => imagewebp($out, $dst, 90),
    'jpg', 'jpeg' => imagejpeg($out, $dst, 92),
    default       => imagepng($out, $dst, 9),
};
if ($ok && $maskImg && $opts['save-mask']) {
    imagepng($maskImg, (string)$opts['save-mask']);
}
imagedestroy($im);
imagedestroy($out);
if ($maskImg) imagedestroy($maskImg);

if (!$ok) {
    fwrite(STDERR, "Cannot write: {$dst}\n");
    exit(1);
}

printf(
    "OK %s (%dx%d) base hue %.0f° -> #%s, recolored %.1f%% pixels\n",
    $dst, $w, $h, $baseHue, strtoupper($hex), 100 * $changed / max(1, $w * $h)
);

/* ------------------------------- helpers -------------------------------- */

/** @return array{0:float,1:float,2:float} hue 0..360, saturation 0..1, lightness 0..1 */
function rgb2hsl(int $r, int $g, int $b): array
{
    $r /= 255; $g /= 255; $b /= 255;
    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $l = ($max + $min) / 2;
    if ($max === $min) return [0.0, 0.0, $l];
    $d = $max - $min;
    $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
    $h = match ($max) {
        $r => (($g - $b) / $d + ($g < $b ? 6 : 0)),
        $g => (($b - $r) / $d + 2),
        default => (($r - $g) / $d + 4),
    };
    return [$h * 60.0, $s, $l];
}

/** @return array{0:int,1:int,2:int} */
function hsl2rgb(float $h, float $s, float $l): array
{
    $h = fmod(fmod($h, 360) + 360, 360) / 360;
    if ($s <= 0.0) {
        $v = (int)round($l * 255);
        return [$v, $v, $v];
    }
    $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
    $p = 2 * $l - $q;
    $f = static fn(float $t): float => hue2rgb($p, $q, $t);
    return [
        (int)round($f($h + 1 / 3) * 255),
        (int)round($f($h) * 255),
        (int)round($f($h - 1 / 3) * 255),
    ];
}

function hue2rgb(float $p, float $q, float $t): float
{
    if ($t < 0) $t += 1;
    if ($t > 1) $t -= 1;
    if ($t < 1 / 6) return $p + ($q - $p) * 6 * $t;
    if ($t < 1 / 2) return $q;
    if ($t < 2 / 3) return $p + ($q - $p) * (2 / 3 - $t) * 6;
    return $p;
}

function smooth(float $e0, float $e1, float $x): float
{
    if ($e0 === $e1) return $x < $e0 ? 0.0 : 1.0;
    $t = ($x - $e0) / ($e1 - $e0);
    $t = max(0.0, min(1.0, $t));
    return $t * $t * (3 - 2 * $t);
}
