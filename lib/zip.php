<?php
declare(strict_types=1);

/**
 * Минимальный ZIP-архиватор на чистом PHP (метод «store», без сжатия).
 * Нужен потому, что расширение zip в текущей сборке отключено
 * (в php.ini строка ";extension=zip"), и класс ZipArchive недоступен.
 *
 * Принимает карту «имя в архиве => содержимое», возвращает бинарный ZIP.
 */
function zipCreate(array $entries): string {
    $out = '';        /* локальные записи + данные */
    $central = '';    /* центральный каталог */
    $offset = 0;

    $now = getdate();
    $dosTime = (($now['hours'] & 0x1F) << 11) | (($now['minutes'] & 0x3F) << 5) | (($now['seconds'] >> 1) & 0x1F);
    $dosDate = ((($now['year'] - 1980) & 0x7F) << 9) | (($now['mon'] & 0x0F) << 5) | ($now['mday'] & 0x1F);

    foreach ($entries as $name => $data) {
        $name = str_replace('\\', '/', (string)$name);
        $data = (string)$data;
        $crc  = crc32($data);
        $size = strlen($data);
        $nLen = strlen($name);

        $local = pack('V', 0x04034b50)
            . pack('v', 20)       /* нужная версия */
            . pack('v', 0x0800)   /* имена в UTF-8 */
            . pack('v', 0)        /* метод: store */
            . pack('v', $dosTime)
            . pack('v', $dosDate)
            . pack('V', $crc)
            . pack('V', $size)
            . pack('V', $size)
            . pack('v', $nLen)
            . pack('v', 0)        /* extra */
            . $name;

        $out .= $local . $data;

        $central .= pack('V', 0x02014b50)
            . pack('v', 20)       /* версия упаковщика */
            . pack('v', 20)       /* нужная версия */
            . pack('v', 0x0800)
            . pack('v', 0)
            . pack('v', $dosTime)
            . pack('v', $dosDate)
            . pack('V', $crc)
            . pack('V', $size)
            . pack('V', $size)
            . pack('v', $nLen)
            . pack('v', 0)        /* extra */
            . pack('v', 0)        /* comment */
            . pack('v', 0)        /* диск */
            . pack('v', 0)        /* внутренние атрибуты */
            . pack('V', 0)        /* внешние атрибуты */
            . pack('V', $offset)  /* смещение локальной записи */
            . $name;

        $offset += strlen($local) + $size;
    }

    $count  = count($entries);
    $cdSize = strlen($central);
    $eocd = pack('V', 0x06054b50)
        . pack('v', 0) . pack('v', 0)
        . pack('v', $count) . pack('v', $count)
        . pack('V', $cdSize) . pack('V', $offset)
        . pack('v', 0);        /* comment */

    return $out . $central . $eocd;
}