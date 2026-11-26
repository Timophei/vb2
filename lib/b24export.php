<?php
declare(strict_types=1);

/**
 * Выгрузка для анализа из Битрикс24 (Настройки → Выгрузка для анализа).
 *
 * Берёт данные портала, а не бота: все диалоги открытых линий всех менеджеров за период
 * и CRM (лиды, сделки воронки, контакты, звонки). Только чтение, режим bitrix_mode не влияет.
 *
 *   b24x_dialogs_zip()  → zip: dialogs.jsonl (машинный разбор), dialogs.txt (читать глазами), messages.csv
 *   b24x_crm_xlsx()     → xlsx: лиды созданные / закрытые, сделки созданные / закрытые, контакты, звонки, диалоги
 *
 * Диалоги находим через дела CRM открытых линий (PROVIDER_ID = IMOPENLINES_SESSION): у дела есть
 * сессия (ASSOCIATED_ENTITY_ID) и привязки к лиду/сделке/контакту. Переписку сессии даёт
 * imopenlines.session.history.get. Нужны права вебхука: crm, imopenlines (и желательно user, im).
 * Отдельный вебхук можно задать в config.php → b24_export_webhook, иначе берётся bitrix_webhook.
 *
 * Персональные данные маскируются (по умолчанию): телефоны, почта, номера карт, фамилии клиентов.
 * Ничего не хранится на сервере: файл собирается во временный каталог, отдаётся и удаляется.
 */

const B24X_TYPE = [1 => 'lead', 2 => 'deal', 3 => 'contact', 4 => 'company'];

/* ------------------------------------------------------------------ */
/*  Запросы                                                            */
/* ------------------------------------------------------------------ */

function b24x_webhook(): string
{
    return rtrim(trim((string)(cfg('b24_export_webhook', '') ?: cfg('bitrix_webhook', ''))), '/');
}

/** Один запрос с повтором при лимите портала (QUERY_LIMIT_EXCEEDED, 503). */
function b24x(string $method, array $params = []): array
{
    if (function_exists('b24_mock')) return b24_mock($method, $params);
    $base = b24x_webhook();
    if ($base === '') throw new RuntimeException('Не задан вебхук Битрикс24 (b24_export_webhook или bitrix_webhook в config.php)');
    for ($try = 0; ; $try++) {
        $ch = curl_init($base . '/' . $method . '.json');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE),
        ]);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $data = $raw === false ? null : json_decode((string)$raw, true);
        $limit = $code === 503 || (is_array($data) && in_array($data['error'] ?? '', ['QUERY_LIMIT_EXCEEDED', 'OPERATION_TIME_LIMIT'], true));
        if (($limit || $raw === false) && $try < 6) { sleep(1 + $try); continue; }
        if ($raw === false) throw new RuntimeException("Битрикс24 $method: нет соединения: $err");
        if (!is_array($data) || isset($data['error'])) {
            $msg = is_array($data) ? ($data['error'] . ': ' . ($data['error_description'] ?? '')) : mb_substr((string)$raw, 0, 300);
            throw new RuntimeException("$method (HTTP $code): $msg");
        }
        return $data;
    }
}

/**
 * Пакет до 50 команд. $cmds: ключ => [method, params]. Возвращает ключ => result (или ['_error' => текст]).
 */
function b24x_batch(array $cmds): array
{
    $out = [];
    foreach (array_chunk($cmds, 50, true) as $chunk) {
        $cmd = [];
        foreach ($chunk as $k => [$m, $p]) $cmd[$k] = $m . '?' . http_build_query($p);
        $r = b24x('batch', ['halt' => 0, 'cmd' => $cmd])['result'] ?? [];
        foreach ($chunk as $k => $_) {
            if (array_key_exists($k, $r['result'] ?? [])) $out[$k] = $r['result'][$k];
            else {
                $e = $r['result_error'][$k] ?? null;
                $out[$k] = ['_error' => is_array($e) ? (($e['error'] ?? '') . ': ' . ($e['error_description'] ?? '')) : 'нет ответа'];
            }
        }
    }
    return $out;
}

/** Все записи списка: постранично по ID (start = -1 — без подсчёта, быстро на больших порталах). */
function b24x_list(string $method, array $filter, array $select, ?callable $progress = null): array
{
    $all = [];
    $last = 0;
    while (true) {
        $f = $filter + ['>ID' => $last];
        $res = b24x($method, ['filter' => $f, 'select' => $select, 'order' => ['ID' => 'ASC'], 'start' => -1])['result'] ?? [];
        if (!is_array($res) || !$res) break;
        foreach ($res as $row) { $all[] = $row; $last = max($last, (int)$row['ID']); }
        if ($progress) $progress(count($all));
        if (count($res) < 50) break;
    }
    return $all;
}

/** Список с постраничной выдачей по start/next — для методов без фильтра по ID. */
function b24x_list_offset(string $method, array $params): array
{
    $all = [];
    $start = 0;
    for ($i = 0; $i < 200; $i++) {
        $r = b24x($method, $params + ['start' => $start]);
        foreach ((array)($r['result'] ?? []) as $row) $all[] = $row;
        if (!isset($r['next'])) break;
        $start = (int)$r['next'];
    }
    return $all;
}

/** Записи по списку ID (кусками по 50). */
function b24x_by_ids(string $method, array $ids, array $select): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    $out = [];
    foreach (array_chunk($ids, 50) as $chunk) {
        foreach (b24x($method, ['filter' => ['@ID' => $chunk], 'select' => $select, 'start' => -1])['result'] ?? [] as $r) $out[(int)$r['ID']] = $r;
    }
    return $out;
}

/* ------------------------------------------------------------------ */
/*  Даты                                                               */
/* ------------------------------------------------------------------ */

/** Период в часовом поясе админки → ISO-границы для фильтра Битрикса. */
function b24x_period(string $from, string $to): array
{
    $tz = app_tz();
    $a = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $tz);
    $b = DateTimeImmutable::createFromFormat('!Y-m-d', $to, $tz);
    if (!$a || !$b) throw new RuntimeException('Укажите даты «с» и «по».');
    if ($b < $a) [$a, $b] = [$b, $a];
    if ($a->diff($b)->days > 366) throw new RuntimeException('Период не больше года.');
    return [$a->format('c'), $b->setTime(23, 59, 59)->format('c'), $a->format('Ymd') . '-' . $b->format('Ymd')];
}

/** Дата Битрикса (ISO с поясом) → «Y-m-d H:i:s» в поясе админки. */
function b24x_dt($v): string
{
    $v = trim((string)$v);
    if ($v === '') return '';
    try { return (new DateTimeImmutable($v))->setTimezone(app_tz())->format('Y-m-d H:i:s'); } catch (Throwable $e) { return $v; }
}

/* ------------------------------------------------------------------ */
/*  Маскировка персональных данных                                     */
/* ------------------------------------------------------------------ */

