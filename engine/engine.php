<?php
declare(strict_types=1);

/**
 * Движок: один ход диалога.
 *
 *   сообщение клиента
 *     → событие client_message (closed → greeting, postponed → возврат)
 *     → классификатор: {intent, slots}            (engine/llm.php)
 *     → слоты записываются, проверяются переходы: сначала global_transitions, потом состояния;
 *       переходы идут цепочкой (jobs → jobs_finish → closed), не больше max_transitions_per_event
 *     → при переходе: on_exit старого, on_enter нового, таймеры   (engine/actions.php, timers.php)
 *     → ответчик пишет ответ в рамках итогового состояния (или последнего транзитного)
 *     → события от инструментов (invoice_sent) → снова переходы
 *
 * Всё пишется в журналы: transitions, llm_calls, actions_log — их показывает карточка диалога.
 *
 * Контекст хода $T (передаётся по ссылке):
 *   d         — диалог (slots, counters — массивы)
 *   sc        — сценарий диалога
 *   msg_id    — id сообщения, вызвавшего ход
 *   visited   — состояния, пройденные за ход
 *   reply_state — состояние, от имени которого пишется ответ (последнее транзитное)
 *   say       — готовые тексты к отправке (таймеры, действие say с текстом)
 *   changed   — какие слоты изменились за ход
 */

/* ------------------------------------------------------------------ */
/*  Шаблоны {{…}}                                                      */
/* ------------------------------------------------------------------ */

/** Значение по пути: slots (ключи с точками), settings.*, lead.id, dialog.*. */
function ctx_value(array $T, string $path)
{
    $path = trim($path);
    if (str_starts_with($path, 'settings.')) return sc_setting($T['sc'], substr($path, 9));
    if ($path === 'lead.id') return (int)$T['d']['lead_id'] ?: null;
    if ($path === 'dialog.id') return $T['d']['id'];
    if ($path === 'state') return $T['d']['state'];
    $slots = $T['d']['slots'];
    if (array_key_exists($path, $slots)) return $slots[$path];
    // jobs.full_name.last → слот jobs.full_name, поле last
    $parts = explode('.', $path);
    for ($i = count($parts) - 1; $i > 0; $i--) {
        $key = implode('.', array_slice($parts, 0, $i));
        if (array_key_exists($key, $slots)) {
            $v = $slots[$key];
            foreach (array_slice($parts, $i) as $p) { if (!is_array($v) || !array_key_exists($p, $v)) return null; $v = $v[$p]; }
            return $v;
        }
    }
    $def = $T['sc']['slots'][$path] ?? null;
    return $def['default'] ?? null;
}

function value_text($v): string
{
    if ($v === null) return '';
    if (is_bool($v)) return $v ? 'да' : 'нет';
    if (is_array($v)) {
        if (isset($v['last']) || isset($v['first'])) return trim(implode(' ', array_filter([$v['last'] ?? '', $v['first'] ?? '', $v['middle'] ?? ''])));
        if (isset($v['value'])) return (string)$v['value'];
        if (isset($v['name'], $v['file'])) return 'файл ' . $v['name'];
        if (array_is_list($v)) return implode(', ', array_map('value_text', $v));
        return jenc($v);
    }
    return (string)$v;
}

/**
 * Подстановка шаблонов. Строка, целиком равная «{{x}}», возвращает значение как есть (число, массив).
 * Фильтр: {{x | default: 'текст'}}.
 */
function tpl(array $T, $s)
{
    if (is_array($s)) return array_map(fn($x) => tpl($T, $x), $s);
    if (!is_string($s) || !str_contains($s, '{{')) return $s;
    $one = '/^\{\{\s*([^}|]+?)\s*(?:\|\s*default:\s*\'([^\']*)\')?\s*\}\}$/u';
    if (preg_match($one, $s, $m)) {
        $v = ctx_value($T, $m[1]);
        return ($v === null || $v === '') && isset($m[2]) ? $m[2] : $v;
    }
    return preg_replace_callback('/\{\{\s*([^}|]+?)\s*(?:\|\s*default:\s*\'([^\']*)\')?\s*\}\}/u', function ($m) use ($T) {
        $v = value_text(ctx_value($T, $m[1]));
        return $v === '' && isset($m[2]) ? $m[2] : $v;
    }, $s);
}

