<?php
declare(strict_types=1);

/**
 * Действия состояний (on_enter / on_exit) и инструменты ответчика.
 *
 * Действия:  say, b24_update_lead, b24_attach_resume, b24_create_smart_item, openline_finish, notify_manager.
 * Инструменты: create_payment_link, send_form_link, biometry_check.
 *
 * Поля со значением «TODO…» пропускаются (с пометкой в журнале) — сценарий можно запускать недозаполненным.
 * Битрикс: в режиме dry ничего не отправляется, в журнал пишется, что ушло бы.
 */

function action_log(array $T, string $action, array $params, string $result, bool $ok = true): void
{
    db()->prepare('INSERT INTO actions_log(dialog_id, action, params, result, ok) VALUES(?,?,?,?,?)')
        ->execute([$T['d']['id'], $action, $params ? jenc($params) : '', mb_substr($result, 0, 4000), $ok ? 1 : 0]);
}

/** Выполняет список действий. false — если хоть одно упало (для on_error). */
function run_actions(array &$T, array $list, string $where): bool
{
    $ok = true;
    foreach ($list as $a) {
        $name = (string)($a['action'] ?? '');
        if (isset($a['if']) && !cond_match($T, (array)$a['if'], [])) continue;
        try {
            switch ($name) {
                case 'say':
                    if (isset($a['text'])) $T['say'][] = (string)tpl($T, $a['text']);
                    else $T['reply_state'] = $T['d']['state']; // «from: instruction» — ответ пишет модель по инструкции этого состояния
                    break;
                case 'b24_update_lead': act_b24_update_lead($T, $a); break;
                case 'b24_attach_resume': act_b24_attach_resume($T, $a); break;
                case 'b24_create_smart_item': act_b24_create_smart_item($T, $a); break;
                case 'openline_finish': act_openline_finish($T, $a); break;
                case 'notify_manager': act_notify_manager($T, $a); break;
                default: action_log($T, $name ?: '?', [], "неизвестное действие ($where)", false);
            }
        } catch (Throwable $e) {
            $ok = false;
            action_log($T, $name, ['где' => $where], 'ОШИБКА: ' . $e->getMessage(), false);
            app_log("action $name ($where) dialog {$T['d']['id']}: " . $e->getMessage());
        }
    }
    return $ok;
}

/* ------------------------------------------------------------------ */
/*  Битрикс24                                                          */
/* ------------------------------------------------------------------ */

/** Вызов Б24 с учётом режима. В dry возвращает null и пишет в журнал. */
function b24_call(array &$T, string $label, string $method, array $params): ?array
{
    if (!b24_live($T['d']['channel'])) {
        action_log($T, $label, ['method' => $method] + $params, 'тестовый режим: не отправлено');
        return null;
    }
    $res = b24($method, $params);
    action_log($T, $label, ['method' => $method] + $params, 'ok: ' . mb_substr(jenc($res['result'] ?? $res), 0, 500));
    return $res;
}

/** Лид диалога: если ещё нет — создаём (канал сайт/Telegram). */
function lead_ensure(array &$T): int
{
    if ((int)$T['d']['lead_id'] > 0) return (int)$T['d']['lead_id'];
    if (!b24_live($T['d']['channel']) && !empty($T['dry_lead'])) return 0; // в тестовом режиме «создание лида» пишем в журнал один раз за ход
    $T['dry_lead'] = true;
    $c = get_client((int)$T['d']['client_id']);
    $fields = [
        'TITLE' => crm_title_prefix($T) . 'Бот: ' . client_display_name($c) . ' (' . (CHANNEL_NAMES[$T['d']['channel']] ?? $T['d']['channel']) . ')',
        'SOURCE_DESCRIPTION' => 'бот vb2, ' . (CHANNEL_NAMES[$T['d']['channel']] ?? $T['d']['channel']) . ', диалог #' . $T['d']['id'],
        'OPENED' => 'Y',
    ] + b24_client_fields($c);
    if (crm_assignee() > 0) $fields['ASSIGNED_BY_ID'] = crm_assignee();
    foreach (b24_defaults('lead') as $code => $val) if ($val !== '') $fields[$code] ??= $val;
    $res = b24_call($T, 'b24_create_lead', 'crm.lead.add', ['fields' => array_filter($fields, fn($v) => $v !== '')]);
    if ($res === null) return 0; // тестовый режим
    $T['d']['lead_id'] = (int)$res['result'];
    dialog_save($T['d']);
    return (int)$T['d']['lead_id'];
}

