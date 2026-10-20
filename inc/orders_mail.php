<?php
declare(strict_types=1);

/**
 * ПОЛИФОРМ — письмо-уведомление о новой заявке.
 *
 * Письмо уходит сразу после того, как посетитель оформил заявку на витрине
 * (api.php → orderAdd). Настройки лежат в группе `mail` таблицы settings и
 * заполняются в админке: «Контакты и SEO» → «Уведомления о новых заявках».
 *
 * Отправка никогда не ломает приём заявки: ошибки SMTP попадают в lastStatus
 * и в журнал data/logs/orders-mail.log, а заявка остаётся в базе.
 */

require_once __DIR__ . '/../lib/mailer.php';

/** Настройки группы `mail` (всегда массив). */
function ordersMailSettings(PDO $db): array
{
    $s = dbGetSetting($db, 'mail', []);
    return is_array($s) ? $s : [];
}

/** Сохраняет изменения в группе `mail`, не затирая остальные поля. */
function ordersMailSave(PDO $db, array $patch): void
{
    $s = ordersMailSettings($db);
    foreach ($patch as $k => $v) {
        $s[$k] = $v;
    }
    dbSetSetting($db, 'mail', $s);
}

function ordersMailMoney(float $v): string
{
    return number_format($v, 2, ',', ' ') . ' ₽';
}

/** Строка в журнал уведомлений. */
function ordersMailLog(string $line): void
{
    $dir = dirname(__DIR__) . '/data/logs';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    @file_put_contents($dir . '/orders-mail.log', date('Y-m-d H:i:s') . ' ' . $line . PHP_EOL, FILE_APPEND);
}

/**
 * Кому слать уведомления: поле «E-mail получателя» из админки, а если оно
 * пустое — общий e-mail из «Контактов и SEO».
 */
function ordersMailRecipients(PDO $db): array
{
    $s = ordersMailSettings($db);
    $to = mailRecipients((string)($s['notifyEmail'] ?? ''));
    if ($to) return $to;

    $contacts = dbGetSetting($db, 'contacts', []);
    $fallback = is_array($contacts) ? (string)($contacts['email'] ?? '') : '';
    return mailRecipients($fallback);
}

/**
 * Тема, тексто и HTML письма о заявке.
 * $order: id, name, phone, email, city, comment, items[], total, kind, source, created.
 *
 * @return array{subject: string, text: string, html: string}
 */
