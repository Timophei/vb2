<?php
declare(strict_types=1);

/**
 * Каталог товаров и услуг.
 *
 * Товар: id, section (раздел: cards / green_card / visa …), name, currency,
 *   price      — наша цена (в счёт); у тарифа может быть своя, иначе берётся эта,
 *   price_per  — order | participant, sale_mode — bot | manager, sales_status — open | paused | closed,
 *   props      — характеристики [[название, значение], …] — по ним бот подбирает и обосновывает выбор,
 *   fit / limits — кому подходит / ограничения, documents / process — документы / как оформляется,
 *   variants   — тарифы [{id, name, price|null, bank_fee, props, …}] — выбирается один,
 *   options    — [{id, name, type: addon|choice, price_delta, bank_fee, values}] — можно несколько,
 *   params, bot_info, pause_notice, pause_details.
 * Раздел (catalog_sections): название и вопросы для подбора — они становятся полями для ответов клиента.
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
    $p['price'] = price_or_null($p['price'] ?? null);
    $p['props'] = props_norm($p['props'] ?? []);
    foreach ($p['variants'] as &$v) {
        $v['price'] = price_or_null($v['price'] ?? null);
        $v['props'] = props_norm($v['props'] ?? []);
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

/** Характеристики → [[название, значение], …]. Принимает список пар, массив «ключ => значение» или текст «Название: значение» по строкам. */
function props_norm($props): array
{
    if (is_string($props)) {
        $out = [];
        foreach (preg_split('/\R/u', $props) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            [$k, $v] = array_map('trim', explode(':', $line, 2)) + [1 => ''];
            if ($k !== '' && $v !== '') $out[] = [$k, $v];
        }
        return $out;
    }
    $out = [];
    foreach ((array)$props as $k => $v) {
        if (is_array($v) && array_is_list($v)) { [$k2, $v2] = $v + [1 => '']; if (trim((string)$k2) !== '' && trim((string)$v2) !== '') $out[] = [trim((string)$k2), trim((string)$v2)]; }
        elseif (is_string($k) && trim((string)$v) !== '') $out[] = [$k, trim((string)$v)];
    }
    return $out;
}

function props_text(array $props, string $sep = "\n"): string
{
    return implode($sep, array_map(fn($x) => $x[0] . ': ' . $x[1], props_norm($props)));
}

/* ------------------------------------------------------------------ */
/*  Разделы каталога                                                   */
/* ------------------------------------------------------------------ */

function section_get(string $code): array
{
    $st = db()->prepare('SELECT * FROM catalog_sections WHERE code = ?');
    $st->execute([normalize_section($code)]);
    $r = $st->fetch();
    return ['code' => normalize_section($code), 'name' => (string)($r['name'] ?? ''), 'questions' => $r ? jdec($r['questions']) : [], 'sort' => (int)($r['sort'] ?? 100)];
}

/** Все разделы: из таблицы и из товаров. */
function sections_all(): array
{
    $out = [];
    foreach (db()->query('SELECT code FROM catalog_sections ORDER BY sort, code')->fetchAll(PDO::FETCH_COLUMN) as $c) $out[$c] = section_get($c);
    foreach (catalog_all() as $p) if (!isset($out[$p['section']])) $out[$p['section']] = section_get($p['section']);
    return $out;
}

function section_save(string $code, string $name, array $questions): void
{
    $qs = [];
    foreach ($questions as $q) {
        $c = strtolower(trim((string)($q['code'] ?? '')));
        $c = preg_replace('/[^a-z0-9_]/', '_', $c);
        if ($c === '' || trim((string)($q['question'] ?? '')) === '') continue;
        $qs[] = ['code' => $c, 'question' => trim((string)$q['question']), 'options' => trim((string)($q['options'] ?? '')), 'affects' => trim((string)($q['affects'] ?? ''))];
    }
    db()->prepare('INSERT INTO catalog_sections(code, name, questions) VALUES(?,?,?) ON CONFLICT(code) DO UPDATE SET name = excluded.name, questions = excluded.questions')
        ->execute([normalize_section($code), trim($name), jenc($qs)]);
}

