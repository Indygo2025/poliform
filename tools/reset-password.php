<?php
declare(strict_types=1);

/**
 * Сброс пароля пользователя админки и снятие блокировки входа.
 *
 * Использование:
 *   php tools/reset-password.php [--user=admin] [--password=НовыйПароль123]
 *   php tools/reset-password.php --generate
 *
 * Без --password пароль генерируется и печатается один раз.
 * Пароль должен быть не короче 8 символов и содержать буквы и цифры
 * (то же правило, что в админке).
 *
 * Скрипт: меняет password_hash, удаляет блокировки login_attempts для
 * пользователя и пишет запись в audit_log.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require dirname(__DIR__) . '/db.php';

$user     = 'admin';
$password = null;
$generate = false;

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('~^--user=(.+)$~', $arg, $m))       $user = trim($m[1]);
    elseif (preg_match('~^--password=(.*)$~', $arg, $m)) $password = $m[1];
    elseif ($arg === '--generate')                       $generate = true;
    elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "php tools/reset-password.php [--user=admin] [--password=...] [--generate]\n");
        exit(0);
    }
}

/** Пароль: минимум 8 символов, есть буквы и цифры. */
function passwordOk(string $p): bool
{
    return mb_strlen($p) >= 8
        && preg_match('~\p{L}~u', $p) === 1
        && preg_match('~\d~', $p) === 1;
}

function randomPassword(int $len = 14): string
{
    $letters = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
    $digits  = '23456789';
    $all     = $letters . $digits;
    $out     = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $all[random_int(0, strlen($all) - 1)];
    }
    // Гарантируем букву и цифру.
    $out[0] = $letters[random_int(0, strlen($letters) - 1)];
    $out[1] = $digits[random_int(0, strlen($digits) - 1)];
    return $out;
}

$db = db();

$stmt = $db->prepare('SELECT username, role FROM users WHERE username = ?');
$stmt->execute([$user]);
$row = $stmt->fetch();

if (!$row) {
    fwrite(STDERR, "Пользователь «{$user}» не найден.\n");
    fwrite(STDERR, "Есть: ");
    foreach ($db->query('SELECT username, role FROM users') as $u) {
        fwrite(STDERR, $u['username'] . ' (' . $u['role'] . ') ');
    }
    fwrite(STDERR, "\n");
    exit(1);
}

$generated = false;
if ($password === null || $password === '' || $generate) {
    $password  = randomPassword();
    $generated = true;
}

if (!passwordOk($password)) {
    fwrite(STDERR, "Пароль слишком простой: нужно минимум 8 символов, буквы и цифры.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
$db->prepare('UPDATE users SET password_hash = ? WHERE username = ?')->execute([$hash, $user]);

// Снимаем блокировку и счётчик неудачных попыток.
$db->prepare('DELETE FROM login_attempts WHERE username = ?')->execute([$user]);

dbAudit($db, $user, 'password_reset', 'cli', 'сброс пароля через CLI');

echo "Пользователь: {$user} ({$row['role']})\n";
echo "Пароль:       {$password}\n";
if ($generated) {
    echo "(сгенерирован, сохраните его — больше не показывается)\n";
}
echo "Блокировка входа снята.\n";
