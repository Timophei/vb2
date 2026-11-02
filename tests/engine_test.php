<?php
declare(strict_types=1);
/**
 * Прогон сценария без OpenAI и Битрикса: модель и портал подменены заглушками.
 * Запуск: php tests/engine_test.php
 * Проверяет переходы, слоты, действия Б24 (порядок и поля), таймеры, оплату.
 */

$tmp = sys_get_temp_dir() . '/vb2test_' . getmypid();
@mkdir($tmp, 0777, true);
file_put_contents("$tmp/config.php", '<?php return ' . var_export([
    'timezone' => 'Asia/Irkutsk', 'openai_api_key' => 'x', 'use_embeddings' => false,
    'bitrix_webhook' => 'https://crm.example.ru/rest/1/abc/', 'bitrix_mode' => 'live',
    'public_url' => 'https://vb2.example.ru', 'data_dir' => "$tmp/data", 'telegram_token' => 'T',
], true) . ';');
putenv("VB2_CONFIG=$tmp/config.php");

/* ---------- заглушки ---------- */
$GLOBALS['CLS'] = [];       // текст клиента => [intent, [slot => value]]
$GLOBALS['TOOLS'] = [];     // текст клиента => [[name, args], …]
$GLOBALS['B24'] = [];
$GLOBALS['TG'] = [];
$GLOBALS['LAST_USER'] = '';

