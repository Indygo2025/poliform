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
require_once __DIR__ . '/lib/xlsx.php';
require_once __DIR__ . '/lib/image.php';
require_once __DIR__ . '/inc/orders_mail.php';

const MAX_LOGIN_ATTEMPTS = 5;
const LOCK_MINUTES      = 5;
/** Перебор по IP: не больше попыток с одного адреса за окно (в минутах). */
const IP_LOGIN_ATTEMPTS = 30;
const IP_LOGIN_WINDOW   = 15;
/** Спам-фильтр заявок: не больше LIMIT заявок с одного IP за WINDOW минут. */
const ORDER_RATE_LIMIT  = 8;
const ORDER_RATE_WINDOW = 15;

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

/** Длительность автоблокировки в минутах (настраивается админом, 1…1440). */
function lockMinutes(): int {
    $s = dbGetSetting(db(), 'security');
    $m = is_array($s) ? (int)($s['lockMinutes'] ?? 0) : 0;
    return ($m >= 1 && $m <= 1440) ? $m : LOCK_MINUTES;
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

    $ip = clientIp();
    if (dbIpLoginBlocked($db, $ip, IP_LOGIN_ATTEMPTS, IP_LOGIN_WINDOW)) {
        dbAudit($db, $username, 'ip_block', $ip, 'слишком много неудачных входов');
        fail('Слишком много неудачных попыток. Попробуйте позже.', 429);
    }

    $lock = dbLockInfo($db, $username);
    if ($lock) {
        fail('Вход заблокирован до ' . date('H:i', strtotime((string)$lock['locked_until'])), 429);
    }

    $user = dbGetUser($db, $username);
    if (!$user || !password_verify($password, (string)$user['password_hash'])) {
        $lockMin = lockMinutes();
        $msg = dbFailLogin($db, $username, clientIp(), MAX_LOGIN_ATTEMPTS, $lockMin);
        dbAudit($db, $username, 'login_fail', clientIp(), $msg !== null ? 'неудачные попытки исчерпаны' : 'неверный пароль');
        if ($msg !== null) {
            dbAudit($db, $username, 'lock', clientIp(), 'автоблокировка на ' . $lockMin . ' мин.');
        }
        /* Небольшая задержка замедляет перебор даже при смене логинов. */
        usleep(250000);
        fail($msg ?? 'Неверный логин или пароль', 401);
    }

    dbClearLogin($db, $username);
    dbTouchLastLogin($db, $username);
    dbAudit($db, $username, 'login_ok', clientIp(), '');
    rotateSessionId();
    $_SESSION['pl_user'] = $username;
    unset($_SESSION['pl_csrf']);
    return ['user' => ['username' => $user['username'], 'role' => $user['role']], 'csrf' => csrfToken()];
}

