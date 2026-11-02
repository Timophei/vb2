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
        $rules = ['ваканс' => 'jobs', 'работ' => 'jobs', 'виз' => 'visa', 'карт' => 'cards', 'грин' => 'green_card', 'green' => 'green_card', 'человек' => 'ask_human', 'менеджер' => 'ask_human', 'подумаю' => 'postpone'];
        foreach ($rules as $k => $v) if (str_contains($last, $k)) { $intent = $v; break; }
        if (preg_match('/ваканси[юи]\s+([\p{L} ]+?)(?:[,.]|$)/u', $last, $m)) $slots[] = ['name' => 'jobs.position', 'value' => trim($m[1])];
        if (preg_match('/(\+?\d[\d\s\-()]{9,})/u', $last, $m)) $slots[] = ['name' => 'contact', 'value' => $m[1]];
        if (preg_match('/меня зовут ([\p{L}]+) ([\p{L}]+)/u', $last, $m)) $slots[] = ['name' => 'jobs.full_name', 'value' => "$m[2] / $m[1] / "];
        if (str_contains($last, 'резюме нет')) $slots[] = ['name' => 'jobs.no_resume', 'value' => 'true'];
        return ['choices' => [['message' => ['content' => json_encode(['intent' => $intent, 'slots' => $slots], JSON_UNESCAPED_UNICODE)]]], 'usage' => ['total_tokens' => 150]];
    }
    preg_match('/## Текущий этап: (.+)/u', $p['messages'][0]['content'], $m);
    return ['choices' => [['message' => ['content' => "(тестовый ответ без модели) Этап: " . ($m[1] ?? '?') . ". Спасибо за сообщение!"]]], 'usage' => ['total_tokens' => 900]];
}