function openai_mock(string $path, array $p): array
{
    $msgs = $p['messages'];
    $lastUser = '';
    foreach (array_reverse($msgs) as $m) if ($m['role'] === 'user') { $lastUser = preg_replace('/\n\[клиент приложил.*$/su', '', $m['content']); break; }
    if (isset($p['response_format'])) {
        [$intent, $slots] = $GLOBALS['CLS'][$lastUser] ?? ['none', []];
        $arr = [];
        foreach ($slots as $k => $v) $arr[] = ['name' => $k, 'value' => (string)$v];
        return ['choices' => [['message' => ['content' => json_encode(['intent' => $intent, 'slots' => $arr], JSON_UNESCAPED_UNICODE)]]], 'usage' => ['total_tokens' => 10]];
    }
    preg_match('/## Текущий этап: (.+)/u', $msgs[0]['content'], $m);
    $stage = $m[1] ?? '?';
    $hadTool = (bool)array_filter($msgs, fn($x) => $x['role'] === 'tool');
    if (!empty($p['tools']) && !$hadTool && isset($GLOBALS['TOOLS'][$lastUser])) {
        $calls = [];
        foreach ($GLOBALS['TOOLS'][$lastUser] as $i => [$name, $args]) $calls[] = ['id' => "c$i", 'type' => 'function', 'function' => ['name' => $name, 'arguments' => json_encode($args, JSON_UNESCAPED_UNICODE)]];
        return ['choices' => [['message' => ['content' => null, 'tool_calls' => $calls]]], 'usage' => ['total_tokens' => 10]];
    }
    $toolRes = '';
    foreach ($msgs as $x) if ($x['role'] === 'tool') $toolRes .= ' tool=' . $x['content'];
    return ['choices' => [['message' => ['content' => "[ответ: $stage]$toolRes"]]], 'usage' => ['total_tokens' => 20]];
}
function b24_mock(string $method, array $params): array
{
    $GLOBALS['B24'][] = [$method, $params];
    return match ($method) {
        'crm.lead.add' => ['result' => 501],
        'crm.deal.add' => ['result' => 700],
        'crm.deal.list' => ['result' => array_map(fn($id) => ['ID' => (string)$id, 'ASSIGNED_BY_ID' => (string)($GLOBALS['OWNER'][$id] ?? 5)], (array)($params['filter']['ID'] ?? []))],
        'crm.lead.list' => ['result' => []],
        'crm.timeline.comment.add' => ['result' => 1],
        'crm.contact.add' => ['result' => 901],
        'crm.deal.get' => ['result' => ['COMMENTS' => '']],
        'crm.duplicate.findbycomm' => ['result' => in_array('+79140000001', $params['values'] ?? [], true) ? ['CONTACT' => [900]] : []],
        'crm.deal.fields' => ['result' => [
            'TITLE' => ['type' => 'string', 'title' => 'Название', 'isRequired' => true], 'OPPORTUNITY' => ['type' => 'double', 'title' => 'Сумма'],
            'UF_CRM_SVC' => ['type' => 'enumeration', 'formLabel' => 'Услуги', 'isMultiple' => true, 'items' => [['ID' => '11', 'VALUE' => 'Визы'], ['ID' => '12', 'VALUE' => 'Green Card'], ['ID' => '13', 'VALUE' => 'Банковские карты']]],
            'UF_CRM_COUNTRY' => ['type' => 'enumeration', 'formLabel' => 'Страна', 'items' => [['ID' => '21', 'VALUE' => 'Италия'], ['ID' => '22', 'VALUE' => 'Испания'], ['ID' => '29', 'VALUE' => 'Другая']]],
            'UF_CRM_CITY' => ['type' => 'enumeration', 'formLabel' => 'Город подачи', 'items' => [['ID' => '31', 'VALUE' => 'Москва'], ['ID' => '39', 'VALUE' => 'Другой']]],
            'UF_CRM_REQ' => ['type' => 'string', 'formLabel' => 'Обязательное поле портала', 'isRequired' => true],
        ]],
        'crm.item.add' => ['result' => ['item' => ['id' => 77]]],
        'crm.lead.get' => ['result' => ['COMMENTS' => 'заметка менеджера']],
        'crm.lead.fields' => ['result' => [
            'TITLE' => ['type' => 'string', 'title' => 'Название'], 'NAME' => ['type' => 'string', 'title' => 'Имя'],
            'OPPORTUNITY' => ['type' => 'double', 'title' => 'Сумма'], 'PHONE' => ['type' => 'crm_multifield', 'title' => 'Телефон'],
            'UF_CRM_1700000001' => ['type' => 'string', 'formLabel' => 'Страна визы'],
            'UF_CRM_1700000002' => ['type' => 'enumeration', 'formLabel' => 'Тариф Green Card', 'items' => [['ID' => '41', 'VALUE' => 'Стандартный'], ['ID' => '42', 'VALUE' => 'Всё включено']]],
            'UF_CRM_1700000003' => ['type' => 'integer', 'formLabel' => 'Количество участников'],
            'ID' => ['type' => 'integer', 'title' => 'ID', 'isReadOnly' => true],
        ]],
        default => ['result' => true],
    };
}
function tg_mock(string $method, array $params): array { $GLOBALS['TG'][] = [$method, $params]; return ['ok' => true]; }

require __DIR__ . '/../lib/app.php';

$fails = 0;
function check(bool $ok, string $what): void
{
    global $fails;
    echo ($ok ? "  ✓ " : "  ✗ ") . $what . "\n";
    if (!$ok) $fails++;
}
function say(int $dlg, string $text, ?array $att = null): array
{
    $out = engine_message($dlg, $text, $att);
    echo "  клиент: $text\n";
    foreach ($out as $o) echo "  бот: " . mb_strimwidth($o, 0, 160, '…') . "\n";
    return $out;
}
function state(int $dlg): string { return dialog_load($dlg)['state']; }
function new_dialog(string $key, string $channel = 'web'): int
{
    $c = client_by_key($key);
    return current_dialog((int)$c['id'], $channel, true);
}

$sc = scenario_active();
[$E, $W] = scenario_validate($sc);
echo "Сценарий: {$sc['meta']['name']} v{$sc['meta']['version']} — ошибок " . count($E) . ', предупреждений ' . count($W) . "\n";
foreach ($E as $e) echo "  ОШИБКА: $e\n";
check(!$E, 'сценарий проходит проверку');

