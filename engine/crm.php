<?php
declare(strict_types=1);

/**
 * Сделки и контакты в Битрикс24 (направления визы / карты / Green Card).
 *
 * Настройка — админка → Битрикс24 → «Направления»: для каждого продукта сделка или лид, воронка,
 * стадия при создании и стадии по этапам бота, значение поля «Услуга», ответственный.
 *
 * Когда создаётся сделка: продукт понятен И (известен контакт клиента — телефон, e-mail, Telegram —
 * ИЛИ диалог дошёл до важного этапа: счёт, оплата, передача менеджеру).
 * Контакт: ищется по телефону/e-mail среди существующих (crm.duplicate.findbycomm), иначе создаётся.
 * Сделка связывается с контактом. Дальше на каждом ходе: поля по сопоставлению, стадия по этапу бота.
 * Вызывается из engine_finish() — после переходов и ответа.
 */

const CRM_IMPORTANT_STATES = ['payment_wait', 'paid', 'handed_to_manager'];

/** Чем ведётся текущий диалог в CRM: 'deal' или 'lead'. */
function crm_entity(array $T): string
{
    $p = (string)($T['d']['slots']['intent'] ?? '');
    if ($p === '' || $p === 'jobs') return 'lead';
    return (b24_flow($p)['entity'] ?? 'lead') === 'deal' ? 'deal' : 'lead';
}

function crm_title_prefix(array $T): string
{
    return sc_setting($T['sc'], 'test_mode') ? '[ТЕСТ] ' : '';
}

function b24_deal_url(int $id): string { return b24_portal() . '/crm/deal/details/' . $id . '/'; }
function b24_contact_url(int $id): string { return b24_portal() . '/crm/contact/details/' . $id . '/'; }

/** Синхронизация сделки и контакта после хода. */
function crm_sync(array &$T): void
{
    if (!b24_configured() || crm_entity($T) !== 'deal') return;
    $d = &$T['d'];
    $product = (string)$d['slots']['intent'];
    $flow = b24_flow($product);
    $stage = (string)($flow['stages'][$d['state']] ?? '');
    $c = get_client((int)$d['client_id']);
    $hasContact = $c['phone'] !== '' || $c['email'] !== '' || (int)$c['tg_id'] > 0 || $c['messenger_contact'] !== '';
    $important = in_array($d['state'], CRM_IMPORTANT_STATES, true) || $stage !== '';
    $exists = (int)$d['deal_id'] > 0 || !empty($d['counters']['dry:deal']);
    if (!$exists && !$hasContact && !$important) return; // рано: продукт есть, но ни контакта, ни важного этапа
    if ($exists && empty($T['crm_dirty']) && !$T['changed'] && !$T['visited']) return; // ничего не поменялось

    try {
        $contactId = crm_contact_ensure($T, $c, $flow);
        [$fields, $comment] = b24_fields_from_slots($T, 'deal');
        if (isset($fields['OPPORTUNITY'])) $fields['CURRENCY_ID'] = payment_currency($T);
        $block = implode("\n", $comment) . "\nДиалог: " . public_url('admin/?tab=dialogs&id=' . $d['id']) . "\nЭтап бота: " . sc_state_title($T['sc'], $d['state']) . ', ' . fmt_dt(now_utc());

        if (!$exists) {
            $fields = [
                'TITLE' => crm_title_prefix($T) . (FLOW_PRODUCTS[$product] ?? $product) . ' — ' . client_display_name($c),
                'CATEGORY_ID' => (int)($flow['category'] ?? 0),
                'OPENED' => 'Y',
                'SOURCE_DESCRIPTION' => 'бот vb2, ' . (CHANNEL_NAMES[$d['channel']] ?? $d['channel']) . ', диалог #' . $d['id'],
                'COMMENTS' => b24_merge_comments('', $block),
            ] + $fields;
            $st = $stage !== '' ? $stage : (string)($flow['stage_new'] ?? '');
            if ($st !== '') $fields['STAGE_ID'] = $st;
            if ($contactId > 0) $fields['CONTACT_ID'] = $contactId;
            if ((int)($flow['assigned'] ?? 0) > 0) $fields['ASSIGNED_BY_ID'] = (int)$flow['assigned'];
            $res = b24_call($T, 'b24_create_deal', 'crm.deal.add', ['fields' => $fields]);
            if ($res) $d['deal_id'] = (int)$res['result'];
            else $d['counters']['dry:deal'] = 1;
            $d['counters']['deal_stage'] = $fields['STAGE_ID'] ?? '';
            return;
        }

        if ($stage !== '' && $stage !== ($d['counters']['deal_stage'] ?? '')) $fields['STAGE_ID'] = $stage;
        if ($contactId > 0 && (int)($d['counters']['deal_contact'] ?? 0) !== $contactId) $fields['CONTACT_ID'] = $contactId;
        $current = '';
        if ((int)$d['deal_id'] > 0 && b24_live($d['channel'])) {
            try { $current = (string)(b24('crm.deal.get', ['id' => (int)$d['deal_id']])['result']['COMMENTS'] ?? ''); } catch (Throwable $e) { /* не критично */ }
        }
        $fields['COMMENTS'] = b24_merge_comments($current, $block);
        b24_call($T, 'b24_update_deal', 'crm.deal.update', ['id' => (int)$d['deal_id'] ?: '(сделка)', 'fields' => $fields]);
        if (isset($fields['STAGE_ID'])) $d['counters']['deal_stage'] = $fields['STAGE_ID'];
        if (isset($fields['CONTACT_ID'])) $d['counters']['deal_contact'] = $fields['CONTACT_ID'];
    } catch (Throwable $e) {
        action_log($T, 'crm_sync', [], 'ОШИБКА: ' . $e->getMessage(), false);
        app_log("crm_sync dialog {$d['id']}: " . $e->getMessage());
    }
}

