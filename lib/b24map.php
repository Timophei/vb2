<?php
declare(strict_types=1);

/**
 * Сопоставление полей бота с СУЩЕСТВУЮЩИМИ полями лида в Битрикс24.
 *
 * Поля лида загружаются с портала (crm.lead.fields) и кешируются в settings.b24_lead_fields.
 * Сущности: lead, deal, contact — у каждой своя карта (settings.b24_map_<entity>) и свой кеш полей.
 * Сопоставление: {"visa.country": "UF_CRM_1700000001", "gc.tariff": "", …}
 *   код поля — писать туда; "" — в комментарий лида (блок бота); "-" — не передавать.
 * Карта из админки важнее, чем b24_field в сценарии.
 *
 * Кроме слотов есть служебные поля бота (@product, @state, @dialog_url, @channel).
 */

/** Что знает бот: поле → [подпись, группа]. Подписи для админки и для комментария в лиде. */
const BOT_FIELDS = [
    // клиент
    'client_name'      => ['Имя клиента', 'Клиент'],
    'phone'            => ['Телефон', 'Клиент'],
    'client.fio'       => ['ФИО клиента', 'Клиент'],
    'client.email'     => ['E-mail', 'Клиент'],
    'client.messenger' => ['Мессенджер (ник или номер)', 'Клиент'],
    'contact'          => ['Контакт для связи (телефон / e-mail / мессенджер)', 'Клиент'],
    '@channel'         => ['Канал (сайт / Telegram)', 'Клиент'],
    // общее
    '@product'         => ['Продукт / направление обращения', 'Обращение'],
    '@state'           => ['Этап бота', 'Обращение'],
    '@dialog_url'      => ['Ссылка на диалог в админке бота', 'Обращение'],
    'handoff_reason'   => ['Причина передачи менеджеру', 'Обращение'],
    'side_interests'   => ['Интересовался также', 'Обращение'],
    'followup.at'      => ['Когда связаться (клиент отложил)', 'Обращение'],
    // визы
    'visa.country'          => ['Виза: страна', 'Визы'],
    'visa.submission_city'  => ['Виза: город подачи', 'Визы'],
    'visa.citizenship'      => ['Виза: гражданство', 'Визы'],
    'visa.purpose'          => ['Виза: цель поездки', 'Визы'],
    'visa.travel_dates'     => ['Виза: планируемые даты', 'Визы'],
    'visa.applicants_count' => ['Виза: количество заявителей', 'Визы'],
    'visa.prior_visas'      => ['Виза: предыдущие визы / отказы', 'Визы'],
    'visa.circumstances'    => ['Виза: доп. обстоятельства', 'Визы'],
    // карты
    'cards.purpose'         => ['Карта: для чего', 'Карты'],
    'cards.product_id'      => ['Карта: выбранная карта', 'Карты'],
    'cards.offer_accepted'  => ['Карта: согласие на оформление', 'Карты'],
    // Green Card
    'gc.request_type'       => ['Green Card: тип обращения', 'Green Card'],
    'gc.tariff'             => ['Green Card: тариф', 'Green Card'],
    'gc.participants'       => ['Green Card: участников', 'Green Card'],
    'gc.has_foreign_card'   => ['Green Card: есть своя зарубежная карта', 'Green Card'],
    'gc.form_link_sent'     => ['Green Card: отправлена ссылка на анкету', 'Green Card'],
    'gc.terms_accepted'     => ['Green Card: согласие с условиями', 'Green Card'],
    // оплата
    'payment.invoice_no'    => ['Номер счёта', 'Оплата'],
    'payment.amount'        => ['Сумма счёта', 'Оплата'],
    'payment.status'        => ['Статус оплаты', 'Оплата'],
    // вакансии
    'jobs.position'         => ['Вакансия (со слов соискателя)', 'Вакансии'],
    'jobs.full_name'        => ['ФИО соискателя', 'Вакансии'],
    'jobs.resume'           => ['Резюме', 'Вакансии'],
    'jobs.no_resume'        => ['Резюме нет', 'Вакансии'],
    // действующая услуга
    'existing.service'      => ['Действующая услуга', 'Действующая услуга'],
    'existing.manager_name' => ['Менеджер клиента (со слов)', 'Действующая услуга'],
    'existing.question'     => ['Вопрос по услуге', 'Действующая услуга'],
];