/* ---------- 1. HR-воронка ---------- */
echo "\n1. Вакансия: без участия людей\n";
$GLOBALS['CLS'] = [
    'Здравствуйте, я по вакансии менеджера по продажам' => ['jobs', ['jobs.position' => 'менеджер по продажам']],
    'Иванов Иван Петрович, +7 914 123-45-67' => ['none', ['jobs.full_name' => 'Иванов / Иван / Петрович', 'contact' => '+7 914 123-45-67']],
    'резюме нет' => ['none', ['jobs.no_resume' => 'true']],
];
$dlg = new_dialog('web:hr1');
say($dlg, 'Здравствуйте, я по вакансии менеджера по продажам');
check(state($dlg) === 'jobs', 'greeting → jobs');
say($dlg, 'Иванов Иван Петрович, +7 914 123-45-67');
check(state($dlg) === 'jobs', 'остаёмся в jobs, пока нет резюме/отказа');
$GLOBALS['B24'] = [];
$out = say($dlg, 'резюме нет');
check(state($dlg) === 'closed', 'jobs → jobs_finish → closed');
check(str_contains($out[0] ?? '', 'Вакансии: оформление'), 'ответ написан по инструкции jobs_finish');
$methods = array_column($GLOBALS['B24'], 0);
echo '  Б24: ' . implode(' → ', $methods) . "\n";
check(($methods[0] ?? '') === 'crm.lead.add', 'лид создан (канал сайт)');
$upd = array_values(array_filter($GLOBALS['B24'], fn($c) => $c[0] === 'crm.lead.update'));
check(($upd[0][1]['fields']['TITLE'] ?? '') === 'менеджер по продажам', 'вакансия в названии лида');
check(($upd[0][1]['fields']['LAST_NAME'] ?? '') === 'Иванов' && ($upd[0][1]['fields']['SECOND_NAME'] ?? '') === 'Петрович', 'ФИО разложено по полям');
check(($upd[0][1]['fields']['PHONE'][0]['VALUE'] ?? '') === '+79141234567', 'контакт → PHONE');
$item = array_values(array_filter($GLOBALS['B24'], fn($c) => $c[0] === 'crm.item.add'))[0] ?? null;
check($item && $item[1]['entityTypeId'] === 1038 && ($item[1]['fields']['parentId1'] ?? 0) === 501, 'смарт-процесс 1038 со связью с лидом');
check(!isset($item[1]['fields']['categoryId']), 'поля TODO (воронка) пропущены');
$last = end($upd);
check(($last[1]['fields']['STATUS_ID'] ?? '') === 'UC_IGWO64', 'последним — лид в стадию HR (UC_IGWO64)');
check(array_search('crm.item.add', $methods) < array_key_last($methods), 'стадия ставится после создания смарт-процесса');
$d = dialog_load($dlg);
check(($d['slots']['jobs.smart_item_id'] ?? 0) === 77, 'id элемента сохранён в слот');
check(get_client((int)$d['client_id'])['last_name'] === 'Иванов', 'ФИО записано в карточку клиента');

echo "\n1б. Вакансия с файлом резюме\n";
$GLOBALS['CLS'] = [
    'Хочу откликнуться на вакансию бухгалтера. Петрова Анна, anna@mail.ru' => ['jobs', ['jobs.position' => 'бухгалтер', 'jobs.full_name' => 'Петрова / Анна / ', 'contact' => 'anna@mail.ru']],
];
$dlg = new_dialog('web:hr2');
say($dlg, 'Хочу откликнуться на вакансию бухгалтера. Петрова Анна, anna@mail.ru');
check(state($dlg) === 'jobs', 'данные есть, ждём резюме');
file_put_contents("$tmp/cv.pdf", '%PDF-1.4 test');
$att = store_upload("$tmp/cv.pdf", 'Резюме Петрова.pdf');
$GLOBALS['B24'] = [];
say($dlg, '', $att);
check(state($dlg) === 'closed', 'файл резюме → оформление → closed');
$tl = array_values(array_filter($GLOBALS['B24'], fn($c) => $c[0] === 'crm.timeline.comment.add'))[0] ?? null;
check($tl && ($tl[1]['fields']['FILES'][0][0] ?? '') === 'Резюме Петрова.pdf', 'резюме приложено в таймлайн лида');
$upd = array_values(array_filter($GLOBALS['B24'], fn($c) => $c[0] === 'crm.lead.update'));
check(($upd[0][1]['fields']['EMAIL'][0]['VALUE'] ?? '') === 'anna@mail.ru', 'e-mail → EMAIL');