/** Мультиполе контакта (PHONE / EMAIL / IM) из слота contact. */
function contact_fields($contact): array
{
    if (!is_array($contact) || ($contact['value'] ?? '') === '') return [];
    return match ($contact['type'] ?? 'other') {
        'phone' => ['PHONE' => [['VALUE' => $contact['value'], 'VALUE_TYPE' => 'WORK']]],
        'email' => ['EMAIL' => [['VALUE' => $contact['value'], 'VALUE_TYPE' => 'WORK']]],
        default => ['IM' => [['VALUE' => $contact['value'], 'VALUE_TYPE' => 'OTHER']]],
    };
}

/**
 * fields: объект ПОЛЕ => шаблон, или "slots:visa.*" / "slots:all".
 * Слоты без своего поля Б24 (или с TODO) идут текстом в блок бота в «Комментарии».
 */
/** Служебные слоты — в лид не пишем. */
const BOT_INTERNAL_SLOTS = '/^(intent|crosssell_done|followup\.return_state|payment\.invoice_id|jobs\.smart_item_id|jobs\.resume_asked|visa\.biometry_questions|handoff_priority)$/';

function payment_currency(array $T): string
{
    $code = (string)($T['d']['slots']['payment.invoice_id'] ?? '');
    if ($code === '') return 'RUB';
    $st = db()->prepare('SELECT currency FROM payments WHERE code = ?');
    $st->execute([$code]);
    return (string)($st->fetchColumn() ?: 'RUB');
}

function act_b24_update_lead(array &$T, array $a): void
{
    // направление ведётся сделкой (визы, карты, Green Card) — лид не трогаем, сделку обновит crm_sync()
    if (crm_entity($T) === 'deal') { $T['crm_dirty'] = true; return; }

    $spec = $a['fields'] ?? [];
    $fields = [];
    $comment = [];
    $skipped = [];
    $sc = $T['sc'];

    if (is_string($spec) && str_starts_with($spec, 'slots:')) {
        [$fields, $comment] = b24_fields_from_slots($T, 'lead', substr($spec, 6));
        foreach ($T['d']['slots'] as $name => $v) {
            $type = sc_slot($sc, $name)['type'] ?? '';
            if ($type === 'contact') $fields += contact_fields($v);
            if ($type === 'fio' && is_array($v)) $fields += array_filter(['LAST_NAME' => $v['last'] ?? '', 'NAME' => $v['first'] ?? '', 'SECOND_NAME' => $v['middle'] ?? '']);
        }
        if (isset($fields['OPPORTUNITY'])) $fields['CURRENCY_ID'] = payment_currency($T);
        $fields += b24_client_fields(get_client((int)$T['d']['client_id']));
    } elseif (is_array($spec)) {
        foreach ($spec as $field => $tplv) {
            if (is_todo($tplv)) { $skipped[] = $field; continue; }
            if ($tplv === 'slot:contact' || str_contains((string)$field, '|')) { $fields += contact_fields(ctx_value($T, 'contact')); continue; }
            $v = tpl($T, $tplv);
            if (is_array($v)) $v = value_text($v);
            if ($v === null || $v === '') continue;
            if ($field === 'COMMENTS') { $comment[] = (string)$v; continue; }
            $fields[$field] = $v;
        }
    }
    if ($skipped) action_log($T, 'b24_update_lead', ['пропущено' => $skipped], 'поля не настроены (TODO) — пропущены');
    if (!$fields && !$comment) return;

    $leadId = lead_ensure($T);
    if ($comment) {
        $block = implode("\n", $comment) . "\nДиалог #{$T['d']['id']}, этап: " . sc_state_title($sc, $T['d']['state']) . ', ' . fmt_dt(now_utc());
        $current = '';
        if ($leadId > 0 && b24_live($T['d']['channel'])) {
            try { $current = (string)(b24('crm.lead.get', ['id' => $leadId])['result']['COMMENTS'] ?? ''); } catch (Throwable $e) { /* не критично */ }
        }
        $fields['COMMENTS'] = b24_merge_comments($current, $block);
    }
    b24_call($T, 'b24_update_lead', 'crm.lead.update', ['id' => $leadId ?: '(будет создан)', 'fields' => $fields]);
}