/** Телефон: прячем 3 цифры в середине — номера различаются, но не узнаются. 79141234567 → 7914***4567 */
function b24x_mask_phone(string $p): string
{
    $d = preg_replace('/\D/', '', $p);
    $n = strlen($d);
    if ($n < 7) return str_repeat('*', $n);
    return substr($d, 0, $n - 7) . '***' . substr($d, $n - 4);
}

function b24x_mask_email(string $e): string
{
    if (!str_contains($e, '@')) return $e;
    [$l, $dom] = explode('@', $e, 2);
    $len = mb_strlen($l);
    $keep = $len <= 3 ? mb_substr($l, 0, 1) . '***' : mb_substr($l, 0, 2) . '***' . mb_substr($l, -1);
    return $keep . '@' . $dom;
}

/** Фамилия → первая буква с точкой. */
function b24x_mask_surname(string $s): string
{
    $s = trim($s);
    return $s === '' ? '' : mb_substr($s, 0, 1) . '.';
}

/** Свободный текст: почта, номера карт (оставляем 4 последние цифры), телефоны. */
function b24x_mask_text(string $t): string
{
    if ($t === '') return $t;
    $t = preg_replace_callback('/[\w.+\-]+@[\w\-]+(\.[\w\-]+)+/u', fn($m) => b24x_mask_email($m[0]), $t);
    return preg_replace_callback('/(?<![\d\w])\+?\d[\d\s\-().]{5,22}\d(?![\d\w])/u', function ($m) {
        $d = preg_replace('/\D/', '', $m[0]);
        $n = strlen($d);
        if ($n >= 13 && $n <= 19) return str_repeat('*', $n - 4) . substr($d, -4);  // карта
        if ($n >= 10 && $n <= 12) return b24x_mask_phone($d);                        // телефон
        if ($n >= 7 && $n <= 9 && preg_match('/^\+|[\s\-()]/', $m[0]) && !preg_match('/^\d{1,2}[.\-]\d{1,2}[.\-]\d{2,4}$/', $m[0])) return b24x_mask_phone($d); // короткий номер, не дата
        return $m[0];
    }, $t) ?? $t;
}

/* ------------------------------------------------------------------ */
/*  Справочники                                                        */
/* ------------------------------------------------------------------ */

/** Справочники портала: статусы/стадии/источники, воронки, линии, поля сущностей, имена сотрудников. */
final class B24xRef
{
    public array $status = [];     // ENTITY_ID => [STATUS_ID => NAME]
    public array $semantic = [];   // ENTITY_ID => [STATUS_ID => S|F|'']
    public array $categories = []; // id => name
    public array $lines = [];      // id => name
    public array $fields = [];     // lead|deal|contact => [CODE => ['label'=>..., 'items'=>[id=>value], 'type'=>...]]
    public array $users = [];      // id => name
    public array $warnings = [];

    public function load(): void
    {
        try {
            foreach (b24x_list_offset('crm.status.list', ['order' => ['SORT' => 'ASC']]) as $s) {
                $this->status[$s['ENTITY_ID']][(string)$s['STATUS_ID']] = (string)$s['NAME'];
                $this->semantic[$s['ENTITY_ID']][(string)$s['STATUS_ID']] = (string)($s['EXTRA']['SEMANTICS'] ?? $s['SEMANTICS'] ?? '');
            }
        } catch (Throwable $e) { $this->warnings[] = 'Справочник статусов: ' . $e->getMessage(); }
        $this->categories = [0 => 'Общая'];
        try {
            foreach (b24x('crm.category.list', ['entityTypeId' => 2])['result']['categories'] ?? [] as $c) $this->categories[(int)$c['id']] = (string)$c['name'];
        } catch (Throwable $e) {
            try { foreach ((array)(b24x('crm.dealcategory.list', [])['result'] ?? []) as $c) $this->categories[(int)$c['ID']] = (string)$c['NAME']; }
            catch (Throwable $e2) { $this->warnings[] = 'Воронки: ' . $e2->getMessage(); }
        }
        try {
            foreach ((array)(b24x('imopenlines.config.list.get', ['PARAMS' => ['select' => ['ID', 'LINE_NAME']]])['result'] ?? []) as $l) $this->lines[(int)$l['ID']] = (string)$l['LINE_NAME'];
        } catch (Throwable $e) { $this->warnings[] = 'Названия открытых линий: ' . $e->getMessage(); }
        foreach (['lead', 'deal', 'contact'] as $ent) {
            try {
                foreach ((array)(b24x("crm.$ent.fields", [])['result'] ?? []) as $code => $f) {
                    $items = [];
                    foreach ((array)($f['items'] ?? []) as $it) $items[(string)$it['ID']] = (string)$it['VALUE'];
                    $label = (string)($f['listLabel'] ?? '') ?: (string)($f['formLabel'] ?? '') ?: (string)($f['title'] ?? $code);
                    $this->fields[$ent][$code] = ['label' => $label, 'items' => $items, 'type' => (string)($f['type'] ?? '')];
                }
            } catch (Throwable $e) { $this->warnings[] = "Поля $ent: " . $e->getMessage(); }
        }
    }

    public function st(string $entity, $id): string
    {
        $id = (string)$id;
        return $id === '' ? '' : ($this->status[$entity][$id] ?? $id);
    }

    public function stage(int $cat, $stage): string
    {
        return $this->st($cat ? 'DEAL_STAGE_' . $cat : 'DEAL_STAGE', $stage);
    }

    public function stageSemantic(int $cat, $stage): string
    {
        $s = $this->semantic[$cat ? 'DEAL_STAGE_' . $cat : 'DEAL_STAGE'][(string)$stage] ?? '';
        if ($s === '' && preg_match('/(^|:)(WON|LOSE|APOLOGY)$/', (string)$stage, $m)) $s = $m[2] === 'WON' ? 'S' : 'F';
        return $s;
    }

    /** Имена сотрудников по ID (user.get пакетом; без права user — только номера). */
    public function needUsers(array $ids): void
    {
        $ids = array_values(array_diff(array_unique(array_filter(array_map('intval', $ids))), array_keys($this->users)));
        if (!$ids) return;
        $cmds = [];
        foreach ($ids as $id) $cmds['u' . $id] = ['user.get', ['ID' => $id]];
        try {
            foreach (b24x_batch($cmds) as $k => $r) {
                $id = (int)substr($k, 1);
                $u = $r[0] ?? null;
                $this->users[$id] = $u ? trim(($u['NAME'] ?? '') . ' ' . ($u['LAST_NAME'] ?? '')) ?: ('#' . $id) : ('#' . $id);
            }
        } catch (Throwable $e) {
            foreach ($ids as $id) $this->users[$id] = '#' . $id;
            $this->warnings[] = 'Имена сотрудников (нужно право user): ' . $e->getMessage();
        }
    }

    public function user($id): string
    {
        $id = (int)$id;
        return $id ? ($this->users[$id] ?? '#' . $id) : '';
    }
}