/* ------------------------------------------------------------------ */
/*  Слоты                                                              */
/* ------------------------------------------------------------------ */

function slot_filled($v, array $def): bool
{
    if (($def['type'] ?? '') === 'bool') return $v === true;
    if ($v === null || $v === '' || $v === []) return false;
    if (is_array($v) && isset($v['last']) || is_array($v) && isset($v['first'])) return trim(($v['last'] ?? '') . ($v['first'] ?? '')) !== '';
    return true;
}

function slot_get(array $T, string $name)
{
    return $T['d']['slots'][$name] ?? ($T['sc']['slots'][$name]['default'] ?? null);
}

/** Приведение значения от классификатора к типу слота. null — отбросить. */
function slot_coerce(array $sc, string $name, $raw, $current)
{
    $def = sc_slot($sc, $name);
    $type = $def['type'] ?? 'string';
    if (is_array($raw)) $raw = jenc($raw);
    $s = trim((string)$raw);
    if ($s === '' || in_array(mb_strtolower($s), ['null', 'none', 'нет данных', 'неизвестно'], true)) return null;
    switch ($type) {
        case 'phone':
            return normalize_phone($s) ?: null;
        case 'contact':
            if (filter_var($s, FILTER_VALIDATE_EMAIL)) return ['type' => 'email', 'value' => mb_strtolower($s)];
            if ($p = normalize_phone($s)) return ['type' => 'phone', 'value' => $p];
            return ['type' => 'other', 'value' => mb_substr($s, 0, 120)];
        case 'enum':
            $vals = (array)($def['values'] ?? []);
            if (in_array($s, $vals, true)) return $s;
            foreach ($vals as $v) if (mb_strtolower($v) === mb_strtolower($s)) return $v;
            return null;
        case 'int':
            if (!preg_match('/\d+/', $s, $m)) return null;
            $n = (int)$m[0];
            return $n > 0 && $n < 1000 ? $n : null;
        case 'bool':
            $l = mb_strtolower($s);
            if (in_array($l, ['true', 'да', 'yes', '1'], true)) return true;
            if (in_array($l, ['false', 'нет', 'no', '0'], true)) return false;
            return null;
        case 'fio':
            $parts = array_map('trim', explode('/', $s));
            if (count($parts) === 1) { // классификатор не разделил — «Фамилия Имя Отчество»
                $w = preg_split('/\s+/u', $s);
                $parts = count($w) >= 2 ? [$w[0], $w[1], $w[2] ?? ''] : ['', $w[0], ''];
            }
            $cur = is_array($current) ? $current : [];
            $new = ['last' => $parts[0] ?? '', 'first' => $parts[1] ?? '', 'middle' => $parts[2] ?? ''];
            foreach ($new as $k => $v) {
                $v = mb_convert_case(mb_strtolower($v), MB_CASE_TITLE);
                $new[$k] = $v !== '' ? mb_substr($v, 0, 60) : ($cur[$k] ?? '');
            }
            return $new['last'] . $new['first'] === '' ? null : $new;
        case 'list':
            $list = is_array($current) ? $current : [];
            if (!in_array($s, $list, true)) $list[] = mb_substr($s, 0, 200);
            return $list;
        case 'datetime':
            try {
                $dt = new DateTimeImmutable($s, app_tz());
                return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            } catch (Throwable $e) { return null; }
        case 'catalog_ref':
            [$pid] = explode(':', $s) + [''];
            return catalog_get($pid) ? $s : null;
        case 'file_or_link':
            if (preg_match('#https?://\S+#u', $s, $m)) return ['link' => rtrim($m[0], '.,;)')];
            return null;
        case 'counter': case 'money': case 'state_ref':
            return null; // заполняет только движок
        default:
            return mb_substr($s, 0, 600);
    }
}

/** Слоты, которые классификатор может заполнять. */
function classifiable_slots(array $sc): array
{
    $out = [];
    foreach ((array)($sc['slots'] ?? []) as $name => $def) {
        $t = $def['type'] ?? 'string';
        if (in_array($t, ['counter', 'money', 'state_ref'], true)) continue;
        if (preg_match('/^(payment|handoff_|followup\.return|crosssell)/', $name)) continue;
        if (str_ends_with($name, '_id') && $t === 'int') continue;
        if (str_ends_with($name, '_asked')) continue;
        $out[$name] = $def;
    }
    return $out;
}