/** Поля, которые пишутся всегда одинаково (не выбираются). */
const BOT_FIELDS_FIXED = [
    'client.fio'     => 'LAST_NAME, NAME, SECOND_NAME (лид, контакт)',
    'client.email'   => 'EMAIL',
    'client.messenger' => 'IM (мессенджер)',
    'contact'        => 'PHONE / EMAIL / IM — по типу контакта',
    'jobs.full_name' => 'LAST_NAME, NAME, SECOND_NAME',
    'jobs.resume'    => 'файл или ссылка — в ленту (таймлайн) лида',
];

/** Сопоставление по умолчанию (если в админке не выбрано). */
const BOT_FIELDS_DEFAULT = [
    'client_name' => 'NAME',
    'phone' => 'PHONE',
    'payment.amount' => 'OPPORTUNITY',
];

/** Подсказки для автоподбора: слова в названии поля Битрикса. */
const BOT_FIELDS_HINTS = [
    'visa.country@deal' => ['стран'], 'visa.submission_city@deal' => ['город'], '@product@deal' => ['услуг', 'продукт'],
    'visa.country' => ['стран', 'направлен'], 'visa.submission_city' => ['город подач', 'город'], 'visa.citizenship' => ['гражданств'],
    'visa.purpose' => ['цель', 'тип виз'], 'visa.travel_dates' => ['дат', 'поездк'], 'visa.applicants_count' => ['заявител', 'количество', 'человек'],
    'visa.prior_visas' => ['предыдущ', 'отказ', 'истори'], 'visa.circumstances' => ['обстоятельств', 'особенност'],
    'gc.tariff' => ['тариф'], 'gc.participants' => ['участник'], 'cards.product_id' => ['карт'], 'payment.invoice_no' => ['номер сч', 'счёт', 'счет'],
    'payment.status' => ['оплат'], '@product' => ['продукт', 'услуг', 'направлен'], '@dialog_url' => ['ссылк', 'диалог', 'чат'],
    'jobs.position' => ['ваканс', 'должност'], 'followup.at' => ['перезвон', 'связаться', 'дата контакт'],
];

/** Служебные поля лида, которые не предлагаем для записи. */
const B24_SKIP_FIELDS = ['ID', 'STATUS_ID', 'STATUS_SEMANTIC_ID', 'CURRENCY_ID', 'ASSIGNED_BY_ID', 'CREATED_BY_ID', 'MODIFY_BY_ID', 'DATE_CREATE', 'DATE_MODIFY',
    'DATE_CLOSED', 'COMPANY_ID', 'CONTACT_ID', 'CONTACT_IDS', 'IS_RETURN_CUSTOMER', 'IS_MANUAL_OPPORTUNITY', 'ORIGINATOR_ID', 'ORIGIN_ID', 'MOVED_BY_ID', 'MOVED_TIME',
    'LAST_ACTIVITY_TIME', 'LAST_ACTIVITY_BY', 'OPENED', 'HAS_PHONE', 'HAS_EMAIL', 'HAS_IMOL', 'WEB', 'LINK', 'ADDRESS_LOC_ADDR_ID', 'UTM_SOURCE', 'UTM_MEDIUM',
    'UTM_CAMPAIGN', 'UTM_CONTENT', 'UTM_TERM', 'LAST_COMMUNICATION_TIME', 'COMMENTS', 'CATEGORY_ID', 'STAGE_ID', 'STAGE_SEMANTIC_ID', 'IS_NEW', 'IS_RECURRING',
    'CLOSED', 'TYPE_ID', 'LEAD_ID', 'QUOTE_ID', 'LOCATION_ID', 'EXPORT', 'PHOTO', 'FACE_ID', 'POST', 'IS_REPEATED_APPROACH'];

/** Поля, которые бот заполняет сам при создании (для проверки обязательных). */
const B24_SYSTEM_FILLED = [
    'lead' => ['TITLE', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'PHONE', 'EMAIL', 'IM', 'SOURCE_DESCRIPTION', 'COMMENTS', 'OPENED', 'STATUS_ID'],
    'deal' => ['TITLE', 'CATEGORY_ID', 'STAGE_ID', 'CONTACT_ID', 'CONTACT_IDS', 'OPPORTUNITY', 'CURRENCY_ID', 'COMMENTS', 'ASSIGNED_BY_ID', 'OPENED', 'SOURCE_DESCRIPTION'],
    'contact' => ['NAME', 'LAST_NAME', 'SECOND_NAME', 'PHONE', 'EMAIL', 'IM', 'SOURCE_DESCRIPTION', 'OPENED', 'ASSIGNED_BY_ID'],
];
const B24_ENTITIES = ['lead' => 'Лид', 'deal' => 'Сделка', 'contact' => 'Контакт'];