/* ------------------------------------------------------------------ */
/*  Сбор данных                                                        */
/* ------------------------------------------------------------------ */

/** Значение пользовательского поля текстом (списки — названиями пунктов). */
function b24x_uf_value(array $f, $v, bool $mask): string
{
    $vals = is_array($v) ? $v : [$v];
    $out = [];
    foreach ($vals as $x) {
        if (is_array($x)) $x = $x['VALUE'] ?? $x['value'] ?? jenc($x);
        $x = (string)$x;
        if ($x === '') continue;
        if ($f['items']) $x = $f['items'][$x] ?? $x;
        elseif ($f['type'] === 'boolean') $x = in_array($x, ['1', 'Y'], true) ? 'да' : 'нет';
        elseif (in_array($f['type'], ['datetime', 'date'], true)) $x = b24x_dt($x);
        $out[] = $mask ? b24x_mask_text($x) : $x;
    }
    return implode(', ', $out);
}

/** Мультиполе (PHONE, EMAIL, IM) текстом. */
function b24x_multi($v, string $kind, bool $mask): string
{
    $out = [];
    foreach ((array)$v as $x) {
        $val = (string)($x['VALUE'] ?? '');
        if ($val === '') continue;
        if ($mask) $val = match ($kind) { 'PHONE' => b24x_mask_phone($val), 'EMAIL' => b24x_mask_email($val), default => b24x_mask_text($val) };
        $type = (string)($x['VALUE_TYPE'] ?? '');
        $out[] = $kind === 'IM' && $type !== '' ? $type . ': ' . $val : $val;
    }
    return implode(', ', $out);
}

function b24x_person(array $r, bool $mask): string
{
    $last = (string)($r['LAST_NAME'] ?? '');
    $parts = [(string)($r['NAME'] ?? '')];
    if (!$mask) $parts[] = (string)($r['SECOND_NAME'] ?? '');    // отчество при маскировке не выводим
    $parts[] = $mask ? b24x_mask_surname($last) : $last;
    return trim(implode(' ', array_filter($parts, fn($x) => $x !== '')));
}

/** Пользовательские поля сущности: [заголовки], функция строки. */
function b24x_uf_columns(B24xRef $ref, string $ent, array $rows): array
{
    $codes = [];
    foreach ($ref->fields[$ent] ?? [] as $code => $f) {
        if (!str_starts_with($code, 'UF_')) continue;
        // только поля, которые хоть где-то заполнены — иначе таблица из пустых колонок
        foreach ($rows as $r) if (isset($r[$code]) && $r[$code] !== '' && $r[$code] !== [] && $r[$code] !== false && $r[$code] !== null) { $codes[] = $code; break; }
    }
    return $codes;
}

/**
 * Все данные периода. $progress(string) — сообщения о ходе.
 */
