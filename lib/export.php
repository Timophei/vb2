<?php
declare(strict_types=1);

/**
 * Выгрузка диалогов: TXT (читать глазами / отдать на разбор) и CSV (таблица всех сообщений).
 * Архив: по TXT на диалог + messages.csv со всеми сообщениями выбранных диалогов.
 */

/** Переписка одного диалога текстом: шапка, сообщения вперемешку с переходами и действиями, итоговые данные. */
function dialog_export_txt(int $id): string
{
    $d = dialog_load($id);
    $sc = scenario_for($d);
    $c = get_client((int)$d['client_id']) ?: [];
    $q = function (string $sql) use ($id): array {
        $st = db()->prepare($sql);
        $st->execute([$id]);
        return $st->fetchAll();
    };
    $L = [];
    $L[] = 'Диалог #' . $id . ' · ' . (CHANNEL_NAMES[$d['channel']] ?? $d['channel']) . ' · ' . client_display_name($c);
    $contacts = array_filter([$c['phone'] ?? '', $c['email'] ?? '', $c['messenger_contact'] ?? '', !empty($c['tg_username']) ? '@' . $c['tg_username'] : '']);
    if ($contacts) $L[] = 'Контакты: ' . implode(' · ', $contacts);
    $L[] = 'Сценарий: #' . $sc['_id'] . ' (' . $sc['_version'] . ')';
    $L[] = 'Начат: ' . fmt_dt($d['started_at'], 'd.m.Y H:i:s') . ' · последнее: ' . fmt_dt($d['last_at'], 'd.m.Y H:i:s') . ' (' . tz_label() . ')';
    $crm = array_filter(['лид' => (int)$d['lead_id'], 'сделка' => (int)($d['deal_id'] ?? 0), 'контакт' => (int)($d['contact_id'] ?? 0)]);
    if ($crm) $L[] = 'Битрикс24: ' . implode(', ', array_map(fn($k, $v) => "$k #$v", array_keys($crm), $crm));
    $L[] = 'Сейчас: ' . sc_state_title($sc, $d['state']);
    $L[] = str_repeat('=', 60);

    // общая лента: переход — сразу после сообщения клиента, которое его вызвало; действие — после последнего
    // сообщения клиента не позже него по времени (ответ бота в ту же секунду идёт после)
    $msgs = $q('SELECT * FROM messages WHERE dialog_id = ? ORDER BY id');
    $feed = [];
    $anchor = function (string $at) use ($msgs): int {
        $k = 0;
        foreach ($msgs as $m) if ($m['role'] === 'user' && $m['created_at'] <= $at) $k = (int)$m['id'];
        return $k;
    };
    foreach ($msgs as $m) {
        $att = $m['attachment'] ? jdec($m['attachment']) : null;
        $txt = (string)$m['content'];
        if ($att && ($att['kind'] ?? '') === 'qr') $txt = '[QR-код] ' . $txt;
        elseif ($att) $txt .= ' [вложение: ' . ($att['name'] ?? $att['kind'] ?? 'файл') . ']';
        $feed[] = [(int)$m['id'], $m['role'] === 'user' ? 0 : 3, 0, "\n[" . fmt_dt($m['created_at'], 'd.m H:i:s') . '] ' . (ROLE_NAMES[$m['role']] ?? $m['role']) . ":\n" . $txt];
    }
    foreach ($q('SELECT * FROM transitions WHERE dialog_id = ? ORDER BY id') as $t) {
        $feed[] = [(int)($t['message_id'] ?: $anchor($t['created_at'])), 1, (int)$t['id'], '   → ' . sc_state_title($sc, $t['from_state']) . ' ⇒ ' . sc_state_title($sc, $t['to_state']) . ($t['reason'] !== '' ? ' (' . $t['reason'] . ')' : '')];
    }
    foreach ($q('SELECT * FROM actions_log WHERE dialog_id = ? ORDER BY id') as $a) {
        $feed[] = [$anchor($a['created_at']), 2, (int)$a['id'], '   · ' . ($a['ok'] ? '' : 'ОШИБКА ') . $a['action'] . ($a['result'] !== '' ? ': ' . mb_strimwidth((string)$a['result'], 0, 300, '…') : '')];
    }
    // ответы бота — после переходов и действий своего хода
    $replyAnchor = [];
    $lastUser = 0;
    foreach ($msgs as $m) { if ($m['role'] === 'user') $lastUser = (int)$m['id']; else $replyAnchor[(int)$m['id']] = $lastUser; }
    foreach ($feed as &$f) if ($f[1] === 3) { $f[2] = $f[0]; $f[0] = $replyAnchor[$f[0]] ?? 0; }
    unset($f);
    usort($feed, fn($x, $y) => [$x[0], $x[1], $x[2]] <=> [$y[0], $y[1], $y[2]]);
    foreach ($feed as $f) $L[] = $f[3];

    $L[] = "\n" . str_repeat('=', 60);
    $L[] = 'Собранные данные:';
    $any = false;
    foreach ($d['slots'] as $name => $v) {
        $txt = value_text($v);
        if ($txt === '') continue;
        $L[] = '- ' . bot_field_label($name, $sc) . ' [' . $name . ']: ' . $txt;
        $any = true;
    }
    if (!$any) $L[] = '- нет';
    return implode("\n", $L) . "\n";
}

/** Строки CSV (разделитель «;», UTF-8 с BOM — Excel открывает без вопросов). */
function csv_line(array $row): string
{
    return implode(';', array_map(fn($v) => '"' . str_replace('"', '""', (string)$v) . '"', $row)) . "\r\n";
}

/** Архив выбранных диалогов. Возвращает путь к временному zip. */
function dialogs_export_zip(array $ids): string
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) throw new RuntimeException('Не выбрано ни одного диалога.');
    $path = tempnam(sys_get_temp_dir(), 'dlg');
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Не удалось создать архив.');
    $csv = "\xEF\xBB\xBF" . csv_line(['Диалог', 'Канал', 'Клиент', 'Время', 'Кто', 'Этап', 'Текст']);
    foreach ($ids as $id) {
        try { $d = dialog_load($id); } catch (Throwable $e) { continue; }
        $sc = scenario_for($d);
        $c = get_client((int)$d['client_id']) ?: [];
        $name = client_display_name($c);
        $zip->addFromString(sprintf('dialog_%05d_%s.txt', $id, export_slug($name)), dialog_export_txt($id));
        $st = db()->prepare('SELECT * FROM messages WHERE dialog_id = ? ORDER BY id');
        $st->execute([$id]);
        foreach ($st as $m) {
            $csv .= csv_line([$id, CHANNEL_NAMES[$d['channel']] ?? $d['channel'], $name, fmt_dt($m['created_at'], 'd.m.Y H:i:s'),
                ROLE_NAMES[$m['role']] ?? $m['role'], $m['state'] !== '' ? sc_state_title($sc, $m['state']) : '', $m['content']]);
        }
    }
    $zip->addFromString('messages.csv', $csv);
    $zip->close();
    return $path;
}

function export_slug(string $s): string
{
    $s = preg_replace('/[^\p{L}\p{N}]+/u', '_', $s);
    return trim(mb_substr((string)$s, 0, 40), '_') ?: 'client';
}

function send_download(string $path, string $name, string $type, bool $delete = true): never
{
    header('Content-Type: ' . $type);
    header("Content-Disposition: attachment; filename=\"" . preg_replace('/[^\w.\-]/', '_', $name) . "\"; filename*=UTF-8''" . rawurlencode($name));
    header('Content-Length: ' . filesize($path));
    readfile($path);
    if ($delete) @unlink($path);
    exit;
}