/* ---------- 2. Green Card до оплаты ---------- */
echo "\n2. Green Card: тариф → согласие → счёт → оплата\n";
$gc = catalog_get('green_card');
$gc['price_per'] = 'participant';
$gc['variants'][0]['price'] = 3000;
catalog_save($gc);
$GLOBALS['CLS'] = [
    'Интересует грин карта' => ['green_card', ['gc.request_type' => 'new']],
    'Стандартный, нас двое, согласен, выставляйте' => ['none', ['gc.tariff' => 'standard', 'gc.participants' => '2', 'gc.terms_accepted' => 'true']],
];
$GLOBALS['TOOLS'] = ['Стандартный, нас двое, согласен, выставляйте' => [['create_payment_link', ['product_id' => 'green_card', 'variant_id' => 'standard', 'options' => [], 'quantity' => 2]]]];
$dlg = new_dialog('tg:555', 'telegram');
db()->prepare('UPDATE dialogs SET ext_id = ? WHERE id = ?')->execute(['555', $dlg]);
say($dlg, 'Интересует грин карта');
check(state($dlg) === 'green_card', 'greeting → green_card');
$out = say($dlg, 'Стандартный, нас двое, согласен, выставляйте');
check(state($dlg) === 'payment_wait', 'счёт выставлен → payment_wait');
$d = dialog_load($dlg);
check(($d['slots']['payment.amount'] ?? 0) == 6000, 'сумма посчитана кодом: 3000 × 2 = 6000');
check(str_contains($out[0] ?? '', 'pay.php?c='), 'ссылка на оплату в ответе');
$qrMsg = array_values(array_filter(dialog_messages($dlg), fn($m) => $m['attachment'] && (jdec($m['attachment'])['kind'] ?? '') === 'qr'));
if (trim((string)shell_exec('command -v qrencode')) !== '') {
    check(count($qrMsg) === 1 && str_contains($qrMsg[0]['content'], 'Счёт № 0001'), 'QR-код счёта отдельным сообщением, порядковый номер 0001');
    check(is_file(data_dir() . '/' . jdec($qrMsg[0]['attachment'])['file']), 'картинка QR создана');
    check(($d['slots']['payment.invoice_no'] ?? '') === '0001', 'номер счёта в слоте');
} else echo "  · qrencode не установлен — проверка QR пропущена\n";
$t = db()->query("SELECT timer_id FROM timers WHERE dialog_id = $dlg AND active = 1 ORDER BY timer_id")->fetchAll(PDO::FETCH_COLUMN);
check($t === ['payment_reminder', 'payment_timeout'], 'запущены таймеры ожидания оплаты');
$GLOBALS['TG'] = [];
payment_mark((string)$d['slots']['payment.invoice_id'], true);
check(state($dlg) === 'handed_to_manager', 'оплата → paid → handed_to_manager');
check(count($GLOBALS['TG']) >= 1, 'сообщение об оплате отправлено в Telegram');
$t = db()->query("SELECT COUNT(*) FROM timers WHERE dialog_id = $dlg AND active = 1")->fetchColumn();
check((int)$t === 0, 'таймеры оплаты погашены');

