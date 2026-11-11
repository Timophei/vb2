<?php
declare(strict_types=1);

/**
 * «Чем ещё?» — универсальный выход из любой темы (облом, отказ, тема закрыта).
 *
 * Состояние сценария `anything_else` (имя можно сменить: settings.anything_else_state).
 * При входе движок запоминает, откуда пришли (counters):
 *   origin_state   — состояние темы (для справки из её базы знаний),
 *   origin_product — продукт (значение слота intent),
 *   origin_reason  — с чем ушли (товар на паузе / отказ / тема закрыта …).
 * Слот intent очищается — новая задача клиента распределится заново.
 *
 * Правила:
 *   - вопросы по прежней теме — отвечаем здесь, назад не гоняем;
 *   - прежний продукт недоступен (пауза) — остаёмся здесь, объявление не повторяем;
 *   - прежний продукт доступен — обычный переход (новая покупка);
 *   - другой продукт — переход, новая сделка/лид (контакт тот же), прежняя тема забывается.
 */

function ae_state(array $sc): ?string
{
    $name = (string)(sc_setting($sc, 'anything_else_state') ?? 'anything_else');
    return isset($sc['states'][$name]) ? $name : null;
}

/** Вход в «Чем ещё?»: запомнить, откуда пришли. */
function ae_on_enter(array &$T, string $from, string $reason): void
{
    $c = &$T['d']['counters'];
    if (empty($T['origin_set'])) { // пауза уже записала origin сама
        $c['origin_state'] = $from;
        $c['origin_product'] = (string)($T['d']['slots']['intent'] ?? '');
        $c['origin_reason'] = $reason;
    }
    $c['ae_entered'] = 1; // ход входа: короткая фраза «чем ещё помочь»
    unset($T['d']['slots']['intent']);
}

/** Выход из «Чем ещё?» в другую тему: прежнюю забываем; другой продукт — новая сделка/лид. */
function ae_on_leave(array &$T, string $to): void
{
    $c = &$T['d']['counters'];
    $new = (string)($T['d']['slots']['intent'] ?? '');
    if ($new !== '' && $new !== (string)($c['origin_product'] ?? '')) {
        $T['d']['deal_id'] = 0;
        $T['d']['lead_id'] = 0;
        unset($c['dry:deal'], $c['deal_stage'], $c['deal_contact'], $c['waitlist']);
    }
    unset($c['origin_state'], $c['origin_product'], $c['origin_reason'], $c['pause_ctx'], $c['ae_entered']);
}

/**
 * Переход в состояние, чей товар не продаётся: вместо него — «Чем ещё?» (или приветствие, если такого состояния нет).
 * Объявление — если в этом диалоге ещё не отправлялось. Возвращает новую цель или null (товар продаётся).
 */
function pause_redirect(array &$T, string $to): ?string
{
    $paused = paused_products_for_state($T, $to);
    if (!$paused) return null;
    foreach ($paused as $id => $p) {
        $key = 'notice_shown.' . $id;
        if (!empty($T['d']['counters'][$key])) continue;
        $notice = trim((string)($p['pause_notice'] ?? ''));
        if ($notice === '') $notice = 'Продажи «' . ($p['name'] ?? $id) . '» сейчас ' . SALES_STATUSES[product_sales_status($p)] . '.';
        $T['notice'][] = $notice;
        $T['d']['counters'][$key] = 1;
        action_log($T, 'pause_notice', ['товар' => $id], 'отправлено объявление о паузе');
    }
    $c = &$T['d']['counters'];
    $c['pause_ctx'] = implode(',', array_keys($paused));
    $c['origin_state'] = $to;
    $c['origin_product'] = (string)($T['d']['slots']['intent'] ?? '');
    $c['origin_reason'] = 'продажи «' . implode(', ', array_map(fn($p) => (string)($p['name'] ?? $p['id']), $paused)) . '» ' . SALES_STATUSES[product_sales_status(reset($paused))];
    $T['origin_set'] = true;
    unset($T['d']['slots']['intent']);
    $target = ae_state($T['sc']) ?? (string)($T['sc']['meta']['entry_state'] ?? 'greeting');
    if ($T['d']['state'] === $target) action_log($T, 'pause_stay', ['товар' => array_keys($paused)], 'товар не продаётся — остаёмся в «' . sc_state_title($T['sc'], $target) . '», отвечаем на месте');
    return $target;
}

/** Блок промпта для «Чем ещё?»: откуда пришли и как отвечать. */
function ae_prompt_block(array $T, bool $justEntered): string
{
    $c = $T['d']['counters'];
    $L = ['## Откуда пришли'];
    if (!empty($c['origin_state'])) {
        $L[] = 'Предыдущая тема: «' . sc_state_title($T['sc'], (string)$c['origin_state']) . '». Итог: ' . ((string)($c['origin_reason'] ?? '') ?: 'тема закрыта') . '.';
        $L[] = 'Вопросы по этой теме отвечай здесь, коротко и только по фактам (подробности, справка из базы знаний). К продаже по ней не возвращайся, сказанное не повторяй.';
    } else {
        $L[] = 'Предыдущая тема закрыта.';
    }
    if ($justEntered) {
        $L[] = 'Сейчас твоё сообщение завершает тему: 1–2 коротких предложения. Без приветствия и без пересказа предыдущих сообщений. Спроси, можешь ли помочь чем-то ещё; можно предположить одну смежную потребность — уместно, без навязывания.';
    }
    $L[] = 'Услуги компании: ' . implode(', ', array_values(array_filter(array_map(fn($p) => product_sales_status($p) === 'open' ? (string)($p['name'] ?? '') : '', catalog_all(true))))) . '.';
    return implode("\n", $L);
}
