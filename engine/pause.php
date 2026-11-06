<?php
declare(strict_types=1);

/**
 * Пауза продаж товара — общий механизм для любого товара каталога, без правки сценария.
 *
 * Товар: sales_status = open | paused | closed (≠ active: active — бот видит товар, sales_status — можно ли продавать),
 *        pause_notice  — объявление (уходит клиенту дословно, один раз за диалог),
 *        pause_details — подробности для ответов на вопросы во время паузы.
 *
 * Состояние → товары: по "knowledge" состояния ("catalog:<раздел>").
 * Клиент попал в состояние, где товар раздела не продаётся:
 *   1) движок отправляет объявление (без модели, слово в слово) — если в этом диалоге ещё не отправлял;
 *   2) диалог возвращается в выявление потребности (entry_state) с «контекстом паузы»:
 *      модель отвечает на вопросы по подробностям, не продаёт и спрашивает «Могу ли я ещё чем-то помочь?»;
 *      новый вопрос про этот товар → снова сюда (объявление уже не повторяется); другой продукт → обычная ветка;
 *   3) счёт и анкету по такому товару код не даст, что бы ни вызвала модель;
 *   4) клиент оставил контакт → лид «Лист ожидания» (стадия settings.waitlist_status_id).
 */

const SALES_STATUSES = ['open' => 'продажи открыты', 'paused' => 'приостановлены', 'closed' => 'закрыты'];

function product_sales_status(array $p): string
{
    $s = (string)($p['sales_status'] ?? ($p['params']['sales_status'] ?? 'open'));
    return isset(SALES_STATUSES[$s]) ? $s : 'open';
}

/** Товары разделов каталога, на которые опирается состояние. */
function state_products(array $T, string $state): array
{
    $secs = [];
    foreach ((array)(sc_state($T['sc'], $state)['knowledge'] ?? []) as $ref) {
        if (is_string($ref) && str_starts_with($ref, 'catalog:')) $secs[] = normalize_section(substr($ref, 8));
    }
    if (!$secs) return [];
    $out = [];
    foreach (catalog_all(true) as $p) foreach ($secs as $s) if ($p['section'] === $s || str_starts_with($p['section'], $s . '/')) $out[$p['id']] = $p;
    return $out;
}

function paused_products_for_state(array $T, string $state): array
{
    return array_filter(state_products($T, $state), fn($p) => product_sales_status($p) !== 'open');
}

/** Товары текущего «контекста паузы» (по которым клиент спрашивал). */
function pause_ctx_products(array $T): array
{
    $ids = array_filter(explode(',', (string)($T['d']['counters']['pause_ctx'] ?? '')));
    $out = [];
    foreach ($ids as $id) if (($p = catalog_get($id)) && product_sales_status($p) !== 'open') $out[$id] = $p;
    return $out;
}

/** Почему по товару нельзя выставить счёт / дать анкету. null — можно. */
function product_sale_blocked(string $productId): ?string
{
    $p = catalog_get($productId);
    if (!$p) return null;
    $st = product_sales_status($p);
    if ($st === 'open') return null;
    return 'продажи «' . ($p['name'] ?? $productId) . '» ' . SALES_STATUSES[$st] . ' — счёт и анкету по нему выдавать нельзя. Не продавай, ответь по подробностям паузы.';
}

/**
 * После переходов хода: если текущее состояние опирается на товар не на продаже —
 * объявление (один раз) и возврат в выявление потребности с контекстом паузы.
 */
function pause_check(array &$T): void
{
    $sc = $T['sc'];
    $state = $T['d']['state'];
    $entry = (string)($sc['meta']['entry_state'] ?? 'greeting');
    $paused = paused_products_for_state($T, $state);
    if (!$paused) {
        // ушли в другую ветку продаж — контекст паузы больше не нужен
        if ($state !== $entry && sc_state_type($sc, $state) === 'conversation') unset($T['d']['counters']['pause_ctx']);
        return;
    }
    foreach ($paused as $id => $p) {
        $key = 'notice_shown.' . $id;
        if (!empty($T['d']['counters'][$key])) continue;
        $notice = trim((string)($p['pause_notice'] ?? ''));
        if ($notice === '') $notice = 'Продажи «' . ($p['name'] ?? $id) . '» сейчас ' . SALES_STATUSES[product_sales_status($p)] . '.';
        $T['notice'][] = $notice;
        $T['d']['counters'][$key] = 1;
        action_log($T, 'pause_notice', ['товар' => $id], 'отправлено объявление о паузе');
    }
    $T['d']['counters']['pause_ctx'] = implode(',', array_keys($paused));
    unset($T['d']['slots']['intent']); // следующий вопрос снова распределится из выявления потребности
    if ($state !== $entry && isset($sc['states'][$entry])) {
        transition($T, $entry, 'товар на паузе: ' . implode(', ', array_map(fn($p) => $p['name'] ?? $p['id'], $paused)), false);
        $T['reply_state'] = null;
    }
}

/** Блок для промпта ответчика — выше инструкций этапа. */
function pause_prompt_block(array $products): string
{
    $L = [];
    foreach ($products as $p) {
        $name = (string)($p['name'] ?? $p['id']);
        $L[] = "## ВАЖНЕЕ ВСЕГО: товар «{$name}» " . SALES_STATUSES[product_sales_status($p)];
        $L[] = 'Объявление клиенту уже отправлено — не повторяй и не пересказывай его. Этапы продажи не выполняй: тарифы, цены, анкету и счёт не предлагай. '
            . 'На вопросы отвечай по разделу «Подробности»; чего там нет — скажи, что сообщим, когда появятся новости, дат не придумывай. '
            . 'Предложи сообщить о возобновлении и уточни контакт для связи (если его ещё нет). Заверши вопросом, может ли клиенту помочь ещё чем-то — по другим услугам.';
        $det = trim((string)($p['pause_details'] ?? ''));
        $L[] = 'Подробности: ' . ($det !== '' ? $det : 'дополнительных сведений нет.');
    }
    return implode("\n", $L);
}

/** Контакт оставлен во время паузы → лид «Лист ожидания» (один раз за диалог). */
function pause_waitlist(array &$T): void
{
    $products = pause_ctx_products($T);
    if (!$products || !empty($T['d']['counters']['waitlist'])) return;
    $s = $T['d']['slots'];
    if (empty($s['contact']) && empty($s['phone']) && empty($s['client.email'])) return; // ждём, пока клиент сам оставит контакт
    $names = implode(', ', array_map(fn($p) => (string)($p['name'] ?? $p['id']), $products));
    $c = get_client((int)$T['d']['client_id']);
    $fields = [
        'TITLE' => crm_title_prefix($T) . 'Лист ожидания: ' . $names . ' — ' . client_display_name($c),
        'COMMENTS' => 'Лист ожидания: ' . $names,
    ];
    $st = sc_setting($T['sc'], 'waitlist_status_id');
    if (is_string($st) && $st !== '' && !is_todo($st)) $fields['STATUS_ID'] = $st;
    else action_log($T, 'waitlist', [], 'settings.waitlist_status_id не задан — лид без стадии листа ожидания');
    $T['d']['counters']['waitlist'] = 1;
    act_b24_update_lead($T, ['fields' => $fields]);
}