function slot_set(array &$T, string $name, $value, string $why = ''): void
{
    $old = $T['d']['slots'][$name] ?? null;
    if ($old === $value) return;
    $T['d']['slots'][$name] = $value;
    $T['changed'][$name] = $value;
}

/** Подтягивает известное о клиенте в слоты и обратно — чтобы не переспрашивать. */
function sync_client_slots(array &$T): void
{
    $sc = $T['sc'];
    $d = &$T['d'];
    $c = get_client((int)$d['client_id']);
    if (!$c) return;
    $slots = $sc['slots'] ?? [];
    // клиент → слоты (только пустые)
    if (isset($slots['client_name']) && empty($d['slots']['client_name']) && $c['first_name'] !== '') $d['slots']['client_name'] = $c['first_name'];
    if (isset($slots['phone']) && empty($d['slots']['phone']) && $c['phone'] !== '') $d['slots']['phone'] = $c['phone'];
    // слоты → клиент
    $upd = [];
    if (!empty($d['slots']['client_name'])) $upd['first_name'] = $d['slots']['client_name'];
    if (!empty($d['slots']['phone'])) $upd['phone'] = $d['slots']['phone'];
    foreach ($d['slots'] as $name => $v) {
        $t = $slots[$name]['type'] ?? '';
        if ($t === 'fio' && is_array($v)) { $upd['last_name'] = $v['last'] ?? ''; $upd['first_name'] = $v['first'] ?: ($upd['first_name'] ?? ''); $upd['middle_name'] = $v['middle'] ?? ''; }
        if ($t === 'contact' && is_array($v)) {
            if ($v['type'] === 'phone') $upd['phone'] = $v['value'];
            elseif ($v['type'] === 'email') $upd['email'] = $v['value'];
            else $upd['messenger_contact'] = $v['value'];
        }
    }
    if ($upd) update_client((int)$d['client_id'], array_filter($upd, fn($x) => $x !== ''));
}

/* ------------------------------------------------------------------ */
/*  Условия                                                            */
/* ------------------------------------------------------------------ */

/** $ctx: ['intent' => ?string, 'event' => ?string] */
function cond_match(array $T, array $on, array $ctx): bool
{
    if (!$on) return false;
    $sc = $T['sc'];
    $state = sc_state($sc, $T['d']['state']);
    foreach ($on as $k => $v) {
        switch ($k) {
            case 'always':
                if (!$v) return false; break;
            case 'intent':
                if (($ctx['intent'] ?? null) !== $v) return false; break;
            case 'event':
                if (($ctx['event'] ?? null) !== $v) return false;
                if (isset($on['count_gte']) && (int)($T['d']['counters']['event:' . $v] ?? 0) < (int)$on['count_gte']) return false;
                break;
            case 'count_gte': break; // проверяется вместе с event
            case 'slot':
                $val = slot_get($T, $v);
                if (array_key_exists('eq', $on)) { if ($val !== $on['eq'] && !(is_scalar($val) && (string)$val === (string)$on['eq'] && !is_bool($on['eq']))) return false; }
                elseif (!slot_filled($val, sc_slot($sc, $v))) return false;
                break;
            case 'eq': break;
            case 'slot_set':
                if (!slot_filled(slot_get($T, $v), sc_slot($sc, $v))) return false; break;
            case 'slot_not':
                if (slot_filled(slot_get($T, $v), sc_slot($sc, $v))) return false; break;
            case 'slots_complete':
                foreach ((array)($state['slots_required'] ?? []) as $sl) if (!slot_filled(slot_get($T, $sl), sc_slot($sc, $sl))) return false;
                break;
            case 'counter':
                $n = (int)(slot_get($T, $v) ?? ($T['d']['counters'][$v] ?? 0));
                if (isset($on['gte']) && $n < (int)$on['gte']) return false;
                break;
            case 'gte': break;
            case 'any':
                $ok = false;
                foreach ((array)$v as $sub) if (is_array($sub) && cond_match($T, $sub, $ctx)) { $ok = true; break; }
                if (!$ok) return false;
                break;
            default:
                return false;
        }
    }
    return true;
}