function b24x_collect(string $from, string $to, array $opt = [], ?callable $progress = null): array
{
    @set_time_limit(0);
    $mask = $opt['mask'] ?? true;
    $dialogs = $opt['dialogs'] ?? true;
    $say = $progress ?? fn($s) => null;
    [$a, $b, $tag] = b24x_period($from, $to);
    $ref = new B24xRef();
    $say('справочники');
    $ref->load();
    $crmSel = ['*', 'UF_*', 'PHONE', 'EMAIL', 'IM'];

    $say('лиды');
    $leadsNew = b24x_list('crm.lead.list', ['>=DATE_CREATE' => $a, '<=DATE_CREATE' => $b], $crmSel);
    $leadsClosed = b24x_list('crm.lead.list', ['>=DATE_CLOSED' => $a, '<=DATE_CLOSED' => $b, '@STATUS_SEMANTIC_ID' => ['S', 'F']], $crmSel);
    $say('сделки');
    $dealFilter = [];
    if (isset($opt['category']) && $opt['category'] !== '' && $opt['category'] !== null) $dealFilter['CATEGORY_ID'] = (int)$opt['category'];
    $dealsNew = b24x_list('crm.deal.list', $dealFilter + ['>=DATE_CREATE' => $a, '<=DATE_CREATE' => $b], ['*', 'UF_*']);
    $dealsClosed = b24x_list('crm.deal.list', $dealFilter + ['CLOSED' => 'Y', '>=CLOSEDATE' => $a, '<=CLOSEDATE' => $b], ['*', 'UF_*']);
    $say('контакты');
    $contactsNew = b24x_list('crm.contact.list', ['>=DATE_CREATE' => $a, '<=DATE_CREATE' => $b], $crmSel);

    $say('звонки');
    $actSel = ['ID', 'OWNER_TYPE_ID', 'OWNER_ID', 'TYPE_ID', 'PROVIDER_ID', 'PROVIDER_TYPE_ID', 'ASSOCIATED_ENTITY_ID', 'SUBJECT', 'CREATED', 'START_TIME', 'END_TIME', 'DEADLINE', 'COMPLETED', 'DIRECTION', 'RESPONSIBLE_ID', 'ORIGIN_ID', 'COMMUNICATIONS'];
    $calls = [];
    try { $calls = b24x_list('crm.activity.list', ['PROVIDER_ID' => 'VOXIMPLANT_CALL', '>=CREATED' => $a, '<=CREATED' => $b], $actSel); }
    catch (Throwable $e) { $ref->warnings[] = 'Звонки: ' . $e->getMessage(); }

    $say('сессии открытых линий');
    $sessions = b24x_list('crm.activity.list', ['PROVIDER_ID' => 'IMOPENLINES_SESSION', '>=CREATED' => $a, '<=CREATED' => $b], $actSel);

    // привязки дел (у одной сессии бывают и лид, и сделка, и контакт)
    $say('привязки (' . count($sessions) . ' диалогов, ' . count($calls) . ' звонков)');
    $bind = [];
    $cmds = [];
    foreach (array_merge($sessions, $calls) as $s) $cmds['a' . $s['ID']] = ['crm.activity.binding.list', ['activityId' => (int)$s['ID']]];
    foreach ($cmds ? b24x_batch($cmds) : [] as $k => $r) {
        $id = (int)substr($k, 1);
        $bind[$id] = [];
        if (isset($r['_error'])) continue;
        foreach ((array)$r as $x) {
            $t = B24X_TYPE[(int)($x['entityTypeId'] ?? 0)] ?? null;
            if ($t) $bind[$id][$t][] = (int)$x['entityId'];
        }
    }
    $binding = function (array $act) use (&$bind): array {
        $b = $bind[(int)$act['ID']] ?? [];
        $t = B24X_TYPE[(int)$act['OWNER_TYPE_ID']] ?? null;
        if ($t && !in_array((int)$act['OWNER_ID'], $b[$t] ?? [], true)) $b[$t][] = (int)$act['OWNER_ID'];
        return $b;
    };

    // переписка
    $hist = [];
    if ($dialogs && $sessions) {
        $cmds = [];
        foreach ($sessions as $s) if ((int)$s['ASSOCIATED_ENTITY_ID']) $cmds['s' . $s['ID']] = ['imopenlines.session.history.get', ['SESSION_ID' => (int)$s['ASSOCIATED_ENTITY_ID']]];
        $done = 0;
        foreach (array_chunk($cmds, 50, true) as $chunk) {
            foreach (b24x_batch($chunk) as $k => $r) $hist[(int)substr($k, 1)] = $r;
            $done += count($chunk);
            // на части версий история требует ещё и CHAT_ID: узнаём чат через imopenlines.dialog.get и повторяем
            $retry = [];
            foreach ($chunk as $k => [$m, $p]) if (isset($hist[(int)substr($k, 1)]['_error'])) $retry[$k] = ['imopenlines.dialog.get', $p];
            if ($retry) {
                $again = [];
                foreach (b24x_batch($retry) as $k => $r) {
                    $chat = (int)($r['id'] ?? $r['chat_id'] ?? 0);
                    if ($chat) $again[$k] = ['imopenlines.session.history.get', $retry[$k][1] + ['CHAT_ID' => $chat]];
                }
                foreach ($again ? b24x_batch($again) : [] as $k => $r) if (!isset($r['_error'])) $hist[(int)substr($k, 1)] = $r;
            }
            $say("переписка: $done из " . count($cmds));
        }
    }

    // связанные записи вне периода: лид/сделка/контакт диалога, лид-родитель сделки, контакт лида
    $say('связанные записи');
    $leadIds = $dealIds = $contactIds = [];
    foreach (array_merge($sessions, $calls) as $s) {
        $bb = $binding($s);
        array_push($leadIds, ...($bb['lead'] ?? []));
        array_push($dealIds, ...($bb['deal'] ?? []));
        array_push($contactIds, ...($bb['contact'] ?? []));
    }
    foreach (array_merge($dealsNew, $dealsClosed) as $d) { $leadIds[] = (int)$d['LEAD_ID']; $contactIds[] = (int)$d['CONTACT_ID']; }
    foreach (array_merge($leadsNew, $leadsClosed) as $l) $contactIds[] = (int)$l['CONTACT_ID'];
    $leads = [];
    foreach (array_merge($leadsNew, $leadsClosed) as $l) $leads[(int)$l['ID']] = $l;
    $leads += b24x_by_ids('crm.lead.list', array_diff($leadIds, array_keys($leads)), $crmSel);
    $deals = [];
    foreach (array_merge($dealsNew, $dealsClosed) as $d) $deals[(int)$d['ID']] = $d;
    $deals += b24x_by_ids('crm.deal.list', array_diff($dealIds, array_keys($deals)), ['*', 'UF_*']);
    foreach ($deals as $d) { $leadIds[] = (int)$d['LEAD_ID']; $contactIds[] = (int)$d['CONTACT_ID']; }
    $leads += b24x_by_ids('crm.lead.list', array_diff($leadIds, array_keys($leads)), $crmSel);
    $contacts = [];
    foreach ($contactsNew as $c) $contacts[(int)$c['ID']] = $c;
    $contacts += b24x_by_ids('crm.contact.list', array_diff($contactIds, array_keys($contacts)), $crmSel);
    // сделки, выросшие из лидов периода (в том числе созданные позже периода)
    $fromLeads = [];
    $newLeadIds = array_map(fn($l) => (int)$l['ID'], $leadsNew);
    foreach (array_chunk($newLeadIds, 50) as $chunk) {
        foreach (b24x('crm.deal.list', ['filter' => $dealFilter + ['@LEAD_ID' => $chunk], 'select' => ['*', 'UF_*'], 'start' => -1])['result'] ?? [] as $d) {
            $deals[(int)$d['ID']] ??= $d;
            $fromLeads[(int)$d['LEAD_ID']][] = (int)$d['ID'];
        }
    }

    $users = [];
    foreach ([$leads, $deals, $contacts] as $set) foreach ($set as $r) $users[] = (int)($r['ASSIGNED_BY_ID'] ?? 0);
    foreach (array_merge($sessions, $calls) as $s) $users[] = (int)$s['RESPONSIBLE_ID'];
    $ref->needUsers($users);

    return compact('ref', 'mask', 'tag', 'from', 'to', 'leadsNew', 'leadsClosed', 'dealsNew', 'dealsClosed', 'contactsNew', 'calls', 'sessions', 'hist', 'leads', 'deals', 'contacts', 'fromLeads') + ['binding' => $binding];
}

/* ------------------------------------------------------------------ */
/*  Диалоги                                                            */
/* ------------------------------------------------------------------ */

/** Строка связи коммуникации открытой линии: imol|коннектор|линия|… */
function b24x_imol(array $act): array
{
    foreach ((array)($act['COMMUNICATIONS'] ?? []) as $c) {
        $v = (string)($c['VALUE'] ?? '');
        if (str_starts_with($v, 'imol|')) {
            $p = explode('|', $v);
            return ['connector' => $p[1] ?? '', 'line' => (int)($p[2] ?? 0)];
        }
    }
    return ['connector' => '', 'line' => 0];
}

/** Тип автора реплики по данным пользователя из истории. */
function b24x_author_type(int $authorId, array $u): string
{
    if ($authorId === 0) return 'system';
    $ext = (string)($u['external_auth_id'] ?? '');
    if (!empty($u['connector']) || $ext === 'imconnector') return 'client';
    if (!empty($u['bot']) || $ext === 'bot') return 'bot';
    return 'manager';
}