/** Какие поля бота показывать в таблице сопоставления сущности. */
function bot_fields_for(string $entity): array
{
    if ($entity === 'contact') return array_filter(BOT_FIELDS, fn($f, $k) => $f[1] === 'Клиент' || $k === '@dialog_url', ARRAY_FILTER_USE_BOTH);
    if ($entity === 'deal') return array_filter(BOT_FIELDS, fn($f, $k) => !in_array($f[1], ['Вакансии'], true), ARRAY_FILTER_USE_BOTH);
    return BOT_FIELDS;
}

function bot_field_label(string $name, ?array $sc = null): string
{
    if ($sc && !empty($sc['slots'][$name]['label'])) return (string)$sc['slots'][$name]['label'];
    return BOT_FIELDS[$name][0] ?? $name;
}

/** Поля сущности с портала (кеш). $refresh — перечитать. */
function b24_fields(string $entity, bool $refresh = false): array
{
    $key = 'b24_fields_' . $entity;
    if ($entity === 'lead' && !$refresh && setting($key) === '' && setting('b24_lead_fields') !== '') return jdec(setting('b24_lead_fields')); // из прошлой версии
    if (!$refresh) return jdec(setting($key)); // с портала читаем только по кнопке в админке
    $res = b24('crm.' . $entity . '.fields', [])['result'] ?? [];
    if (!is_array($res)) $res = [];
    $out = [];
    foreach ($res as $code => $f) {
        if (!is_array($f)) continue;
        $required = !empty($f['isRequired']);
        if (!$required && (in_array($code, B24_SKIP_FIELDS, true) || !empty($f['isReadOnly']))) continue;
        $title = (string)($f['formLabel'] ?? $f['listLabel'] ?? $f['title'] ?? $code);
        if ($title === '' || $title === $code) $title = (string)($f['title'] ?? $code);
        $items = [];
        foreach ((array)($f['items'] ?? []) as $it) $items[(string)$it['ID']] = (string)$it['VALUE'];
        $out[$code] = ['title' => $title, 'type' => (string)($f['type'] ?? 'string'), 'multiple' => !empty($f['isMultiple']), 'custom' => str_starts_with($code, 'UF_'),
            'required' => $required, 'readonly' => !empty($f['isReadOnly']), 'items' => $items];
    }
    uasort($out, fn($a, $b) => [$a['custom'], $a['title']] <=> [$b['custom'], $b['title']]);
    set_setting($key, jenc($out));
    set_setting($key . '_at', now_utc());
    return $out;
}

function b24_lead_fields(bool $refresh = false): array { return b24_fields('lead', $refresh); }

/** Воронки сделок и их стадии (кеш). */
function b24_deal_pipelines(bool $refresh = false): array
{
    if (!$refresh) return jdec(setting('b24_deal_pipes'));
    $out = [];
    $cats = [['id' => 0, 'name' => 'Общая (основная)']];
    try {
        $r = b24('crm.category.list', ['entityTypeId' => 2])['result']['categories'] ?? [];
        if (is_array($r) && $r) $cats = array_map(fn($c) => ['id' => (int)$c['id'], 'name' => (string)$c['name']], $r);
    } catch (Throwable $e) {
        try { // старые коробки
            $r = b24('crm.dealcategory.list', [])['result'] ?? [];
            foreach ((array)$r as $c) $cats[] = ['id' => (int)$c['ID'], 'name' => (string)$c['NAME']];
        } catch (Throwable $e2) { /* остаётся основная */ }
    }
    foreach ($cats as $c) {
        $ent = $c['id'] ? 'DEAL_STAGE_' . $c['id'] : 'DEAL_STAGE';
        $stages = [];
        try {
            foreach ((array)(b24('crm.status.list', ['filter' => ['ENTITY_ID' => $ent], 'order' => ['SORT' => 'ASC']])['result'] ?? []) as $s) {
                $sem = (string)($s['EXTRA']['SEMANTICS'] ?? '');
                $stages[(string)$s['STATUS_ID']] = (string)$s['NAME'] . ($sem === 'S' ? ' (успех)' : ($sem === 'F' ? ' (провал)' : ''));
            }
        } catch (Throwable $e) { /* пусто */ }
        $out[(string)$c['id']] = ['name' => $c['name'], 'stages' => $stages];
    }
    set_setting('b24_deal_pipes', jenc($out));
    return $out;
}