function act_b24_attach_resume(array &$T, array $a): void
{
    $resume = null;
    foreach ($T['d']['slots'] as $name => $v) if ((sc_slot($T['sc'], $name)['type'] ?? '') === 'file_or_link' && $v) { $resume = $v; break; }
    if (!$resume) return;
    $leadId = lead_ensure($T);
    $fields = ['ENTITY_ID' => $leadId ?: '(лид)', 'ENTITY_TYPE' => 'lead', 'COMMENT' => 'Резюме соискателя (бот, диалог #' . $T['d']['id'] . ')'];
    if (isset($resume['link'])) {
        $fields['COMMENT'] .= ': ' . $resume['link'];
    } elseif (isset($resume['file'])) {
        $path = upload_path($resume);
        if (!is_file($path)) throw new RuntimeException('файл резюме не найден на сервере');
        $fields['FILES'] = b24_live($T['d']['channel']) ? [[$resume['name'], base64_encode((string)file_get_contents($path))]] : [[$resume['name'], '(base64, ' . filesize($path) . ' байт)']];
    }
    b24_call($T, 'b24_attach_resume', 'crm.timeline.comment.add', ['fields' => $fields]);
}

function act_b24_create_smart_item(array &$T, array $a): void
{
    $raw = (array)($a['fields'] ?? []);
    $fields = [];
    $skipped = [];
    foreach ($raw as $k => $v) {
        $val = tpl($T, $v);
        if (is_todo($v) || is_todo($val)) { $skipped[] = $k; continue; }
        if ($val === null || $val === '') continue;
        $fields[$k] = $val;
    }
    if (empty($fields['assignedById']) && crm_assignee() > 0) $fields['assignedById'] = crm_assignee();
    $type = (int)($fields['entityTypeId'] ?? 0);
    unset($fields['entityTypeId']);
    if ($type <= 0) throw new RuntimeException('не задан entityTypeId смарт-процесса');
    if ($skipped) action_log($T, 'b24_create_smart_item', ['пропущено' => $skipped], 'поля не настроены (TODO) — элемент создаётся без них');
    if (isset($raw['parentId1']) && empty($fields['parentId1'])) {
        $lead = lead_ensure($T);
        if ($lead) $fields['parentId1'] = $lead;
    }
    $res = b24_call($T, 'b24_create_smart_item', 'crm.item.add', ['entityTypeId' => $type, 'fields' => $fields]);
    if ($res && !empty($a['save_id_to'])) slot_set($T, (string)$a['save_id_to'], (int)($res['result']['item']['id'] ?? 0));
}

/** Закрыть сессию открытой линии / передать ответственному. Для сайта и Telegram — только отметка. */
function act_openline_finish(array &$T, array $a): void
{
    $mode = (string)tpl($T, $a['mode'] ?? 'close');
    if ($T['d']['channel'] !== 'bitrix') {
        action_log($T, 'openline_finish', ['mode' => $mode], 'канал не открытая линия — диалог просто завершён');
        return;
    }
    // Подключение к открытым линиям — следующий этап (после теста в веб-чате).
    action_log($T, 'openline_finish', ['mode' => $mode], 'открытые линии ещё не подключены в vb2', false);
}

