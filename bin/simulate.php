<?php
declare(strict_types=1);
/**
 * Прогон тестовых диалогов: модель играет клиента с заданным характером, бот отвечает настоящим движком.
 * Диалоги идут в канале «песочница»: в Битрикс24 не пишут, менеджерам не уходят, в списке «Диалоги» не видны.
 *
 *   sudo -u www-data php bin/simulate.php                     все персонажи, по одному разу
 *   sudo -u www-data php bin/simulate.php --persona=premium   один персонаж (коды — ниже, --list)
 *   sudo -u www-data php bin/simulate.php --runs=3            каждого по 3 раза (ответы модели разные)
 *   sudo -u www-data php bin/simulate.php --scenario=12       версия сценария по id (не обязательно активная)
 *   sudo -u www-data php bin/simulate.php --max-turns=12 --client-model=gpt-5-mini
 *
 * Итог: стенограммы и сводка в data/sim/<дата>/ (report.txt + по файлу на диалог) и в консоли.
 * Метрики: реплик бота до рекомендации и до счёта, вопросов до рекомендации, средняя/максимальная длина ответа,
 * ответов длиннее лимита, сколько раз сработало сокращение, где закончили.
 */
require __DIR__ . '/../lib/app.php';

const SIM_PERSONAS = [
    'premium' => [
        'title' => 'Путешественник, бюджет не важен, торопится',
        'first' => 'Добрый день, вы карты выпускаете?',
        'persona' => 'Ты часто ездишь за границу и платишь зарубежные подписки (ChatGPT и т.п.). У тебя iPhone. Деньги не проблема — хочешь «самое лучшее», с бизнес-залами. '
            . 'Пишешь коротко, с опечатками, без заглавных. Раздражаешься, если тебя долго расспрашивают («че ты меня грузишь»). Живёшь в селе под Иркутском. '
            . 'Если предложили хорошую премиальную карту — соглашаешься и просишь выставить счёт.',
    ],
    'budget' => [
        'title' => 'Экономный, только подписки',
        'first' => 'здравствуйте, мне нужна карта чтобы оплачивать chatgpt и spotify',
        'persona' => 'Тебе нужна карта только для оплаты зарубежных подписок, за границу не ездишь. Важна цена — спрашиваешь, нет ли дешевле, сравниваешь. '
            . 'Пишешь вежливо, средними фразами. Покупаешь, если цена разумная и объяснили, почему это лучший вариант для тебя.',
    ],
    'thorough' => [
        'title' => 'Обстоятельный, решает не сразу',
        'first' => 'Добрый вечер! Расскажите, пожалуйста, какие у вас есть зарубежные карты и чем они отличаются?',
        'persona' => 'Ты переезжаешь работать за границу на полгода, будешь получать зарплату в долларах на карту. Хочешь понять детали: комиссии, пополнение, снятие наличных. '
            . 'Пишешь развёрнуто и грамотно, задаёшь уточняющие вопросы. В конце говоришь, что подумаешь до завтра и просишь напомнить.',
    ],
    'vague' => [
        'title' => 'Не знает, что хочет',
        'first' => 'привет',
        'persona' => 'Ты зашёл посмотреть, что предлагает компания. Сначала ничего конкретного не говоришь. Если спросят — признаёшься, что летишь в Турцию в отпуск через месяц '
            . 'и боишься, что российская карта не сработает. Отвечаешь односложно. Соглашаешься, если предложение понятное.',
    ],
    'urgent' => [
        'title' => 'Срочно, улетает через 5 дней',
        'first' => 'нужна карта срочно, улетаю в дубай через 5 дней',
        'persona' => 'Ты улетаешь в Дубай через 5 дней, нужна карта для оплаты отеля и такси. Главное — успеть. Android. Пишешь отрывисто. '
            . 'Если по срокам не успевают — спрашиваешь, что можно сделать (виртуальная карта, срочный выпуск). Покупаешь самый быстрый вариант.',
    ],
    'green_card' => [
        'title' => 'Green Card, семья',
        'first' => 'Здравствуйте, хотим участвовать в лотерее грин кард, я и жена',
        'persona' => 'Ты с женой хочешь участвовать в DV-лотерее, двое детей. Не знаешь правил, боишься ошибиться в анкете. Спрашиваешь, сколько стоит и какие шансы. '
            . 'Готов заплатить за полное сопровождение, если объяснят понятно.',
    ],
    'visa' => [
        'title' => 'Виза (бот не продаёт, передаёт менеджеру)',
        'first' => 'Добрый день, нужна шенгенская виза в Италию, едем в мае вдвоём',
        'persona' => 'Ты гражданин РФ из Иркутска, с женой летите в Италию в мае на 10 дней туристами. Раньше был шенген в 2019. Подавать хотите в Москве или Новосибирске. '
            . 'Спрашиваешь, сколько стоит и какие документы. Отвечаешь на вопросы по одному.',
    ],
];