/**
 * Контакт клиента: уже привязан → дописываем новые телефон/e-mail (если контакт создан ботом);
 * иначе ищем по телефону и e-mail, не нашли — создаём.
 */
function crm_contact_ensure(array &$T, array $c, array $flow): int
{
    $d = &$T['d'];
    $live = b24_live($d['channel']);
    $client = b24_client_fields($c);
    [$mapped] = b24_fields_from_slots($T, 'contact');

    if ((int)$d['contact_id'] > 0 || !empty($d['counters']['dry:contact'])) {
        if (empty($d['counters']['contact_own'])) return (int)$d['contact_id']; // чужой (найденный) контакт не правим
        $upd = $mapped;
        foreach (['NAME', 'LAST_NAME', 'SECOND_NAME'] as $k) if (isset($client[$k]) && ($d['counters']['c_' . $k] ?? '') !== $client[$k]) $upd[$k] = $d['counters']['c_' . $k] = $client[$k];
        foreach (['PHONE', 'EMAIL', 'IM'] as $k) {
            $val = $client[$k][0]['VALUE'] ?? '';
            if ($val !== '' && ($d['counters']['c_' . $k] ?? '') !== $val) { $upd[$k] = $client[$k]; $d['counters']['c_' . $k] = $val; } // мультиполе без ID — добавляется новое значение
        }
        if ($upd) b24_call($T, 'b24_update_contact', 'crm.contact.update', ['id' => (int)$d['contact_id'] ?: '(контакт)', 'fields' => $upd]);
        return (int)$d['contact_id'];
    }

    // поиск существующего
    if ($live) {
        foreach (['PHONE' => $c['phone'], 'EMAIL' => $c['email']] as $type => $val) {
            if ($val === '') continue;
            $r = b24('crm.duplicate.findbycomm', ['entity_type' => 'CONTACT', 'type' => $type, 'values' => [$val]])['result'] ?? [];
            $ids = is_array($r) ? ($r['CONTACT'] ?? []) : [];
            if ($ids) {
                $d['contact_id'] = (int)$ids[0];
                $d['counters']['contact_own'] = 0;
                action_log($T, 'b24_find_contact', [$type => $val], 'найден существующий контакт #' . $d['contact_id'] . ' — привязываем, данные в нём не меняем');
                return (int)$d['contact_id'];
            }
        }
    }
    $fields = $client + $mapped + ['OPENED' => 'Y', 'SOURCE_DESCRIPTION' => 'бот vb2, ' . (CHANNEL_NAMES[$d['channel']] ?? $d['channel'])];
    if (empty($fields['NAME']) && empty($fields['LAST_NAME'])) $fields['NAME'] = 'Клиент ' . (CHANNEL_NAMES[$d['channel']] ?? '') . ' #' . $d['client_id'];
    if ((int)($flow['assigned'] ?? 0) > 0) $fields['ASSIGNED_BY_ID'] = (int)$flow['assigned'];
    $res = b24_call($T, 'b24_create_contact', 'crm.contact.add', ['fields' => $fields] + ($live ? [] : ['поиск' => 'сначала ищем по телефону/e-mail среди существующих']));
    foreach (['NAME', 'LAST_NAME', 'SECOND_NAME'] as $k) if (isset($client[$k])) $d['counters']['c_' . $k] = $client[$k];
    foreach (['PHONE', 'EMAIL', 'IM'] as $k) if (isset($client[$k])) $d['counters']['c_' . $k] = $client[$k][0]['VALUE'];
    $d['counters']['contact_own'] = 1;
    if (!$res) { $d['counters']['dry:contact'] = 1; return 0; }
    $d['contact_id'] = (int)$res['result'];
    return (int)$d['contact_id'];
}