echo "\n2б. Счёт без согласия не выставляется, цена null — тоже\n";
$GLOBALS['CLS'] = [
    'Грин карта, всё включено' => ['green_card', ['gc.tariff' => 'all_inclusive', 'gc.participants' => '1']],
    'давайте' => ['none', ['gc.terms_accepted' => 'true']],
];
$GLOBALS['TOOLS'] = [
    'Грин карта, всё включено' => [['create_payment_link', ['product_id' => 'green_card', 'variant_id' => 'all_inclusive', 'options' => [], 'quantity' => 1]]],
    'давайте' => [['create_payment_link', ['product_id' => 'green_card', 'variant_id' => 'all_inclusive', 'options' => [], 'quantity' => 1]]],
];
$dlg = new_dialog('web:gc2');
$out = say($dlg, 'Грин карта, всё включено');
check(state($dlg) === 'green_card' && str_contains($out[0], 'нет явного согласия'), 'без согласия — отказ инструмента');
$out = say($dlg, 'давайте');
check(state($dlg) === 'green_card' && str_contains($out[0], 'не задана в каталоге'), 'цена не задана — счёт не выставлен');

/* ---------- 2в. Сопоставление полей с существующими полями лида ---------- */
echo "\n2в. Поля лида: сопоставление с существующими полями Битрикса\n";
$fl = b24_lead_fields(true);
check(isset($fl['UF_CRM_1700000002']) && !isset($fl['ID']), 'поля лида загружены (служебные отброшены)');
check(bot_field_suggest('visa.country', $fl) === 'UF_CRM_1700000001', 'автоподбор: «Виза: страна» → «Страна визы»');
check(bot_field_suggest('gc.tariff', $fl) === 'UF_CRM_1700000002', 'автоподбор: тариф');
set_setting('b24_map', jenc(['gc.tariff' => 'UF_CRM_1700000002', 'gc.participants' => 'UF_CRM_1700000003', 'gc.request_type' => '-']));
$GLOBALS['CLS'] = ['грин карта, тариф всё включено, нас трое' => ['green_card', ['gc.tariff' => 'all_inclusive', 'gc.participants' => '3', 'gc.request_type' => 'new']]];
$GLOBALS['TOOLS'] = [];
$dlg = new_dialog('web:map1');
say($dlg, 'грин карта, тариф всё включено, нас трое');
$GLOBALS['B24'] = [];
$GLOBALS['CLS'] = ['можно человека?' => ['ask_human', []]];
say($dlg, 'можно человека?');
$upd = array_values(array_filter($GLOBALS['B24'], fn($c) => $c[0] === 'crm.lead.update'));
$f = $upd[0][1]['fields'] ?? [];
check(($f['UF_CRM_1700000002'] ?? null) === '42', 'тариф all_inclusive → вариант списка «Всё включено» (ID 42)');
check(($f['UF_CRM_1700000003'] ?? null) === 3, 'участники → целое число');
check(!str_contains($f['COMMENTS'] ?? '', 'тип обращения'), '«не передавать» — не пишется');
check(str_contains($f['COMMENTS'] ?? '', 'Продукт / направление обращения: Green Card США'), 'служебное поле без сопоставления — в комментарий');
check(str_contains($f['COMMENTS'] ?? '', 'заметка менеджера'), 'заметки менеджеров в комментарии сохранены');
set_setting('b24_map', '{}');

/* ---------- 2г. Сделки и контакты ---------- */
echo "\n2г. Сделки: контакт (новый или найденный), воронка, стадии, списки с «Другой»\n";
b24_fields('deal', true);
set_setting('b24_map_deal', jenc(['@product' => 'UF_CRM_SVC', 'visa.country' => 'UF_CRM_COUNTRY', 'visa.submission_city' => 'UF_CRM_CITY', 'payment.amount' => 'OPPORTUNITY']));
set_setting('b24_defaults_deal', jenc(['UF_CRM_REQ' => 'бот']));
set_setting('b24_flow', jenc([
    'green_card' => ['entity' => 'deal', 'category' => 3, 'stage_new' => 'C3:NEW', 'service' => '12', 'assigned' => 15, 'stages' => ['payment_wait' => 'C3:PREPAYMENT_INVOICE']],
    'visa' => ['entity' => 'deal', 'category' => 3, 'stage_new' => 'C3:NEW', 'service' => '11'],
]));
$gaps = b24_required_gaps(scenario_active(), 'deal');
check(($gaps['TITLE']['how'] ?? '') === 'заполняет бот' && ($gaps['UF_CRM_REQ']['how'] ?? '') === 'значение по умолчанию', 'проверка обязательных полей сделки');
check(in_array('Италия', bot_field_options(scenario_active(), 'visa.country'), true), 'классификатору передаются варианты списка «Страна»');