$args = getopt('', ['persona:', 'runs:', 'scenario:', 'max-turns:', 'client-model:', 'list']);
if (isset($args['list'])) { foreach (SIM_PERSONAS as $k => $p) echo str_pad($k, 12) . $p['title'] . "\n"; exit(0); }
$codes = isset($args['persona']) ? array_map('trim', explode(',', (string)$args['persona'])) : array_keys(SIM_PERSONAS);
foreach ($codes as $c) if (!isset(SIM_PERSONAS[$c])) { fwrite(STDERR, "Нет персонажа «{$c}». Список: --list\n"); exit(1); }
$runs = max(1, (int)($args['runs'] ?? 1));
$maxTurns = max(2, (int)($args['max-turns'] ?? 12));
$clientModel = (string)($args['client-model'] ?? cfg('chat_model', 'gpt-5-mini'));
$scenarioId = isset($args['scenario']) ? (int)$args['scenario'] : 0;
if ($scenarioId && !scenario_by_id($scenarioId)) { fwrite(STDERR, "Нет сценария #{$scenarioId}\n"); exit(1); }

$now = new DateTimeImmutable('now', app_tz());
$outDir = data_dir() . '/sim/' . $now->format('Y-m-d_His');
@mkdir($outDir, 0770, true);
$sc0 = $scenarioId ? scenario_by_id($scenarioId) : scenario_active();
$rs = reply_style($sc0) ?? ['max_chars' => 300];
$report = ["Прогон тестовых диалогов · сценарий «{$sc0['meta']['name']}» v{$sc0['meta']['version']} (#{$sc0['_id']}) · " . $now->format('d.m.Y H:i'), ''];
$rows = [];

/** Следующая реплика клиента-персонажа. null — клиент закончил разговор. */
function sim_client_reply(array $persona, array $transcript, string $model): ?string
{
    $sys = "Ты играешь КЛИЕНТА, который пишет в чат компании Milli Mind (зарубежные банковские карты, визы, Green Card). Ты не бот и не помощник.\n"
        . "Твой характер и задача: {$persona['persona']}\n\n"
        . "Пиши ОДНУ следующую реплику клиента — как живой человек в мессенджере, в своём стиле, обычно коротко. Не повторяй вопрос, на который уже ответили.\n"
        . "Если разговор по твоей задаче закончен (счёт выставлен, ты отказался, договорились созвониться, обращение передали менеджеру и тебе больше нечего спросить) — ответь ровно: [КОНЕЦ]";
    $msgs = [['role' => 'system', 'content' => $sys]];
    foreach ($transcript as [$who, $text]) $msgs[] = ['role' => $who === 'client' ? 'assistant' : 'user', 'content' => $text];
    $p = chat_payload($msgs);
    $p['model'] = $model;
    $res = openai('chat/completions', $p);
    $t = trim((string)($res['choices'][0]['message']['content'] ?? ''));
    return ($t === '' || str_contains($t, '[КОНЕЦ]')) ? null : $t;
}

