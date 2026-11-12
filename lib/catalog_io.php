<?php
declare(strict_types=1);

/**
 * Каталог ⇄ Excel.
 *
 * Листы (порядок и регистр не важны, лишние листы пропускаются):
 *   Продукты        — строка = товар: Код, Название, Раздел, Статус продаж, Наша цена, Цена за, Кто продаёт,
 *                     Кому подходит, Ограничения, Документы, Как оформляется, Объявление, Подробности паузы, Для бота, Активен
 *   Тарифы          — Банк (код товара), Код тарифа, Тариф, Наша цена, Плата банку, + любые колонки → характеристики тарифа
 *   Характеристики  — матрица: первая колонка — характеристика, дальше колонки с кодами товаров
 *   Опции           — Банк, Код опции, Название, Тип (выбор / доплата), Наша надбавка, Плата банку, Значения
 *   Вопросы…        — Код, Вопрос клиенту, Варианты ответа, На что влияет, [Раздел]
 * Ячейка, начинающаяся с «?», — пометка для человека, не загружается.
 */

const CATALOG_XLSX_SHEETS = ['Продукты', 'Тарифы', 'Характеристики', 'Опции', 'Вопросы'];

function cio_val($v): string
{
    $v = trim((string)$v);
    return str_starts_with($v, '?') ? '' : $v;
}

/** Строки листа → список ассоциативных массивов по заголовкам. */
function cio_table(array $rows): array
{
    $rows = array_values(array_filter($rows, fn($r) => $r !== [] && implode('', array_map('trim', array_map('strval', $r))) !== ''));
    if (!$rows) return [[], []];
    $head = array_map(fn($h) => trim((string)$h), array_shift($rows));
    $out = [];
    foreach ($rows as $r) {
        $row = [];
        foreach ($head as $i => $h) if ($h !== '') $row[$h] = (string)($r[$i] ?? '');
        $out[] = $row;
    }
    return [$head, $out];
}

/** Значение по началу названия колонки (без учёта регистра). */
function cio_col(array $row, string ...$names): string
{
    foreach ($row as $h => $v) {
        $hl = mb_strtolower($h);
        foreach ($names as $n) if (str_starts_with($hl, mb_strtolower($n))) return cio_val($v);
    }
    return '';
}

function cio_sheet(array $sheets, string $name): ?array
{
    foreach ($sheets as $n => $rows) if (str_starts_with(mb_strtolower(trim($n)), mb_strtolower($name))) return $rows;
    return null;
}

/**
 * Импорт. $prune — удалить из разделов файла товары, которых в файле нет.
 * @return array{products: int, variants: int, options: int, questions: int, removed: string[], warnings: string[]}
 */