$GLOBALS['CLS'] = [
    'хочу грин карту' => ['green_card', []],
    'беру стандартный, я один, согласен' => ['none', ['gc.tariff' => 'standard', 'gc.participants' => '1', 'gc.terms_accepted' => 'true']],
];
$GLOBALS['TOOLS'] = ['беру стандартный, я один, согласен' => [['create_payment_link', ['product_id' => 'green_card', 'variant_id' => 'standard', 'options' => [], 'quantity' => 1]]]];
$c = client_by_key('tg:777');
attach_telegram((int)$c['id'], ['id' => 777, 'first_name' => 'Олег', 'username' => 'oleg_tg']);
$dlg = current_dialog((int)$c['id'], 'telegram', true, '777');
$GLOBALS['B24'] = [];
say($dlg, 'хочу грин карту');
$m = array_column($GLOBALS['B24'], 0);
echo '  Б24: ' . implode(' → ', $m) . "\n";
$deal = array_values(array_filter($GLOBALS['B24'], fn($x) => $x[0] === 'crm.deal.add'))[0][1]['fields'] ?? [];
$cont = array_values(array_filter($GLOBALS['B24'], fn($x) => $x[0] === 'crm.contact.add'))[0][1]['fields'] ?? [];
check(!in_array('crm.lead.add', $m, true), 'лид не создаётся — направление ведётся сделкой');
check(($cont['IM'][0]['VALUE'] ?? '') === '@oleg_tg' && ($cont['NAME'] ?? '') === 'Олег', 'контакт создан: имя и Telegram');
check(($deal['CATEGORY_ID'] ?? 0) === 3 && ($deal['STAGE_ID'] ?? '') === 'C3:NEW' && ($deal['CONTACT_ID'] ?? 0) === 901, 'сделка в воронке 3, стадия «новая», связана с контактом');
check(($deal['UF_CRM_SVC'] ?? null) === ['12'], 'поле «Услуги» = Green Card');
check(($deal['ASSIGNED_BY_ID'] ?? 0) === 15 && ($deal['UF_CRM_REQ'] ?? '') === 'бот', 'ответственный и обязательное поле по умолчанию');
check(str_starts_with($deal['TITLE'] ?? '', '[ТЕСТ] Green Card США — '), 'название сделки с пометкой [ТЕСТ]');
check((int)dialog_load($dlg)['deal_id'] === 700, 'id сделки сохранён в диалоге');
$GLOBALS['B24'] = [];
say($dlg, 'беру стандартный, я один, согласен');
check(state($dlg) === 'payment_wait', 'счёт выставлен');
$upd = array_values(array_filter($GLOBALS['B24'], fn($x) => $x[0] === 'crm.deal.update'));
$last = end($upd)[1]['fields'] ?? [];
check(($last['STAGE_ID'] ?? '') === 'C3:PREPAYMENT_INVOICE', 'ожидание оплаты → стадия «Заключение договора и оплата»');
check(($last['OPPORTUNITY'] ?? 0) == 3000 && ($last['CURRENCY_ID'] ?? '') === 'RUB', 'сумма сделки = сумма счёта');
check(!in_array('crm.contact.add', array_column($GLOBALS['B24'], 0), true), 'контакт повторно не создаётся');

