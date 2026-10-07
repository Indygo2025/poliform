<?php
declare(strict_types=1);

/** Рендер одной страницы в отдельном PHP-процессе.
 *  Вызов: php tools/render-page.php <page> <json-GET>
 */
$argv = $_SERVER['argv'] ?? [];
$_GET = [];
if (isset($argv[2])) {
    $decoded = json_decode((string)$argv[2], true);
    if (is_array($decoded)) $_GET = $decoded;
}
include __DIR__ . '/../' . ltrim((string)($argv[1] ?? ''), '/');