function cond_text(array $on): string
{
    $p = [];
    foreach ($on as $k => $v) {
        if (in_array($k, ['eq', 'gte', 'count_gte'], true)) continue;
        $p[] = match ($k) {
            'slot' => array_key_exists('eq', $on) ? "слот $v = " . value_text($on['eq']) : "слот $v заполнен",
            'slots_complete' => 'собраны обязательные слоты',
            'counter' => "счётчик $v ≥ " . ($on['gte'] ?? '?'),
            'event' => "событие $v" . (isset($on['count_gte']) ? " (≥{$on['count_gte']} раз)" : ''),
            'intent' => "интент $v",
            'always' => 'сразу',
            'any' => 'одно из: ' . implode(' / ', array_map(fn($x) => cond_text((array)$x), (array)$v)),
            default => "$k=" . value_text($v),
        };
    }
    return implode(', ', $p);
}

/* ------------------------------------------------------------------ */
/*  Переходы                                                           */
/* ------------------------------------------------------------------ */

function turn_init(array $d, int $msgId = 0): array
{
    return ['d' => $d, 'sc' => scenario_for($d), 'msg_id' => $msgId, 'visited' => [], 'reply_state' => null, 'say' => [], 'changed' => [], 'left' => null, 'error' => null];
}

/**
 * Проверяет переходы, пока что-то срабатывает. $ctx['intent'] и $ctx['event'] гасятся,
 * как только по ним сработал переход (одно намерение — один переход).
 */
function eval_transitions(array &$T, array $ctx): void
{
    $max = (int)(sc_setting($T['sc'], 'max_transitions_per_event') ?? 10);
    for ($i = 0; $i < $max; $i++) {
        $state = $T['d']['state'];
        $rules = array_merge(
            array_map(fn($r) => $r + ['_g' => 1], (array)($T['sc']['global_transitions'] ?? [])),
            (array)(sc_state($T['sc'], $state)['transitions'] ?? [])
        );
        $fired = null;
        foreach ($rules as $r) {
            $on = (array)($r['on'] ?? []);
            if (cond_match($T, $on, $ctx)) { $fired = $r; break; }
        }
        if (!$fired) return;
        $on = (array)$fired['on'];
        if (isset($on['intent'])) $ctx['intent'] = null;
        if (isset($on['event'])) $ctx['event'] = null;

        // служебное действие без смены состояния (пауза бота после реплики оператора)
        if (($fired['action'] ?? null) === 'pause_bot') {
            $min = (float)tpl($T, $fired['minutes'] ?? 10);
            $T['d']['paused_until'] = utc_plus_minutes($min);
            action_log($T, 'pause_bot', ['minutes' => $min], 'бот молчит до ' . fmt_dt($T['d']['paused_until']));
            continue;
        }
        foreach ((array)($fired['set'] ?? []) as $k => $v) slot_set($T, $k, tpl($T, $v));
        if (is_array($fired['action'] ?? null) && isset($fired['action']['say'])) $T['say'][] = (string)tpl($T, $fired['action']['say']);

        $to = isset($fired['to']) ? (string)tpl($T, $fired['to']) : '';
        if ($to === '' || $to === $state) {
            // переход в себя: on_enter не повторяем (не дублируем уведомления менеджеру)
            if (!empty($fired['set']) || isset($fired['action'])) continue;
            return;
        }
        if (!isset($T['sc']['states'][$to])) {
            action_log($T, 'transition', ['to' => $to], "состояния «{$to}» нет в сценарии", false);
            return;
        }
        transition($T, $to, (!empty($fired['_g']) ? 'глобальный: ' : '') . cond_text($on));
        if (count(array_keys($T['visited'], $to, true)) > 2) return; // защита от петель
    }
    action_log($T, 'transition', [], "достигнут лимит переходов за ход ($max)", false);
}

