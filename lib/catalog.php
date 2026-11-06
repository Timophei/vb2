<?php
declare(strict_types=1);

/**
 * Каталог товаров и услуг.
 *
 * Товар: id, section (cards / green_card / visa …), name, currency, price_per (order | participant),
 *   variants [{id, name, price, term, includes}] — выбирается один,
 *   options  [{id, name, type: addon|choice, price_delta, values}] — можно несколько,
 *   params   {…} — произвольные параметры (период регистрации, акция…),
 *   bot_info — текст для бота, sale_mode: bot | manager.
 *
 * Правило: сумму счёта считает код (catalog_price), а не модель. Цена null — «уточнит менеджер».
 */

function catalog_seed_if_empty(): void
{
    if ((int)db()->query('SELECT COUNT(*) FROM catalog')->fetchColumn() > 0) return;
    $file = BOT_ROOT . '/scenarios/catalog_milli_mind.json';
    if (!is_file($file)) return;
    $data = json_decode((string)file_get_contents($file), true);
    foreach ($data['products'] ?? [] as $i => $p) {
        if (!empty($p['id'])) catalog_save($p, ($i + 1) * 10);
    }
}

function catalog_all(bool $onlyActive = false): array
{
    catalog_seed_if_empty();
    $rows = db()->query('SELECT * FROM catalog ORDER BY sort, id')->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $p = jdec($r['data']);
        $p['id'] = $r['id'];
        $p['section'] = $r['section'];
        $p['active'] = (bool)$r['active'];
        $p['_sort'] = (int)$r['sort'];
        $p['_updated'] = $r['updated_at'];
        $p['sales_status'] = product_sales_status($p); // старые записи: open, или из params.sales_status
        if ($onlyActive && !$p['active']) continue;
        $out[$r['id']] = $p;
    }
    return $out;
}

function catalog_get(string $id): ?array
{
    return catalog_all()[$id] ?? null;
}

function catalog_save(array $p, ?int $sort = null): void
{
    $id = trim((string)($p['id'] ?? ''));
    if (!preg_match('/^[a-z0-9_]{2,40}$/', $id)) throw new RuntimeException('Код товара: латиница, цифры и _ (2–40 символов).');
    $p['variants'] = array_values(array_filter($p['variants'] ?? [], fn($v) => trim((string)($v['id'] ?? '')) !== ''));
    $p['options'] = array_values(array_filter($p['options'] ?? [], fn($o) => trim((string)($o['id'] ?? '')) !== ''));
    foreach ($p['variants'] as &$v) {
        $v['price'] = price_or_null($v['price'] ?? null);
        if (isset($v['consular_fee'])) $v['consular_fee'] = price_or_null($v['consular_fee']);
    }
    unset($v);
    foreach ($p['options'] as &$o) $o['price_delta'] = price_or_null($o['price_delta'] ?? null);
    unset($o);
    $p['sales_status'] = product_sales_status($p);
    unset($p['params']['sales_status']);
    $data = $p;
    unset($data['id'], $data['section'], $data['active'], $data['_sort'], $data['_updated']);
    $sort ??= (int)($p['_sort'] ?? 100);
    db()->prepare("INSERT INTO catalog(id, section, data, active, sort, updated_at) VALUES(?,?,?,?,?,datetime('now'))
                   ON CONFLICT(id) DO UPDATE SET section = excluded.section, data = excluded.data, active = excluded.active, sort = excluded.sort, updated_at = excluded.updated_at")
        ->execute([$id, normalize_section((string)($p['section'] ?? 'general')), jenc($data), !empty($p['active']) ? 1 : 0, $sort]);
}

function catalog_delete(string $id): void
{
    db()->prepare('DELETE FROM catalog WHERE id = ?')->execute([$id]);
}

function price_or_null($v): ?float
{
    if ($v === null) return null;
    $s = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim((string)$v));
    if ($s === '' || !is_numeric($s)) return null;
    return (float)$s;
}

function money_fmt(?float $v, string $cur = 'RUB'): string
{
    if ($v === null) return 'цена уточняется';
    $sym = ['RUB' => '₽', 'USD' => '$', 'EUR' => '€'][$cur] ?? $cur;
    $n = number_format($v, fmod($v, 1.0) ? 2 : 0, ',', ' ');
    return $cur === 'USD' ? "$sym$n" : "$n $sym";
}

/**
 * Расчёт суммы: (цена варианта + надбавки опций) × количество (если price_per = participant).
 * @return array{amount: float, currency: string, description: string}
 */
