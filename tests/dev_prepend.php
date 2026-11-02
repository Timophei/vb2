<?php
/**
 * Локальный просмотр без OpenAI и Битрикса:
 *   php -d auto_prepend_file=tests/dev_prepend.php -S 127.0.0.1:8090 -t public
 * Модель заменена примитивным разбором по ключевым словам — только чтобы посмотреть интерфейс.
 */
$dir = sys_get_temp_dir() . '/vb2dev';
@mkdir($dir, 0777, true);
if (!is_file("$dir/config.php")) file_put_contents("$dir/config.php", '<?php return ' . var_export([
    'timezone' => 'Asia/Irkutsk', 'openai_api_key' => 'dev', 'use_embeddings' => false,
    'bitrix_webhook' => 'https://crm.example.ru/rest/1/xxx/', 'bitrix_mode' => 'dry',
    'public_url' => 'http://127.0.0.1:8090', 'data_dir' => "$dir/data",
], true) . ';');
putenv("VB2_CONFIG=$dir/config.php");

function openai_mock(string $path, array $p): array
{
    $last = '';
    foreach (array_reverse($p['messages']) as $m) if ($m['role'] === 'user') { $last = mb_strtolower((string)$m['content']); break; }
    if (isset($p['response_format'])) {
        $intent = 'none'; $slots = [];
        $rules = ['грин' => 'green_card', 'green' => 'green_card', 'ваканс' => 'jobs', 'работ' => 'jobs', 'виз' => 'visa', 'карт' => 'cards', 'человек' => 'ask_human', 'менеджер' => 'ask_human', 'подумаю' => 'postpone'];
        foreach ($rules as $k => $v) if (str_contains($last, $k)) { $intent = $v; break; }
        if (preg_match('/ваканси[юи]\s+([\p{L} ]+?)(?:[,.]|$)/u', $last, $m)) $slots[] = ['name' => 'jobs.position', 'value' => trim($m[1])];
        if (preg_match('/(\+?\d[\d\s\-()]{9,})/u', $last, $m)) $slots[] = ['name' => 'contact', 'value' => $m[1]];
        if (preg_match('/меня зовут ([\p{L}]+) ([\p{L}]+)/u', $last, $m)) $slots[] = ['name' => 'jobs.full_name', 'value' => "$m[2] / $m[1] / "];
        if (str_contains($last, 'резюме нет')) $slots[] = ['name' => 'jobs.no_resume', 'value' => 'true'];
        if (str_contains($last, 'выставляй')) array_push($slots, ['name' => 'gc.tariff', 'value' => 'standard'], ['name' => 'gc.participants', 'value' => '1'], ['name' => 'gc.terms_accepted', 'value' => 'true']);
        return ['choices' => [['message' => ['content' => json_encode(['intent' => $intent, 'slots' => $slots], JSON_UNESCAPED_UNICODE)]]], 'usage' => ['total_tokens' => 150]];
    }
    $hadTool = (bool)array_filter($p['messages'], fn($x) => $x['role'] === 'tool');
    if (!empty($p['tools']) && !$hadTool && str_contains($last, 'выставляй')) {
        return ['choices' => [['message' => ['content' => null, 'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'create_payment_link', 'arguments' => '{"product_id":"green_card","variant_id":"standard","options":[],"quantity":1}']]]]]], 'usage' => ['total_tokens' => 300]];
    }
    if ($hadTool) {
        $r = json_decode((string)end($p['messages'])['content'], true);
        $txt = isset($r['url']) ? "(тест) Выставил счёт № {$r['invoice_no']} на {$r['amount']}. Ниже QR-код — отсканируйте или откройте ссылку: {$r['url']}" : '(тест) Ошибка: ' . ($r['error'] ?? '?');
        return ['choices' => [['message' => ['content' => $txt]]], 'usage' => ['total_tokens' => 300]];
    }
    preg_match('/## Текущий этап: (.+)/u', $p['messages'][0]['content'], $m);
    return ['choices' => [['message' => ['content' => "(тестовый ответ без модели) Этап: " . ($m[1] ?? '?') . ". Спасибо за сообщение!"]]], 'usage' => ['total_tokens' => 900]];
}

/** Портал-заглушка: поля лида как на типичном портале (+ поля «Виза: …» из v1). */
function b24_mock(string $method, array $p): array
{
    if ($method === 'crm.lead.fields') return ['result' => [
        'TITLE' => ['type' => 'string', 'title' => 'Название лида'], 'NAME' => ['type' => 'string', 'title' => 'Имя'], 'LAST_NAME' => ['type' => 'string', 'title' => 'Фамилия'],
        'SOURCE_DESCRIPTION' => ['type' => 'string', 'title' => 'Дополнительно об источнике'], 'OPPORTUNITY' => ['type' => 'double', 'title' => 'Сумма'],
        'PHONE' => ['type' => 'crm_multifield', 'title' => 'Телефон', 'isMultiple' => true], 'BIRTHDATE' => ['type' => 'date', 'title' => 'Дата рождения'],
        'UF_CRM_VISA_COUNTRY' => ['type' => 'string', 'formLabel' => 'Виза: страна / направление'], 'UF_CRM_VISA_CITY' => ['type' => 'string', 'formLabel' => 'Виза: город подачи'],
        'UF_CRM_VISA_DATES' => ['type' => 'string', 'formLabel' => 'Виза: предполагаемые даты'], 'UF_CRM_VISA_APPLICANTS' => ['type' => 'string', 'formLabel' => 'Виза: количество заявителей'],
        'UF_CRM_1690000001' => ['type' => 'enumeration', 'formLabel' => 'Услуга', 'items' => [['ID' => 1, 'VALUE' => 'Визы'], ['ID' => 2, 'VALUE' => 'Green Card США']]],
    ]];
    if ($method === 'crm.deal.fields') return ['result' => [
        'TITLE' => ['type' => 'string', 'title' => 'Название', 'isRequired' => true], 'OPPORTUNITY' => ['type' => 'double', 'title' => 'Сумма'],
        'UF_CRM_SERVICES' => ['type' => 'enumeration', 'formLabel' => 'Услуги', 'isMultiple' => true, 'items' => [['ID' => 11, 'VALUE' => 'Визы'], ['ID' => 12, 'VALUE' => 'Green Card'], ['ID' => 13, 'VALUE' => 'Банковские карты']]],
        'UF_CRM_COUNTRY' => ['type' => 'enumeration', 'formLabel' => 'Страна', 'items' => [['ID' => 21, 'VALUE' => 'Италия'], ['ID' => 22, 'VALUE' => 'Испания'], ['ID' => 29, 'VALUE' => 'Другая']]],
        'UF_CRM_CITY' => ['type' => 'enumeration', 'formLabel' => 'Город', 'items' => [['ID' => 31, 'VALUE' => 'Москва'], ['ID' => 39, 'VALUE' => 'Другой']]],
        'UF_CRM_SOURCE2' => ['type' => 'enumeration', 'formLabel' => 'Откуда узнали', 'isRequired' => true, 'items' => [['ID' => 51, 'VALUE' => 'Сайт'], ['ID' => 52, 'VALUE' => 'Telegram']]],
    ]];
    if ($method === 'crm.contact.fields') return ['result' => [
        'NAME' => ['type' => 'string', 'title' => 'Имя', 'isRequired' => true], 'LAST_NAME' => ['type' => 'string', 'title' => 'Фамилия'],
        'PHONE' => ['type' => 'crm_multifield', 'title' => 'Телефон'], 'UF_CRM_CITIZENSHIP' => ['type' => 'string', 'formLabel' => 'Гражданство'],
    ]];
    if ($method === 'crm.category.list') return ['result' => ['categories' => [['id' => 0, 'name' => 'Общая'], ['id' => 3, 'name' => 'Продажи']]]];
    if ($method === 'crm.status.list') {
        $e = $p['filter']['ENTITY_ID'] ?? '';
        if ($e === 'DEAL_STAGE_3') return ['result' => [['STATUS_ID' => 'C3:NEW', 'NAME' => 'Новая'], ['STATUS_ID' => 'C3:PREPARATION', 'NAME' => 'Консультация'], ['STATUS_ID' => 'C3:PREPAYMENT_INVOICE', 'NAME' => 'Заключение договора и оплата'], ['STATUS_ID' => 'C3:WON', 'NAME' => 'Оплачено']]];
        if ($e === 'DEAL_STAGE') return ['result' => [['STATUS_ID' => 'NEW', 'NAME' => 'Новая'], ['STATUS_ID' => 'WON', 'NAME' => 'Успешно']]];
        return ['result' => [['STATUS_ID' => 'NEW', 'NAME' => 'Не обработан'], ['STATUS_ID' => 'UC_IGWO64', 'NAME' => 'HR', 'EXTRA' => ['SEMANTICS' => 'F']]]];
    }
    return ['result' => true];
}
