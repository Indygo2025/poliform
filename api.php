<?php
declare(strict_types=1);

/**
 * ПОЛИФОРМ — API админки и витрины.
 * Действия из ?action=..., тело — JSON.
 */

date_default_timezone_set('Europe/Moscow');

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

      header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

require_once __DIR__ . '/db.php';

const MAX_LOGIN_ATTEMPTS = 5;
const LOCK_MINUTES      = 5;

/* ------------------------------------------------------------- ответы */

function fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function success(array $data = []): void {
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
    exit;
}

function input(): array {
    static $in = null;
    if ($in !== null) return $in;
    $raw = (string)file_get_contents('php://input');
    $in = $raw !== '' ? (json_decode($raw, true) ?: []) : ($_POST ?: []);
    if (!is_array($in)) $in = [];
    return $in;
}

function clientIp(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/**
 * Перевыпускает ID сессии (защита от session fixation), отправляя клиенту
 * ровно один cookie. Без header_remove() в ответе оказываются два разных
 * Set-Cookie: один от session_start(), второй от session_regenerate_id().
 */
function rotateSessionId(): void {
    header_remove('Set-Cookie');
    session_regenerate_id(true);
}

/** CSRF-токен в сессии; проверяется для всех изменяющих запросов. */
function csrfToken(): string {
    if (empty($_SESSION['pl_csrf'])) {
        $_SESSION['pl_csrf'] = bin2hex(random_bytes(16));
    }
    return (string)$_SESSION['pl_csrf'];
}

function checkCsrf(): void {
    $sent = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $want = (string)($_SESSION['pl_csrf'] ?? '');
    if ($want === '' || !hash_equals($want, $sent)) fail('Сессия устарела — обновите страницу', 403);
}

function cleanPhone(string $p): string {
    return preg_replace('/\D/', '', $p) ?? '';
}

function validPhone(string $p): bool {
    return strlen(cleanPhone($p)) >= 10;
}

/* ----------------------------------------------------------------- вход */

/** Публичное представление пользователя — без password_hash и прочих служебных полей. */
function publicUser(array $u): array {
    return ['username' => $u['username'], 'role' => $u['role']];
}

function currentUser(): ?array {
    if (empty($_SESSION['pl_user'])) return null;
    $u = dbGetUser(db(), (string)$_SESSION['pl_user']);
    return $u ? publicUser($u) : null;
}

function login(array $in): array {
    $db = db();
    $username = trim((string)($in['username'] ?? ''));
    $password = (string)($in['password'] ?? '');

    if ($username === '' || $password === '') fail('Введите логин и пароль');

    $lock = dbLockInfo($db, $username);
    if ($lock) {
        fail('Вход заблокирован до ' . date('H:i', strtotime((string)$lock['locked_until'])), 429);
    }

    $user = dbGetUser($db, $username);
    if (!$user || !password_verify($password, (string)$user['password_hash'])) {
        $msg = dbFailLogin($db, $username, clientIp(), MAX_LOGIN_ATTEMPTS, LOCK_MINUTES);
        fail($msg ?? 'Неверный логин или пароль', 401);
    }

    dbClearLogin($db, $username);
    rotateSessionId();
    $_SESSION['pl_user'] = $username;
    unset($_SESSION['pl_csrf']);
    return ['user' => ['username' => $user['username'], 'role' => $user['role']], 'csrf' => csrfToken()];
}

function logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** Публичные действия, к которым admin.html обращается после логина. */
function requireAuth(?string $role = null): array {
    $u = currentUser();
    if (!$u) fail('Требуется вход', 401);
    if ($role !== null && (string)$u['role'] !== $role) fail('Недостаточно прав', 403);
    return $u;
}

function requireAdmin(): array {
    $u = requireAuth('admin');
    checkCsrf();
    return $u;
}

/** Только авторизация, без CSRF. Для скачивания файлов обычной ссылкой:
 *  там нельзя приложить заголовок X-CSRF-Token, поэтому checkCsrf() всегда
 *  отвечал 403. Оба этих действия — GET без изменения состояния. */
function requireAdminRead(): array {
    return requireAuth('admin');
}

/* -------------------------------------------------------------- товары */

function productsPayload(): array {
    $db = db();
    return [
        'products'   => dbAllProducts($db),
        'categories' => dbAllCategories($db),
        'colors'     => dbAllColors($db),
        'nextSku'    => dbNextSku($db),
    ];
}

function productSave(array $in): void {
    requireAdmin();
    $p = (array)($in['product'] ?? []);
    $sku = trim((string)($p['sku'] ?? ''));
    if ($sku === '') $sku = dbNextSku(db());
    $p['sku'] = $sku;

    if (!isset($p['pos']) || (int)$p['pos'] <= 0) {
        $p['pos'] = dbNextPos(db());
    }
    if (!isset($p['images']) || !is_array($p['images'])) $p['images'] = [];
    if (!isset($p['features']) || !is_array($p['features'])) $p['features'] = [];

    dbSaveProduct(db(), $p);
    success(['sku' => $sku]);
}

function productDelete(array $in): void {
    requireAdmin();
    $sku = trim((string)($in['sku'] ?? ''));
    if ($sku === '') fail('Не указан артикул');
    dbDeleteProduct(db(), $sku);
    success();
}

function productMove(array $in): void {
    requireAdmin();
    $ok = dbMoveProduct(db(), (string)($in['sku'] ?? ''), (string)($in['dir'] ?? 'up'));
    if (!$ok) fail('Двигать нечего');
    success();
}

/** Смена артикула в критических случаях. Защита от дублей. */
function productRename(array $in): void {
    requireAdmin();
    $old = trim((string)($in['sku'] ?? ''));
    $new = trim((string)($in['to'] ?? ''));
    if ($old === '' || $new === '') fail('Укажите старый и новый артикул');
    if (!preg_match('~^[0-9A-Za-zА-Яа-яЁё._-]{1,40}$~u', $new)) {
        fail('Новый артикул: от 1 до 40 символов, буквы, цифры и ._-');
    }
    if ($old === $new) { success(['from' => $old, 'to' => $new]); return; }

    $db = db();
    $q = $db->prepare('SELECT 1 FROM products WHERE sku = ?');
    $q->execute([$new]);
    if ($q->fetch()) fail('Артикул «' . $new . '» уже занят другим товаром — выберите другой');
    $q->execute([$old]);
    if (!$q->fetch()) fail('Товар с артикулом «' . $old . '» не найден');

    /* переименование файлов фото и путей, если имя файла начинается со старого артикула */
    $dir = __DIR__ . '/images/products';
    foreach (glob($dir . '/' . preg_quote($old, '/') . '-*', GLOB_NOSORT) ?: [] as $f) {
        $base = basename($f);
        if (preg_match('~^' . preg_quote($old, '/') . '-(\d+(-t)?)\.(jpg|jpeg|png|webp)$~i', $base)) {
            $to = $dir . '/' . $new . '-' . preg_replace('~^' . preg_quote($old, '/') . '-~', '', $base);
            if (file_exists($to)) @unlink($to);
            if (!@rename($f, $to)) fail('Не удалось переименовать файл ' . $base);
        }
    }

    $row = $db->prepare('SELECT images FROM products WHERE sku = ?');
    $row->execute([$old]);
    $images = json_decode((string)$row->fetch()['images'], true);
    if (is_array($images)) {
        foreach ($images as $i => $src) {
            if (preg_match('~^images/products/' . preg_quote($old, '/') . '-~', (string)$src)) {
                $images[$i] = preg_replace('~^images/products/' . preg_quote($old, '/') . '-~', 'images/products/' . $new . '-', (string)$src);
            }
        }
        $db->prepare('UPDATE products SET images = ?, updated = ? WHERE sku = ?')
           ->execute([json_encode(array_values($images), JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s'), $old]);
    }

    $db->prepare('UPDATE products SET sku = ?, updated = ? WHERE sku = ?')
       ->execute([$new, date('Y-m-d H:i:s'), $old]);
    success(['from' => $old, 'to' => $new]);
}

function categorySave(array $in): void {
    requireAdmin();
    dbSaveCategory(db(), (array)($in['category'] ?? []));
    success(['categories' => dbAllCategories(db())]);
}

function categoryDelete(array $in): void {
    requireAdmin();
    $id = (string)($in['id'] ?? '');
    $used = db()->prepare("SELECT COUNT(*) AS c FROM products WHERE categoryId = ?");
    $used->execute([$id]);
    if ((int)$used->fetch()['c'] > 0) fail('В категории есть товары — сначала перенесите их');
    dbDeleteCategory(db(), $id);
    success(['categories' => dbAllCategories(db())]);
}

function colorSave(array $in): void {
    requireAdmin();
    dbSaveColor(db(), (array)($in['color'] ?? []));
    success(['colors' => dbAllColors(db())]);
}

function colorDelete(array $in): void {
    requireAdmin();
    dbDeleteColor(db(), (string)($in['id'] ?? ''));
    success(['colors' => dbAllColors(db())]);
}

/* --------------------------------------------------------------- заявки */

/** Приём заявки с витрины: без авторизации, с проверкой и honeypot. */
function orderAdd(array $in): void {
    $db = db();
    if (trim((string)($in['website'] ?? '')) !== '') success(['id' => 0]);

    $name  = trim((string)($in['name'] ?? ''));
    $phone = trim((string)($in['phone'] ?? ''));

    if ($name === '') fail('Укажите имя');
    if (!validPhone($phone)) fail('Укажите телефон для связи');

    $items = (array)($in['items'] ?? []);
    $total = 0.0;
    foreach ($items as $it) $total += (float)($it['lineTotal'] ?? 0);

    $kind = trim((string)($in['kind'] ?? ''));
    if ($kind === '') {
        $hasOpt = false; $hasRet = false;
        foreach ($items as $it) {
            if (($it['mode'] ?? '') === 'opt') $hasOpt = true;
            else $hasRet = true;
        }
        $kind = $hasOpt && $hasRet ? 'опт + розница' : ($hasOpt ? 'опт' : 'розница');
    }

    $id = dbAddOrder($db, [
        'name'    => mb_substr($name, 0, 120, 'UTF-8'),
        'phone'   => mb_substr($phone, 0, 40, 'UTF-8'),
        'email'   => mb_substr(trim((string)($in['email'] ?? '')), 0, 120, 'UTF-8'),
        'city'    => mb_substr(trim((string)($in['city'] ?? '')), 0, 120, 'UTF-8'),
        'comment' => mb_substr(trim((string)($in['comment'] ?? '')), 0, 2000, 'UTF-8'),
        'items'   => $items,
        'total'   => $total,
        'kind'    => $kind,
        'source'  => mb_substr(trim((string)($in['source'] ?? '')), 0, 60, 'UTF-8'),
    ]);

    success(['id' => $id]);
}

function ordersList(array $in): void {
    requireAuth();
    success([
        'orders' => dbOrders(db(), (string)($in['status'] ?? 'all')),
        'counts' => dbOrderCounts(db()),
    ]);
}

function orderUpdate(array $in): void {
    requireAuth();
    checkCsrf();
    $id = (int)($in['id'] ?? 0);
    if ($id <= 0) fail('Не указан номер заявки');
    dbUpdateOrder(db(), $id, [
        'status' => (string)($in['status'] ?? 'new'),
        'note'   => (string)($in['note'] ?? ''),
    ]);
    success();
}

function orderDelete(array $in): void {
    requireAdmin();
    dbDeleteOrder(db(), (int)($in['id'] ?? 0));
    success();
}

/* ----------------------------------------------------------- настройки */

function settingsGet(): void {
    requireAuth();
    success(['settings' => dbAllSettings(db())]);
}

function settingsSave(array $in): void {
    requireAdmin();
    $s = (array)($in['settings'] ?? []);
    foreach ($s as $group => $values) {
        if (!preg_match('/^[a-z_]+$/', (string)$group)) continue;
        $current = (array)(dbGetSetting(db(), (string)$group, []) ?: []);
        foreach ((array)$values as $k => $v) {
            if (!preg_match('/^[a-zA-Z0-9_]+$/', (string)$k)) continue;
            $current[$k] = is_scalar($v) ? (string)$v : $v;
        }
        dbSetSetting(db(), (string)$group, $current);
    }
    success(['settings' => dbAllSettings(db())]);
}

/* ------------------------------------------------------------- пользов. */

function usersList(): void {
    requireAdmin();
    success(['users' => dbAllUsers(db()), 'attempts' => dbListLoginAttempts(db())]);
}

function userAdd(array $in): void {
    requireAdmin();
    $u = trim((string)($in['username'] ?? ''));
    $p = (string)($in['password'] ?? '');
    if ($u === '' || strlen($p) < 6) fail('Логин обязателен, пароль — минимум 6 символов');
    if (dbGetUser(db(), $u)) fail('Такой логин уже есть');
    dbCreateUser(db(), $u, $p, (string)($in['role'] ?? 'operator'));
    success(['users' => dbAllUsers(db())]);
}

function userUpdate(array $in): void {
    requireAdmin();
    $u = trim((string)($in['username'] ?? ''));
    $me = requireAdmin();
    if (!empty($in['password'])) {
        if (strlen((string)$in['password']) < 6) fail('Пароль — минимум 6 символов');
        dbSetPassword(db(), $u, (string)$in['password']);
    }
    if (!empty($in['role'])) dbSetRole(db(), $u, (string)$in['role']);
    success(['users' => dbAllUsers(db())]);
}

function userDelete(array $in): void {
    $me = requireAdmin();
    $u = (string)($in['username'] ?? '');
    if ($u === $me['username']) fail('Нельзя удалить себя');
    dbDeleteUser(db(), $u);
    success(['users' => dbAllUsers(db())]);
}

function userUnlock(array $in): void {
    requireAdmin();
    dbUnlockUser(db(), (string)($in['username'] ?? ''));
    success(['attempts' => dbListLoginAttempts(db())]);
}

/* ---------------------------------------------------------------- фото */

function upload(array $in): void {
    requireAdmin();

    $sku = trim((string)($in['sku'] ?? ''));
    if ($sku === '') fail('Не указан артикул');
    if (!dbGetProduct(db(), $sku)) fail('Товар не найден');

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        fail('Файл не получен');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$_FILES['file']['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) fail('Только JPG, PNG или WebP');

    $dir = __DIR__ . '/images/products';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) fail('Нет папки для фото');

    $base = preg_replace('/[^0-9A-Za-z_-]+/', '', $sku) ?: 'sku';
    $name = $base . '-' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $dest = $dir . '/' . $name;

    if (!move_uploaded_file((string)$_FILES['file']['tmp_name'], $dest)) fail('Не удалось сохранить файл');
    @chmod($dest, 0644);

    if (function_exists('makeThumb')) {
        makeThumb($dest, preg_replace('~\.jpg$~i', '-t.jpg', $dest) ?: $dest, 400);
    }

    success(['path' => 'images/products/' . $name, 'thumb' => 'images/products/' . preg_replace('~\.jpg$~i', '-t.jpg', $name)]);
}

function photoDelete(array $in): void {
    requireAdmin();
    $rel = (string)($in['path'] ?? '');
    if (!preg_match('~^images/products/[0-9A-Za-z_.-]+\.(jpg|jpeg|png|webp)$~i', $rel)) fail('Недопустимый путь');
    $abs = __DIR__ . '/' . $rel;
    if (!is_file($abs)) fail('Файла нет');
    @unlink($abs);
    $t = preg_replace('~\.jpe?g$~i', '-t.jpg', $rel);
    if ($t && is_file(__DIR__ . '/' . $t)) @unlink(__DIR__ . '/' . $t);
    success();
}

/* ----------------------------------------------------------------- прочее */

function catalogPublic(): void {
    $db = db();
    $out = dbCatalog($db);
    $s = dbAllSettings($db);
    $out['settings'] = [
        'order'    => (array)($s['order'] ?? []),
        'contacts' => (array)($s['contacts'] ?? []),
    ];
    header('Cache-Control: public, max-age=60');
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function exportJson(): void {
    requireAdminRead();
    header('Content-Disposition: attachment; filename="products.json"');
    echo json_encode(dbCatalog(db()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

/** Миниатюра из GD; если GD нет — копия исходника. */
function makeThumb(string $src, string $dest, int $maxW): void {
    if (!function_exists('imagecreatefromjpeg')) {
        @copy($src, $dest);
        return;
    }
    $info = @getimagesize($src);
    if (!$info) return;
    [$w, $h, $type] = $info;

    $image = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($src),
        IMAGETYPE_PNG  => @imagecreatefrompng($src),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false,
        default        => false,
    };
    if (!$image) { @copy($src, $dest); return; }

    $tw = min($maxW, $w);
    $th = max(1, (int)round($h * $tw / $w));
    $out = imagecreatetruecolor($tw, $th);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    $transparent = imagecolorallocatealpha($out, 255, 255, 255, 127);
    imagefilledrectangle($out, 0, 0, $tw, $th, $transparent);
    imagecopyresampled($out, $image, 0, 0, 0, 0, $tw, $th, $w, $h);
    imagejpeg($out, $dest, 82);
    imagedestroy($out);
    imagedestroy($image);
}

function backupDb(): void {
    requireAdminRead();
    $file = getenv('SITE_DB') ?: (__DIR__ . '/site.db');
    if (!is_file($file)) fail('Файл базы не найден');
    header('Content-Disposition: attachment; filename="poliform-' . date('Ymd-His') . '.sqlite"');
    header('Content-Type: application/octet-stream');
    readfile($file);
    exit;
}

/**
 * Полная копия сайта одним ZIP-архивом: все файлы проекта + база site.db.
 * Сначала принудительно списываем WAL в основной файл, чтобы копия базы была целостной.
 */
function backupFull(): void {
    requireAdminRead();
    $base = __DIR__;

    /* Предыдущие копии, служебные файлы и мусор в архив не берём. */
    $skipSegments = ['.git', '.github', 'node_modules'];
    $skipNames = ['site.db-wal', 'site.db-shm'];
    $skipPrefixes = ['site.db-bak-', '~$']; /* прежние бэкапы БД и временные файлы Office */

    try {
        db()->query('PRAGMA wal_checkpoint(TRUNCATE)');
    } catch (Throwable $e) {
        /* не критично — основная база всё равно попадает в архив */
    }

    $zipPath = (string)tempnam(sys_get_temp_dir(), 'plzip');
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($zipPath);
        fail('Не удалось создать архив', 500);
    }

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($it as $file) {
        $path = (string)$file->getPathname();
        $rel  = str_replace('\\', '/', substr($path, strlen($base) + 1));
        if ($rel === '' || $rel === $zipPath) continue;

        $skip = false;
        foreach (explode('/', $rel) as $seg) {
            if (in_array($seg, $skipSegments, true) || in_array($seg, $skipNames, true)) { $skip = true; break; }
            foreach ($skipPrefixes as $p) if (str_starts_with($seg, $p)) { $skip = true; break; }
            if ($skip) break;
        }
        if ($skip) continue;

        $zip->addFile($path, $rel);
    }

    $count = (int)$zip->numFiles;
    $zip->close();

    if ($count === 0 || !is_file($zipPath)) {
        @unlink($zipPath);
        fail('Не удалось собрать архив', 500);
    }

    header('Content-Disposition: attachment; filename="poliform-site-' . date('Ymd-His') . '.zip"');
    header('Content-Type: application/zip');
    header('Content-Length: ' . (string)filesize($zipPath));
    readfile($zipPath);
    @unlink($zipPath);
    exit;
}

/* --------------------------------------------------------------- роутер */

$action = (string)($_GET['action'] ?? ($_POST['action'] ?? ''));
$in     = input();

try {
    switch ($action) {
        case 'login':        success(login($in)); break;
        case 'logout':       logout(); success(); break;
        case 'session':      success(['user' => currentUser(), 'csrf' => csrfToken()]); break;

        case 'products':     requireAuth(); success(productsPayload()); break;
        case 'product_save': productSave($in); break;
        case 'product_del':  productDelete($in); break;
        case 'product_move': productMove($in); break;
          case 'product_rename': productRename($in); break;
        case 'cat_save':     categorySave($in); break;
        case 'cat_del':      categoryDelete($in); break;
        case 'color_save':   colorSave($in); break;
        case 'color_del':    colorDelete($in); break;

        case 'order_add':    orderAdd($in); break;
        case 'orders':       ordersList($in); break;
        case 'order_save':   orderUpdate($in); break;
        case 'order_del':    orderDelete($in); break;

        case 'settings':     settingsGet(); break;
        case 'settings_save': settingsSave($in); break;

        case 'users':        usersList(); break;
        case 'user_add':     userAdd($in); break;
        case 'user_save':    userUpdate($in); break;
        case 'user_del':     userDelete($in); break;
        case 'user_unlock':  userUnlock($in); break;

        case 'upload':       upload($in); break;
        case 'photo_del':    photoDelete($in); break;
        case 'export':       exportJson(); break;
        case 'backup':       backupDb(); break;
        case 'backup_full':  backupFull(); break;

        case 'catalog':      catalogPublic(); break;

        default:             fail('Неизвестное действие', 400);
    }
} catch (Throwable $e) {
    error_log('api: ' . $e->getMessage());
    fail('Ошибка сервера: ' . $e->getMessage(), 500);
}