$n = 0;
foreach ($codes as $code) {
    $persona = SIM_PERSONAS[$code];
    for ($run = 1; $run <= $runs; $run++) {
        $n++;
        $c = client_by_key('sandbox:sim-' . $code . '-' . uniqid());
        $dlg = current_dialog((int)$c['id'], 'sandbox', true);
        if ($scenarioId) { $d = dialog_load($dlg); $d['scenario_id'] = $scenarioId; $d['state'] = (string)$sc0['meta']['entry_state']; dialog_save($d); }
        echo "\n[{$n}] {$code} — {$persona['title']}" . ($runs > 1 ? " (прогон {$run})" : '') . " · диалог #{$dlg}\n";

        $transcript = [];
        $botLens = []; $botQ = []; $botTurn = 0;
        $offerAt = null; $invoiceAt = null;
        $clientText = $persona['first'];
        $err = '';
        for ($turn = 1; $turn <= $maxTurns && $clientText !== null; $turn++) {
            echo "  клиент: {$clientText}\n";
            $transcript[] = ['client', $clientText];
            try { $out = engine_message($dlg, $clientText); } catch (Throwable $e) { $err = $e->getMessage(); echo "  ! ошибка движка: {$err}\n"; break; }
            $d = dialog_load($dlg);
            foreach ($out as $i => $o) {
                if (str_starts_with($o, '[QR-код]')) continue;
                echo "  бот: {$o}\n";
                $transcript[] = ['bot', $o];
                if ($i === 0) { $botTurn++; $botLens[] = mb_strlen($o); $botQ[] = count_questions($o); }
            }
            if ($offerAt === null && array_filter(array_keys($d['counters']), fn($k) => str_starts_with($k, 'offered.'))) $offerAt = $botTurn;
            if ($invoiceAt === null && !empty($d['slots']['payment.invoice_id'])) $invoiceAt = $botTurn;
            if (in_array($d['state'], ['payment_wait', 'handed_to_manager', 'postponed', 'closed'], true) && $turn >= 2) {
                // даём клиенту одну реплику после финала (вопрос по оплате и т.п.), дальше — конец
                $clientText = sim_client_reply($persona, $transcript, $clientModel);
                if ($clientText !== null) {
                    echo "  клиент: {$clientText}\n";
                    $transcript[] = ['client', $clientText];
                    foreach (engine_message($dlg, $clientText) as $o) if (!str_starts_with($o, '[QR-код]')) { echo "  бот: {$o}\n"; $transcript[] = ['bot', $o]; $botLens[] = mb_strlen($o); $botQ[] = count_questions($o); }
                }
                break;
            }
            try { $clientText = sim_client_reply($persona, $transcript, $clientModel); } catch (Throwable $e) { $err = 'клиент: ' . $e->getMessage(); break; }
        }

        $d = dialog_load($dlg);
        $asked = 0;
        foreach ($d['counters'] as $k => $v) if (str_starts_with($k, 'asked_before_offer.')) $asked += (int)$v;
        $shortened = (int)db()->query("SELECT COUNT(*) FROM actions_log WHERE dialog_id = {$dlg} AND action = 'reply_shorten'")->fetchColumn();
        $over = count(array_filter($botLens, fn($l) => $l > $rs['max_chars']));
        $prof = profile_slot(scenario_for($d));
        $row = [
            'code' => $code, 'dialog' => $dlg, 'state' => $d['state'],
            'bot_msgs' => count($botLens),
            'avg' => $botLens ? (int)round(array_sum($botLens) / count($botLens)) : 0,
            'max' => $botLens ? max($botLens) : 0,
            'over' => $over, 'multi_q' => count(array_filter($botQ, fn($q) => $q > 1)),
            'asked' => $asked, 'offer_at' => $offerAt, 'invoice_at' => $invoiceAt, 'shortened' => $shortened,
            'profile' => $prof ? value_text($d['slots'][$prof] ?? null) : '', 'error' => $err,
        ];
        $rows[] = $row;
        file_put_contents(sprintf('%s/%02d_%s.txt', $outDir, $n, $code), dialog_export_txt($dlg));
    }
}

/* ---------- сводка ---------- */
$W = [11, 7, 18, 5, 6, 5, 6, 7, 8, 7, 5];
$line = function (array $cells) use ($W): string {
    $out = '';
    foreach ($cells as $i => $c) $out .= $i === 0 || $i === 2 ? mb_str_pad((string)$c, $W[$i]) : mb_str_pad((string)$c, $W[$i], ' ', STR_PAD_LEFT);
    return rtrim($out);
};
$report[] = $line(['персонаж', 'диалог', ' итог', 'отв.', 'сред.', 'макс', '>лим.', '2+вопр', 'вопр.до', 'рек.на', 'счёт']);
foreach ($rows as $r) {
    $report[] = $line([$r['code'], '#' . $r['dialog'], ' ' . mb_strimwidth(sc_state_title(scenario_for(dialog_load($r['dialog'])), $r['state']), 0, 16, '…'),
        $r['bot_msgs'], $r['avg'], $r['max'], $r['over'], $r['multi_q'], $r['asked'], $r['offer_at'] ?? '—', $r['invoice_at'] ?? '—']);
}
$report[] = '';
$report[] = "Колонки: отв. — ответов бота; сред./макс — длина ответа в символах; >лим. — ответов длиннее {$rs['max_chars']}; 2+вопр — ответов с двумя и более вопросами;";
$report[] = 'вопр.до — уточняющих вопросов до рекомендации; рек.на / счёт — на каком ответе бота названа карта / выставлен счёт.';
$report[] = '';
foreach ($rows as $r) {
    $extra = array_filter([
        $r['shortened'] ? "сокращений: {$r['shortened']}" : '',
        $r['profile'] !== '' ? "профиль: {$r['profile']}" : '',
        $r['error'] !== '' ? "ОШИБКА: {$r['error']}" : '',
    ]);
    if ($extra) $report[] = "#{$r['dialog']} {$r['code']}: " . implode(' · ', $extra);
}
$report[] = "\nСтенограммы: {$outDir}/  · карточки: " . public_url('admin/?tab=sandbox') . ' (диалоги песочницы)';
file_put_contents("$outDir/report.txt", implode("\n", $report) . "\n");
echo "\n" . implode("\n", $report) . "\n";
