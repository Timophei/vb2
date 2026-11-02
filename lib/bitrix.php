<?php
declare(strict_types=1);

/**
 * Битрикс24 через входящий вебхук: лиды, смарт-процессы, таймлайн.
 *
 * Режимы (config.php → bitrix_mode):
 *   live — запросы уходят в портал;
 *   dry  — ничего не отправляется, в журнал действий пишется, что бы ушло.
 * Песочница всегда работает в dry, если в админке не включено «песочница пишет в Б24».
 */

function b24_configured(): bool
{
    return trim((string)cfg('bitrix_webhook', '')) !== '';
}

/** Пишем ли по-настоящему для этого канала. */
function b24_live(string $channel): bool
{
    if (!b24_configured() || cfg('bitrix_mode', 'dry') !== 'live') return false;
    if ($channel === 'sandbox') return setting('b24_sandbox_live') === '1';
    return true;
}

function b24(string $method, array $params): array
{
    if (function_exists('b24_mock')) return b24_mock($method, $params); // тесты
    $base = rtrim((string)cfg('bitrix_webhook', ''), '/');
    if ($base === '') throw new RuntimeException('Не задан bitrix_webhook в config.php');
    $ch = curl_init($base . '/' . $method . '.json');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE),
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new RuntimeException("Битрикс24 $method: нет соединения: $err");
    $data = json_decode((string)$raw, true);
    if (!is_array($data) || isset($data['error'])) {
        $msg = is_array($data) ? ($data['error'] . ': ' . ($data['error_description'] ?? '')) : substr((string)$raw, 0, 300);
        throw new RuntimeException("$method (HTTP $code): $msg");
    }
    return $data;
}

function b24_portal(): string
{
    $u = parse_url((string)cfg('bitrix_webhook', ''));
    return isset($u['host']) ? ($u['scheme'] ?? 'https') . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '') : '';
}

function b24_lead_url(int $id): string { return b24_portal() . '/crm/lead/details/' . $id . '/'; }
function b24_item_url(int $entityTypeId, int $id): string { return b24_portal() . '/crm/type/' . $entityTypeId . '/details/' . $id . '/'; }

/** Блок бота в комментарии лида: обновляется на месте, заметки менеджеров не трогает. */
function b24_merge_comments(string $comments, string $block): string
{
    $start = '=== Бот vb2 ===';
    $end = '=== /бот ===';
    $new = $start . "\n" . trim($block) . "\n" . $end;
    $comments = str_replace(["<br>", "<br/>", "<br />"], "\n", $comments);
    if (str_contains($comments, $start) && str_contains($comments, $end)) {
        return preg_replace('/' . preg_quote($start, '/') . '.*?' . preg_quote($end, '/') . '/su', addcslashes($new, '\\$'), $comments, 1);
    }
    return trim($comments . "\n\n" . $new);
}

/** Справочник для настройки: стадии лидов, воронки и стадии смарт-процесса. */
function b24_reference(int $smartTypeId): array
{
    $out = ['scope' => [], 'lead_statuses' => [], 'smart' => null, 'smart_categories' => [], 'errors' => []];
    try { $out['scope'] = b24('scope', [])['result'] ?? []; } catch (Throwable $e) { $out['errors'][] = $e->getMessage(); return $out; }
    try {
        foreach (b24('crm.status.list', ['filter' => ['ENTITY_ID' => 'STATUS'], 'order' => ['SORT' => 'ASC']])['result'] ?? [] as $s) {
            $out['lead_statuses'][] = ['id' => $s['STATUS_ID'], 'name' => $s['NAME'], 'semantics' => $s['EXTRA']['SEMANTICS'] ?? ''];
        }
    } catch (Throwable $e) { $out['errors'][] = $e->getMessage(); }
    if ($smartTypeId > 0) {
        try {
            $t = b24('crm.type.get', ['id' => 0, 'entityTypeId' => $smartTypeId]);
            $out['smart'] = $t['result']['type'] ?? null;
        } catch (Throwable $e) {
            try {
                foreach (b24('crm.type.list', [])['result']['types'] ?? [] as $t) if ((int)$t['entityTypeId'] === $smartTypeId) $out['smart'] = $t;
            } catch (Throwable $e2) { $out['errors'][] = $e2->getMessage(); }
        }
        try {
            foreach (b24('crm.category.list', ['entityTypeId' => $smartTypeId])['result']['categories'] ?? [] as $c) {
                $stages = [];
                try {
                    foreach (b24('crm.status.list', ['filter' => ['ENTITY_ID' => 'DYNAMIC_' . $smartTypeId . '_STAGE_' . $c['id']], 'order' => ['SORT' => 'ASC']])['result'] ?? [] as $s) {
                        $stages[] = ['id' => $s['STATUS_ID'], 'name' => $s['NAME']];
                    }
                } catch (Throwable $e) { $out['errors'][] = $e->getMessage(); }
                $out['smart_categories'][] = ['id' => (int)$c['id'], 'name' => $c['name'], 'default' => ($c['isDefault'] ?? 'N') === 'Y', 'stages' => $stages];
            }
        } catch (Throwable $e) { $out['errors'][] = $e->getMessage(); }
    }
    return $out;
}