function b24_map(string $entity = 'lead'): array
{
    $m = jdec(setting('b24_map_' . $entity));
    if (!$m && $entity === 'lead') $m = jdec(setting('b24_map')); // из прошлой версии
    return $m;
}

function b24_defaults(string $entity): array { return jdec(setting('b24_defaults_' . $entity)); }

/** Направления: сделка или лид, воронка, стадии по этапам бота, услуга, ответственный. */
function b24_flow(?string $product = null): array
{
    $all = jdec(setting('b24_flow'));
    return $product === null ? $all : ($all[$product] ?? []);
}

const FLOW_PRODUCTS = ['visa' => 'Визы', 'cards' => 'Банковские карты', 'green_card' => 'Green Card США', 'other' => 'Другие обращения', 'existing_service' => 'Действующая услуга'];

/** Куда писать поле бота: код поля, '' — в комментарий, '-' — не передавать. */
function bot_field_target(array $sc, string $name, string $entity = 'lead'): string
{
    $map = b24_map($entity);
    if (array_key_exists($name, $map)) return (string)$map[$name];
    if ($entity === 'lead') {
        $bf = $sc['slots'][$name]['b24_field'] ?? null;
        if (is_string($bf) && $bf !== '' && !is_todo($bf) && $bf !== 'COMMENTS') return $bf;
    }
    if ($entity === 'contact') return '-';
    if ($entity === 'deal' && in_array($name, ['client_name', 'phone'], true)) return '-'; // клиент — в контакт сделки
    return BOT_FIELDS_DEFAULT[$name] ?? '';
}

/** Подбор поля Битрикса по названию (для подсказки в админке). */
function bot_field_suggest(string $name, array $fields, array $taken = [], string $entity = 'lead'): string
{
    $hints = BOT_FIELDS_HINTS[$name . '@' . $entity] ?? BOT_FIELDS_HINTS[$name] ?? [];
    if (!$hints) return '';
    $best = ''; $score = 0;
    foreach ($fields as $code => $f) {
        if (in_array($code, $taken, true) || !empty($f['readonly'])) continue;
        $t = mb_strtolower($f['title']);
        $s = 0;
        foreach ($hints as $i => $h) if (str_contains($t, $h)) $s += 10 - $i;
        if ($s === 0) continue; // без совпадения по смыслу не предлагаем
        if (str_starts_with($name, 'visa.') && str_contains($t, 'виз')) $s += 3;
        if (str_starts_with($name, 'gc.') && (str_contains($t, 'green') || str_contains($t, 'грин'))) $s += 3;
        if (($f['type'] ?? '') === 'enumeration') $s += 1;
        if ($s > $score) { $score = $s; $best = $code; }
    }
    return $best;
}

/** Вариант «Другой / Другая / Другое» в списке. */
function b24_enum_other(array $meta): ?string
{
    foreach ((array)($meta['items'] ?? []) as $id => $label) if (preg_match('/^\s*друг(ой|ая|ое|ие)\b/iu', $label)) return (string)$id;
    return null;
}

/** Основа слова для сравнения: «Италию» и «Италия» → «итали». */
function word_stem(string $s): string
{
    $s = mb_strtolower(trim($s));
    $s = preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $s);
    return implode(' ', array_map(fn($w) => mb_strlen($w) > 4 ? mb_substr($w, 0, max(4, mb_strlen($w) - 2)) : $w, preg_split('/\s+/u', trim($s))));
}

/** Подбор варианта списка: точное совпадение → по основе слова → вхождение. null — нет. */
function b24_enum_match(array $meta, array $cands): ?string
{
    $items = (array)($meta['items'] ?? []);
    foreach ($cands as $c) foreach ($items as $id => $label) if (mb_strtolower(trim($label)) === mb_strtolower(trim($c))) return (string)$id;
    foreach ($cands as $c) foreach ($items as $id => $label) if (word_stem($label) !== '' && word_stem($label) === word_stem($c)) return (string)$id;
    foreach ($cands as $c) foreach ($items as $id => $label) {
        $l = mb_strtolower($label); $cc = mb_strtolower($c);
        if (mb_strlen($cc) >= 3 && mb_strlen($l) >= 3 && (str_contains($l, $cc) || str_contains($cc, $l))) return (string)$id;
    }
    return null;
}