echo "\n2д. Виза с сайта: без контакта сделки нет; телефон → найден существующий контакт; страны нет в списке → «Другая»\n";
$GLOBALS['CLS'] = [
    'нужна виза во Вьетнам' => ['visa', ['visa.country' => 'Вьетнам']],
    'подаваться в Москве, мой телефон +7 914 000-00-01' => ['none', ['visa.submission_city' => 'Москва', 'contact' => '+79140000001']],
];
$GLOBALS['TOOLS'] = [];
$dlg = new_dialog('web:visa-deal');
$GLOBALS['B24'] = [];
say($dlg, 'нужна виза во Вьетнам');
check(!array_filter($GLOBALS['B24'], fn($x) => str_starts_with($x[0], 'crm.deal')), 'контакта нет — сделку пока не создаём');
say($dlg, 'подаваться в Москве, мой телефон +7 914 000-00-01');
$m = array_column($GLOBALS['B24'], 0);
echo '  Б24: ' . implode(' → ', $m) . "\n";
$deal = array_values(array_filter($GLOBALS['B24'], fn($x) => $x[0] === 'crm.deal.add'))[0][1]['fields'] ?? [];
check(!in_array('crm.contact.add', $m, true) && ($deal['CONTACT_ID'] ?? 0) === 900, 'найден существующий контакт по телефону — привязан');
check(($deal['UF_CRM_COUNTRY'] ?? '') === '29' && str_contains($deal['COMMENTS'] ?? '', '«Вьетнам» нет в списке'), 'Вьетнама нет в списке → «Другая», исходное — в комментарий');
check(($deal['UF_CRM_CITY'] ?? '') === '31' && ($deal['UF_CRM_SVC'] ?? null) === ['11'], 'город из списка, услуга «Визы»');

echo "\n2е. Ответственный: бот → менеджер забрал → бот молчит → вернули боту\n";
set_setting('b24_bot_user', '5');
$GLOBALS['CLS'] = ['а сколько стоит?' => ['none', []], 'ещё вопрос' => ['none', []]];
$GLOBALS['B24'] = [];
$GLOBALS['OWNER'] = [700 => 5];
check(crm_watch_takeover() === 0, 'ответственный бот — ничего не меняется');
$GLOBALS['OWNER'] = [700 => 25];
check(crm_watch_takeover() >= 1 && dialog_taken(dialog_load($dlg)) === 25, 'менеджер #25 забрал — диалог помечен');
$GLOBALS['B24'] = [];
$out = say($dlg, 'а сколько стоит?');
$tl = array_values(array_filter($GLOBALS['B24'], fn($x) => $x[0] === 'crm.timeline.comment.add'));
check($out === [] && $tl && ($tl[0][1]['fields']['ENTITY_TYPE'] ?? '') === 'deal', 'бот молчит, сообщение клиента — в ленту сделки');
$GLOBALS['OWNER'] = [700 => 5];
crm_watch_takeover();
$out = say($dlg, 'ещё вопрос');
check($out !== [] && !dialog_taken(dialog_load($dlg)), 'вернули боту — бот снова отвечает');
set_setting('b24_bot_user', '0');
set_setting('b24_flow', '{}'); set_setting('b24_map_deal', '{}'); set_setting('b24_defaults_deal', '{}');

/* ---------- 3. Глобальные переходы, таймеры, отложено ---------- */
echo "\n3. Карты: напоминание через 15 мин, «позовите человека», отложено\n";
$GLOBALS['CLS'] = [
    'Нужна карта для поездок' => ['cards', ['cards.purpose' => 'путешествия', 'cards.product_id' => 'foreign_card']],
    'подумаю, напишите завтра в 10' => ['postpone', ['followup.at' => (new DateTimeImmutable('+1 day', app_tz()))->format('Y-m-d') . ' 10:00']],
];
$GLOBALS['TOOLS'] = [];
$dlg = new_dialog('web:cards1');
say($dlg, 'Нужна карта для поездок');
check(state($dlg) === 'cards', 'greeting → cards');
$n = timers_fast_forward($dlg, 16);
$msgs = dialog_messages($dlg);
check($n === 1 && str_contains(end($msgs)['content'], 'Оформляем'), 'через 15 минут — напоминание');
check(timers_fast_forward($dlg, 30) === 0, 'напоминание только один раз (max_fires)');
say($dlg, 'подумаю, напишите завтра в 10');
check(state($dlg) === 'postponed', 'cards → postponed');
$tm = db()->query("SELECT timer_id FROM timers WHERE dialog_id = $dlg AND active = 1")->fetchAll(PDO::FETCH_COLUMN);
check($tm === ['followup'], 'таймер followup поставлен на время из слота');
timers_fast_forward($dlg, 60 * 48); // «завтра в 10» — до 34 ч в зависимости от времени запуска
check(state($dlg) === 'cards', 'в назначенное время — напоминание и возврат в cards');
$msgs = dialog_messages($dlg);
check(str_contains(end($msgs)['content'], 'Вы просили напомнить'), 'текст напоминания из сценария');

