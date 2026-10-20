<?php
declare(strict_types=1);

/**
 * ПОЛИФОРМ — минимальный SMTP-клиент.
 *
 * Без внешних библиотек и без php.ini: сокет, EHLO, AUTH LOGIN/PLAIN,
 * SSL (implicit) и STARTTLS. Тема и тело письма кодируются в base64,
 * поэтому кириллица уходит без «кракозябр» в любом клиенте.
 *
 * Два способа отправки, переключаются в админке (поле `transport`):
 *   smtp — через почтовый сервер с логином и паролем приложения (надёжнее);
 *   php  — средствами сайта, встроенной функцией mail() (без пароля и ящика).
 *
 * Всё, что нужно вызывающему коду:
 *   $cfg  = mailConfig($settings);            // нормализация полей из админки
 *   $to   = mailRecipients($settings['notifyEmail']);
 *   mailSendMessage($cfg, $to, $subject, $text, $html);
 *
 * Ошибки — исключение MailError с человеческим текстом (его можно
 * показывать в админке как есть).
 */

final class MailError extends RuntimeException {}

/** Приводит разрозненные настройки из админки к одному массиву с типами. */
function mailConfig(array $in): array
{
    $str = static function (string $key, string $default = '') use ($in): string {
        $v = $in[$key] ?? $default;
        return is_scalar($v) ? trim((string)$v) : $default;
    };

    $secure = strtolower($str('smtpSecure'));
    if (!in_array($secure, ['', 'tls', 'ssl'], true)) $secure = 'tls';

    $port = (int)$str('smtpPort', '0');
    if ($port < 1 || $port > 65535) $port = $secure === 'ssl' ? 465 : 587;

    $from = $str('fromEmail');
    if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        $user = $str('smtpUser');
        $from = filter_var($user, FILTER_VALIDATE_EMAIL) ? $user : '';
    }

    return [
        'host'      => $str('smtpHost'),
        'port'      => $port,
        'secure'    => $secure,
        'transport' => $str('transport') === 'php' ? 'php' : 'smtp',
        'user'      => $str('smtpUser'),
        'pass'      => (string)($in['smtpPass'] ?? ''),
        'fromEmail' => $from,
        'fromName'  => $str('fromName') !== '' ? $str('fromName') : 'Сайт',
        'timeout'   => 15,
    ];
}

/** Разбирает список адресов: «a@b.ru, c@d.ru» → ['a@b.ru','c@d.ru']. */
function mailRecipients(string $raw): array
{
    $out = [];
    foreach (preg_split('~[,;\s]+~u', $raw) ?: [] as $one) {
        $one = trim($one);
        if ($one !== '' && filter_var($one, FILTER_VALIDATE_EMAIL)) $out[] = $one;
    }
    return array_values(array_unique($out));
}