function logout(): void {
    $username = (string)($_SESSION['pl_user'] ?? '');
    if ($username !== '') dbAudit(db(), $username, 'logout', clientIp(), '');
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

/**
 * Короткоживущий одноразовый токен для скачивания обычной ссылкой.
 * CSRF-токен в URL не светим: он долгоживущий и годится для всех действий.
 * Токен живёт 5 минут и удаляется после первого использования.
 */
function downloadToken(): string {
    $now  = time();
    $list = (array)($_SESSION['pl_dl'] ?? []);
    foreach ($list as $t => $exp) {
        if ((int)$exp < $now) unset($list[$t]);
    }
    $t = bin2hex(random_bytes(16));
    $list[$t] = $now + 300;
    $_SESSION['pl_dl'] = $list;
    return $t;
}

function checkDownloadToken(): void {
    $t = isset($_GET['token']) && is_string($_GET['token']) ? $_GET['token'] : '';
    $list = (array)($_SESSION['pl_dl'] ?? []);
    $exp  = ($t !== '' && isset($list[$t])) ? (int)$list[$t] : 0;
    if ($exp === 0 || $exp < time()) {
        fail('Ссылка устарела — обновите страницу и скачайте снова', 403);
    }
    unset($_SESSION['pl_dl'][$t]);
}

/**
 * Скачивания обычной ссылкой (backup/export/report): одноразовый токен
 * передаём в параметре ?token=… и проверяем вручную. SameSite=Lax не
 * блокирует top-level переход, поэтому без токена чужая страница могла бы
 * ссылкой инициировать выгрузку БД из открытой сессии админа.
 */
function requireAuthDownload(bool $adminOnly = true): array {
    $u = $adminOnly ? requireAuth('admin') : requireAuth();
    checkDownloadToken();
    return $u;
}

/** Выдать токен для скачивания (POST с CSRF-заголовком). */
function downloadTokenAction(): void {
    requireAuth();
    checkCsrf();
    success(['token' => downloadToken()]);
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

/** Приём заявки с витрины: без авторизации, с проверкой, honeypot,
 *  спам-фильтром по IP и пересчётом цен на сервере. */
function orderAdd(array $in): void {
    $db = db();
    if (trim((string)($in['website'] ?? '')) !== '') success(['id' => 0]);

    /* Спам-фильтр: ограничиваем частоту заявок с одного адреса. */
    if (!dbOrderRateOk($db, clientIp(), ORDER_RATE_LIMIT, ORDER_RATE_WINDOW)) {
        fail('Слишком много заявок подряд. Позвоните нам или попробуйте позже.', 429);
    }

    $name  = trim((string)($in['name'] ?? ''));
    $phone = trim((string)($in['phone'] ?? ''));

    if ($name === '') fail('Укажите имя');
    if (!validPhone($phone)) fail('Укажите телефон для связи');

    /* Цены пересчитываем по базе: клиент присылает только sku, режим и количество.
       Так витрину нельзя использовать для «заказа» по заниженной цене. */
    $items = [];
    $total = 0.0;
    foreach ((array)($in['items'] ?? []) as $it) {
        $sku = trim((string)($it['sku'] ?? ''));
        $p   = $sku !== '' ? dbGetProduct($db, $sku) : null;
        if (!$p) fail('Позиция ' . ($sku !== '' ? $sku : '?') . ' не найдена — обновите страницу и соберите заявку заново');

        $mode = (($it['mode'] ?? '') === 'opt') ? 'opt' : 'ret';
        $qty  = (int)($it['qty'] ?? 0);
        if ($mode === 'opt') {
            $pack = max(1, (int)$p['packCount']);
            $qty  = max($pack, (int)ceil($qty / $pack) * $pack); /* кратно боксу */
        }
        $qty  = max(1, $qty);
        $unit = $mode === 'opt' ? (float)$p['priceOpt'] : (float)$p['priceRetail'];
        $line = round($unit * $qty, 2);
        $total += $line;

        $items[] = [
            'sku'       => (string)$p['sku'],
            'baseArt'   => (string)$p['baseArt'],
            'title'     => (string)$p['title'],
            'mode'      => $mode,
            'qty'       => $qty,
            'packCount' => (int)$p['packCount'],
            'unit'      => $unit,
            'lineTotal' => $line,
        ];
    }
    $total = round($total, 2);

    $kind = trim((string)($in['kind'] ?? ''));
    if ($kind === '') {
        $hasOpt = false; $hasRet = false;
        foreach ($items as $it) {
            if (($it['mode'] ?? '') === 'opt') $hasOpt = true;
            else $hasRet = true;
        }
        $kind = $hasOpt && $hasRet ? 'опт + розница' : ($hasOpt ? 'опт' : 'розница');
    }

    $order = [
        'name'    => mb_substr($name, 0, 120, 'UTF-8'),
        'phone'   => mb_substr($phone, 0, 40, 'UTF-8'),
        'email'   => mb_substr(trim((string)($in['email'] ?? '')), 0, 120, 'UTF-8'),
        'city'    => mb_substr(trim((string)($in['city'] ?? '')), 0, 120, 'UTF-8'),
        'comment' => mb_substr(trim((string)($in['comment'] ?? '')), 0, 2000, 'UTF-8'),
        'items'   => $items,
        'total'   => $total,
        'kind'    => $kind,
        'source'  => mb_substr(trim((string)($in['source'] ?? '')), 0, 60, 'UTF-8'),
    ];

    $id = dbAddOrder($db, $order);
    dbOrderRateHit($db, clientIp());

    /* Письмо о новой заявке. Ошибки SMTP уже записаны в lastStatus и журнал:
       посетитель в любом случае получает подтверждение, заявка сохранена. */
    $order['id']      = $id;
    $order['created'] = date('Y-m-d H:i:s');
    orderNoticeSend($db, $order);

    success(['id' => $id]);
}

function ordersList(array $in): void {
    requireAuth();
    success([
        'orders' => dbOrders(db(), (string)($in['status'] ?? 'all')),
        'counts' => dbOrderCounts(db()),
    ]);
}

/**
 * Лёгкая проверка для живого уведомления в админке: нет ли новых заявок.
 * Отдаёт счётчики и несколько последних «новых» записей.
 */
function ordersLatest(): void {
    requireAuth();
    $db = db();
    $st = $db->prepare("SELECT id, name, phone, city, total, kind, created
                        FROM orders WHERE status = 'new' ORDER BY id DESC LIMIT 10");
    $st->execute();
    success([
        'counts' => dbOrderCounts($db),
        'latest' => $st->fetchAll(),
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

/**
 * Кнопка «Отправить тестовое письмо»: проверяет SMTP и показывает, как будет
 * выглядеть уведомление о заявке. Работает и при снятой галочке.
 */
function notifyTest(): void {
    $user = requireAdmin();
    $res  = orderNoticeTest(db());

    if (!$res['sent']) {
        fail($res['error'] !== '' ? $res['error'] : 'Письмо не отправлено', 500);
    }

    dbAudit(db(), (string)$user['username'], 'notify_test', clientIp(),
        'получатель: ' . implode(', ', (array)($res['recipients'] ?? [])));
    success(['settings' => dbAllSettings(db())]);
}

/* ----------------------------------------------------------- настройки */

function settingsGet(): void {
    $u = requireAuth();
    $s = dbAllSettings(db());
    /* SMTP-пароль и адреса сервера — только админу: оператору они не нужны. */
    if ((string)$u['role'] !== 'admin') unset($s['mail']);
    success(['settings' => $s]);
}

/** Группы настроек, которые админка вообще может сохранять. */
const SETTINGS_GROUPS = ['site', 'seo', 'contacts', 'blocks', 'home', 'order', 'mail', 'shop', 'security'];

function settingsSave(array $in): void {
    requireAdmin();
    $s = (array)($in['settings'] ?? []);
    foreach ($s as $group => $values) {
        if (!in_array((string)$group, SETTINGS_GROUPS, true)) continue;
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
    $sec = dbGetSetting(db(), 'security');
    $lockMin = is_array($sec) ? (int)($sec['lockMinutes'] ?? 0) : 0;
    success([
        'users'       => dbAllUsers(db()),
        'attempts'    => dbListLoginAttempts(db()),
        'lockMinutes' => $lockMin >= 1 ? $lockMin : LOCK_MINUTES,
    ]);
}

/** Роли пользователей: всё остальное — отклоняем. */
function validRole(string $role): string {
    return in_array($role, ['admin', 'operator'], true) ? $role : 'operator';
}

/** Пароль: минимум 8 символов, буквы и цифры. */
function validPassword(string $p): bool {
    return strlen($p) >= 8 && preg_match('/\p{L}/u', $p) && preg_match('/\d/', $p);
}

function userAdd(array $in): void {
    requireAdmin();
    $u = trim((string)($in['username'] ?? ''));
    $p = (string)($in['password'] ?? '');
    if ($u === '') fail('Укажите логин');
    if (!validPassword($p)) fail('Пароль — минимум 8 символов, буквы и цифры');
    if (dbGetUser(db(), $u)) fail('Такой логин уже есть');
    dbCreateUser(db(), $u, $p, validRole((string)($in['role'] ?? 'operator')));
    success(['users' => dbAllUsers(db())]);
}

function userUpdate(array $in): void {
    $me = requireAdmin();
    $u = trim((string)($in['username'] ?? ''));
    if ($u === '') fail('Не указан логин');

    if (!empty($in['password'])) {
        if (!validPassword((string)$in['password'])) fail('Пароль — минимум 8 символов, буквы и цифры');
        dbSetPassword(db(), $u, (string)$in['password']);
        dbAudit(db(), $me['username'], 'password', clientIp(), 'смена пароля: ' . $u);
    }
    if (!empty($in['role'])) {
        $target = dbGetUser(db(), $u);
        if (!$target) fail('Пользователь не найден');
        $newRole = validRole((string)$in['role']);
        if ((string)$target['role'] === 'admin' && $newRole !== 'admin' && dbCountAdmins(db()) <= 1) {
            fail('Нельзя понизить последнего администратора');
        }
        dbSetRole(db(), $u, $newRole);
    }
    success(['users' => dbAllUsers(db())]);
}

function userDelete(array $in): void {
    $me = requireAdmin();
    $u = (string)($in['username'] ?? '');
    if ($u === $me['username']) fail('Нельзя удалить себя');
    $target = dbGetUser(db(), $u);
    if (!$target) fail('Пользователь не найден');
    if ((string)$target['role'] === 'admin' && dbCountAdmins(db()) <= 1) {
        fail('Нельзя удалить последнего администратора');
    }
    dbDeleteUser(db(), $u);
    success(['users' => dbAllUsers(db())]);
}

function userUnlock(array $in): void {
    $me = requireAdmin();
    $u = (string)($in['username'] ?? '');
    dbUnlockUser(db(), $u);
    if ($u !== '') dbAudit(db(), $me['username'], 'unlock', clientIp(), 'разблокирован: ' . $u);
    success(['attempts' => dbListLoginAttempts(db())]);
}

/** Ручная блокировка учётной записи на выбранное число минут (0 — снять). */
function userBlock(array $in): void {
    $me = requireAdmin();
    $u = trim((string)($in['username'] ?? ''));
    if ($u === '') fail('Не указан логин');
    $min = (int)($in['minutes'] ?? 0);

    if ($min <= 0) {
        dbUnlockUser(db(), $u);
        dbAudit(db(), $me['username'], 'unlock', clientIp(), 'разблокирован: ' . $u);
        success(['attempts' => dbListLoginAttempts(db())]);
    }
    if ($min > 1440) fail('Максимум 1440 минут');
    if (!dbGetUser(db(), $u)) fail('Пользователь не найден');

    dbSetLock(db(), $u, $min, clientIp());
    dbAudit(db(), $me['username'], 'block', clientIp(), 'заблокирован ' . $u . ' на ' . $min . ' мин.');
    success(['attempts' => dbListLoginAttempts(db())]);
}

/** Журнал доступа: входы, выходы, неудачи, блокировки. */
function auditList(array $in): void {
    requireAdmin();
    success(['entries' => dbAuditList(db(), [
        'username' => trim((string)($in['username'] ?? '')),
        'action'   => trim((string)($in['action'] ?? '')),
        'limit'    => (int)($in['limit'] ?? 200),
    ])]);
}

/** Настройка длительности автоблокировки. */
function securitySave(array $in): void {
    $me = requireAdmin();
    $m = (int)($in['lockMinutes'] ?? 0);
    if ($m < 1 || $m > 1440) fail('Длительность блокировки — от 1 до 1440 минут');
    dbSetSetting(db(), 'security', ['lockMinutes' => $m]);
    dbAudit(db(), $me['username'], 'security', clientIp(), 'автоблокировка ' . $m . ' мин.');
    success(['lockMinutes' => $m]);
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

    /* Ограничиваем размер файла и пикселей: без этого GD при создании
       миниатюры мог съесть память на «бомбе декомпрессии». */
    $size = (int)($_FILES['file']['size'] ?? 0);
    if ($size <= 0 || $size > 8 * 1024 * 1024) fail('Файл больше 8 МБ');
    $info = @getimagesize((string)$_FILES['file']['tmp_name']);
    if (!$info || (int)$info[0] < 1 || (int)$info[1] < 1) fail('Не удалось прочитать изображение');
    if ((int)$info[0] > 6000 || (int)$info[1] > 6000 || (int)$info[0] * (int)$info[1] > 24000000) {
        fail('Слишком большое изображение: максимум 6000 px по стороне');
    }

    $dir = __DIR__ . '/images/products';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) fail('Нет папки для фото');

    /* Без GD нельзя сделать .webp и миниатюру, а фронтенд ждёт .webp рядом
       с исходником. Лучше явная ошибка, чем тихо сломанная карточка. */
    if (!function_exists('imagewebp')) {
        fail('На сервере не включено расширение GD (imagewebp) — фото не может быть обработано. '
            . 'Включите extension=gd в php.ini и перезапустите Apache.');
    }

    $base = preg_replace('/[^0-9A-Za-z_-]+/', '', $sku) ?: 'sku';
    $name = $base . '-' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $dest = $dir . '/' . $name;

    if (!move_uploaded_file((string)$_FILES['file']['tmp_name'], $dest)) fail('Не удалось сохранить файл');
    @chmod($dest, 0644);

    /* Миниатюра — отдельный файл <имя>-t.jpg для любого исходника
       (jpg/png/webp). Раньше для не-JPG путём подмены затирался оригинал. */
    $thumbName = preg_replace('~\.(jpe?g|png|webp)$~i', '-t.jpg', $name) ?: $name;
    if (function_exists('makeThumb')) {
        makeThumb($dest, $dir . '/' . $thumbName, 400);
    }

    /* Фронтенд повсюду подставляет .webp / -t.webp по расширению. Если их не
       создать, <picture> и js/shop.js получат 404 и фото не откроется. */
    $webpName = plWebpName($name);
    if ($webpName !== $name) plToWebp($dest, $dir . '/' . $webpName);
    $thumbWebp = preg_replace('~\.(jpe?g|png|webp)$~i', '-t.webp', $name) ?: ($name . '-t.webp');
    $thumbJpg  = $dir . '/' . $thumbName;
    plToWebpThumb(is_file($thumbJpg) ? $thumbJpg : $dest, $dir . '/' . $thumbWebp, 400);

    success(['path' => 'images/products/' . $name, 'thumb' => 'images/products/' . $thumbName]);
}

function photoDelete(array $in): void {
    requireAdmin();
    $rel = (string)($in['path'] ?? '');

    /* Разрешаем подпапки внутри images/products/ (например, from-price-sep-2026/),
       но запрещаем «..», обратные слеши и выход за пределы каталога фото. */
    if (!preg_match('~^images/products/(?:[0-9A-Za-z_.-]+/)*[0-9A-Za-z_.-]+\.(jpg|jpeg|png|webp)$~i', $rel)
        || str_contains($rel, '..') || str_contains($rel, '\\')) {
        fail('Недопустимый путь');
    }
    $root = realpath(__DIR__ . '/images/products');
    $abs  = realpath(__DIR__ . '/' . $rel);
    if ($root === false || $abs === false
        || !str_starts_with($abs, $root . DIRECTORY_SEPARATOR)) {
        fail('Недопустимый путь');
    }
    if (!is_file($abs)) success(['removed' => false]); // файл уже удалён — это не ошибка

    /* Файл, на который ссылается хоть один товар, физически не удаляем:
       иначе карточка останется без фото до сохранения, а общий файл из прайса
       сломается сразу у десятка товаров. Из черновика его уберёт админка. */
    $st = db()->prepare('SELECT COUNT(*) FROM products WHERE images LIKE ?');
    $st->execute(['%' . $rel . '%']);
    if ((int)$st->fetchColumn() >= 1) {
        success(['removed' => false, 'reason' => 'in_use']);
    }

    /* Удаляем файл и все его производные: -t.jpg, .webp, -t.webp.
       Проверяем каждый через realpath, чтобы не выйти за пределы каталога. */
    $candidates = [
        $rel,
        (string)preg_replace('~\.(jpe?g|png|webp)$~i', '-t.jpg', $rel),
        plWebpName($rel),
        (string)preg_replace('~\.(jpe?g|png|webp)$~i', '-t.webp', $rel),
    ];
    foreach (array_unique($candidates) as $c) {
        if ($c === '') continue;
        $f = realpath(__DIR__ . '/' . $c);
        if ($f !== false && str_starts_with($f, $root . DIRECTORY_SEPARATOR) && is_file($f)) {
            @unlink($f);
        }
    }
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
        'shop'     => (array)($s['shop'] ?? []),
    ];
    header('Cache-Control: public, max-age=60');
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function exportJson(): void {
    requireAuthDownload();
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
    requireAuthDownload();
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
    requireAuthDownload();
    $base = __DIR__;

    /* Предыдущие копии, служебные файлы и мусор в архив не берём. */
    $skipSegments = ['.git', '.github', 'node_modules', 'logs', 'dist'];
    $skipNames = ['site.db-wal', 'site.db-shm', 'Пароль.txt'];
    $skipPrefixes = ['site.db-bak-', 'site.db.bak-', '~$']; /* бэкапы БД и временные файлы Office */

    try {
        db()->query('PRAGMA wal_checkpoint(TRUNCATE)');
    } catch (Throwable $e) {
        /* не критично — основная база всё равно попадает в архив */
    }

    /* Если расширение zip доступно — используем ZipArchive, иначе свой архиватор. */
    $useZip = class_exists('ZipArchive');
    $zipPath = '';
    $zip = null;
    $entries = [];
    if ($useZip) {
        $zipPath = (string)tempnam(sys_get_temp_dir(), 'plzip');
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($zipPath);
            fail('Не удалось создать архив', 500);
        }
    }

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($it as $file) {
        $path = (string)$file->getPathname();
        $rel  = str_replace('\\', '/', substr($path, strlen($base) + 1));
        if ($rel === '' || ($zipPath !== '' && $rel === $zipPath)) continue;

        $skip = false;
        foreach (explode('/', $rel) as $seg) {
            if (in_array($seg, $skipSegments, true) || in_array($seg, $skipNames, true)) { $skip = true; break; }
            foreach ($skipPrefixes as $p) if (str_starts_with($seg, $p)) { $skip = true; break; }
            if ($skip) break;
        }
        if ($skip) continue;

        if ($useZip) $zip->addFile($path, $rel);
        else $entries[$rel] = (string)file_get_contents($path);
    }

    $fname = 'poliform-site-' . date('Ymd-His') . '.zip';

    if ($useZip) {
        $count = (int)$zip->numFiles;
        $zip->close();
        if ($count === 0 || !is_file($zipPath)) {
            @unlink($zipPath);
            fail('Не удалось собрать архив', 500);
        }
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        header('Content-Type: application/zip');
        header('Content-Length: ' . (string)filesize($zipPath));
        readfile($zipPath);
        @unlink($zipPath);
        exit;
    }

    if (!$entries) fail('Не удалось собрать архив', 500);
    $bin = zipCreate($entries);
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Content-Type: application/zip');
    header('Content-Length: ' . strlen($bin));
    echo $bin;
    exit;
}

/* --------------------------------------------------------------- отчёты */

function orderStatusLabel(string $s): string {
    return orderStatusName($s);
}

function orderKindLabel(string $k): string {
    return ['opt' => 'Опт', 'retail' => 'Розница', 'mix' => 'Смешанный'][$k] ?? ($k !== '' ? $k : '—');
}

/** Собирает лист отчёта: строки (первая — заголовки) + метаданные. */
function reportSheet(string $type, string $from = '', string $to = '', string $status = ''): array {
    $db = db();

    if ($type === 'sales') {
        $data = dbReportSales(db(), $from, $to);
        $rows = [['Артикул', 'Товар', 'Кол-во, шт', 'Розница, шт', 'Опт, шт', 'Сумма, ₽']];
        $q = $qr = $qo = 0;
        $sum = 0.0;
        foreach ($data as $d) {
            $rows[] = [$d['sku'], $d['title'], (int)$d['qty'], (int)$d['qtyRetail'], (int)$d['qtyOpt'], (float)$d['revenue']];
            $q += (int)$d['qty']; $qr += (int)$d['qtyRetail']; $qo += (int)$d['qtyOpt'];
            $sum += (float)$d['revenue'];
        }
        $rows[] = ['ИТОГО', '', $q, $qr, $qo, round($sum, 2)];
        return ['name' => 'Продажи', 'file' => 'sales-' . date('Ymd') . '.xlsx', 'rows' => $rows];
    }

    if ($type === 'orders') {
        $data = dbReportOrders($db, $from, $to, $status);
        $rows = [['№', 'Дата', 'Клиент', 'Телефон', 'Город', 'Тип', 'Позиций', 'Сумма, ₽', 'Статус']];
        $sum = 0.0;
        foreach ($data as $o) {
            $rows[] = [
                (int)$o['id'], (string)$o['created'], (string)$o['name'], (string)$o['phone'],
                (string)$o['city'], orderKindLabel((string)$o['kind']), count((array)$o['items']),
                (float)$o['total'], orderStatusLabel((string)$o['status']),
            ];
            $sum += (float)$o['total'];
        }
        $rows[] = ['', '', '', '', '', '', 'ИТОГО', round($sum, 2), ''];
        return ['name' => 'Заказы', 'file' => 'orders-' . date('Ymd') . '.xlsx', 'rows' => $rows];
    }

    $data = dbReportProducts($db);
    $rows = [['Артикул', 'Название', 'Категория', 'Цвет', 'Цена розница, ₽', 'Цена опт, ₽', 'Наличие']];
    foreach ($data as $p) {
        $rows[] = [$p['sku'], $p['title'], $p['category'], $p['color'], $p['priceRetail'], $p['priceOpt'], $p['stock']];
    }
    return ['name' => 'Товары', 'file' => 'products-' . date('Ymd') . '.xlsx', 'rows' => $rows];
}

/** JSON-данные отчёта для предпросмотра в админке. */
function reportData(array $in): void {
    requireAuth();
    $type   = (string)($in['type'] ?? 'products');
    $from   = (string)($in['from'] ?? '');
    $to     = (string)($in['to'] ?? '');
    $status = (string)($in['status'] ?? '');
    if (!in_array($type, ['products', 'sales', 'orders'], true)) $type = 'products';

    $sheet = reportSheet($type, $from, $to, $status);
    success(['name' => $sheet['name'], 'rows' => $sheet['rows']]);
}

/** Выгрузка отчёта в .xlsx обычной ссылкой (GET): токен в параметре ?token=…. */
function reportExport(): void {
    requireAuthDownload(false);
    $type   = isset($_GET['type']) && is_string($_GET['type']) ? $_GET['type'] : 'products';
    $from   = isset($_GET['from']) && is_string($_GET['from']) ? $_GET['from'] : '';
    $to     = isset($_GET['to']) && is_string($_GET['to']) ? $_GET['to'] : '';
    $status = isset($_GET['status']) && is_string($_GET['status']) ? $_GET['status'] : '';
    if (!in_array($type, ['products', 'sales', 'orders'], true)) $type = 'products';

    $sheet = reportSheet($type, $from, $to, $status);
    $bin = xlsxBuild([['name' => $sheet['name'], 'rows' => $sheet['rows']]]);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $sheet['file'] . '"');
    header('Content-Length: ' . strlen($bin));
    echo $bin;
    exit;
}

/* --------------------------------------------------------------- роутер */

$action = '';
if (isset($_GET['action']) && is_string($_GET['action'])) {
    $action = $_GET['action'];
} elseif (isset($_POST['action']) && is_string($_POST['action'])) {
    $action = $_POST['action'];
}
$in     = input();

try {
    switch ($action) {
        case 'login':        success(login($in)); break;
        case 'logout':
            requireAuth();
            checkCsrf();
            logout();
            success();
            break;
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
        case 'orders_latest': ordersLatest(); break;
        case 'order_save':   orderUpdate($in); break;
        case 'order_del':    orderDelete($in); break;
        case 'notify_test':  notifyTest(); break;

        case 'settings':     settingsGet(); break;
        case 'settings_save': settingsSave($in); break;

        case 'users':        usersList(); break;
        case 'user_add':     userAdd($in); break;
        case 'user_save':    userUpdate($in); break;
        case 'user_del':     userDelete($in); break;
        case 'user_unlock':  userUnlock($in); break;
        case 'user_block':   userBlock($in); break;
        case 'audit':        auditList($in); break;
        case 'security_save': securitySave($in); break;

        case 'upload':       upload($in); break;
        case 'photo_del':    photoDelete($in); break;
        case 'report_data':  reportData($in); break;
        case 'report_export': reportExport(); break;
        case 'dl_token':     downloadTokenAction(); break;
        case 'export':       exportJson(); break;
        case 'backup':       backupDb(); break;
        case 'backup_full':  backupFull(); break;

        case 'catalog':      catalogPublic(); break;

        default:             fail('Неизвестное действие', 400);
    }
} catch (Throwable $e) {
    /* Текст исключения — только в лог сервера: клиенту наружу детали не выдаём. */
    error_log('api: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    fail('Внутренняя ошибка сервера. Попробуйте ещё раз или позвоните менеджеру.', 500);
}