/**
 * Значение для поля Битрикса с учётом его типа.
 * Возвращает [значение, примечание]; значение null — не подошло (тогда текст пойдёт в комментарий).
 * Списки: вариант по тексту; нет в списке — «Другой» (если есть) + исходное значение в комментарий.
 */
function b24_convert($v, array $meta): array
{
    $txt = value_text($v);
    $type = $meta['type'] ?? 'string';
    $multi = !empty($meta['multiple']);
    switch ($type) {
        case 'enumeration':
            $cands = array_values(array_unique(array_filter(array_merge([$txt], catalog_variant_names($txt)))));
            if (($id = b24_enum_match($meta, $cands)) !== null) return [$multi ? [$id] : $id, ''];
            if (($other = b24_enum_other($meta)) !== null) return [$multi ? [$other] : $other, "«{$txt}» нет в списке — выбрано «" . $meta['items'][$other] . '»'];
            return [null, "«{$txt}» нет в списке"];
        case 'boolean':
            return [($v === true || in_array(mb_strtolower($txt), ['да', 'true', '1', 'yes'], true)) ? 1 : 0, ''];
        case 'integer':
            return [preg_match('/-?\d+/', $txt, $m) ? (int)$m[0] : null, ''];
        case 'double': case 'money':
            return [is_numeric($v) ? (float)$v : (preg_match('/-?\d+([.,]\d+)?/', $txt, $m) ? (float)str_replace(',', '.', $m[0]) : null), ''];
        case 'date':
            $t = strtotime($txt);
            return [$t ? date('Y-m-d', $t) : null, ''];
        case 'datetime':
            try { return [(new DateTimeImmutable($txt, new DateTimeZone('UTC')))->setTimezone(app_tz())->format(DATE_ATOM), '']; } catch (Throwable $e) { return [null, '']; }
        case 'crm_multifield':
            return [null, ''];
        default:
            return [$multi ? [$txt] : $txt, ''];
    }
}

/** Совместимость: только значение. */
function b24_value(string $code, $v, array $meta) { return b24_convert($v, $meta)[0]; }

/** Варианты списка, к которому привязано поле бота (для классификатора — чтобы называл точное значение). */
function bot_field_options(array $sc, string $name): array
{
    foreach (['deal', 'lead'] as $ent) {
        $code = bot_field_target($sc, $name, $ent);
        if ($code === '' || $code === '-') continue;
        $meta = b24_fields($ent)[$code] ?? null;
        if ($meta && ($meta['type'] ?? '') === 'enumeration' && $meta['items']) return array_values($meta['items']);
    }
    return [];
}

/** Служебные поля бота для текущего диалога. */
function bot_virtual_values(array $T): array
{
    $intent = (string)($T['d']['slots']['intent'] ?? '');
    $names = ['visa' => 'Визы', 'cards' => 'Банковские карты', 'green_card' => 'Green Card США', 'jobs' => 'Вакансии', 'existing_service' => 'Действующая услуга', 'other' => 'Другое'];
    return array_filter([
        '@product' => $names[$intent] ?? $intent,
        '@state' => sc_state_title($T['sc'], $T['d']['state']),
        '@dialog_url' => public_url('admin/?tab=dialogs&id=' . $T['d']['id']),
        '@channel' => CHANNEL_NAMES[$T['d']['channel']] ?? $T['d']['channel'],
    ], fn($x) => $x !== '');
}

/** Названия вариантов каталога с таким кодом (gc.tariff = standard → «Стандартный»). */
function catalog_variant_names(string $code): array
{
    $out = [];
    foreach (catalog_all() as $p) foreach ($p['variants'] ?? [] as $v) if (($v['id'] ?? '') === $code && !empty($v['name'])) $out[] = (string)$v['name'];
    if ($p = catalog_get($code)) $out[] = (string)($p['name'] ?? '');
    return $out;
}

/**
 * Поля сущности из слотов по карте сопоставления.
 * @return array{0: array, 1: string[]} [поля, строки для комментария]
 */
