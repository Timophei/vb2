<?php
declare(strict_types=1);

/**
 * Сопоставление полей бота с СУЩЕСТВУЮЩИМИ полями лида в Битрикс24.
 *
 * Поля лида загружаются с портала (crm.lead.fields) и кешируются в settings.b24_lead_fields.
 * Сопоставление хранится в settings.b24_map: {"visa.country": "UF_CRM_1700000001", "gc.tariff": "", …}
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
    'UTM_CAMPAIGN', 'UTM_CONTENT', 'UTM_TERM', 'LAST_COMMUNICATION_TIME', 'COMMENTS'];

function bot_field_label(string $name, ?array $sc = null): string
{
    if ($sc && !empty($sc['slots'][$name]['label'])) return (string)$sc['slots'][$name]['label'];
    return BOT_FIELDS[$name][0] ?? $name;
}

/** Поля лида с портала (кеш). $refresh — перечитать. */
function b24_lead_fields(bool $refresh = false): array
{
    if (!$refresh) {
        $c = jdec(setting('b24_lead_fields'));
        if ($c) return $c;
        if (!b24_configured()) return [];
    }
    $res = b24('crm.lead.fields', [])['result'] ?? [];
    if (!is_array($res)) $res = [];
    $out = [];
    foreach ($res as $code => $f) {
        if (in_array($code, B24_SKIP_FIELDS, true) || !empty($f['isReadOnly'])) continue;
        $title = (string)($f['formLabel'] ?? $f['listLabel'] ?? $f['title'] ?? $code);
        if ($title === '' || $title === $code) $title = (string)($f['title'] ?? $code);
        $items = [];
        foreach ((array)($f['items'] ?? []) as $it) $items[(string)$it['ID']] = (string)$it['VALUE'];
        $out[$code] = ['title' => $title, 'type' => (string)($f['type'] ?? 'string'), 'multiple' => !empty($f['isMultiple']), 'custom' => str_starts_with($code, 'UF_'), 'items' => $items];
    }
    uasort($out, fn($a, $b) => [$a['custom'], $a['title']] <=> [$b['custom'], $b['title']]);
    set_setting('b24_lead_fields', jenc($out));
    set_setting('b24_lead_fields_at', now_utc());
    return $out;
}

function b24_map(): array
{
    return jdec(setting('b24_map'));
}

/** Куда писать поле бота: код поля, '' — в комментарий, '-' — не передавать. */
function bot_field_target(array $sc, string $name): string
{
    $map = b24_map();
    if (array_key_exists($name, $map)) return (string)$map[$name];
    $bf = $sc['slots'][$name]['b24_field'] ?? null;
    if (is_string($bf) && $bf !== '' && !is_todo($bf) && $bf !== 'COMMENTS') return $bf;
    return BOT_FIELDS_DEFAULT[$name] ?? '';
}

/** Подбор поля Битрикса по названию (для подсказки в админке). */
function bot_field_suggest(string $name, array $fields, array $taken = []): string
{
    $hints = BOT_FIELDS_HINTS[$name] ?? [];
    if (!$hints) return '';
    $best = ''; $score = 0;
    foreach ($fields as $code => $f) {
        if (in_array($code, $taken, true)) continue;
        $t = mb_strtolower($f['title']);
        $s = 0;
        foreach ($hints as $i => $h) if (str_contains($t, $h)) $s += 10 - $i;
        if ($s === 0) continue; // без совпадения по смыслу не предлагаем
        if (str_starts_with($name, 'visa.') && str_contains($t, 'виз')) $s += 3;
        if (str_starts_with($name, 'gc.') && (str_contains($t, 'green') || str_contains($t, 'грин'))) $s += 3;
        if ($s > $score) { $score = $s; $best = $code; }
    }
    return $best;
}

/**
 * Значение для поля Битрикса с учётом его типа. null — не подошло (тогда пишем в комментарий).
 * Списки (enumeration) — по совпадению текста с вариантами; да/нет — 1/0; числа — числом.
 */
function b24_value(string $code, $v, array $meta)
{
    $txt = value_text($v);
    $type = $meta['type'] ?? 'string';
    switch ($type) {
        case 'enumeration':
            // сравниваем и код («standard»), и название варианта из каталога («Стандартный»)
            $cands = array_values(array_unique(array_filter(array_merge([$txt], catalog_variant_names($txt)))));
            foreach ($cands as $c) foreach ((array)($meta['items'] ?? []) as $id => $label) {
                if (mb_strtolower(trim($label)) === mb_strtolower(trim($c))) return $meta['multiple'] ? [(string)$id] : (string)$id;
            }
            foreach ($cands as $c) foreach ((array)($meta['items'] ?? []) as $id => $label) {
                $l = mb_strtolower($label); $cc = mb_strtolower($c);
                if ($cc !== '' && $l !== '' && (str_contains($l, $cc) || str_contains($cc, $l))) return $meta['multiple'] ? [(string)$id] : (string)$id;
            }
            return null;
        case 'boolean':
            return ($v === true || in_array(mb_strtolower($txt), ['да', 'true', '1', 'yes'], true)) ? 1 : 0;
        case 'integer':
            return preg_match('/-?\d+/', $txt, $m) ? (int)$m[0] : null;
        case 'double': case 'money':
            return is_numeric($v) ? (float)$v : (preg_match('/-?\d+([.,]\d+)?/', $txt, $m) ? (float)str_replace(',', '.', $m[0]) : null);
        case 'date':
            $t = strtotime($txt);
            return $t ? date('Y-m-d', $t) : null;
        case 'datetime':
            try { return (new DateTimeImmutable($txt, new DateTimeZone('UTC')))->setTimezone(app_tz())->format(DATE_ATOM); } catch (Throwable $e) { return null; }
        case 'crm_multifield':
            return null; // PHONE/EMAIL/IM — отдельно
        default:
            return $meta['multiple'] ? [$txt] : $txt;
    }
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