function catalog_import_xlsx(string $path, bool $prune = false): array
{
    $sheets = xlsx_sheets($path);
    $rep = ['products' => 0, 'variants' => 0, 'options' => 0, 'questions' => 0, 'removed' => [], 'warnings' => []];
    $prodRows = cio_sheet($sheets, 'Продукты');
    if ($prodRows === null) throw new RuntimeException('В файле нет листа «Продукты».');
    [, $prods] = cio_table($prodRows);

    $status = ['открыт' => 'open', 'приостан' => 'paused', 'пауза' => 'paused', 'закрыт' => 'closed', 'open' => 'open', 'paused' => 'paused', 'closed' => 'closed'];
    $items = [];
    $existing = catalog_all();
    foreach ($prods as $i => $r) {
        $id = strtolower(cio_col($r, 'Код'));
        if ($id === '') continue;
        if (!preg_match('/^[a-z0-9_]{2,40}$/', $id)) { $rep['warnings'][] = "Продукты, строка " . ($i + 2) . ": код «{$id}» — нужны латиница, цифры, _"; continue; }
        $old = $existing[$id] ?? [];
        $st = mb_strtolower(cio_col($r, 'Статус'));
        $sales = 'open';
        foreach ($status as $k => $v) if ($st !== '' && str_starts_with($st, $k)) $sales = $v;
        $active = cio_col($r, 'Активен');
        $items[$id] = array_merge($old, [
            'id' => $id,
            'name' => cio_col($r, 'Название') ?: ($old['name'] ?? $id),
            'section' => cio_col($r, 'Раздел') ?: ($old['section'] ?? 'general'),
            'sales_status' => $sales,
            'price' => price_or_null(cio_col($r, 'Наша цена')),
            'price_per' => str_starts_with(mb_strtolower(cio_col($r, 'Цена за')), 'участ') ? 'participant' : 'order',
            'sale_mode' => str_starts_with(mb_strtolower(cio_col($r, 'Кто продаёт', 'Кто продает')), 'менедж') ? 'manager' : 'bot',
            'fit' => cio_col($r, 'Кому подходит'),
            'limits' => cio_col($r, 'Ограничения'),
            'documents' => cio_col($r, 'Документы'),
            'process' => cio_col($r, 'Как оформляется'),
            'pause_notice' => cio_col($r, 'Объявление'),
            'pause_details' => cio_col($r, 'Подробности'),
            'bot_info' => cio_col($r, 'Для бота') ?: ($old['bot_info'] ?? ''),
            'active' => $active === '' ? ($old['active'] ?? true) : !in_array(mb_strtolower($active), ['нет', '0', 'no', 'false'], true),
            '_sort' => ($i + 1) * 10,
        ]);
        if (cio_val(cio_col($r, 'Наша цена')) === '' && $sales === 'open') $rep['warnings'][] = "«{$items[$id]['name']}»: нет нашей цены — бот скажет, что стоимость уточнит менеджер";
    }

    // Тарифы
    if (($rows = cio_sheet($sheets, 'Тарифы')) !== null) {
        [$head, $tab] = cio_table($rows);
        $fixed = ['банк', 'товар', 'код тарифа', 'тариф', 'наша цена', 'плата банку', 'валюта'];
        $byProd = [];
        foreach ($tab as $r) {
            $pid = strtolower(cio_col($r, 'Банк', 'Товар'));
            $vid = strtolower(preg_replace('/[^a-z0-9_]/i', '_', cio_col($r, 'Код тарифа')));
            if ($pid === '' || $vid === '') continue;
            if (!isset($items[$pid])) { $rep['warnings'][] = "Тарифы: товара «{$pid}» нет на листе «Продукты» — пропущен"; continue; }
            $props = [];
            foreach ($r as $h => $v) {
                $hl = mb_strtolower($h);
                $isFixed = false;
                foreach ($fixed as $f) if ($hl === $f || str_starts_with($hl, $f . ',') || str_starts_with($hl, $f . ' ')) $isFixed = true;
                if (!$isFixed && cio_val($v) !== '') $props[] = [$h, cio_val($v)];
            }
            $byProd[$pid][] = ['id' => $vid, 'name' => cio_col($r, 'Тариф') ?: $vid, 'price' => price_or_null(cio_col($r, 'Наша цена')), 'currency' => strtoupper(cio_col($r, 'Валюта')) ?: null, 'bank_fee' => cio_col($r, 'Плата банку'), 'props' => $props];
            $rep['variants']++;
        }
        foreach ($items as $pid => &$it) $it['variants'] = $byProd[$pid] ?? ($it['variants'] ?? []);
        unset($it);
    }

    // Характеристики (матрица)
    if (($rows = cio_sheet($sheets, 'Характеристики')) !== null) {
        $rows = array_values(array_filter($rows, fn($r) => $r !== []));
        $head = array_map(fn($h) => strtolower(trim((string)$h)), $rows[0] ?? []);
        $props = [];
        foreach (array_slice($rows, 1) as $r) {
            $name = trim((string)($r[0] ?? ''));
            if ($name === '') continue;
            foreach ($head as $ci => $pid) if ($ci > 0 && $pid !== '' && cio_val($r[$ci] ?? '') !== '') $props[$pid][] = [$name, cio_val($r[$ci])];
        }
        foreach ($head as $ci => $pid) if ($ci > 0 && $pid !== '' && !isset($items[$pid])) $rep['warnings'][] = "Характеристики: колонка «{$pid}» — такого товара нет на листе «Продукты»";
        foreach ($items as $pid => &$it) $it['props'] = $props[$pid] ?? [];
        unset($it);
    }

    // Опции
    if (($rows = cio_sheet($sheets, 'Опции')) !== null) {
        [, $tab] = cio_table($rows);
        $byProd = [];
        foreach ($tab as $r) {
            $pid = strtolower(cio_col($r, 'Банк', 'Товар'));
            $oid = strtolower(preg_replace('/[^a-z0-9_]/i', '_', cio_col($r, 'Код опции')));
            if ($pid === '' || $oid === '' || !isset($items[$pid])) continue;
            $type = str_starts_with(mb_strtolower(cio_col($r, 'Тип')), 'выбор') ? 'choice' : 'addon';
            $byProd[$pid][] = ['id' => $oid, 'name' => cio_col($r, 'Название') ?: $oid, 'type' => $type, 'price_delta' => price_or_null(cio_col($r, 'Наша надбавка')),
                'bank_fee' => cio_col($r, 'Плата банку'), 'values' => array_values(array_filter(array_map('trim', preg_split('/[,;]/u', cio_col($r, 'Значения')))))];
            $rep['options']++;
        }
        foreach ($items as $pid => &$it) $it['options'] = $byProd[$pid] ?? ($it['options'] ?? []);
        unset($it);
    }

    foreach ($items as $it) { catalog_save($it); $rep['products']++; }

    // Вопросы раздела
    if (($rows = cio_sheet($sheets, 'Вопросы')) !== null) {
        [, $tab] = cio_table($rows);
        $secsInFile = array_values(array_unique(array_map(fn($it) => normalize_section($it['section']), $items)));
        $bySec = [];
        foreach ($tab as $r) {
            $sec = cio_col($r, 'Раздел') ?: (count($secsInFile) === 1 ? $secsInFile[0] : '');
            if ($sec === '') { $rep['warnings'][] = 'Вопросы: не указан раздел (в файле товары из нескольких разделов) — пропущено'; continue; }
            $bySec[normalize_section($sec)][] = ['code' => cio_col($r, 'Код'), 'question' => cio_col($r, 'Вопрос'), 'options' => cio_col($r, 'Варианты'), 'affects' => cio_col($r, 'На что влияет')];
        }
        foreach ($bySec as $sec => $qs) {
            $cur = section_get($sec);
            section_save($sec, $cur['name'], $qs);
            $rep['questions'] += count($qs);
        }
    }

    if ($prune) {
        $secs = array_unique(array_map(fn($it) => normalize_section($it['section']), $items));
        foreach (catalog_all() as $p) {
            if (in_array($p['section'], $secs, true) && !isset($items[$p['id']])) { catalog_delete($p['id']); $rep['removed'][] = $p['name'] ?? $p['id']; }
        }
    }
    return $rep;
}