function transition(array &$T, string $to, string $reason): void
{
    $from = $T['d']['state'];
    $sc = $T['sc'];
    run_actions($T, (array)(sc_state($sc, $from)['on_exit'] ?? []), "$from.on_exit");
    timers_cancel_state($T['d'], $from);

    db()->prepare('INSERT INTO transitions(dialog_id, message_id, from_state, to_state, reason, set_json) VALUES(?,?,?,?,?,?)')
        ->execute([$T['d']['id'], $T['msg_id'] ?: null, $from, $to, $reason, $T['changed'] ? jenc($T['changed']) : '']);
    // новый круг после завершённого диалога: тема и лид — с чистого листа, контакты клиента остаются
    if (sc_state_type($sc, $from) === 'terminal') {
        $keep = (array)(sc_setting($sc, 'keep_slots_on_restart') ?? ['client_name', 'phone', 'contact']);
        $T['d']['slots'] = array_intersect_key($T['d']['slots'], array_flip($keep));
        $T['d']['counters'] = array_intersect_key($T['d']['counters'], ['event:client_message' => 1]);
        $T['d']['lead_id'] = 0;
        $T['changed'] = [];
    }
    $T['d']['state'] = $to;
    $T['d']['state_turns'] = 0;
    $T['d']['state_entered_at'] = now_utc();
    foreach (array_keys($T['d']['counters']) as $k) if (str_starts_with($k, 'timer:')) unset($T['d']['counters'][$k]);
    $T['visited'][] = $to;
    $T['left'] ??= $from;
    $def = sc_state($sc, $to);
    if (($def['type'] ?? '') === 'transit') $T['reply_state'] = $to;

    $ok = run_actions($T, (array)($def['on_enter'] ?? []), "$to.on_enter");
    if (!$ok && isset($def['on_error'])) {
        foreach ((array)($def['on_error']['set'] ?? []) as $k => $v) slot_set($T, $k, tpl($T, $v));
        $target = (string)tpl($T, $def['on_error']['to'] ?? '');
        if ($target !== '' && isset($sc['states'][$target]) && $target !== $to) {
            $T['reply_state'] = null;
            transition($T, $target, 'ошибка в действиях «' . $to . '»');
            return;
        }
    }
    timers_start_state($T['d'], $sc, $to, null);
}

/* ------------------------------------------------------------------ */
/*  Ход: сообщение клиента                                             */
/* ------------------------------------------------------------------ */

/**
 * Обрабатывает сообщение клиента. Возвращает тексты бота за этот ход (уже сохранены в messages).
 * $attachment — файл от клиента (store_upload).
 */
function engine_message(int $dialogId, string $text, ?array $attachment = null): array
{
    $text = trim(mb_substr($text, 0, 3000));
    $d = dialog_load($dialogId);
    $msgId = save_message($dialogId, 'user', $text !== '' ? $text : '(файл)', $attachment, $d['state']);
    $T = turn_init($d, $msgId);

    // бот на паузе (рядом работает оператор) — только слушаем
    if ($T['d']['paused_until'] && $T['d']['paused_until'] > now_utc()) {
        dialog_save($T['d']);
        return [];
    }
    $T['d']['paused_until'] = null;

    // событие «клиент написал» — возврат из closed/postponed
    $T['d']['counters']['event:client_message'] = (int)($T['d']['counters']['event:client_message'] ?? 0) + 1;
    eval_transitions($T, ['event' => 'client_message']);

    // файл → слот типа file_or_link текущего состояния
    if ($attachment) {
        $st = sc_state($T['sc'], $T['d']['state']);
        foreach (array_merge((array)($st['slots_required'] ?? []), (array)($st['slots_optional'] ?? [])) as $sl) {
            if ((sc_slot($T['sc'], $sl)['type'] ?? '') === 'file_or_link') { slot_set($T, $sl, $attachment); break; }
        }
    }

    sync_client_slots($T);
    $intent = null;
    try {
        $cls = classify($T, $text, $attachment);
        $intent = $cls['intent'];
        foreach ($cls['slots'] as $name => $raw) {
            $v = slot_coerce($T['sc'], $name, $raw, $T['d']['slots'][$name] ?? null);
            if ($v !== null) slot_set($T, $name, $v);
        }
        // интент-продукт заполняет слот intent (для выбора ветки в приветствии)
        $intentSlot = $T['sc']['slots']['intent'] ?? null;
        if ($intent && $intentSlot && in_array($intent, (array)($intentSlot['values'] ?? []), true) && empty($T['d']['slots']['intent'])) {
            slot_set($T, 'intent', $intent);
        }
    } catch (Throwable $e) {
        action_log($T, 'classify', [], 'ошибка разбора: ' . $e->getMessage(), false);
    }
    if ($phone = find_phone($text)) {
        if (isset($T['sc']['slots']['phone']) && empty($T['d']['slots']['phone'])) slot_set($T, 'phone', $phone);
    }
    sync_client_slots($T);

    $T['d']['state_turns']++;
    eval_transitions($T, ['intent' => $intent]);

    $max = (int)(sc_state($T['sc'], $T['d']['state'])['max_turns'] ?? 0);
    if ($max > 0 && $T['d']['state_turns'] > $max) eval_transitions($T, ['event' => 'turn_limit_exceeded']);

    return engine_finish($T, true, $text);
}

