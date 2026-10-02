<?php
declare(strict_types=1);

/**
 * ПОЛИФОРМ — слой данных.
 * SQLite через PDO, схема создаётся при первом обращении,
 * каталог один раз заливается из data/products.json.
 */

$dbPath = getenv('SITE_DB') ?: (__DIR__ . '/site.db');
$db = null;

function db(): PDO {
    global $db, $dbPath;
    if ($db) return $db;

    $dir = dirname($dbPath);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);

    $db = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA busy_timeout = 5000');
    $db->exec('PRAGMA foreign_keys = ON');

    initSchema($db);
    seedFromJson($db);

    return $db;
}

/* ------------------------------------------------------------------ схема */

function initSchema(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS categories (
        id          TEXT PRIMARY KEY,
        name        TEXT NOT NULL DEFAULT '',
        short       TEXT NOT NULL DEFAULT '',
        desc        TEXT NOT NULL DEFAULT '',
        pos         INTEGER NOT NULL DEFAULT 0
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS products (
        sku          TEXT PRIMARY KEY,
        title        TEXT NOT NULL DEFAULT '',
        shortTitle   TEXT NOT NULL DEFAULT '',
        categoryId   TEXT NOT NULL DEFAULT '',
        art          TEXT NOT NULL DEFAULT '',
        form         TEXT NOT NULL DEFAULT '',
        sizeL        REAL NOT NULL DEFAULT 0,
        sizeW        REAL NOT NULL DEFAULT 0,
        sizeH        REAL NOT NULL DEFAULT 0,
        gridGap      REAL NOT NULL DEFAULT 0,
        color        TEXT NOT NULL DEFAULT '',
        colorHex     TEXT NOT NULL DEFAULT '',
        packCount    INTEGER NOT NULL DEFAULT 1,
        packNote     TEXT NOT NULL DEFAULT '',
        weightG      REAL NOT NULL DEFAULT 0,
        priceRetail  REAL NOT NULL DEFAULT 0,
        priceOpt     REAL NOT NULL DEFAULT 0,
        volumeMl     REAL NOT NULL DEFAULT 0,
        material     TEXT NOT NULL DEFAULT 'Пластик',
        country      TEXT NOT NULL DEFAULT 'Россия',
        cert         TEXT NOT NULL DEFAULT '',
        description  TEXT NOT NULL DEFAULT '',
        features     TEXT NOT NULL DEFAULT '[]',
        images       TEXT NOT NULL DEFAULT '[]',
        inStock      INTEGER NOT NULL DEFAULT 1,
        stockNote    TEXT NOT NULL DEFAULT '',
        sima         TEXT NOT NULL DEFAULT '',
        pos          INTEGER NOT NULL DEFAULT 0,
        updated      TEXT NOT NULL DEFAULT ''
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_products_cat ON products(categoryId)");

    $db->exec("CREATE TABLE IF NOT EXISTS product_colors (
        id    TEXT PRIMARY KEY,
        name  TEXT NOT NULL DEFAULT '',
        hex   TEXT NOT NULL DEFAULT '',
        pos   INTEGER NOT NULL DEFAULT 0
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS orders (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        name        TEXT NOT NULL DEFAULT '',
        phone       TEXT NOT NULL DEFAULT '',
        email       TEXT NOT NULL DEFAULT '',
        city        TEXT NOT NULL DEFAULT '',
        comment     TEXT NOT NULL DEFAULT '',
        items       TEXT NOT NULL DEFAULT '[]',
        total       REAL NOT NULL DEFAULT 0,
        kind        TEXT NOT NULL DEFAULT '',
        source      TEXT NOT NULL DEFAULT '',
        status      TEXT NOT NULL DEFAULT 'new',
        note        TEXT NOT NULL DEFAULT '',
        created     TEXT NOT NULL DEFAULT ''
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_orders_status ON orders(status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_orders_created ON orders(created DESC)");

    $db->exec("CREATE TABLE IF NOT EXISTS settings (
        key    TEXT PRIMARY KEY,
        value  TEXT NOT NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS users (
        username       TEXT PRIMARY KEY,
        password_hash  TEXT NOT NULL,
        role           TEXT NOT NULL DEFAULT 'operator',
        created        TEXT NOT NULL DEFAULT ''
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        username      TEXT PRIMARY KEY,
        cnt           INTEGER NOT NULL DEFAULT 0,
        ip            TEXT NOT NULL DEFAULT '',
        last_attempt  TEXT NOT NULL DEFAULT '',
        locked_until  TEXT
    )");

    ensureColumn($db, 'products', 'onHome', 'INTEGER NOT NULL DEFAULT 0');
    backfillColorHexes($db);
}

/**
 * Досоставляет HEX справочнику цветов из data/products.json.
 * База заполняется сидом один раз, поэтому если позже добавить hex
 * в colorOptions, в уже созданной базе он так и останется пустым.
 */
function backfillColorHexes(PDO $db): void {
    $file = __DIR__ . '/data/products.json';
    if (!is_file($file)) return;

    $json = json_decode((string)file_get_contents($file), true);
    if (!is_array($json)) return;

    $st = $db->prepare("UPDATE product_colors SET hex = ? WHERE hex = '' AND name = ?");
    foreach (($json['colorOptions'] ?? []) as $co) {
        if (!is_array($co)) continue;
        $name = (string)($co['name'] ?? '');
        $hex  = (string)($co['hex'] ?? '');
        if ($name === '' || $hex === '') continue;
        $st->execute([$hex, $name]);
    }
}

/** Идемпотентное добавление колонки — для баз, созданных до этой правки. */
function ensureColumn(PDO $db, string $table, string $column, string $definition): void {
    foreach ($db->query("PRAGMA table_info($table)")->fetchAll() as $c) {
        if ($c['name'] === $column) return;
    }
    $db->exec("ALTER TABLE $table ADD COLUMN $column $definition");
}

/* --------------------------------------------------------------- сид из JSON */

function seedFromJson(PDO $db): void {
    $count = (int)$db->query("SELECT COUNT(*) AS c FROM products")->fetch()['c'];
    if ($count > 0) return;

    $file = __DIR__ . '/data/products.json';
    if (!is_file($file)) return;

    $json = json_decode((string)file_get_contents($file), true);
    if (!is_array($json) || empty($json['products'])) return;

    $now = date('Y-m-d H:i:s');
    $db->beginTransaction();
    try {
        foreach (($json['categories'] ?? []) as $i => $c) {
            $st = $db->prepare("INSERT OR REPLACE INTO categories (id, name, short, desc, pos)
                               VALUES (?,?,?,?,?)");
            $st->execute([
                (string)($c['id'] ?? ''),
                (string)($c['name'] ?? ''),
                (string)($c['short'] ?? ''),
                (string)($c['desc'] ?? ''),
                $i + 1,
            ]);
        }

        foreach (($json['colorOptions'] ?? []) as $i => $co) {
            $hex = '';
            $name = '';
            if (is_array($co)) {
                $name = (string)($co['name'] ?? '');
                $hex  = (string)($co['hex'] ?? '');
            } else {
                $name = (string)$co;
            }
            if ($name === '') continue;
            $db->prepare("INSERT OR REPLACE INTO product_colors (id, name, hex, pos) VALUES (?,?,?,?)")
                ->execute([slug($name), $name, $hex, $i + 1]);
        }

        foreach ($json['products'] as $i => $p) {
            $size = (array)($p['size'] ?? []);
            $st = $db->prepare("INSERT OR REPLACE INTO products
                (sku,title,shortTitle,categoryId,art,form,sizeL,sizeW,sizeH,gridGap,color,colorHex,
                 packCount,packNote,weightG,priceRetail,priceOpt,volumeMl,material,country,cert,
                 description,features,images,inStock,stockNote,sima,pos,updated)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $st->execute([
                (string)($p['sku'] ?? ''),
                (string)($p['title'] ?? ''),
                (string)($p['shortTitle'] ?? ''),
                (string)($p['categoryId'] ?? ''),
                (string)($p['art'] ?? ''),
                (string)($p['form'] ?? ''),
                (float)($size['l'] ?? 0),
                (float)($size['w'] ?? 0),
                (float)($size['h'] ?? 0),
                (float)($p['gridGap'] ?? 0),
                (string)($p['color'] ?? ''),
                (string)($p['colorHex'] ?? ''),
                (int)($p['packCount'] ?? 1),
                (string)($p['packNote'] ?? ''),
                (float)($p['weightG'] ?? 0),
                (float)($p['priceRetail'] ?? 0),
                (float)($p['priceOpt'] ?? 0),
                (float)($p['volumeMl'] ?? 0),
                (string)($p['material'] ?? 'Пластик'),
                (string)($p['country'] ?? 'Россия'),
                (string)($p['cert'] ?? ''),
                (string)($p['description'] ?? ''),
                json_encode(array_values((array)($p['features'] ?? [])), JSON_UNESCAPED_UNICODE),
                json_encode(array_values((array)($p['images'] ?? [])), JSON_UNESCAPED_UNICODE),
                !empty($p['inStock']) ? 1 : 0,
                (string)($p['stockNote'] ?? ''),
                (string)($p['sima'] ?? ''),
                $i + 1,
                $now,
            ]);
        }

        seedSettings($db, $json);
        dbCreateUser($db, 'admin', initialAdminPassword(), 'admin');

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Пароль первого администратора при первичном заполнении базы.
 * Задаётся переменной окружения POLIFORM_ADMIN_PASSWORD.
 * Если она не задана — генерируется случайный и печатается в консоль один раз,
 * чтобы в исходном коде не лежал готовый пароль.
 */
function initialAdminPassword(): string {
    $fromEnv = getenv('POLIFORM_ADMIN_PASSWORD');
    if (is_string($fromEnv) && trim($fromEnv) !== '') return trim($fromEnv);

    $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $pass = '';
    for ($i = 0; $i < 16; $i++) $pass .= $alphabet[random_int(0, strlen($alphabet) - 1)];

    fwrite(STDERR, "\n=== Создан администратор admin ===\n"
        . "Пароль (сохраните его): " . $pass . "\n"
        . "Задайте свой через переменную окружения POLIFORM_ADMIN_PASSWORD.\n"
        . "=========================================\n\n");

    return $pass;
}

function seedSettings(PDO $db, array $json): void {
    $defaults = [
        'site' => [
            'company'   => 'ООО «ПОЛИФОРМ КОМПАНИ»',
            'ogrn'      => '1126725000698',
            'inn'       => '6725018221',
            'kpp'       => '672501001',
            'okved'     => '22.21',
            'address'   => '216533, Смоленская обл., м.о. Рославльский, с. Екимовичи, 1-й Советский пер., д. 14А',
            'director'  => 'Васильев В. В.',
            'cert'      => 'Не подлежит сертификации',
            'madeIn'    => 'Россия',
        ],
        'contacts' => [
            'phone'    => '+7 (900) 000-00-00',
            'phoneHref'=> '+79000000000',
            'email'    => 'sales@poliform.ru',
            'telegram' => 'https://t.me/poliform',
            'tgLabel'  => '@poliform',
            'hours'    => 'Пн–Пт, 09:00–18:00 (МСК)',
        ],
        'seo' => [
            'title'       => 'ПОЛИФОРМ — пластиковые изделия для питомцев',
            'description' => 'Лотки и туалеты для кошек, миски одинарные и двойные собственного производства. Опт от одного бокса и розница поштучно.',
            'keywords'    => 'лоток для кошек, туалет для кошек, миска для кошек, пластиковые изделия, опт',
            'ogImage'     => 'images/products/100004-0.jpg',
        ],
        'order' => [
            'lead'    => 'Стоимость доставки рассчитывает менеджер. Наличие по цвету подтверждаем после заявки.',
            'success' => 'Заявка принята. Менеджер свяжется по телефону в рабочее время.',
            'requireApproved' => '1',
        ],
        'blocks' => [
            'heroTitle'   => 'Пластиковые изделия для питомцев',
            'heroLead'    => 'Лотки и туалеты для кошек, миски одинарные и двойные из плотного пластика. Каждая позиция отлита на собственной форме.',
            'heroVisualSku' => '100004',
            'homeAbout'   => 'Полиформ — производство пластмассовых изделий в Смоленской области. Лотки, туалеты и миски отливаются на собственных пресс-формах, поэтому размеры и толщина стенок повторяются от партии к партии.',
            'tagline'     => 'пластиковые изделия · опт и розница',
            'footerAbout' => 'Собственное производство пластиковых изделий: лотки и туалеты для кошек, миски одинарные и двойные. Опт от одного бокса и розница поштучно.',
            'howToOrder'  => 'Мы отгружаем и по одной штуке, и целыми боксами. Разница только в цене и кратности — сам порядок одинаковый.',
        ],
        'home' => [
            'heroEyebrow'   => 'Собственное производство · Смоленская обл.',
            'heroH1a'       => 'Пластиковые изделия',
            'heroH1b'       => 'которые мы делаем сами',
            'heroBtn1'      => 'Открыть каталог',
            'heroBtn2'      => 'Как оформить заказ',
            'kpi1'          => 'артикулов в каталоге',
            'kpi2'          => 'категории изделий',
            'kpi3'          => '₽ за шт. — мин. опт',
            'kpi4'          => 'шт. в боксе',
            'heroAlt'       => 'Пластиковое изделие собственного производства',

            'catEyebrow'    => 'Ассортимент',
            'catTitleA'     => 'Производственные',
            'catTitleB'     => 'линейки',
            'catSub'        => 'Каждая категория — своя пресс-форма и своя фасовка. Внутри категории позиции отличаются размером, высотой борта и цветом, поэтому удобнее выбирать по артикулу.',

            'popEyebrow'    => 'Витрина',
            'popTitleA'     => 'Популярные',
            'popTitleB'     => 'позиции',
            'popSub'        => 'Цены переключаются вместе с режимом в шапке. В оптовом режиме шаг количества равен боксу — добавить можно только целое число коробов.',
            'popBtnAll'     => 'Весь каталог',

            'howEyebrow'    => 'Опт и розница',
            'howTitleA'     => 'Как устроена',
            'howTitleB'     => 'продажа',
            'howSub'        => 'Мы отгружаем и по одной штуке, и целыми боксами. Разница только в цене и кратности — сам порядок одинаковый.',
            'step1Title'    => 'Выберите режим',
            'step1Text'     => 'Переключатель в шапке меняет все цены на сайте: розница — шаг 1 шт., опт — шаг равен фасовке бокса. Выбор сохраняется при переходе между страницами.',
            'step2Title'    => 'Соберите заказ',
            'step2Text'     => 'Кладёте позиции в корзину в нужном режиме. Можно смешивать: например, пять боксов лотков и отдельно розничные миски — итог посчитается двумя строками.',
            'step3Title'    => 'Отправьте заявку',
            'step3Text'     => 'В корзине заполните имя и телефон. Заявка попадает менеджеру, он подтверждает наличие, считает доставку и присылает счёт. Оплата — по договору или по счёту для юрлиц.',
            'boxesTitle'    => 'Почему опт считается боксами',
            'boxesText'     => 'Изделия отгружаются в гофрокоробах: лотки — по 20–60 шт., миски — по 300–600 шт. Оптовая цена действует от одного полного бокса, поэтому количество в оптовом режиме автоматически округляется вверх до кратного фасовке. Так вы всегда получаете ровно закрытую партию без «неполных коробок» на складе.',

            'ctaEyebrow'    => 'Контакты',
            'ctaTitleA'     => 'Заявка',
            'ctaTitleB'     => 'на партию',
            'ctaSub'        => 'Пришлите перечень артикулов с количеством в боксах — ответим по наличию, срокам и стоимости доставки.',
            'ctaBtn1'       => 'Оформить заявку',
            'ctaBtn2'       => 'Написать в Telegram',

            'aboutEyebrow'  => 'О производстве',
            'aboutTitleA'   => 'Собственное производство',
            'aboutTitleB'   => 'в Смоленской области',
            'aboutBtn'      => 'Подробнее о компании',
        ],
    ];

    foreach ($defaults as $key => $val) {
        $st = $db->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (?,?)");
        $st->execute([$key, json_encode($val, JSON_UNESCAPED_UNICODE)]);
    }
}

/* -------------------------------------------------------------- доступ */

function dbCreateUser(PDO $db, string $username, string $password, string $role = 'operator'): bool {
    $st = $db->prepare("INSERT OR REPLACE INTO users (username, password_hash, role, created) VALUES (?,?,?,?)");
    return $st->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role, date('Y-m-d H:i:s')]);
}

function dbGetUser(PDO $db, string $username): ?array {
    $st = $db->prepare("SELECT * FROM users WHERE username = ?");
    $st->execute([$username]);
    $u = $st->fetch();
    return $u ?: null;
}

function dbAllUsers(PDO $db): array {
    return $db->query("SELECT username, role, created FROM users ORDER BY username")->fetchAll();
}

function dbDeleteUser(PDO $db, string $username): bool {
    return $db->prepare("DELETE FROM users WHERE username = ?")->execute([$username]);
}

function dbSetPassword(PDO $db, string $username, string $password): bool {
    return $db->prepare("UPDATE users SET password_hash = ? WHERE username = ?")
        ->execute([password_hash($password, PASSWORD_DEFAULT), $username]);
}

function dbSetRole(PDO $db, string $username, string $role): bool {
    return $db->prepare("UPDATE users SET role = ? WHERE username = ?")->execute([$role, $username]);
}

/* ------------------------------------------------------------- блокировки */

function dbLockInfo(PDO $db, string $username): ?array {
    $st = $db->prepare("SELECT * FROM login_attempts WHERE username = ?");
    $st->execute([$username]);
    $r = $st->fetch();
    if (!$r || empty($r['locked_until'])) return null;
    if (time() < strtotime((string)$r['locked_until'])) return $r;
    return null;
}

function dbFailLogin(PDO $db, string $username, string $ip, int $max = 5, int $lockMin = 5): ?string {
    $st = $db->prepare("SELECT * FROM login_attempts WHERE username = ?");
    $st->execute([$username]);
    $r = $st->fetch() ?: ['cnt' => 0];
    $cnt = (int)$r['cnt'] + 1;

    if ($cnt >= $max) {
        $db->prepare("INSERT OR REPLACE INTO login_attempts (username,cnt,ip,last_attempt,locked_until)
                      VALUES (?,?,?,?,?)")
            ->execute([$username, 0, $ip, date('Y-m-d H:i:s'), date('Y-m-d H:i:s', time() + $lockMin * 60)]);
        return 'Превышено число попыток. Вход заблокирован на ' . $lockMin . ' мин.';
    }

    $db->prepare("INSERT OR REPLACE INTO login_attempts (username,cnt,ip,last_attempt,locked_until)
                  VALUES (?,?,?,?,NULL)")
        ->execute([$username, $cnt, $ip, date('Y-m-d H:i:s')]);
    return null;
}

function dbClearLogin(PDO $db, string $username): void {
    $db->prepare("DELETE FROM login_attempts WHERE username = ?")->execute([$username]);
}

function dbListLoginAttempts(PDO $db): array {
    return $db->query("SELECT * FROM login_attempts ORDER BY last_attempt DESC")->fetchAll();
}

function dbUnlockUser(PDO $db, string $username): void {
    $db->prepare("DELETE FROM login_attempts WHERE username = ?")->execute([$username]);
}

/* -------------------------------------------------------------- настройки */

function dbGetSetting(PDO $db, string $key, $default = null) {
    $st = $db->prepare("SELECT value FROM settings WHERE key = ?");
    $st->execute([$key]);
    $r = $st->fetch();
    if (!$r) return $default;
    $v = json_decode((string)$r['value'], true);
    return $v === null && $default !== null ? $default : $v;
}

function dbSetSetting(PDO $db, string $key, $value): void {
    $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?,?)")
        ->execute([$key, json_encode($value, JSON_UNESCAPED_UNICODE)]);
}

function dbAllSettings(PDO $db): array {
    $out = [];
    foreach ($db->query("SELECT key, value FROM settings")->fetchAll() as $r) {
        $out[$r['key']] = json_decode((string)$r['value'], true);
    }
    return $out;
}

/* ---------------------------------------------------------------- товары */

function dbAllProducts(PDO $db): array {
    $rows = $db->query("SELECT * FROM products ORDER BY pos, sku")->fetchAll();
    foreach ($rows as &$r) {
        $r['features'] = json_decode((string)$r['features'], true) ?: [];
        $r['images']   = json_decode((string)$r['images'], true) ?: [];
        $r['inStock']  = (int)$r['inStock'] === 1;
   $r['onHome']   = (int)$r['onHome'] === 1;
        $r['priceRetail'] = (float)$r['priceRetail'];
        $r['priceOpt']    = (float)$r['priceOpt'];
        $r['packCount']   = (int)$r['packCount'];
        $r['size'] = [
            'l' => (float)$r['sizeL'],
            'w' => (float)$r['sizeW'],
            'h' => (float)$r['sizeH'],
        ];
    }
    return $rows;
}

function dbGetProduct(PDO $db, string $sku): ?array {
    $st = $db->prepare("SELECT * FROM products WHERE sku = ?");
    $st->execute([$sku]);
    $r = $st->fetch();
    if (!$r) return null;
    $all = dbAllProducts($db);
    foreach ($all as $p) if ($p['sku'] === $sku) return $p;
    return $r;
}

function dbSaveProduct(PDO $db, array $p): void {
    $now = date('Y-m-d H:i:s');
    $exists = dbGetProduct($db, (string)$p['sku']) !== null;

    $size = (array)($p['size'] ?? []);

$sql = "INSERT INTO products
        (sku,title,shortTitle,categoryId,art,form,sizeL,sizeW,sizeH,gridGap,color,colorHex,
         packCount,packNote,weightG,priceRetail,priceOpt,volumeMl,material,country,cert,
         description,features,images,inStock,stockNote,sima,pos,onHome,updated)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON CONFLICT(sku) DO UPDATE SET
         title=excluded.title, shortTitle=excluded.shortTitle, categoryId=excluded.categoryId,
         art=excluded.art, form=excluded.form, sizeL=excluded.sizeL, sizeW=excluded.sizeW,
         sizeH=excluded.sizeH, gridGap=excluded.gridGap, color=excluded.color, colorHex=excluded.colorHex,
         packCount=excluded.packCount, packNote=excluded.packNote, weightG=excluded.weightG,
         priceRetail=excluded.priceRetail, priceOpt=excluded.priceOpt, volumeMl=excluded.volumeMl,
         material=excluded.material, country=excluded.country, cert=excluded.cert,
         description=excluded.description, features=excluded.features, images=excluded.images,
         inStock=excluded.inStock, stockNote=excluded.stockNote, sima=excluded.sima,
         pos=excluded.pos, onHome=excluded.onHome, updated=excluded.updated";

    $st = $db->prepare($sql);
    $st->execute([
        (string)($p['sku'] ?? ''),
        (string)($p['title'] ?? ''),
        (string)($p['shortTitle'] ?? ''),
        (string)($p['categoryId'] ?? ''),
        (string)($p['art'] ?? ''),
        (string)($p['form'] ?? ''),
        (float)($size['l'] ?? 0),
        (float)($size['w'] ?? 0),
        (float)($size['h'] ?? 0),
        (float)($p['gridGap'] ?? 0),
        (string)($p['color'] ?? ''),
        (string)($p['colorHex'] ?? ''),
        max(1, (int)($p['packCount'] ?? 1)),
        (string)($p['packNote'] ?? ''),
        (float)($p['weightG'] ?? 0),
        (float)($p['priceRetail'] ?? 0),
        (float)($p['priceOpt'] ?? 0),
        (float)($p['volumeMl'] ?? 0),
        (string)($p['material'] ?? 'Пластик'),
        (string)($p['country'] ?? 'Россия'),
        (string)($p['cert'] ?? ''),
        (string)($p['description'] ?? ''),
        json_encode(array_values((array)($p['features'] ?? [])), JSON_UNESCAPED_UNICODE),
        json_encode(array_values((array)($p['images'] ?? [])), JSON_UNESCAPED_UNICODE),
        !empty($p['inStock']) ? 1 : 0,
        (string)($p['stockNote'] ?? ''),
        (string)($p['sima'] ?? ''),
        (int)($p['pos'] ?? 0),
        !empty($p['onHome']) ? 1 : 0,
        $now,
    ]);
}

function dbDeleteProduct(PDO $db, string $sku): bool {
    return $db->prepare("DELETE FROM products WHERE sku = ?")->execute([$sku]);
}

function dbNextPos(PDO $db): int {
    $r = $db->query("SELECT COALESCE(MAX(pos),0) AS m FROM products")->fetch();
    return (int)$r['m'] + 1;
}

function dbMoveProduct(PDO $db, string $sku, string $dir): bool {
    $all = $db->query("SELECT sku, pos FROM products ORDER BY pos, sku")->fetchAll();
    $idx = null;
    foreach ($all as $i => $r) if ($r['sku'] === $sku) $idx = $i;
    if ($idx === null) return false;

    $swap = $dir === 'up' ? $idx - 1 : $idx + 1;
    if ($swap < 0 || $swap >= count($all)) return false;

    $a = $all[$idx];
    $b = $all[$swap];
    $st = $db->prepare("UPDATE products SET pos = ? WHERE sku = ?");
    $st->execute([(int)$b['pos'], $a['sku']]);
    $st->execute([(int)$a['pos'], $b['sku']]);
    return true;
}

/* ------------------------------------------------------------ артикулы */

/**
 * Схема артикулов: префикс 1000 и шесть цифр, то есть 100001, 100002, …
 * Артикул проставляется автоматически, вручную не задаётся.
 */
const SKU_PREFIX = '1000';
const SKU_WIDTH  = 6;

/** Первый номер, который ещё не занят. */
function dbNextSku(PDO $db): string {
    $max = 0;
    foreach ($db->query('SELECT sku FROM products') as $r) {
        $sku = (string)$r['sku'];
        if (strncmp($sku, SKU_PREFIX, strlen(SKU_PREFIX)) !== 0) continue;
        $n = (int)substr($sku, strlen(SKU_PREFIX));
        if ($n > $max) $max = $n;
    }
    return SKU_PREFIX . str_pad((string)($max + 1), SKU_WIDTH - strlen(SKU_PREFIX), '0', STR_PAD_LEFT);
}

/* ------------------------------------------------------------ категории */

function dbAllCategories(PDO $db): array {
    return $db->query("SELECT * FROM categories ORDER BY pos, id")->fetchAll();
}

function dbSaveCategory(PDO $db, array $c): void {
    $id = (string)($c['id'] ?? '');
    if ($id === '') $id = slug((string)($c['short'] ?? $c['name'] ?? 'cat'));
    $pos = (int)($c['pos'] ?? 0);
    if ($pos <= 0) {
        $r = $db->query("SELECT COALESCE(MAX(pos),0) AS m FROM categories")->fetch();
        $pos = (int)$r['m'] + 1;
    }
    $db->prepare("INSERT INTO categories (id,name,short,desc,pos) VALUES (?,?,?,?,?)
                  ON CONFLICT(id) DO UPDATE SET name=excluded.name, short=excluded.short,
                  desc=excluded.desc, pos=excluded.pos")
        ->execute([$id, (string)($c['name'] ?? ''), (string)($c['short'] ?? ''),
                   (string)($c['desc'] ?? ''), $pos]);
}

function dbDeleteCategory(PDO $db, string $id): bool {
    return $db->prepare("DELETE FROM categories WHERE id = ?")->execute([$id]);
}

function dbAllColors(PDO $db): array {
    return $db->query("SELECT * FROM product_colors ORDER BY pos, name")->fetchAll();
}

function dbSaveColor(PDO $db, array $c): void {
    $name = trim((string)($c['name'] ?? ''));
    if ($name === '') return;
    $id = (string)($c['id'] ?? '') ?: slug($name);
    $pos = (int)($c['pos'] ?? 0);
    if ($pos <= 0) {
        $r = $db->query("SELECT COALESCE(MAX(pos),0) AS m FROM product_colors")->fetch();
        $pos = (int)$r['m'] + 1;
    }
    $db->prepare("INSERT INTO product_colors (id,name,hex,pos) VALUES (?,?,?,?)
                  ON CONFLICT(id) DO UPDATE SET name=excluded.name, hex=excluded.hex, pos=excluded.pos")
        ->execute([$id, $name, (string)($c['hex'] ?? ''), $pos]);
}

function dbDeleteColor(PDO $db, string $id): bool {
    return $db->prepare("DELETE FROM product_colors WHERE id = ?")->execute([$id]);
}

/* ---------------------------------------------------------------- заявки */

function dbAddOrder(PDO $db, array $o): int {
    $st = $db->prepare("INSERT INTO orders (name,phone,email,city,comment,items,total,kind,source,status,note,created)
                        VALUES (?,?,?,?,?,?,?,?,?,'new','',?)");
    $st->execute([
        (string)($o['name'] ?? ''),
        (string)($o['phone'] ?? ''),
        (string)($o['email'] ?? ''),
        (string)($o['city'] ?? ''),
        (string)($o['comment'] ?? ''),
        json_encode(array_values((array)($o['items'] ?? [])), JSON_UNESCAPED_UNICODE),
        (float)($o['total'] ?? 0),
        (string)($o['kind'] ?? ''),
        (string)($o['source'] ?? ''),
        date('Y-m-d H:i:s'),
    ]);
    return (int)$db->lastInsertId();
}

function dbOrders(PDO $db, string $status = '', int $limit = 200): array {
    if ($status !== '' && $status !== 'all') {
        $st = $db->prepare("SELECT * FROM orders WHERE status = ? ORDER BY created DESC LIMIT ?");
        $st->bindValue(1, $status, PDO::PARAM_STR);
        $st->bindValue(2, $limit, PDO::PARAM_INT);
        $st->execute();
    } else {
        $st = $db->prepare("SELECT * FROM orders ORDER BY created DESC LIMIT ?");
        $st->bindValue(1, $limit, PDO::PARAM_INT);
        $st->execute();
    }
    $rows = $st->fetchAll();
    foreach ($rows as &$r) $r['items'] = json_decode((string)$r['items'], true) ?: [];
    return $rows;
}

function dbOrderCounts(PDO $db): array {
    $out = ['new' => 0, 'inwork' => 0, 'done' => 0, 'spam' => 0, 'all' => 0];
    foreach ($db->query("SELECT status, COUNT(*) AS c FROM orders GROUP BY status")->fetchAll() as $r) {
        $out[$r['status']] = (int)$r['c'];
        $out['all'] += (int)$r['c'];
    }
    return $out;
}

function dbUpdateOrder(PDO $db, int $id, array $f): bool {
    $sets = [];
    $args = [];
    foreach (['status', 'note'] as $k) {
        if (array_key_exists($k, $f)) { $sets[] = "$k = ?"; $args[] = (string)$f[$k]; }
    }
    if (!$sets) return false;
    $args[] = $id;
    return $db->prepare("UPDATE orders SET " . implode(', ', $sets) . " WHERE id = ?")->execute($args);
}

function dbDeleteOrder(PDO $db, int $id): bool {
    return $db->prepare("DELETE FROM orders WHERE id = ?")->execute([$id]);
}

/* ----------------------------------------------------------------- утили */

function slug(string $s): string {
    $map = [
        'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i',
        'й'=>'i','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t',
        'у'=>'u','ф'=>'f','х'=>'h','ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'',
        'э'=>'e','ю'=>'yu','я'=>'ya',
    ];
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = strtr($s, $map);
    $s = preg_replace('~[^a-z0-9]+~u', '-', $s) ?? '';
    return trim($s, '-') ?: 'item';
}

/** Каталог в формате data/products.json — для экспорта и совместимости с JS. */
function dbCatalog(PDO $db): array {
    $products = dbAllProducts($db);
    $out = [];
    foreach ($products as $p) {
        $out[] = [
            'sku'         => $p['sku'],
            'title'       => $p['title'],
            'shortTitle'  => $p['shortTitle'],
            'categoryId'  => $p['categoryId'],
            'art'         => $p['art'],
            'form'        => $p['form'],
            'size'        => $p['size'],
            'gridGap'     => (float)$p['gridGap'],
            'color'       => $p['color'],
            'colorHex'    => $p['colorHex'],
            'packCount'   => (int)$p['packCount'],
            'packNote'    => $p['packNote'],
            'weightG'     => (float)$p['weightG'],
            'priceRetail' => (float)$p['priceRetail'],
            'priceOpt'    => (float)$p['priceOpt'],
            'volumeMl'    => (float)$p['volumeMl'],
            'material'    => $p['material'],
            'country'     => $p['country'],
            'cert'        => $p['cert'],
            'images'      => $p['images'],
            'thumbs'      => array_map(static function (string $s): string {
                return preg_replace('~\.jpg$~i', '-t.jpg', $s) ?? $s;
            }, $p['images']),
            'description' => $p['description'],
            'features'    => $p['features'],
            'inStock'     => (bool)$p['inStock'],
            'onHome'      => (bool)$p['onHome'],
            'stockNote'   => $p['stockNote'],
            'sima'        => $p['sima'],
        ];
    }

    return [
        'generated'    => date('c'),
        'source'       => 'database',
        'categories'   => dbAllCategories($db),
        'colorOptions' => array_map(static function (array $c) {
            return ['name' => $c['name'], 'hex' => $c['hex']];
        }, dbAllColors($db)),
        'products'     => $out,
    ];
}