function act_notify_manager(array &$T, array $a): void
{
    $c = get_client((int)$T['d']['client_id']);
    $text = (string)tpl($T, $a['text'] ?? 'Новое обращение');
    $prio = (string)tpl($T, $a['priority'] ?? 'normal');
    $lines = [];
    foreach ($T['d']['slots'] as $name => $v) {
        $txt = value_text($v);
        if ($txt !== '' && !preg_match('/^(handoff_|crosssell|followup\.return)/', $name)) $lines[] = '• ' . htmlspecialchars($name) . ': ' . htmlspecialchars(mb_strimwidth($txt, 0, 200, '…'));
    }
    $html = ($prio === 'urgent' ? '🔴 <b>СРОЧНО</b> · ' : '🔔 ') . '<b>' . htmlspecialchars($text) . '</b>'
        . "\nКлиент: " . htmlspecialchars(client_display_name($c)) . ($c['phone'] ? ' · ' . htmlspecialchars($c['phone']) : '')
        . "\nКанал: " . (CHANNEL_NAMES[$T['d']['channel']] ?? $T['d']['channel']) . ' · этап: ' . htmlspecialchars(sc_state_title($T['sc'], $T['d']['state']))
        . ($lines ? "\n" . implode("\n", $lines) : '')
        . "\n" . public_url('admin/?tab=dialogs&id=' . $T['d']['id'])
        . ((int)$T['d']['lead_id'] > 0 ? "\nЛид: " . b24_lead_url((int)$T['d']['lead_id']) : '')
        . ((int)($T['d']['deal_id'] ?? 0) > 0 ? "\nСделка: " . b24_deal_url((int)$T['d']['deal_id']) : '');
    if ($T['d']['channel'] === 'sandbox') { action_log($T, 'notify_manager', ['priority' => $prio, 'text' => $text], 'песочница: менеджерам не отправлено'); return; }
    $sent = notify_managers($html);
    action_log($T, 'notify_manager', ['priority' => $prio, 'text' => $text], $sent ? 'отправлено в Telegram менеджерам' : 'manager_chat_ids не настроены — уведомление только в админке');
}

/* ------------------------------------------------------------------ */
/*  Инструменты ответчика                                              */
/* ------------------------------------------------------------------ */

function run_tool(array &$T, string $name, array $args, array $state): array
{
    if (!in_array($name, (array)($state['tools'] ?? []), true)) {
        $r = ['error' => "инструмент $name недоступен на этом этапе"];
        action_log($T, 'tool:' . $name, $args, $r['error'], false);
        return $r;
    }
    try {
        $r = match ($name) {
            'create_payment_link' => tool_create_payment_link($T, $args, $state),
            'send_form_link' => tool_send_form_link($T, $args),
            'biometry_check' => tool_biometry_check($T, $args),
            default => ['error' => 'неизвестный инструмент'],
        };
        action_log($T, 'tool:' . $name, $args, jenc($r), !isset($r['error']));
        return $r;
    } catch (Throwable $e) {
        action_log($T, 'tool:' . $name, $args, 'ОШИБКА: ' . $e->getMessage(), false);
        return ['error' => $e->getMessage()];
    }
}