function b24_fields_from_slots(array $T, string $entity, string $mask = 'all'): array
{
    $sc = $T['sc'];
    $fields = []; $comment = [];
    $meta = [];
    try { $meta = b24_fields($entity); } catch (Throwable $e) { /* не загружены — пишем текстом */ }
    $vals = $T['d']['slots'] + bot_virtual_values($T);
    foreach ($vals as $name => $v) {
        $virtual = str_starts_with($name, '@');
        if (!$virtual && $mask !== 'all' && !fnmatch($mask, $name)) continue;
        if (preg_match(BOT_INTERNAL_SLOTS, $name)) continue;
        if (str_starts_with($name, 'client.')) continue; // клиентские поля — отдельно (ФИО, e-mail, мессенджер)
        $def = sc_slot($sc, $name);
        $type = $def['type'] ?? 'string';
        $txt = value_text($v);
        if ($txt === '' || in_array($type, ['counter', 'state_ref'], true)) continue;
        if ($type === 'contact' || $type === 'fio') continue; // в контакт / имя
        if ($type === 'file_or_link') { $comment[] = bot_field_label($name, $sc) . ': ' . (isset($v['link']) ? $v['link'] : 'файл ' . ($v['name'] ?? '')); continue; }
        if ($name === '@product' && $entity === 'deal' && ($svc = (string)(b24_flow((string)($T['d']['slots']['intent'] ?? ''))['service'] ?? '')) !== '') {
            $target = bot_field_target($sc, $name, $entity);
            if ($target !== '' && $target !== '-') { $fields[$target] = !empty($meta[$target]['multiple']) ? [$svc] : $svc; continue; }
        }
        $target = bot_field_target($sc, $name, $entity);
        if ($target === '-' || ($entity === 'contact' && $target === '')) continue;
        if ($target === 'PHONE') continue;
        if ($target !== '') {
            [$val, $note] = isset($meta[$target]) ? b24_convert($v, $meta[$target]) : [$txt, ''];
            if ($val !== null) {
                $fields[$target] = $val;
                if ($note !== '') $comment[] = bot_field_label($name, $sc) . ': ' . $note;
                continue;
            }
            $comment[] = bot_field_label($name, $sc) . ': ' . $txt . ($note ? " ({$note})" : '');
            continue;
        }
        $comment[] = bot_field_label($name, $sc) . ': ' . $txt;
    }
    foreach (b24_defaults($entity) as $code => $val) if ($val !== '' && !isset($fields[$code])) $fields[$code] = !empty($meta[$code]['multiple']) ? [$val] : $val;
    return [$fields, $comment];
}

/** Поля клиента: ФИО, телефон, e-mail, мессенджер (для лида и контакта). */
function b24_client_fields(array $c): array
{
    $f = array_filter(['NAME' => $c['first_name'] ?? '', 'LAST_NAME' => $c['last_name'] ?? '', 'SECOND_NAME' => $c['middle_name'] ?? '']);
    if (($c['phone'] ?? '') !== '') $f['PHONE'] = [['VALUE' => $c['phone'], 'VALUE_TYPE' => 'WORK']];
    if (($c['email'] ?? '') !== '') $f['EMAIL'] = [['VALUE' => $c['email'], 'VALUE_TYPE' => 'WORK']];
    $im = [];
    if (($c['tg_username'] ?? '') !== '') $im[] = ['VALUE' => '@' . $c['tg_username'], 'VALUE_TYPE' => 'TELEGRAM'];
    elseif (($c['messenger_contact'] ?? '') !== '') $im[] = ['VALUE' => $c['messenger_contact'], 'VALUE_TYPE' => 'OTHER'];
    if ($im) $f['IM'] = $im;
    return $f;
}

/** Обязательные поля сущности, которые бот не заполнит (ни сам, ни по карте, ни значением по умолчанию). */
function b24_required_gaps(array $sc, string $entity): array
{
    $gaps = [];
    $mapped = array_filter(b24_map($entity), fn($v) => $v !== '' && $v !== '-');
    $def = b24_defaults($entity);
    foreach (b24_fields($entity) as $code => $f) {
        if (empty($f['required'])) continue;
        $how = in_array($code, B24_SYSTEM_FILLED[$entity] ?? [], true) ? 'заполняет бот' : (in_array($code, $mapped, true) ? 'по сопоставлению (если клиент назвал)' : (($def[$code] ?? '') !== '' ? 'значение по умолчанию' : ''));
        $gaps[$code] = ['title' => $f['title'], 'type' => $f['type'], 'how' => $how, 'items' => $f['items']];
    }
    return $gaps;
}