echo "\n3б. «Позовите человека» из любого места\n";
$GLOBALS['CLS'] = ['можно с живым человеком поговорить?' => ['ask_human', []]];
$GLOBALS['B24'] = [];
say($dlg, 'можно с живым человеком поговорить?');
check(state($dlg) === 'handed_to_manager', 'глобальный ask_human → handed_to_manager');
check((dialog_load($dlg)['slots']['handoff_reason'] ?? '') === 'клиент попросил человека', 'причина передачи записана');
$cnt = db()->query("SELECT COUNT(*) FROM actions_log WHERE dialog_id = $dlg AND action = 'notify_manager'")->fetchColumn();
say($dlg, 'можно с живым человеком поговорить?');
$cnt2 = db()->query("SELECT COUNT(*) FROM actions_log WHERE dialog_id = $dlg AND action = 'notify_manager'")->fetchColumn();
check($cnt == 1 && $cnt2 == 1, 'повторная просьба не дублирует уведомление менеджеру');

echo "\n3в. Оператор написал — бот молчит 10 минут\n";
engine_operator_message($dlg, 'Здравствуйте, я менеджер Ольга');
$out = say($dlg, 'Спасибо');
check($out === [], 'бот на паузе после реплики оператора');

echo "\n4. Закрытый диалог: клиент пишет снова → greeting → visa\n";
$GLOBALS['CLS'] = ['А ещё визу в Италию можно?' => ['visa', ['visa.country' => 'Италия']]];
$c = client_by_key('web:hr1');
$dlg = current_dialog((int)$c['id'], 'web');
check(state($dlg) === 'closed', 'старый диалог соискателя закрыт');
say($dlg, 'А ещё визу в Италию можно?');
check(state($dlg) === 'visa', 'closed → greeting → visa в одном ходе');
$tr = db()->query("SELECT from_state || '→' || to_state FROM transitions WHERE dialog_id = $dlg ORDER BY id DESC LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
check($tr === ['greeting→visa', 'closed→greeting'], 'журнал переходов: ' . implode(', ', array_reverse($tr)));

echo "\n5. Песочница не пишет в Битрикс\n";
$GLOBALS['CLS'] = ['я на вакансию водителя, Сидоров Пётр, 89140000000, резюме нет' => ['jobs', ['jobs.position' => 'водитель', 'jobs.full_name' => 'Сидоров / Пётр / ', 'contact' => '89140000000', 'jobs.no_resume' => 'true']]];
$GLOBALS['B24'] = [];
$dlg = new_dialog('sandbox:1', 'sandbox');
say($dlg, 'я на вакансию водителя, Сидоров Пётр, 89140000000, резюме нет');
check(state($dlg) === 'closed', 'песочница: вакансия оформлена за один ход');
check($GLOBALS['B24'] === [], 'в Б24 ничего не ушло');
$dry = db()->query("SELECT COUNT(*) FROM actions_log WHERE dialog_id = $dlg AND result LIKE 'тестовый режим%'")->fetchColumn();
check($dry >= 3, 'в журнале видно, что ушло бы в Б24');

echo "\n" . ($fails ? "ПРОВАЛЕНО: $fails" : 'Все проверки пройдены') . "\n";
exec('rm -rf ' . escapeshellarg($tmp));
exit($fails ? 1 : 0);