function tool_create_payment_link(array &$T, array $args, array $state): array
{
    if ($why = product_sale_blocked((string)($args['product_id'] ?? ''))) return ['error' => $why, 'blocked' => true];
    // согласие: обязательные bool-слоты этапа должны быть true
    foreach ((array)($state['slots_required'] ?? []) as $sl) {
        if ((sc_slot($T['sc'], $sl)['type'] ?? '') === 'bool' && slot_get($T, $sl) !== true) {
            return ['error' => "нет явного согласия клиента ($sl). Подтверди с клиентом продукт, стоимость и условия и получи согласие, потом выставляй счёт."];
        }
    }
    if (!empty($T['d']['slots']['payment.invoice_id']) && ($T['d']['slots']['payment.status'] ?? '') === 'pending') {
        return ['error' => 'счёт уже выставлен и ждёт оплаты', 'url' => pay_url((string)$T['d']['slots']['payment.invoice_id'])];
    }
    $price = catalog_price((string)($args['product_id'] ?? ''), (string)($args['variant_id'] ?? ''), (array)($args['options'] ?? []), (int)($args['quantity'] ?? 1));
    $demo = (bool)(sc_setting($T['sc'], 'test_mode') ?? true);
    $code = bin2hex(random_bytes(8));
    db()->prepare('INSERT INTO payments(code, dialog_id, product_id, variant_id, options, quantity, amount, currency, description, demo) VALUES(?,?,?,?,?,?,?,?,?,?)')
        ->execute([$code, $T['d']['id'], $args['product_id'], (string)($args['variant_id'] ?? ''), jenc(array_values((array)($args['options'] ?? []))), max(1, (int)($args['quantity'] ?? 1)), $price['amount'], $price['currency'], $price['description'], $demo ? 1 : 0]);
    $no = invoice_no((int)db()->lastInsertId());
    slot_set($T, 'payment.invoice_id', $code);
    slot_set($T, 'payment.invoice_no', $no);
    slot_set($T, 'payment.amount', $price['amount']);
    slot_set($T, 'payment.status', 'pending');
    $T['events'][] = 'invoice_sent';
    // QR-код отправляется отдельным сообщением сразу после ответа бота
    $qr = payment_qr($code, $no, $demo);
    if ($qr) $T['attach'][] = [
        'text' => "Счёт № {$no} · " . money_fmt($price['amount'], $price['currency']) . ($demo ? ' · тестовый' : '') . "\nОтсканируйте QR-код или откройте ссылку: " . pay_url($code),
        'att' => ['kind' => 'qr', 'code' => $code, 'file' => $qr, 'name' => "QR счёт № {$no}.png"],
    ];
    return [
        'invoice_no' => $no,
        'url' => pay_url($code),
        'amount' => money_fmt($price['amount'], $price['currency']),
        'description' => $price['description'],
        'demo' => $demo,
        'qr' => $qr ? 'QR-код со ссылкой на оплату система отправит клиенту отдельным сообщением сразу после твоего ответа — сошлись на него («ниже QR-код»), назови номер счёта и сумму.' : '',
        'note' => $demo ? 'Тестовый режим: счёт демонстрационный, деньги не списываются — сообщи это клиенту.' : '',
    ];
}

function invoice_no(int $id): string { return str_pad((string)$id, 4, '0', STR_PAD_LEFT); }

/**
 * PNG с QR-кодом ссылки на оплату (+ подпись «Счёт № … · ТЕСТ»). Нужен qrencode (apt install qrencode).
 * Возвращает путь относительно data/ или null, если qrencode не установлен.
 */
