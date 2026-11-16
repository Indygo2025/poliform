<?php
declare(strict_types=1);

/**
 * Работа с изображениями: конвертация в WebP через GD.
 * Подключается и в api.php (при загрузке фото), и в tools/make-webp.php (разовая догенерация).
 */

/**
 * Сконвертировать изображение в WebP.
 *
 * @return bool true, если файл записан.
 */
function plToWebp(string $src, string $dest, int $quality = 88): bool
{
    if (!function_exists('imagewebp') || !is_file($src)) return false;

    $info = @getimagesize($src);
    if (!$info) return false;
    [$w, $h, $type] = $info;

    // Защита от «бомбы декомпрессии», как в upload().
    if ($w < 1 || $h < 1 || $w > 6000 || $h > 6000 || $w * $h > 24000000) return false;

    $im = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($src),
        IMAGETYPE_PNG  => @imagecreatefrompng($src),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false,
        default        => false,
    };
    if (!$im) return false;

    // imagewebp() требует truecolor; PNG может быть палитровым.
    if (!imageistruecolor($im)) {
        $tc = imagecreatetruecolor($w, $h);
        imagealphablending($tc, false);
        imagesavealpha($tc, true);
        imagecopy($tc, $im, 0, 0, 0, 0, $w, $h);
        imagedestroy($im);
        $im = $tc;
    }
    imagealphablending($im, false);
    imagesavealpha($im, true);

    $dir = dirname($dest);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);

    $ok = @imagewebp($im, $dest, $quality);
    imagedestroy($im);
    if (!$ok) {
        if (is_file($dest)) @unlink($dest);
        return false;
    }
    @chmod($dest, 0644);
    return true;
}

/**
 * Создать уменьшенную WebP-миниатюру (по образцу makeThumb, но в WebP).
 * Берёт готовую -t.jpg если есть, иначе ресайзит исходник.
 */
function plToWebpThumb(string $src, string $dest, int $maxW = 400, int $quality = 86): bool
{
    if (!function_exists('imagewebp') || !is_file($src)) return false;

    $info = @getimagesize($src);
    if (!$info) return false;
    [$w, $h, $type] = $info;
    if ($w < 1 || $h < 1 || $w > 6000 || $h > 6000 || $w * $h > 24000000) return false;

    $im = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($src),
        IMAGETYPE_PNG  => @imagecreatefrompng($src),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false,
        default        => false,
    };
    if (!$im) return false;

    $tw = min($maxW, $w);
    $th = max(1, (int)round($h * $tw / $w));
    $out = imagecreatetruecolor($tw, $th);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    $transparent = imagecolorallocatealpha($out, 255, 255, 255, 127);
    imagefilledrectangle($out, 0, 0, $tw, $th, $transparent);
    imagecopyresampled($out, $im, 0, 0, 0, 0, $tw, $th, $w, $h);
    imagedestroy($im);

    $dir = dirname($dest);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $ok = @imagewebp($out, $dest, $quality);
    imagedestroy($out);
    if (!$ok) {
        if (is_file($dest)) @unlink($dest);
        return false;
    }
    @chmod($dest, 0644);
    return true;
}

/** Заменить расширение jpg/jpeg/png на .webp (только если оно меняется). */
function plWebpName(string $path): string
{
    return (string)preg_replace('~\.(jpe?g|png)$~i', '.webp', $path);
}

/** Заменить расширение jpg/jpeg/png на -t.webp. */
function plThumbWebpName(string $path): string
{
    return (string)preg_replace('~\.(jpe?g|png)$~i', '-t.webp', $path);
}

/** Заменить расширение jpg/jpeg/png/webp на -t.jpg. */
function plThumbJpgName(string $path): string
{
    return (string)preg_replace('~\.(jpe?g|png|webp)$~i', '-t.jpg', $path);
}