/** Слот ответа на вопрос раздела. */
function section_question_slot(string $section, string $code): string { return 'q.' . normalize_section($section) . '.' . $code; }

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
    if ($variants && !$v) throw new RuntimeException('не выбран вариант (тариф). Доступны: ' . implode(', ', array_keys($variants)));
    // цена тарифа, а если у тарифа своей нет — цена товара
    $price = $v['price'] ?? null;
    if ($price === null) $price = price_or_null($p['price'] ?? null);
    if ($price === null) throw new RuntimeException('цена «' . ($p['name'] ?? $productId) . ($v ? ' — ' . ($v['name'] ?? '') : '') . '» не задана в каталоге — стоимость уточнит менеджер');
    $amount = (float)$price;
    $desc = [($p['name'] ?? $productId) . ($v ? ' — ' . ($v['name'] ?? $v['id']) : '')];
    $opts = array_column($p['options'] ?? [], null, 'id');
    foreach (array_unique($optionIds) as $oid) {
        $o = $opts[$oid] ?? null;
        if (!$o) throw new RuntimeException("опция «{$oid}» не найдена");
        if (($o['type'] ?? 'addon') === 'addon') {
            if (($o['price_delta'] ?? null) === null && trim((string)($o['bank_fee'] ?? '')) === '') throw new RuntimeException("цена опции «{$o['name']}» не задана — уточнит менеджер");
            $amount += (float)($o['price_delta'] ?? 0); // только плата банку — в наш счёт не входит
        }
        $desc[] = '+ ' . $o['name'];
    }
    $qty = max(1, $qty);
    if (($p['price_per'] ?? 'order') === 'participant') {
        $amount *= $qty;
        if ($qty > 1) $desc[] = "× $qty участн.";
    }
    $cur = (string)(($v['currency'] ?? '') ?: ($p['currency'] ?? 'RUB'));
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

/** Текст каталога раздела для промта ответчика: всё, из чего модель подбирает и обосновывает. */
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
        $per = ($p['price_per'] ?? 'order') === 'participant' ? ' за каждого участника' : '';
        $L = ['### ' . ($p['name'] ?? $p['id']) . " (код: {$p['id']})" . (isset($p['price']) && $p['price'] !== null ? ' — наша цена ' . money_fmt((float)$p['price'], $cur) . $per : '')];
        if (($p['sale_mode'] ?? 'bot') === 'manager') $L[] = 'Продаёт менеджер: бот консультирует и передаёт, счёт не выставляет.';
        foreach (['fit' => 'Кому подходит', 'limits' => 'Ограничения', 'bot_info' => 'Для консультанта'] as $k => $lbl) {
            if (trim((string)($p[$k] ?? '')) !== '') $L[] = $lbl . ': ' . trim((string)$p[$k]);
        }
        if (!empty($p['props'])) $L[] = 'Характеристики: ' . props_text($p['props'], '; ');
        if (!empty($p['variants'])) {
            $L[] = 'Тарифы (выбирается один' . (isset($p['price']) && $p['price'] !== null ? '; наша цена та же, если у тарифа не указана своя' : '') . '):';
            foreach ($p['variants'] as $v) {
                if (!isset($v['id'])) continue; // код-заглушка TODO_…
                $parts = [];
                if (($v['price'] ?? null) !== null) $parts[] = 'наша цена ' . money_fmt((float)$v['price'], (string)(($v['currency'] ?? '') ?: $cur));
                elseif (!isset($p['price']) || $p['price'] === null) $parts[] = 'цена уточняется';
                if (!empty($v['bank_fee'])) $parts[] = 'плата банку/поставщику (отдельно, не в нашем счёте): ' . $v['bank_fee'];
                if (!empty($v['consular_fee'])) $parts[] = 'консульский сбор ' . money_fmt((float)$v['consular_fee'], (string)($v['consular_fee_currency'] ?? 'EUR'));
                if (!empty($v['term'])) $parts[] = 'срок: ' . $v['term'];
                if (!empty($v['includes'])) $parts[] = 'входит: ' . $v['includes'];
                if (!empty($v['props'])) $parts[] = props_text($v['props'], '; ');
                $L[] = '- ' . ($v['name'] ?? $v['id']) . " (код {$v['id']}): " . implode('; ', $parts);
            }
        }
        if (!empty($p['options'])) {
            $L[] = 'Опции:';
            foreach ($p['options'] as $o) {
                if (!isset($o['id'])) continue;
                $txt = ($o['type'] ?? 'addon') === 'choice'
                    ? 'на выбор ' . implode(', ', (array)($o['values'] ?? []))
                    : (($o['price_delta'] ?? null) === null ? (empty($o['bank_fee']) ? 'цена уточняется' : 'нашей доплаты нет') : '+' . money_fmt((float)$o['price_delta'], $cur));
                if (!empty($o['bank_fee'])) $txt .= '; плата банку/поставщику: ' . $o['bank_fee'];
                $L[] = '- ' . ($o['name'] ?? $o['id']) . " (код {$o['id']}): " . $txt;
            }
        }
        if (trim((string)($p['documents'] ?? '')) !== '') $L[] = 'Документы: ' . trim((string)$p['documents']);
        if (trim((string)($p['process'] ?? '')) !== '') $L[] = 'Как оформляется: ' . trim((string)$p['process']);
        if (!empty($p['params'])) $L[] = 'Параметры: ' . jenc($p['params']);
        $out[] = implode("\n", $L);
    }
    return implode("\n\n", $out);
}