function payment_qr(string $code, string $no, bool $demo): ?string
{
    $rel = 'qr/' . $code . '.png';
    $path = data_dir() . '/' . $rel;
    if (is_file($path)) return $rel;
    $bin = trim((string)@shell_exec('command -v qrencode 2>/dev/null'));
    if ($bin === '') { app_log('qrencode не установлен — QR-код не создан (sudo apt install qrencode)'); return null; }
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0770, true);
    exec(escapeshellarg($bin) . ' -t PNG -s 8 -m 3 -l M -o ' . escapeshellarg($path) . ' ' . escapeshellarg(pay_url($code)) . ' 2>&1', $o, $rc);
    if ($rc !== 0 || !is_file($path)) { app_log('qrencode: ' . implode(' ', $o)); return null; }
    if (function_exists('imagecreatefrompng')) {
        // подпись под кодом: номер счёта и пометка «тест»
        $src = @imagecreatefrompng($path);
        if ($src) {
            $w = imagesx($src); $h = imagesy($src); $band = 56;
            $img = imagecreatetruecolor($w, $h + $band);
            $white = imagecolorallocate($img, 255, 255, 255);
            $black = imagecolorallocate($img, 20, 25, 35);
            $red = imagecolorallocate($img, 190, 30, 30);
            imagefill($img, 0, 0, $white);
            imagecopy($img, $src, 0, 0, 0, 0, $w, $h);
            $font = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
            $label = 'Счёт № ' . $no;
            if (is_file($font) && function_exists('imagettftext')) {
                $box = imagettfbbox(18, 0, $font, $label);
                imagettftext($img, 18, 0, (int)(($w - ($box[2] - $box[0])) / 2), $h + 22, $black, $font, $label);
                if ($demo) { $t = 'ТЕСТ · деньги не списываются'; $box = imagettfbbox(11, 0, $font, $t); imagettftext($img, 11, 0, (int)(($w - ($box[2] - $box[0])) / 2), $h + 44, $red, $font, $t); }
            } else {
                $t = 'INVOICE No ' . $no . ($demo ? ' - DEMO' : '');
                imagestring($img, 5, (int)(($w - strlen($t) * 9) / 2), $h + 18, $t, $black);
            }
            imagepng($img, $path);
            imagedestroy($img); imagedestroy($src);
        }
    }
    return $rel;
}

function pay_url(string $code): string { return public_url('pay.php?c=' . $code); }

function tool_send_form_link(array &$T, array $args): array
{
    if ($why = product_sale_blocked((string)($args['product_id'] ?? ''))) return ['error' => $why, 'blocked' => true];
    $url = (string)($T['sc']['tools']['send_form_link']['url'] ?? '');
    if ($url === '' || is_todo($url)) return ['error' => 'ссылка на анкету ещё не настроена. Скажи клиенту, что анкету пришлёт менеджер, и не проси паспортные данные в чате.'];
    $sets = (string)($T['sc']['tools']['send_form_link']['sets'] ?? '');
    if ($sets !== '') slot_set($T, $sets, true);
    return ['url' => $url];
}

function tool_biometry_check(array &$T, array $args): array
{
    $counter = (string)($T['sc']['tools']['biometry_check']['increments'] ?? '');
    if ($counter !== '') slot_set($T, $counter, (int)($T['d']['slots'][$counter] ?? 0) + 1);
    $src = (string)($T['sc']['catalog_refs']['biometry_registry'] ?? '');
    // Реестр биометрии ещё не подключён (Excel) — честно «нет данных».
    return ['answer' => 'unknown', 'note' => is_todo($src) || $src === '' ? 'реестр мест не подключён' : 'реестр не прочитан'];
}

/** Оплата прошла / не прошла (демо-страница или эквайринг). */
function payment_mark(string $code, bool $paid): ?array
{
    $st = db()->prepare('SELECT * FROM payments WHERE code = ?');
    $st->execute([$code]);
    $p = $st->fetch();
    if (!$p || $p['status'] === 'paid') return $p ?: null;
    db()->prepare("UPDATE payments SET status = ?, paid_at = CASE WHEN ? THEN datetime('now') ELSE paid_at END WHERE id = ?")
        ->execute([$paid ? 'paid' : 'failed', $paid ? 1 : 0, $p['id']]);
    $d = dialog_load((int)$p['dialog_id']);
    $d['slots']['payment.status'] = $paid ? 'paid' : 'failed';
    dialog_save($d);
    engine_event((int)$p['dialog_id'], $paid ? 'payment.paid' : 'payment.failed', true);
    return $p;
}