/* ------------------------------------------------------------------ */
/*  Экспорт                                                            */
/* ------------------------------------------------------------------ */

/** Строки листов для экспорта (те же колонки, что читает импорт). */
function catalog_export_rows(?string $section = null): array
{
    $sales = ['open' => 'открыты', 'paused' => 'приостановлены', 'closed' => 'закрыты'];
    $prods = array_filter(catalog_all(), fn($p) => $section === null || $p['section'] === normalize_section($section));
    $P = [['Код', 'Название', 'Раздел', 'Статус продаж', 'Наша цена, ₽', 'Цена за', 'Кто продаёт', 'Кому подходит', 'Ограничения / кому не подходит', 'Документы', 'Как оформляется', 'Объявление (если продажи на паузе)', 'Подробности паузы', 'Для бота', 'Активен']];
    $T = [['Банк', 'Код тарифа', 'Тариф', 'Наша цена, ₽', 'Плата банку']];
    $O = [['Банк', 'Код опции', 'Название', 'Тип', 'Наша надбавка, ₽', 'Плата банку', 'Значения (для «выбор»)']];
    $propNames = [];
    $tPropNames = [];
    foreach ($prods as $p) {
        foreach (props_norm($p['props'] ?? []) as [$n]) $propNames[$n] = true;
        foreach ($p['variants'] ?? [] as $v) foreach (props_norm($v['props'] ?? []) as [$n]) $tPropNames[$n] = true;
        foreach ($p['variants'] ?? [] as $v) {
            if (!empty($v['term']) || !empty($v['includes'])) { $tPropNames['Срок'] = true; $tPropNames['Что входит'] = true; }
        }
    }
    $T[0] = array_merge($T[0], array_keys($tPropNames));
    foreach ($prods as $p) {
        $P[] = [$p['id'], $p['name'] ?? '', $p['section'], $sales[product_sales_status($p)], $p['price'] ?? '', ($p['price_per'] ?? 'order') === 'participant' ? 'участник' : 'заказ',
            ($p['sale_mode'] ?? 'bot') === 'manager' ? 'менеджер' : 'бот', $p['fit'] ?? '', $p['limits'] ?? '', $p['documents'] ?? '', $p['process'] ?? '',
            $p['pause_notice'] ?? '', $p['pause_details'] ?? '', $p['bot_info'] ?? '', $p['active'] ? 'да' : 'нет'];
        foreach ($p['variants'] ?? [] as $v) {
            $vp = [];
            foreach (props_norm($v['props'] ?? []) as [$n, $val]) $vp[$n] = $val;
            if (!empty($v['term'])) $vp['Срок'] = $v['term'];
            if (!empty($v['includes'])) $vp['Что входит'] = $v['includes'];
            $T[] = array_merge([$p['id'], $v['id'], $v['name'] ?? '', $v['price'] ?? '', $v['bank_fee'] ?? ''], array_map(fn($n) => $vp[$n] ?? '', array_keys($tPropNames)));
        }
        foreach ($p['options'] ?? [] as $o) {
            $O[] = [$p['id'], $o['id'], $o['name'] ?? '', ($o['type'] ?? 'addon') === 'choice' ? 'выбор' : 'доплата', $o['price_delta'] ?? '', $o['bank_fee'] ?? '', implode(', ', (array)($o['values'] ?? []))];
        }
    }
    $C = [array_merge(['Характеристика'], array_keys($prods))];
    foreach (array_keys($propNames) as $n) {
        $row = [$n];
        foreach ($prods as $p) { $v = ''; foreach (props_norm($p['props'] ?? []) as [$k, $val]) if ($k === $n) $v = $val; $row[] = $v; }
        $C[] = $row;
    }
    $Q = [['Код', 'Вопрос клиенту', 'Варианты ответа', 'На что влияет', 'Раздел']];
    foreach (sections_all() as $sec) {
        if ($section !== null && $sec['code'] !== normalize_section($section)) continue;
        foreach ($sec['questions'] as $q) $Q[] = [$q['code'], $q['question'], $q['options'], $q['affects'], $sec['code']];
    }
    return ['Продукты' => $P, 'Тарифы' => $T, 'Характеристики' => $C, 'Опции' => $O, 'Вопросы' => $Q];
}