/** Текст реплики Битрикса: [b], [br], [url=…], [user=…] и т. п. → простой текст. */
function b24x_clean_text(string $t): string
{
    $t = str_ireplace(['[br]', '<br>', '<br/>', '<br />'], "\n", $t);
    $t = preg_replace('/\[url=([^\]]+)\](.*?)\[\/url\]/isu', '$2 ($1)', $t);
    $t = preg_replace('/\[(user|chat|call|send|put|context)=[^\]]*\](.*?)\[\/\1\]/isu', '$2', $t);
    $t = preg_replace('/\[\/?(b|i|u|s|quote|code|color[^\]]*|size[^\]]*|icon[^\]]*|attach[^\]]*)\]/iu', '', $t);
    $t = preg_replace('/-{20,}/', '———', $t);
    return trim(html_entity_decode((string)$t, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

function b24x_crm_brief(array $data, string $type, int $id): ?array
{
    $ref = $data['ref'];
    $mask = $data['mask'];
    switch ($type) {
        case 'lead':
            $l = $data['leads'][$id] ?? null;
            if (!$l) return ['id' => $id];
            return ['id' => $id, 'title' => $mask ? b24x_mask_text((string)$l['TITLE']) : (string)$l['TITLE'], 'status' => $ref->st('STATUS', $l['STATUS_ID']), 'semantic' => (string)($l['STATUS_SEMANTIC_ID'] ?? ''),
                'source' => $ref->st('SOURCE', $l['SOURCE_ID']), 'created' => b24x_dt($l['DATE_CREATE']), 'closed' => b24x_dt($l['DATE_CLOSED'] ?? ''),
                'return_customer' => ($l['IS_RETURN_CUSTOMER'] ?? '') === 'Y', 'assigned' => $ref->user($l['ASSIGNED_BY_ID']), 'deals' => $data['fromLeads'][$id] ?? []];
        case 'deal':
            $d = $data['deals'][$id] ?? null;
            if (!$d) return ['id' => $id];
            $cat = (int)$d['CATEGORY_ID'];
            return ['id' => $id, 'title' => $mask ? b24x_mask_text((string)$d['TITLE']) : (string)$d['TITLE'], 'pipeline' => $ref->categories[$cat] ?? (string)$cat, 'stage' => $ref->stage($cat, $d['STAGE_ID']),
                'semantic' => $ref->stageSemantic($cat, $d['STAGE_ID']), 'created' => b24x_dt($d['DATE_CREATE']), 'closed' => ($d['CLOSED'] ?? '') === 'Y' ? b24x_dt($d['CLOSEDATE']) : '',
                'opportunity' => (float)($d['OPPORTUNITY'] ?? 0), 'lead_id' => (int)$d['LEAD_ID'], 'return_customer' => ($d['IS_RETURN_CUSTOMER'] ?? '') === 'Y',
                'repeated_approach' => ($d['IS_REPEATED_APPROACH'] ?? '') === 'Y', 'assigned' => $ref->user($d['ASSIGNED_BY_ID'])];
        case 'contact':
            $c = $data['contacts'][$id] ?? null;
            if (!$c) return ['id' => $id];
            return ['id' => $id, 'name' => b24x_person($c, $mask), 'created' => b24x_dt($c['DATE_CREATE']), 'source' => $ref->st('SOURCE', $c['SOURCE_ID'] ?? ''),
                'phone' => b24x_multi($c['PHONE'] ?? [], 'PHONE', $mask), 'email' => b24x_multi($c['EMAIL'] ?? [], 'EMAIL', $mask)];
        default:
            return ['id' => $id];
    }
}

/** Диалоги в виде записей (одна сессия открытой линии — одна запись). */
function b24x_dialog_records(array $data): array
{
    $ref = $data['ref'];
    $mask = $data['mask'];
    $out = [];
    foreach ($data['sessions'] as $s) {
        $aid = (int)$s['ID'];
        $h = $data['hist'][$aid] ?? [];
        $im = b24x_imol($s);
        $bb = ($data['binding'])($s);
        $rec = [
            'session_id' => (int)$s['ASSOCIATED_ENTITY_ID'], 'chat_id' => (int)($h['chatId'] ?? $h['CHAT_ID'] ?? 0), 'activity_id' => $aid,
            'line_id' => $im['line'], 'line' => $ref->lines[$im['line']] ?? '', 'connector' => $im['connector'],
            'started' => b24x_dt($s['START_TIME'] ?: $s['CREATED']), 'ended' => ($s['COMPLETED'] ?? '') === 'Y' ? b24x_dt($s['END_TIME']) : '',
            'closed' => ($s['COMPLETED'] ?? '') === 'Y', 'responsible' => $ref->user($s['RESPONSIBLE_ID']),
            'subject' => $mask ? b24x_mask_text((string)$s['SUBJECT']) : (string)$s['SUBJECT'],
            'lead' => null, 'deal' => null, 'contact' => null, 'company_id' => (int)($bb['company'][0] ?? 0),
            'all_links' => $bb, 'messages' => [], 'error' => isset($h['_error']) ? $h['_error'] : null,
        ];
        foreach (['lead', 'deal', 'contact'] as $t) {
            $ids = $bb[$t] ?? [];
            if ($ids) $rec[$t] = b24x_crm_brief($data, $t, (int)max($ids));
        }
        $users = (array)($h['users'] ?? []);
        $msgs = (array)($h['message'] ?? $h['messages'] ?? []);
        uasort($msgs, fn($x, $y) => (int)$x['id'] <=> (int)$y['id']);
        foreach ($msgs as $m) {
            $author = (int)($m['author_id'] ?? $m['senderId'] ?? 0);
            $u = (array)($users[$author] ?? []);
            $type = b24x_author_type($author, $u);
            $name = (string)($u['name'] ?? '');
            if ($type === 'client' && $mask) $name = trim(($u['first_name'] ?? '') . ' ' . b24x_mask_surname((string)($u['last_name'] ?? ''))) ?: 'Клиент';
            $text = b24x_clean_text((string)($m['text'] ?? ''));
            $files = [];
            foreach ((array)($m['params']['FILE_ID'] ?? []) as $fid) {
                $f = $h['files'][$fid] ?? null;
                $files[] = $f ? ((string)($f['name'] ?? 'файл') . ' (' . ($f['type'] ?? '') . ')') : 'файл #' . $fid;
            }
            if ($files) $text = trim($text . ' [вложение: ' . implode('; ', $files) . ']');
            if ($text === '' && !$files) continue;
            $rec['messages'][] = ['id' => (int)$m['id'], 'at' => b24x_dt($m['date'] ?? ''), 'author_type' => $type,
                'author' => $type === 'system' ? 'Система' : ($name ?: '#' . $author), 'text' => $mask ? b24x_mask_text($text) : $text];
        }
        $out[] = $rec;
    }
    return $out;
}

const B24X_AUTHOR = ['client' => 'Клиент', 'manager' => 'Менеджер', 'bot' => 'Бот', 'system' => 'Система'];

function b24x_dialog_txt(array $r): string
{
    $L = [];
    $L[] = 'Сессия ' . $r['session_id'] . ' · ' . ($r['line'] ?: 'линия ' . $r['line_id']) . ($r['connector'] ? ' · ' . $r['connector'] : '') . ' · ' . $r['started'] . ($r['ended'] ? ' — ' . $r['ended'] : ' (не закрыта)');
    $L[] = 'Ответственный: ' . ($r['responsible'] ?: '—');
    if ($r['contact']) $L[] = 'Контакт #' . $r['contact']['id'] . (isset($r['contact']['name']) ? ': ' . $r['contact']['name'] . ' (создан ' . $r['contact']['created'] . ')' : '');
    if ($r['lead']) $L[] = 'Лид #' . $r['lead']['id'] . (isset($r['lead']['status']) ? ': ' . $r['lead']['status'] . ', создан ' . $r['lead']['created'] . ($r['lead']['return_customer'] ? ', повторный' : '') . ($r['lead']['deals'] ? ', сделки из лида: #' . implode(', #', $r['lead']['deals']) : '') : '');
    if ($r['deal']) $L[] = 'Сделка #' . $r['deal']['id'] . (isset($r['deal']['stage']) ? ': ' . $r['deal']['pipeline'] . ' / ' . $r['deal']['stage'] . ', создана ' . $r['deal']['created'] : '');
    if ($r['error']) $L[] = 'Переписка не получена: ' . $r['error'];
    $L[] = str_repeat('-', 60);
    foreach ($r['messages'] as $m) $L[] = '[' . $m['at'] . '] ' . (B24X_AUTHOR[$m['author_type']] ?? '') . ($m['author_type'] === 'system' ? '' : ' (' . $m['author'] . ')') . ': ' . $m['text'];
    return implode("\n", $L) . "\n";
}

/** Архив диалогов. Возвращает путь к временному zip. */
function b24x_dialogs_zip(array $data): string
{
    $recs = b24x_dialog_records($data);
    $path = tempnam(sys_get_temp_dir(), 'b24d');
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Не удалось создать архив.');
    $jsonl = '';
    $txt = '';
    $csv = "\xEF\xBB\xBF" . csv_line(['Сессия', 'Линия', 'Канал', 'Лид', 'Сделка', 'Контакт', 'Время', 'Кто', 'Автор', 'Текст']);
    foreach ($recs as $r) {
        $jsonl .= json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        $txt .= b24x_dialog_txt($r) . "\n" . str_repeat('=', 60) . "\n\n";
        foreach ($r['messages'] as $m) {
            $csv .= csv_line([$r['session_id'], $r['line'] ?: $r['line_id'], $r['connector'], $r['lead']['id'] ?? '', $r['deal']['id'] ?? '', $r['contact']['id'] ?? '',
                $m['at'], B24X_AUTHOR[$m['author_type']] ?? $m['author_type'], $m['author'], $m['text']]);
        }
    }
    $zip->addFromString('dialogs.jsonl', $jsonl);
    $zip->addFromString('dialogs.txt', $txt);
    $zip->addFromString('messages.csv', $csv);
    $zip->addFromString('README.txt', b24x_readme($data, count($recs)));
    $zip->close();
    return $path;
}

function b24x_readme(array $data, int $dialogs): string
{
    $L = ['Выгрузка Битрикс24 ' . b24_portal_x() . ' за ' . $data['from'] . ' — ' . $data['to'] . ' (время: ' . tz_label() . ')',
        'Собрано: ' . (new DateTimeImmutable('now', app_tz()))->format('d.m.Y H:i') . ' · диалогов: ' . $dialogs . ' · персональные данные ' . ($data['mask'] ? 'замаскированы' : 'НЕ замаскированы'), '',
        'dialogs.jsonl — по строке JSON на сессию открытой линии: линия, канал, ответственный, лид/сделка/контакт (последние привязанные; все — в all_links), реплики с временем и автором.',
        'author_type: client — клиент, manager — сотрудник, bot — бот, system — служебные сообщения Битрикса.',
        'dialogs.txt — то же для чтения глазами. messages.csv — все реплики таблицей (Excel).',
        'Диалоги берутся из дел CRM открытых линий: сессии линий без связи с CRM сюда не попадают.'];
    if ($data['ref']->warnings) { $L[] = ''; $L[] = 'Предупреждения:'; foreach ($data['ref']->warnings as $w) $L[] = '- ' . $w; }
    return implode("\n", $L) . "\n";
}

function b24_portal_x(): string
{
    $u = parse_url(b24x_webhook());
    return (string)($u['host'] ?? '');
}

/* ------------------------------------------------------------------ */
/*  Таблица CRM                                                        */
/* ------------------------------------------------------------------ */

function b24x_crm_xlsx(array $data): string
{
    $ref = $data['ref'];
    $mask = $data['mask'];
    $yn = fn($v) => ($v ?? '') === 'Y' ? 'да' : '';
    $sessionsBy = [];                     // lead|deal|contact => id => [session ids]
    foreach ($data['sessions'] as $s) foreach (($data['binding'])($s) as $t => $ids) foreach ($ids as $id) $sessionsBy[$t][$id][] = (int)$s['ASSOCIATED_ENTITY_ID'];
    $callsBy = [];
    foreach ($data['calls'] as $c) foreach (($data['binding'])($c) as $t => $ids) foreach ($ids as $id) $callsBy[$t][$id] = ($callsBy[$t][$id] ?? 0) + 1;
    $contactCreated = fn($id) => $id && isset($data['contacts'][(int)$id]) ? b24x_dt($data['contacts'][(int)$id]['DATE_CREATE']) : '';

    $leadSheet = function (array $rows) use ($ref, $mask, $yn, $sessionsBy, $callsBy, $contactCreated, $data): array {
        $uf = b24x_uf_columns($ref, 'lead', $rows);
        $head = ['ID', 'Создан', 'Закрыт', 'Название', 'Статус', 'Итог', 'Источник', 'Описание источника', 'Повторный', 'Ответственный', 'Создал',
            'Имя', 'Телефон', 'Почта', 'Мессенджеры', 'Сумма', 'Контакт ID', 'Контакт создан', 'Компания ID', 'Сделки из лида', 'Сессии ОЛ', 'Звонков',
            'UTM source', 'UTM medium', 'UTM campaign', 'Комментарий'];
        foreach ($uf as $code) $head[] = $ref->fields['lead'][$code]['label'] . ' (' . $code . ')';
        $out = [$head];
        foreach ($rows as $l) {
            $id = (int)$l['ID'];
            $sem = (string)($l['STATUS_SEMANTIC_ID'] ?? '');
            $comments = b24x_clean_text((string)($l['COMMENTS'] ?? ''));
            $row = [$id, b24x_dt($l['DATE_CREATE']), b24x_dt($l['DATE_CLOSED'] ?? ''), $mask ? b24x_mask_text((string)$l['TITLE']) : (string)$l['TITLE'],
                $ref->st('STATUS', $l['STATUS_ID']), $sem === 'S' ? 'успех' : ($sem === 'F' ? 'провал' : 'в работе'), $ref->st('SOURCE', $l['SOURCE_ID']),
                $mask ? b24x_mask_text((string)($l['SOURCE_DESCRIPTION'] ?? '')) : (string)($l['SOURCE_DESCRIPTION'] ?? ''), $yn($l['IS_RETURN_CUSTOMER'] ?? ''),
                $ref->user($l['ASSIGNED_BY_ID']), $ref->user($l['CREATED_BY_ID'] ?? 0), b24x_person($l, $mask),
                b24x_multi($l['PHONE'] ?? [], 'PHONE', $mask), b24x_multi($l['EMAIL'] ?? [], 'EMAIL', $mask), b24x_multi($l['IM'] ?? [], 'IM', $mask),
                (float)($l['OPPORTUNITY'] ?? 0), (int)$l['CONTACT_ID'] ?: '', $contactCreated($l['CONTACT_ID']), (int)($l['COMPANY_ID'] ?? 0) ?: '',
                implode(', ', $data['fromLeads'][$id] ?? []), implode(', ', $sessionsBy['lead'][$id] ?? []), $callsBy['lead'][$id] ?? '',
                (string)($l['UTM_SOURCE'] ?? ''), (string)($l['UTM_MEDIUM'] ?? ''), (string)($l['UTM_CAMPAIGN'] ?? ''), $mask ? b24x_mask_text($comments) : $comments];
            foreach ($uf as $code) $row[] = b24x_uf_value($ref->fields['lead'][$code], $l[$code] ?? '', $mask);
            $out[] = $row;
        }
        return $out;
    };

    $dealSheet = function (array $rows) use ($ref, $mask, $yn, $sessionsBy, $callsBy, $contactCreated, $data): array {
        $uf = b24x_uf_columns($ref, 'deal', $rows);
        $head = ['ID', 'Создана', 'Закрыта', 'Название', 'Воронка', 'Стадия', 'Итог', 'Сумма', 'Валюта', 'Источник', 'Повторная', 'Повторное обращение',
            'Ответственный', 'Лид ID', 'Лид создан', 'Лид из другого периода', 'Контакт ID', 'Контакт создан', 'Компания ID', 'Сессии ОЛ', 'Звонков', 'Комментарий'];
        foreach ($uf as $code) $head[] = $ref->fields['deal'][$code]['label'] . ' (' . $code . ')';
        $out = [$head];
        foreach ($rows as $d) {
            $id = (int)$d['ID'];
            $cat = (int)$d['CATEGORY_ID'];
            $sem = $ref->stageSemantic($cat, $d['STAGE_ID']);
            $lead = $data['leads'][(int)$d['LEAD_ID']] ?? null;
            $leadCreated = $lead ? b24x_dt($lead['DATE_CREATE']) : '';
            $other = $leadCreated === '' ? '' : (substr($leadCreated, 0, 10) < $data['from'] || substr($leadCreated, 0, 10) > $data['to'] ? 'да' : 'нет');
            $comments = b24x_clean_text((string)($d['COMMENTS'] ?? ''));
            $row = [$id, b24x_dt($d['DATE_CREATE']), ($d['CLOSED'] ?? '') === 'Y' ? b24x_dt($d['CLOSEDATE']) : '', $mask ? b24x_mask_text((string)$d['TITLE']) : (string)$d['TITLE'],
                $ref->categories[$cat] ?? (string)$cat, $ref->stage($cat, $d['STAGE_ID']), $sem === 'S' ? 'успех' : ($sem === 'F' ? 'провал' : 'в работе'),
                (float)($d['OPPORTUNITY'] ?? 0), (string)($d['CURRENCY_ID'] ?? ''), $ref->st('SOURCE', $d['SOURCE_ID'] ?? ''), $yn($d['IS_RETURN_CUSTOMER'] ?? ''),
                $yn($d['IS_REPEATED_APPROACH'] ?? ''), $ref->user($d['ASSIGNED_BY_ID']), (int)$d['LEAD_ID'] ?: '', $leadCreated, $other,
                (int)$d['CONTACT_ID'] ?: '', $contactCreated($d['CONTACT_ID']), (int)($d['COMPANY_ID'] ?? 0) ?: '',
                implode(', ', $sessionsBy['deal'][$id] ?? []), $callsBy['deal'][$id] ?? '', $mask ? b24x_mask_text($comments) : $comments];
            foreach ($uf as $code) $row[] = b24x_uf_value($ref->fields['deal'][$code], $d[$code] ?? '', $mask);
            $out[] = $row;
        }
        return $out;
    };

    $contactSheet = function (array $rows) use ($ref, $mask, $sessionsBy, $callsBy): array {
        $uf = b24x_uf_columns($ref, 'contact', $rows);
        $head = ['ID', 'Создан', 'Имя', 'Телефон', 'Почта', 'Мессенджеры', 'Источник', 'Ответственный', 'Лид ID', 'Компания ID', 'Сессии ОЛ', 'Звонков'];
        foreach ($uf as $code) $head[] = $ref->fields['contact'][$code]['label'] . ' (' . $code . ')';
        $out = [$head];
        foreach ($rows as $c) {
            $id = (int)$c['ID'];
            $row = [$id, b24x_dt($c['DATE_CREATE']), b24x_person($c, $mask), b24x_multi($c['PHONE'] ?? [], 'PHONE', $mask), b24x_multi($c['EMAIL'] ?? [], 'EMAIL', $mask),
                b24x_multi($c['IM'] ?? [], 'IM', $mask), $ref->st('SOURCE', $c['SOURCE_ID'] ?? ''), $ref->user($c['ASSIGNED_BY_ID']), (int)($c['LEAD_ID'] ?? 0) ?: '',
                (int)($c['COMPANY_ID'] ?? 0) ?: '', implode(', ', $sessionsBy['contact'][$id] ?? []), $callsBy['contact'][$id] ?? ''];
            foreach ($uf as $code) $row[] = b24x_uf_value($ref->fields['contact'][$code], $c[$code] ?? '', $mask);
            $out[] = $row;
        }
        return $out;
    };

    $calls = [['ID дела', 'Время', 'Направление', 'Завершён', 'Тема', 'Номер', 'Ответственный', 'Лид ID', 'Сделка ID', 'Контакт ID', 'Лид создан']];
    foreach ($data['calls'] as $c) {
        $bb = ($data['binding'])($c);
        $num = '';
        foreach ((array)($c['COMMUNICATIONS'] ?? []) as $cm) if (($cm['TYPE'] ?? '') === 'PHONE') $num = $mask ? b24x_mask_phone((string)$cm['VALUE']) : (string)$cm['VALUE'];
        $leadId = (int)($bb['lead'][0] ?? 0);
        $calls[] = [(int)$c['ID'], b24x_dt($c['START_TIME'] ?: $c['CREATED']), (int)$c['DIRECTION'] === 1 ? 'входящий' : ((int)$c['DIRECTION'] === 2 ? 'исходящий' : ''),
            $yn($c['COMPLETED'] ?? ''), $mask ? b24x_mask_text((string)$c['SUBJECT']) : (string)$c['SUBJECT'], $num, $ref->user($c['RESPONSIBLE_ID']),
            $leadId ?: '', implode(', ', $bb['deal'] ?? []), implode(', ', $bb['contact'] ?? []), $leadId && isset($data['leads'][$leadId]) ? b24x_dt($data['leads'][$leadId]['DATE_CREATE']) : ''];
    }

    $dlg = [['Сессия', 'Начата', 'Закрыта', 'Линия', 'Канал', 'Ответственный', 'Реплик клиента', 'Реплик сотрудников', 'Реплик бота', 'Первая реплика клиента', 'Первый ответ (не клиент, не система)',
        'Лид ID', 'Лид создан', 'Лид повторный', 'Лид статус', 'Сделка ID', 'Сделка стадия', 'Контакт ID', 'Контакт создан']];
    foreach (b24x_dialog_records($data) as $r) {
        $cnt = ['client' => 0, 'manager' => 0, 'bot' => 0];
        $firstClient = $firstReply = '';
        foreach ($r['messages'] as $m) {
            if (isset($cnt[$m['author_type']])) $cnt[$m['author_type']]++;
            if ($m['author_type'] === 'client' && $firstClient === '') $firstClient = $m['at'];
            if (in_array($m['author_type'], ['manager', 'bot'], true) && $firstClient !== '' && $firstReply === '') $firstReply = $m['at'];
        }
        $dlg[] = [$r['session_id'], $r['started'], $r['ended'], $r['line'] ?: $r['line_id'], $r['connector'], $r['responsible'], $cnt['client'], $cnt['manager'], $cnt['bot'],
            $firstClient, $firstReply, $r['lead']['id'] ?? '', $r['lead']['created'] ?? '', !empty($r['lead']['return_customer']) ? 'да' : '', $r['lead']['status'] ?? '',
            $r['deal']['id'] ?? '', isset($r['deal']['stage']) ? $r['deal']['pipeline'] . ' / ' . $r['deal']['stage'] : '', $r['contact']['id'] ?? '', $r['contact']['created'] ?? ''];
    }

    $info = [['Параметр', 'Значение'],
        ['Портал', b24_portal_x()], ['Период', $data['from'] . ' — ' . $data['to']], ['Часовой пояс', tz_label()], ['Собрано', (new DateTimeImmutable('now', app_tz()))->format('d.m.Y H:i')],
        ['Персональные данные', $mask ? 'замаскированы' : 'НЕ замаскированы'],
        ['Лиды созданные', count($data['leadsNew'])], ['Лиды закрытые (успех и провал)', count($data['leadsClosed'])],
        ['Сделки созданные', count($data['dealsNew'])], ['Сделки закрытые', count($data['dealsClosed'])],
        ['Сделки из лидов периода (вкл. созданные позже)', count(array_merge(...array_values($data['fromLeads'] ?: [[]])))],
        ['Контакты созданные', count($data['contactsNew'])], ['Звонки', count($data['calls'])], ['Диалоги открытых линий', count($data['sessions'])],
        ['', ''], ['Как считалось', 'Лиды закрытые — по дате закрытия (DATE_CLOSED). Сделки закрытые — CLOSED = да и дата закрытия в периоде. «Лид из другого периода» — лид сделки создан вне периода. Даты — в часовом поясе админки.']];
    foreach ($ref->warnings as $w) $info[] = ['Предупреждение', $w];

    // сделки, выросшие из лидов периода, — отдельно (часть создана после периода)
    $fl = [];
    foreach ($data['fromLeads'] as $ids) foreach ($ids as $id) if (isset($data['deals'][$id])) $fl[$id] = $data['deals'][$id];
    ksort($fl);

    $sheets = ['Сводка' => $info, 'Лиды созданные' => $leadSheet($data['leadsNew']), 'Лиды закрытые' => $leadSheet($data['leadsClosed']),
        'Сделки созданные' => $dealSheet($data['dealsNew']), 'Сделки закрытые' => $dealSheet($data['dealsClosed']), 'Сделки из лидов периода' => $dealSheet(array_values($fl)),
        'Контакты созданные' => $contactSheet($data['contactsNew']), 'Звонки' => $calls, 'Диалоги' => $dlg];
    $path = tempnam(sys_get_temp_dir(), 'b24x');
    xlsx_write($path, $sheets);
    return $path;
}

/* ------------------------------------------------------------------ */
/*  Проверка доступа                                                   */
/* ------------------------------------------------------------------ */

/** Права вебхука и пробные запросы: что получится выгрузить. */
function b24x_check(): array
{
    $out = [];
    try {
        $scope = (array)(b24x('scope', [])['result'] ?? []);
        $out[] = ['Права вебхука', implode(', ', $scope), true];
        foreach (['crm' => 'лиды, сделки, контакты, дела', 'imopenlines' => 'переписка открытых линий', 'user' => 'имена сотрудников'] as $s => $what) {
            $out[] = ['Право ' . $s, in_array($s, $scope, true) ? 'есть — ' . $what : 'НЕТ — ' . $what . ' не выгрузятся', in_array($s, $scope, true)];
        }
    } catch (Throwable $e) { return [['Вебхук', $e->getMessage(), false]]; }
    try {
        $a = b24x('crm.activity.list', ['filter' => ['PROVIDER_ID' => 'IMOPENLINES_SESSION'], 'select' => ['ID', 'CREATED', 'ASSOCIATED_ENTITY_ID', 'COMMUNICATIONS'], 'order' => ['ID' => 'DESC'], 'start' => -1])['result'][0] ?? null;
        $out[] = ['Последний диалог ОЛ в CRM', $a ? 'дело #' . $a['ID'] . ', сессия ' . $a['ASSOCIATED_ENTITY_ID'] . ', ' . b24x_dt($a['CREATED']) . ', ' . json_encode(b24x_imol($a), JSON_UNESCAPED_UNICODE) : 'не найдено', (bool)$a];
        if ($a) {
            try {
                $h = b24x('imopenlines.session.history.get', ['SESSION_ID' => (int)$a['ASSOCIATED_ENTITY_ID']])['result'] ?? [];
                $msgs = (array)($h['message'] ?? []);
                $types = [];
                foreach ($msgs as $m) { $id = (int)($m['author_id'] ?? 0); $t = b24x_author_type($id, (array)($h['users'][$id] ?? [])); $types[$t] = ($types[$t] ?? 0) + 1; }
                $out[] = ['Переписка этой сессии', count($msgs) . ' сообщ.: ' . json_encode($types, JSON_UNESCAPED_UNICODE) . ' · ключи ответа: ' . implode(', ', array_keys($h)), (bool)$msgs];
            } catch (Throwable $e) { $out[] = ['Переписка этой сессии', $e->getMessage(), false]; }
        }
    } catch (Throwable $e) { $out[] = ['Дела открытых линий', $e->getMessage(), false]; }
    try {
        $n = b24x('crm.activity.list', ['filter' => ['PROVIDER_ID' => 'VOXIMPLANT_CALL'], 'select' => ['ID'], 'order' => ['ID' => 'DESC'], 'start' => -1])['result'] ?? [];
        $out[] = ['Звонки в CRM', $n ? 'есть (последнее дело #' . $n[0]['ID'] . ')' : 'не найдено', true];
    } catch (Throwable $e) { $out[] = ['Звонки', $e->getMessage(), false]; }
    try {
        $l = b24x('imopenlines.config.list.get', ['PARAMS' => ['select' => ['ID', 'LINE_NAME']]])['result'] ?? [];
        $out[] = ['Открытые линии', implode('; ', array_map(fn($x) => $x['ID'] . ': ' . $x['LINE_NAME'], (array)$l)), true];
    } catch (Throwable $e) { $out[] = ['Открытые линии', $e->getMessage(), false]; }
    return $out;
}