function orderNotifyBuild(PDO $db, array $order): array
{
    $site    = dbGetSetting($db, 'site', []);
    $company = is_array($site) ? (string)($site['company'] ?? '') : '';
    $mail    = ordersMailSettings($db);
    $admin   = trim((string)($mail['adminUrl'] ?? ''));

    $id      = (int)($order['id'] ?? 0);
    $name    = (string)($order['name'] ?? '');
    $phone   = (string)($order['phone'] ?? '');
    $email   = (string)($order['email'] ?? '');
    $city    = (string)($order['city'] ?? '');
    $comment = (string)($order['comment'] ?? '');
    $kind    = (string)($order['kind'] ?? '');
    $source  = (string)($order['source'] ?? '');
    $items   = array_values((array)($order['items'] ?? []));
    $total   = (float)($order['total'] ?? 0);
    $created = (string)($order['created'] ?? date('Y-m-d H:i:s'));

    $subject = 'Новая заявка №' . $id . ' — ' . ($name !== '' ? $name : 'без имени') .
        ' · ' . ordersMailMoney($total);
    if ($kind !== '') $subject .= ' · ' . $kind;

    /* ---------------------------------------------------------- text/plain */
    $L = [];
    $L[] = 'Новая заявка с сайта' . ($company !== '' ? ' (' . $company . ')' : '');
    $L[] = str_repeat('=', 52);
    $L[] = '';
    $L[] = '№ заявки:   ' . $id;
    $L[] = 'Оформлена:  ' . $created;
    $L[] = 'Клиент:     ' . $name . ($city !== '' ? ' (' . $city . ')' : '');
    $L[] = 'Телефон:    ' . $phone;
    if ($email !== '') $L[] = 'E-mail:     ' . $email;
    $L[] = 'Тип:        ' . ($kind !== '' ? $kind : '—');
    if ($source !== '') $L[] = 'Источник:   ' . $source;
    $L[] = '';
    $L[] = 'Состав заявки (' . count($items) . ' поз. на ' . ordersMailMoney($total) . '):';

    foreach ($items as $it) {
        $L[] = '  - ' . (string)($it['sku'] ?? '') . ' ' . (string)($it['title'] ?? '') .
            ' — ' . (int)($it['qty'] ?? 0) . ' шт. (' . (($it['mode'] ?? '') === 'opt' ? 'опт' : 'розница') . ')' .
            ' × ' . ordersMailMoney((float)($it['unit'] ?? 0)) .
            ' = ' . ordersMailMoney((float)($it['lineTotal'] ?? 0));
    }

    $L[] = '';
    $L[] = 'Итого: ' . ordersMailMoney($total);

    if ($comment !== '') {
        $L[] = '';
        $L[] = 'Комментарий клиента:';
        $L[] = $comment;
    }

    if ($admin !== '') {
        $L[] = '';
        $L[] = 'Открыть заявку в админке: ' . $admin;
    }

    $text = implode("\r\n", $L);

    /* ------------------------------------------------------------ text/html */
    $h = [];
    $h[] = '<div style="font:15px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;color:#10161a">';
    $h[] = '<h2 style="margin:0 0 4px">Новая заявка №' . $id . '</h2>';
    $h[] = '<p style="margin:0 0 14px;color:#6c7880">' . htmlspecialchars($created, ENT_QUOTES, 'UTF-8') .
        ($company !== '' ? ' · ' . htmlspecialchars($company, ENT_QUOTES, 'UTF-8') : '') . '</p>';

    $h[] = '<table cellspacing="0" cellpadding="6" style="border-collapse:collapse;font-size:14px;margin-bottom:14px">';
    $rows = [
        'Клиент'   => $name . ($city !== '' ? ' (' . $city . ')' : ''),
        'Телефон'  => $phone,
        'E-mail'   => $email,
        'Тип'      => $kind,
        'Источник' => $source,
    ];
    foreach ($rows as $k => $v) {
        $v = trim((string)$v);
        if ($v === '') continue;
        $h[] = '<tr><td style="color:#6c7880;padding-right:14px">' . htmlspecialchars($k, ENT_QUOTES, 'UTF-8') . '</td>' .
            '<td><b>' . htmlspecialchars($v, ENT_QUOTES, 'UTF-8') . '</b></td></tr>';
    }
    $h[] = '</table>';

    $h[] = '<table cellspacing="0" cellpadding="8" style="border-collapse:collapse;width:100%;font-size:14px">';
    $h[] = '<tr style="background:#fafcfd;color:#6c7880;font-size:12px;text-transform:uppercase">' .
        '<th align="left">Артикул</th><th align="left">Товар</th>' .
        '<th align="right">Кол-во</th><th align="right">Цена</th><th align="right">Сумма</th></tr>';
    foreach ($items as $it) {
        $h[] = '<tr style="border-top:1px solid #e3e9ec">' .
            '<td style="white-space:nowrap">' . htmlspecialchars((string)($it['sku'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>' .
            '<td>' . htmlspecialchars((string)($it['title'] ?? ''), ENT_QUOTES, 'UTF-8') .
            (($it['mode'] ?? '') === 'opt' ? ' <span style="color:#6c7880">(опт)</span>' : '') . '</td>' .
            '<td align="right">' . (int)($it['qty'] ?? 0) . ' шт.</td>' .
            '<td align="right">' . htmlspecialchars(ordersMailMoney((float)($it['unit'] ?? 0)), ENT_QUOTES, 'UTF-8') . '</td>' .
            '<td align="right" style="white-space:nowrap">' . htmlspecialchars(ordersMailMoney((float)($it['lineTotal'] ?? 0)), ENT_QUOTES, 'UTF-8') . '</td>' .
            '</tr>';
    }
    $h[] = '<tr style="border-top:2px solid #10161a">' .
        '<td colspan="4" align="right"><b>Итого</b></td>' .
        '<td align="right" style="white-space:nowrap"><b>' . htmlspecialchars(ordersMailMoney($total), ENT_QUOTES, 'UTF-8') . '</b></td></tr>';
    $h[] = '</table>';

    if ($comment !== '') {
        $h[] = '<p style="margin:14px 0 0;padding:10px 12px;background:#fafcfd;border-left:3px solid #d63a2f">' .
            '<b>Комментарий клиента:</b><br>' . nl2br(htmlspecialchars($comment, ENT_QUOTES, 'UTF-8')) . '</p>';
    }
    if ($admin !== '') {
        $h[] = '<p style="margin:16px 0 0"><a href="' . htmlspecialchars($admin, ENT_QUOTES, 'UTF-8') .
            '" style="color:#10161a">Открыть заявки в админке</a></p>';
    }
    $h[] = '</div>';

    return ['subject' => $subject, 'text' => $text, 'html' => implode("\n", $h)];
}

/**
 * Отправляет уведомление о заявке.
 *
 * @param bool $force true — отправить, даже если галочка «Отправлять уведомления»
 *                    снята (кнопка «Отправить тестовое письмо»).
 * @return array{sent: bool, error: string, skipped: string}
 */
function orderNoticeSend(PDO $db, array $order, bool $force = false): array
{
    $s   = ordersMailSettings($db);
    $out = ['sent' => false, 'error' => '', 'skipped' => ''];

    $enabled = (string)($s['enabled'] ?? '0') === '1';
    if (!$enabled && !$force) {
        return $out;
    }

    $to = ordersMailRecipients($db);
    if (!$to) {
        $out['error'] = 'Не указан e-mail для уведомлений (Контакты и SEO → Уведомления о новых заявках).';
        ordersMailLog('Ошибка: ' . $out['error']);
        ordersMailSave($db, ['lastStatus' => 'Ошибка ' . date('d.m.Y H:i') . ': ' . $out['error']]);
        return $out;
    }

    $cfg = mailConfig($s);
    /* Заявку принимаем у посетителя — ждать SMTP долго нельзя. */
    $cfg['timeout'] = 8;

    $letter = orderNotifyBuild($db, $order);

    try {
        mailSendMessage($cfg, $to, $letter['subject'], $letter['text'], $letter['html']);
    } catch (Throwable $e) {
        $out['error'] = $e->getMessage();
        ordersMailLog('Ошибка: ' . $out['error']);
        ordersMailSave($db, ['lastStatus' => 'Ошибка ' . date('d.m.Y H:i') . ': ' . $out['error']]);
        return $out;
    }

    $out['sent'] = true;
    ordersMailSave($db, [
        'lastSent'   => date('Y-m-d H:i:s'),
        'lastStatus' => 'Заявка №' . (int)($order['id'] ?? 0) . ' отправлена ' . date('d.m.Y H:i') .
            ' · получатель: ' . implode(', ', $to),
    ]);
    ordersMailLog('Отправлено: заявка №' . (int)($order['id'] ?? 0) . ' → ' . implode(', ', $to));
    return $out;
}

/**
 * Тестовое письмо: показывает, как выглядит уведомление, и проверяет SMTP.
 * Ничего в базе не меняет — заявка-образец собирается на лету.
 */
function orderNoticeTest(PDO $db): array
{
    $sample = [
        'id'      => 0,
        'name'    => 'Пример Клиента',
        'phone'   => '+7 900 000-00-00',
        'email'   => 'client@example.com',
        'city'    => 'Смоленск',
        'comment' => 'Это тестовое письмо: так будет выглядеть уведомление о новой заявке.',
        'kind'    => 'опт + розница',
        'source'  => 'тест из админки',
        'created' => date('Y-m-d H:i:s'),
        'total'   => 1150.0,
        'items'   => [
            ['sku' => '100002', 'title' => 'Лоток средний', 'mode' => 'retail', 'qty' => 3, 'unit' => 300, 'lineTotal' => 900],
            ['sku' => '100004', 'title' => 'Миска двойная', 'mode' => 'retail', 'qty' => 1, 'unit' => 250, 'lineTotal' => 250],
        ],
    ];

    $letter = orderNotifyBuild($db, $sample);
    $letter['subject'] = 'Тест: ' . $letter['subject'];

    $to  = ordersMailRecipients($db);
    if (!$to) {
        return ['sent' => false, 'error' => 'Не указан e-mail для уведомлений (Контакты и SEO → Уведомления о новых заявках).'];
    }

    $cfg = mailConfig($s = ordersMailSettings($db));
    try {
        mailSendMessage($cfg, $to, $letter['subject'], $letter['text'], $letter['html']);
    } catch (Throwable $e) {
        ordersMailLog('Ошибка теста: ' . $e->getMessage());
        ordersMailSave($db, ['lastStatus' => 'Ошибка теста ' . date('d.m.Y H:i') . ': ' . $e->getMessage()]);
        return ['sent' => false, 'error' => $e->getMessage()];
    }

    ordersMailSave($db, ['lastStatus' => 'Тестовое письмо отправлено ' . date('d.m.Y H:i') .
        ' · получатель: ' . implode(', ', $to)]);
    ordersMailLog('Отправлен тест → ' . implode(', ', $to));
    return ['sent' => true, 'error' => '', 'recipients' => $to];
}
