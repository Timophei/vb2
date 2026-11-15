<?php
declare(strict_types=1);

/**
 * Оценка качества диалога другой моделью («судья»).
 * Критерии — settings.quality_rubric сценария (правятся в админке) или JUDGE_RUBRIC по умолчанию.
 * Судья видит стенограмму, сценарные правила и каталог разделов диалога; возвращает баллы 1–5 по критериям
 * (0 — критерий неприменим), общий балл, худшую реплику бота и советы, что поправить в сценарии.
 * Результат пишется в журнал действий диалога (action = judge) — его видно в карточке.
 *
 * Модель: cfg('judge_model') (лучше сильнее, чем у бота), иначе chat_model; reasoning — cfg('judge_reasoning_effort', 'medium').
 */

const JUDGE_RUBRIC = [
    ['code' => 'greeting', 'title' => 'Вежливость', 'check' => 'Поздоровался на первое сообщение, тон дружелюбный и человеческий, без сухости и грубости.'],
    ['code' => 'listening', 'title' => 'Слышит клиента', 'check' => 'Отвечает по сути на заданный вопрос, прежде чем вести дальше; не переспрашивает уже сказанное; учитывает все требования клиента.'],
    ['code' => 'qualification', 'title' => 'Квалификация', 'check' => 'До рекомендации выяснил цель и главный приоритет клиента (что важнее, где проблема острее); не анкетирует подряд; не спешит к продаже, но и не тянет лишними вопросами.'],
    ['code' => 'recommendation', 'title' => 'Рекомендация', 'check' => '1–2 варианта: основной — с акцентом и причиной под приоритет клиента, второй — чем отличается; варианты действительно подходят под сказанное клиентом. 0 — если до рекомендации дело не дошло.'],
    ['code' => 'accuracy', 'title' => 'Точность', 'check' => 'Цены, сроки, условия и возможности банков совпадают с каталогом; ничего не выдумано; неизвестное не выдаётся за факт.'],
    ['code' => 'brevity', 'title' => 'Краткость', 'check' => 'Без воды, повторов и пересказа слов клиента; не больше одного вопроса за сообщение; длинно — только когда раскрываются цена и условия.'],
    ['code' => 'formatting', 'title' => 'Оформление', 'check' => 'Абзацы по смыслу, перечисления списком с новой строки (не через «;»), вопрос последним абзацем.'],
    ['code' => 'hygiene', 'title' => 'Чистота', 'check' => 'Нет пояснений о своих рассуждениях и правилах (в т.ч. в скобках), внутренних кодов и служебных слов; анкету и счёт не предлагает до выбора и согласия клиента.'],
];

function judge_rubric(array $sc): array
{
    $r = $sc['settings']['quality_rubric'] ?? null;
    if (!is_array($r) || !$r) return JUDGE_RUBRIC;
    $out = [];
    foreach ($r as $x) if (is_array($x) && ($x['code'] ?? '') !== '' && ($x['check'] ?? '') !== '') $out[] = ['code' => (string)$x['code'], 'title' => (string)($x['title'] ?? $x['code']), 'check' => (string)$x['check']];
    return $out ?: JUDGE_RUBRIC;
}

/**
 * @return array{overall: float, scores: array<string, array{score:int, comment:string}>, summary: string, worst: string, fixes: string[], model: string}
 */