/** Кодирует не-ASCII заголовок по RFC 2047. */
function mailHeaderValue(string $s): string
{
    return preg_match('~[^\x20-\x7E]~', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}

/**
 * Собирает письмо по частям: заголовки отдельно, тело отдельно.
 * Так одним кодом пользуются и SMTP-отправка, и встроенная mail().
 *
 * @return array{head: list<string>, body: string}
 */
function mailBuildParts(array $cfg, array $to, string $subject, string $text, string $html = ''): array
{
    $from = $cfg['fromEmail'];
    $name = (string)$cfg['fromName'];
    $fromHdr = $name !== '' ? mailHeaderValue($name) . ' <' . $from . '>' : $from;
    $host = parse_url('http://' . (string)($cfg['host'] ?: 'localhost'), PHP_URL_HOST) ?: 'localhost';

    $head = [
        'Date: ' . date('r'),
        'From: ' . $fromHdr,
        'To: ' . implode(', ', $to),
        'Subject: ' . mailHeaderValue($subject),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $host . '>',
        'MIME-Version: 1.0',
        'X-Mailer: Poliform admin',
    ];

    $plain = chunk_split(base64_encode($text), 76, "\r\n");

    if ($html === '') {
        $head[] = 'Content-Type: text/plain; charset=UTF-8';
        $head[] = 'Content-Transfer-Encoding: base64';
        return ['head' => $head, 'body' => $plain];
    }

    $boundary = '=_' . bin2hex(random_bytes(12));
    $head[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

    $parts = [
        '--' . $boundary,
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        '',
        $plain,
        '--' . $boundary,
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        '',
        chunk_split(base64_encode($html), 76, "\r\n"),
        '--' . $boundary . '--',
        '',
    ];

    return ['head' => $head, 'body' => implode("\r\n", $parts)];
}

/** Собирает готовое письмо целиком: text/plain либо multipart/alternative с HTML. */
function mailBuildMessage(array $cfg, array $to, string $subject, string $text, string $html = ''): string
{
    $m = mailBuildParts($cfg, $to, $subject, $text, $html);
    return implode("\r\n", $m['head']) . "\r\n\r\n" . $m['body'];
}

/**
 * Отправка средствами сайта — встроенной mail().
 * Так письмо уходит без ящика, логина и пароля: почту отправляет сам сервер.
 * На Linux-хостинге обычно работает сразу; локальный XAMPP на Windows
 * почту не отправляет, пока в php.ini не задан внешний релей.
 */
function mailSendViaPhp(array $cfg, array $to, string $subject, string $text, string $html = ''): void
{
    if ($cfg['fromEmail'] === '') {
        /* mail() не умеет отправлять без отправителя — берём адрес получателя. */
        $cfg['fromEmail'] = (string)$to[0];
    }

    $m    = mailBuildParts($cfg, $to, $subject, $text, $html);
    /* mail() сам подставляет To, Subject и часть служебных заголовков. */
    $head = array_values(array_filter(
        $m['head'],
        static fn (string $h): bool => !str_starts_with($h, 'To: ')
            && !str_starts_with($h, 'Subject: ')
            && !str_starts_with($h, 'Date: ')
    ));

    $params = defined('PHP_OS_FAMILY') && PHP_OS_FAMILY === 'Windows' ? '' : '-f' . $cfg['fromEmail'];
    $ok = @mail(
        implode(', ', $to),
        mailHeaderValue($subject),
        $m['body'],
        implode("\r\n", $head),
        $params
    );

    if (!$ok) {
        $e   = error_get_last();
        $why = $e && isset($e['message']) ? preg_replace('~^mail\(\): ~', '', (string)$e['message']) : '';
        throw new MailError('Сервер не отправил письмо напрямую' . ($why !== '' ? ' — ' . $why : '') .
            '. На хостинге этот способ обычно работает; локально нужен настроенный почтовый сервер ' .
            'или отправка через SMTP.');
    }
}

/** Открывает соединение: сразу SSL (465) или обычное с последующим STARTTLS. */
function mailConnect(array $cfg)
{
    $ssl = $cfg['secure'] === 'ssl';
    $remote = ($ssl ? 'ssl://' : 'tcp://') . $cfg['host'] . ':' . $cfg['port'];

    $strict = stream_context_create(['ssl' => [
        'verify_peer'      => true,
        'verify_peer_name' => true,
        'SNI_enabled'      => true,
    ]]);
    $fp = @stream_socket_client($remote, $errno, $errstr, $cfg['timeout'], STREAM_CLIENT_CONNECT, $strict);

    /* Самоподписанный сертификат — частая история у хостингов и у локальной
       отладки. Строгая проверка не прошла, но соединение возможно: пробуем
       ещё раз без проверки, чтобы админ не упирался в непонятную ошибку. */
    if (!$fp && $ssl) {
        $loose = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $fp = @stream_socket_client($remote, $errno, $errstr, $cfg['timeout'], STREAM_CLIENT_CONNECT, $loose);
    }

    if (!$fp) {
        throw new MailError('Нет связи с ' . $cfg['host'] . ':' . $cfg['port'] . ' — ' . ($errstr ?: 'проверьте хост, порт и шифрование'));
    }

    stream_set_timeout($fp, (int)$cfg['timeout']);
    return $fp;
}

/** Диалог с SMTP-сервером до момента передачи письма. */
function smtpDeliver(array $cfg, array $to, string $raw): void
{
    if ($cfg['host'] === '') throw new MailError('Не указан SMTP-сервер');
    if ($cfg['fromEmail'] === '') throw new MailError('Не указан адрес отправителя');

    $fp = mailConnect($cfg);

    $read = static function () use ($fp): string {
        $out = '';
        while (($line = fgets($fp, 2048)) !== false) {
            $out .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') break;
        }
        return $out;
    };

    $say = static function (string $line, array $expect, string $label) use ($fp, $read): string {
        if (fwrite($fp, $line . "\r\n") === false) throw new MailError('Соединение с SMTP оборвалось');
        $reply = $read();
        $code = (int)substr($reply, 0, 3);
        if ($reply === '') throw new MailError('SMTP не ответил на ' . $label);
        if (!in_array($code, $expect, true)) {
            throw new MailError('SMTP отклонил ' . $label . ': ' . trim(preg_replace('~\s+~', ' ', $reply) ?? $reply));
        }
        return $reply;
    };

    try {
        $greet = $read();
        if ((int)substr($greet, 0, 3) !== 220) {
            throw new MailError('SMTP не поздоровался: ' . trim($greet));
        }

        $helo = 'poliform.local';
        $cap = $say('EHLO ' . $helo, [250], 'EHLO');

        if ($cfg['secure'] === 'tls') {
            $say('STARTTLS', [220], 'STARTTLS');
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new MailError('Не удалось включить TLS после STARTTLS');
            }
            $cap = $say('EHLO ' . $helo, [250], 'EHLO после STARTTLS');
        }

        if ($cfg['user'] !== '') {
            $authLogin = preg_match('~AUTH[^\r\n]*\bLOGIN\b~i', $cap) === 1;
            $authPlain = preg_match('~AUTH[^\r\n]*\bPLAIN\b~i', $cap) === 1;

            if ($authLogin) {
                $say('AUTH LOGIN', [334], 'AUTH LOGIN');
                $say(base64_encode($cfg['user']), [334], 'имя пользователя');
                $say(base64_encode($cfg['pass']), [235], 'пароль SMTP');
            } elseif ($authPlain) {
                $say('AUTH PLAIN ' . base64_encode("\0" . $cfg['user'] . "\0" . $cfg['pass']), [235], 'AUTH PLAIN');
            } else {
                throw new MailError('SMTP-сервер не поддерживает AUTH LOGIN/PLAIN');
            }
        }

        $say('MAIL FROM:<' . $cfg['fromEmail'] . '>', [250, 251], 'MAIL FROM');
        foreach ($to as $rcpt) {
            $say('RCPT TO:<' . $rcpt . '>', [250, 251], 'адрес получателя ' . $rcpt);
        }
        $say('DATA', [354], 'DATA');

        /* Тело письма кодировано base64, точка в начале строки возможна только
           теоретически, но по RFC её всё равно нужно удваивать. */
        $data = str_replace(["\r\n", "\r"], "\n", $raw);
        $data = str_replace("\n", "\r\n", $data);
        $data = preg_replace('~^\.~m', '..', $data) ?? $data;
        if (!str_ends_with($data, "\r\n")) $data .= "\r\n";

        if (fwrite($fp, $data . ".\r\n") === false) throw new MailError('Соединение оборвалось при передаче письма');
        $reply = $read();
        if ((int)substr($reply, 0, 3) !== 250) {
            throw new MailError('SMTP не принял письмо: ' . trim($reply));
        }
    } finally {
        @fwrite($fp, "QUIT\r\n");
        @fclose($fp);
    }
}

/** Собрать и отправить письмо выбранным способом. Бросает MailError. */
function mailSendMessage(array $cfg, array $to, string $subject, string $text, string $html = ''): void
{
    if (!$to) throw new MailError('Не указан получатель письма');

    if (($cfg['transport'] ?? 'smtp') === 'php') {
        mailSendViaPhp($cfg, $to, $subject, $text, $html);
        return;
    }

    smtpDeliver($cfg, $to, mailBuildMessage($cfg, $to, $subject, $text, $html));
}