function catalog_price(string $productId, string $variantId = '', array $optionIds = [], int $qty = 1): array
{
    $p = catalog_get($productId);
    if (!$p || !$p['active']) throw new RuntimeException("товар «{$productId}» не найден или выключен");
    if (($p['sale_mode'] ?? 'bot') === 'manager') throw new RuntimeException('этот товар продаёт менеджер, счёт бот не выставляет');
    $variants = array_column($p['variants'] ?? [], null, 'id');
    if ($variantId === '' && count($variants) === 1) $variantId = (string)array_key_first($variants);
    $v = $variants[$variantId] ?? null;
    if (!$v) throw new RuntimeException('не выбран вариант (тариф). Доступны: ' . implode(', ', array_keys($variants)));
    if ($v['price'] === null) throw new RuntimeException("цена варианта «{$v['name']}» не задана в каталоге — стоимость уточнит менеджер");
    $amount = (float)$v['price'];
    $desc = [$p['name'] . ' — ' . $v['name']];
    $opts = array_column($p['options'] ?? [], null, 'id');
    foreach (array_unique($optionIds) as $oid) {
        $o = $opts[$oid] ?? null;
        if (!$o) throw new RuntimeException("опция «{$oid}» не найдена");
        if (($o['type'] ?? 'addon') === 'addon') {
            if ($o['price_delta'] === null) throw new RuntimeException("цена опции «{$o['name']}» не задана — уточнит менеджер");
            $amount += (float)$o['price_delta'];
        }
        $desc[] = '+ ' . $o['name'];
    }
    $qty = max(1, $qty);
    if (($p['price_per'] ?? 'order') === 'participant') {
        $amount *= $qty;
        if ($qty > 1) $desc[] = "× $qty участн.";
    }
    $cur = (string)($v['currency'] ?? ($p['currency'] ?? 'RUB'));
    return ['amount' => round($amount, 2), 'currency' => $cur, 'description' => implode(' ', $desc)];
}

/** Убирает незаполненные значения «TODO…» — бот их не должен видеть. */
function strip_todo($v)
{
    if (is_string($v)) return str_starts_with(ltrim($v), 'TODO') ? null : $v;
    if (!is_array($v)) return $v;
    $out = [];
    foreach ($v as $k => $x) {
        $x = strip_todo($x);
        if ($x === null && !in_array($k, ['price', 'price_delta'], true)) continue;
        $out[$k] = $x;
    }
    return $out;
}

/** Текст каталога раздела для промта ответчика. */
function catalog_prompt(array $sections): string
{
    if (!$sections) return '';
    $secs = array_map('normalize_section', $sections);
    $out = [];
    foreach (catalog_all(true) as $p) {
        if (product_sales_status($p) !== 'open') continue; // приостановленный товар бот не продаёт — тарифов и цен не видит
        $p = strip_todo($p);
        $match = false;
        foreach ($secs as $s) if ($p['section'] === $s || str_starts_with($p['section'], $s . '/')) $match = true;
        if (!$match) continue;
        $cur = (string)($p['currency'] ?? 'RUB');
        $L = ['### ' . ($p['name'] ?? $p['id']) . " (код: {$p['id']})"];
        if (($p['sale_mode'] ?? 'bot') === 'manager') $L[] = 'Продаёт менеджер: бот консультирует и передаёт, счёт не выставляет.';
        $L[] = 'Цена указана ' . (($p['price_per'] ?? 'order') === 'participant' ? 'за каждого участника' : 'за заказ') . '.';
        if (!empty($p['variants'])) {
            $L[] = 'Варианты (выбирается один):';
            foreach ($p['variants'] as $v) {
                if (!isset($v['id'])) continue; // код-заглушка TODO_…
                $line = '- ' . ($v['name'] ?? 'вариант') . " (код {$v['id']}): " . money_fmt($v['price'] ?? null, (string)($v['currency'] ?? $cur));
                if (!empty($v['consular_fee'])) $line .= '; консульский сбор ' . money_fmt((float)$v['consular_fee'], (string)($v['consular_fee_currency'] ?? 'EUR'));
                if (!empty($v['term'])) $line .= '; срок: ' . $v['term'];
                if (!empty($v['includes'])) $line .= '; входит: ' . $v['includes'];
                $L[] = $line;
            }
        }
        if (!empty($p['options'])) {
            $L[] = 'Опции:';
            foreach ($p['options'] as $o) {
                if (!isset($o['id'])) continue;
                $L[] = '- ' . ($o['name'] ?? $o['id']) . " (код {$o['id']})" . (($o['type'] ?? 'addon') === 'choice'
                    ? ': на выбор ' . implode(', ', (array)($o['values'] ?? []))
                    : ': ' . (($o['price_delta'] ?? null) === null ? 'цена уточняется' : '+' . money_fmt((float)$o['price_delta'], $cur)));
            }
        }
        if (!empty($p['params'])) $L[] = 'Параметры: ' . jenc($p['params']);
        if (trim((string)($p['bot_info'] ?? '')) !== '') $L[] = 'Для консультанта: ' . trim((string)$p['bot_info']);
        $out[] = implode("\n", $L);
    }
    return implode("\n\n", $out);
}