/** Простой XLSX без библиотек: строки — inline, первая строка жирная, перенос текста. */
function xlsx_write(string $path, array $sheets): void
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Не удалось создать XLSX.');
    $esc = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $col = function (int $i): string { $s = ''; $i++; while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = intdiv($i - 1, 26); } return $s; };
    $names = array_keys($sheets);
    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    $wbSheets = '';
    foreach ($names as $i => $name) {
        $n = $i + 1;
        $ct .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $wbRels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        $wbSheets .= '<sheet name="' . $esc(mb_substr($name, 0, 31)) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
        $rows = $sheets[$name];
        $maxCols = max(1, ...array_map('count', $rows ?: [[]]));
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>';
        for ($c = 0; $c < $maxCols; $c++) $xml .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . ($c < 1 ? 16 : 32) . '" customWidth="1"/>';
        $xml .= '</cols><sheetData>';
        foreach ($rows as $ri => $row) {
            $xml .= '<row r="' . ($ri + 1) . '">';
            foreach (array_values($row) as $ci => $v) {
                $ref = $col($ci) . ($ri + 1);
                $style = $ri === 0 ? ' s="1"' : ' s="2"';
                if (is_int($v) || is_float($v)) $xml .= '<c r="' . $ref . '"' . $style . '><v>' . $v . '</v></c>';
                elseif ((string)$v !== '') $xml .= '<c r="' . $ref . '"' . $style . ' t="inlineStr"><is><t xml:space="preserve">' . $esc($v) . '</t></is></c>';
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet' . $n . '.xml', $xml);
    }
    $ct .= '</Types>';
    $n = count($names) + 1;
    $wbRels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    $zip->addFromString('[Content_Types].xml', $ct);
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $wbSheets . '</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="10"/><name val="Arial"/></font><font><b/><sz val="10"/><name val="Arial"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="3"><xf/><xf fontId="1" applyFont="1"><alignment wrapText="1" vertical="top"/></xf><xf fontId="0" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf></cellXfs></styleSheet>');
    $zip->close();
}