function judge_dialog(int $dialogId, ?string $model = null): array
{
    $d = dialog_load($dialogId);
    $sc = scenario_for($d);
    $rubric = judge_rubric($sc);
    $model ??= (string)(cfg('judge_model') ?: cfg('chat_model', 'gpt-5-mini'));

    // разделы каталога, которые затрагивал диалог: по состояниям, через которые он прошёл
    $st = db()->prepare('SELECT DISTINCT to_state FROM transitions WHERE dialog_id = ?');
    $st->execute([$dialogId]);
    $sections = [];
    foreach (array_merge($st->fetchAll(PDO::FETCH_COLUMN), [$d['state']]) as $s) {
        foreach ((array)(sc_state($sc, (string)$s)['knowledge'] ?? []) as $ref) if (str_starts_with((string)$ref, 'catalog:')) $sections[] = substr((string)$ref, 8);
    }
    $catalog = catalog_prompt(array_values(array_unique($sections)));

    $rules = array_map(fn($r) => '- ' . $r, (array)($sc['global_prompt_rules'] ?? []));
    $crit = array_map(fn($c) => "- {$c['code']} ({$c['title']}): {$c['check']}", $rubric);
    $sys = "Ты — строгий эксперт по продажам и качеству чат-ботов. Оцени работу бота-консультанта компании в диалоге с клиентом.\n"
        . "Оценивай только реплики БОТА (не менеджера и не клиента). Будь конкретен: ссылайся на реплики.\n\n"
        . "## Критерии (балл 1–5: 5 — отлично, 3 — терпимо, 1 — плохо; 0 — критерий в этом диалоге неприменим)\n" . implode("\n", $crit) . "\n\n"
        . "## Правила, которые бот должен соблюдать (из сценария)\n" . implode("\n", $rules) . "\n"
        . ($catalog !== '' ? "\n## Каталог — для проверки точности\n" . $catalog . "\n" : '')
        . "\n## Что вернуть\n"
        . "scores — по каждому критерию балл и короткий комментарий (что именно не так или почему хорошо);\n"
        . "overall — общий балл 1–5 (как оценил бы руководитель отдела продаж);\n"
        . "summary — 1–2 фразы: главное впечатление;\n"
        . "worst — самая неудачная реплика бота (короткая цитата) и почему, или пусто;\n"
        . "fixes — 1–3 конкретных совета, что поправить в инструкциях бота (формулировкой правила), чтобы стало лучше.";

    $codes = array_column($rubric, 'code');
    $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['scores', 'overall', 'summary', 'worst', 'fixes'], 'properties' => [
        'scores' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'score', 'comment'], 'properties' => [
            'code' => ['type' => 'string', 'enum' => $codes], 'score' => ['type' => 'integer', 'enum' => [0, 1, 2, 3, 4, 5]], 'comment' => ['type' => 'string'],
        ]]],
        'overall' => ['type' => 'integer', 'enum' => [1, 2, 3, 4, 5]],
        'summary' => ['type' => 'string'], 'worst' => ['type' => 'string'],
        'fixes' => ['type' => 'array', 'items' => ['type' => 'string']],
    ]];
    $payload = chat_payload([['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => "Стенограмма диалога:\n\n" . dialog_export_txt($dialogId)]]);
    $payload['model'] = $model;
    if (preg_match('/^(gpt-5|o\d)/', $model)) $payload['reasoning_effort'] = (string)cfg('judge_reasoning_effort', 'medium');
    else unset($payload['reasoning_effort']);
    $payload['response_format'] = ['type' => 'json_schema', 'json_schema' => ['name' => 'dialog_quality', 'strict' => true, 'schema' => $schema]];

    $T = turn_init($d, 0);
    $t0 = microtime(true);
    try {
        $res = openai('chat/completions', $payload, 300);
    } catch (Throwable $e) {
        llm_log($T, 'judge', $payload, '', 0, (int)((microtime(true) - $t0) * 1000), $e->getMessage());
        throw $e;
    }
    $content = trim((string)($res['choices'][0]['message']['content'] ?? ''));
    llm_log($T, 'judge', $payload, $content, (int)($res['usage']['total_tokens'] ?? 0), (int)((microtime(true) - $t0) * 1000));
    $j = json_decode($content, true);
    if (!is_array($j)) throw new RuntimeException('судья вернул не JSON');

    $scores = [];
    foreach ((array)($j['scores'] ?? []) as $s) $scores[(string)$s['code']] = ['score' => (int)$s['score'], 'comment' => (string)$s['comment']];
    $out = ['overall' => (float)($j['overall'] ?? 0), 'scores' => $scores, 'summary' => (string)($j['summary'] ?? ''), 'worst' => (string)($j['worst'] ?? ''),
        'fixes' => array_values(array_map('strval', (array)($j['fixes'] ?? []))), 'model' => $model];
    action_log($T, 'judge', ['model' => $model, 'overall' => $out['overall']], judge_text($out, $rubric));
    return $out;
}

/** Текст оценки для журнала и отчёта. */
function judge_text(array $j, array $rubric): string
{
    $L = ["Оценка: {$j['overall']}/5 ({$j['model']}) — {$j['summary']}"];
    foreach ($rubric as $c) {
        $s = $j['scores'][$c['code']] ?? null;
        if (!$s) continue;
        $L[] = '- ' . $c['title'] . ': ' . ($s['score'] ? $s['score'] . '/5' : 'н/п') . ($s['comment'] !== '' ? ' — ' . $s['comment'] : '');
    }
    if ($j['worst'] !== '') $L[] = 'Худшая реплика: ' . $j['worst'];
    foreach ($j['fixes'] as $f) $L[] = 'Совет: ' . $f;
    return implode("\n", $L);
}

/** Средний балл по применимым критериям. */
function judge_avg(array $j): float
{
    $v = array_filter(array_column($j['scores'], 'score'));
    return $v ? round(array_sum($v) / count($v), 1) : 0.0;
}