/**
 * Внешнее событие (оплата, таймер, кнопка в песочнице). Если сменилось состояние — бот пишет.
 * Возвращает тексты бота; при $push — отправляет их в канал (Telegram).
 */
function engine_event(int $dialogId, string $event, bool $push = true, array $opts = []): array
{
    $d = dialog_load($dialogId);
    $T = turn_init($d, 0);
    $T['d']['counters']['event:' . $event] = (int)($T['d']['counters']['event:' . $event] ?? 0) + 1;
    if (!empty($opts['say'])) $T['say'][] = (string)$opts['say'];
    $before = $T['d']['state'];
    eval_transitions($T, ['event' => $event]);
    $moved = $T['d']['state'] !== $before;
    $speak = $moved && sc_state_type($T['sc'], $T['d']['state']) !== 'terminal' || $T['reply_state'] !== null;
    $out = engine_finish($T, $speak && !$T['say'], '', 'Событие: ' . $event);
    if ($push) channel_push($T['d'], $out);
    return $out;
}

/** Ответ бота (если нужен), сохранение, таймеры. */
function engine_finish(array &$T, bool $needReply, string $userText, string $eventNote = ''): array
{
    $out = [];
    foreach ($T['say'] as $s) if (trim($s) !== '') $out[] = trim($s);

    if ($needReply) {
        try {
            $reply = respond($T, $userText, $eventNote);
            if ($reply !== '') array_unshift($out, $reply);
        } catch (Throwable $e) {
            action_log($T, 'respond', [], 'ошибка ответа: ' . $e->getMessage(), false);
            app_log('respond: ' . $e->getMessage());
            array_unshift($out, 'Извините, у меня технический сбой. Попробуйте написать ещё раз через минуту — или дождитесь менеджера, он ответит.');
        }
        // события от инструментов (счёт выставлен) → переходы после ответа
        foreach ($T['events'] ?? [] as $ev) {
            $T['d']['counters']['event:' . $ev] = (int)($T['d']['counters']['event:' . $ev] ?? 0) + 1;
            eval_transitions($T, ['event' => $ev]);
        }
        $T['events'] = [];
    }

    foreach ($out as $text) save_message((int)$T['d']['id'], 'assistant', $text, null, $T['d']['state']);
    if ($out) timers_start_state($T['d'], $T['sc'], $T['d']['state'], 'bot_answered_question');
    timers_sync_slots($T['d'], $T['sc']);
    dialog_save($T['d']);
    return $out;
}

/** Доставка сообщений, появившихся вне запроса клиента (таймеры, оплата). Веб-чат забирает их опросом. */
function channel_push(array $d, array $texts): void
{
    if (!$texts) return;
    if ($d['channel'] === 'telegram' && $d['ext_id'] !== '') {
        foreach ($texts as $t) tg_send($d['ext_id'], $t);
    }
}

/** Реплика оператора/менеджера (из админки): сохраняется и ставит бота на паузу через глобальный переход. */
function engine_operator_message(int $dialogId, string $text, string $who = ''): void
{
    $d = dialog_load($dialogId);
    save_message($dialogId, 'operator', $text, null, $d['state']);
    channel_push($d, [$text]);
    $T = turn_init($d, 0);
    $T['d']['counters']['event:operator_message'] = (int)($T['d']['counters']['event:operator_message'] ?? 0) + 1;
    eval_transitions($T, ['event' => 'operator_message']);
    dialog_save($T['d']);
}
