<?php
declare(strict_types=1);

/**
 * Сценарии: хранение версий, проверка, доступ к частям.
 *
 * Версии лежат в таблице scenarios; активная — одна. Диалог помнит scenario_id,
 * с которым начался, поэтому правка сценария не ломает идущие разговоры.
 * При первом запуске загружается scenarios/milli_mind.json из репозитория.
 */

const KNOWN_ACTIONS = ['say', 'b24_update_lead', 'b24_attach_resume', 'b24_create_smart_item', 'openline_finish', 'notify_manager', 'pause_bot'];
const KNOWN_TOOLS = ['kb_search', 'biometry_check', 'send_form_link', 'create_payment_link'];
const STATE_TYPES = ['conversation', 'transit', 'waiting', 'terminal'];
const SLOT_TYPES = ['string', 'phone', 'contact', 'enum', 'int', 'bool', 'fio', 'file_or_link', 'list', 'counter', 'catalog_ref', 'money', 'datetime', 'state_ref'];
/** События, которые генерирует сам движок или админка. */
const SYSTEM_EVENTS = ['client_message', 'operator_message', 'turn_limit_exceeded', 'invoice_sent', 'payment.paid', 'payment.failed', 'payment_timeout', 'manager_took_over', 'followup_due', 'bot_answered_question'];

function scenario_from_row(array $row): array
{
    $sc = json_decode($row['json'], true);
    if (!is_array($sc)) throw new RuntimeException('Сценарий #' . $row['id'] . ' повреждён');
    $sc['_id'] = (int)$row['id'];
    $sc['_version'] = $row['version'];
    return $sc;
}

function scenario_by_id(int $id): ?array
{
    static $cache = [];
    if (isset($cache[$id])) return $cache[$id];
    $st = db()->prepare('SELECT * FROM scenarios WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ? ($cache[$id] = scenario_from_row($row)) : null;
}

function scenario_active(): array
{
    $row = db()->query('SELECT * FROM scenarios WHERE active = 1 ORDER BY id DESC LIMIT 1')->fetch();
    if (!$row) {
        $file = BOT_ROOT . '/scenarios/milli_mind.json';
        if (!is_file($file)) throw new RuntimeException('Нет ни одного сценария. Загрузите JSON в админке → Сценарий.');
        scenario_save((string)file_get_contents($file), 'из репозитория', 'система', true);
        $row = db()->query('SELECT * FROM scenarios WHERE active = 1 ORDER BY id DESC LIMIT 1')->fetch();
    }
    return scenario_from_row($row);
}

/** Сценарий диалога (его версия; если удалена — активная). */
function scenario_for(array $d): array
{
    return ($d['scenario_id'] ? scenario_by_id((int)$d['scenario_id']) : null) ?? scenario_active();
}

/** Сохраняет новую версию. Ошибки проверки — исключение; предупреждения допустимы. */
function scenario_save(string $json, string $note, string $by, bool $activate): int
{
    $sc = json_decode($json, true);
    if (!is_array($sc)) throw new RuntimeException('Это не JSON: ' . json_last_error_msg());
    [$errors] = scenario_validate($sc);
    if ($errors) throw new RuntimeException("В сценарии ошибки:\n- " . implode("\n- ", $errors));
    $pretty = json_encode($sc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $pdo = db();
    $pdo->beginTransaction();
    if ($activate) $pdo->exec('UPDATE scenarios SET active = 0');
    $pdo->prepare('INSERT INTO scenarios(code, version, name, json, active, note, created_by) VALUES(?,?,?,?,?,?,?)')
        ->execute([(string)($sc['meta']['id'] ?? 'scenario'), (string)($sc['meta']['version'] ?? ''), (string)($sc['meta']['name'] ?? ''), $pretty, $activate ? 1 : 0, $note, $by]);
    $id = (int)$pdo->lastInsertId();
    $pdo->commit();
    return $id;
}

function scenario_activate(int $id): void
{
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->exec('UPDATE scenarios SET active = 0');
    $pdo->prepare('UPDATE scenarios SET active = 1 WHERE id = ?')->execute([$id]);
    $pdo->commit();
}

function scenario_versions(): array
{
    return db()->query('SELECT s.id, s.code, s.version, s.name, s.active, s.note, s.created_by, s.created_at,
        (SELECT COUNT(*) FROM dialogs d WHERE d.scenario_id = s.id) AS dialogs FROM scenarios s ORDER BY s.id DESC')->fetchAll();
}

/* ------------------------------------------------------------------ */
/*  Доступ к частям                                                    */
/* ------------------------------------------------------------------ */

function sc_state(array $sc, string $name): array
{
    return $sc['states'][$name] ?? ['title' => $name, 'type' => 'conversation', 'transitions' => []];
}

function sc_state_type(array $sc, string $name): string
{
    return (string)(sc_state($sc, $name)['type'] ?? 'conversation');
}

function sc_state_title(array $sc, string $name): string
{
    return (string)(sc_state($sc, $name)['title'] ?? $name);
}

function sc_slot(array $sc, string $name): array
{
    return $sc['slots'][$name] ?? ['type' => 'string'];
}

function sc_setting(array $sc, string $path)
{
    $v = $sc['settings'] ?? [];
    foreach (explode('.', $path) as $k) {
        if (!is_array($v) || !array_key_exists($k, $v)) return null;
        $v = $v[$k];
    }
    return $v;
}

function is_todo($v): bool
{
    return is_string($v) && str_contains($v, 'TODO');
}

/* ------------------------------------------------------------------ */
/*  Проверка                                                           */
/* ------------------------------------------------------------------ */

/** @return array{0: string[], 1: string[]} [ошибки, предупреждения] */
function scenario_validate(array $sc): array
{
    $E = []; $W = [];
    $states = $sc['states'] ?? null;
    if (!is_array($states) || !$states) return [['нет раздела "states"'], []];
    $entry = $sc['meta']['entry_state'] ?? null;
    if (!$entry) $E[] = 'meta.entry_state не задан';
    elseif (!isset($states[$entry])) $E[] = "meta.entry_state «{$entry}» — такого состояния нет";
    $slots = is_array($sc['slots'] ?? null) ? $sc['slots'] : [];
    $intents = array_values(array_filter((array)($sc['intents']['list'] ?? []), 'is_string'));
    if (!$intents) $W[] = 'intents.list пуст — бот не сможет распознавать намерения';

    foreach ($slots as $name => $def) {
        $t = $def['type'] ?? 'string';
        if (!in_array($t, SLOT_TYPES, true)) $E[] = "слот {$name}: неизвестный тип «{$t}» (можно: " . implode(', ', SLOT_TYPES) . ')';
        if ($t === 'enum' && empty($def['values'])) $E[] = "слот {$name}: enum без values";
        if (isset($def['b24_field']) && is_todo($def['b24_field'])) $W[] = "слот {$name}: поле Б24 не задано (TODO) — значение пойдёт в комментарий лида";
    }

    $checkCond = function (array $on, string $where) use (&$E, &$W, $slots, $intents) {
        foreach ($on as $k => $v) {
            switch ($k) {
                case 'slot': case 'slot_set': case 'slot_not':
                    if (!isset($slots[$v])) $E[] = "$where: слот «{$v}» не описан в slots"; break;
                case 'counter':
                    if (!isset($slots[$v])) $W[] = "$where: счётчик «{$v}» не описан в slots"; break;
                case 'intent':
                    if (!in_array($v, $intents, true)) $E[] = "$where: интент «{$v}» нет в intents.list"; break;
                case 'event':
                    if (!in_array($v, SYSTEM_EVENTS, true)) $W[] = "$where: событие «{$v}» не генерируется движком автоматически (только кнопкой в песочнице)"; break;
                case 'slots_complete':
                    if ($v !== 'required') $E[] = "$where: slots_complete поддерживает только \"required\""; break;
                case 'any':
                    if (!is_array($v)) $E[] = "$where: any должен быть списком условий"; break;
                case 'eq': case 'gte': case 'count_gte': case 'always': break;
                default: $E[] = "$where: неизвестное условие «{$k}»";
            }
        }
    };
    $checkTarget = function ($to, string $where) use (&$E, $states) {
        if ($to === null) return;
        if (!is_string($to)) { $E[] = "$where: to должен быть строкой"; return; }
        if (str_contains($to, '{{')) return; // шаблон — проверяется при работе
        if (!isset($states[$to])) $E[] = "$where: переход в несуществующее состояние «{$to}»";
    };
    $checkActions = function ($list, string $where) use (&$E, &$W) {
        if ($list === null) return;
        if (!is_array($list)) { $E[] = "$where: должен быть списком действий"; return; }
        foreach ($list as $i => $a) {
            $name = $a['action'] ?? null;
            if (!in_array($name, KNOWN_ACTIONS, true)) $E[] = "$where[$i]: неизвестное действие «" . ($name ?? '?') . '»';
            array_walk_recursive($a, function ($v) use (&$W, $where, $i, $name) {
                if (is_todo($v)) $W[] = "$where[$i] ($name): не заполнено «" . mb_strimwidth((string)$v, 0, 70, '…') . '» — это поле будет пропущено';
            });
        }
    };

    foreach ((array)($sc['global_transitions'] ?? []) as $i => $t) {
        $checkCond((array)($t['on'] ?? []), "global_transitions[$i]");
        $checkTarget($t['to'] ?? null, "global_transitions[$i]");
    }
    $reachable = [$entry => true];
    foreach ((array)($sc['global_transitions'] ?? []) as $t) if (is_string($t['to'] ?? null)) $reachable[$t['to']] = true;
    foreach ($states as $name => $s) {
        $w = "states.$name";
        $type = $s['type'] ?? 'conversation';
        if (!in_array($type, STATE_TYPES, true)) $E[] = "$w: неизвестный type «{$type}» (можно: " . implode(', ', STATE_TYPES) . ')';
        foreach (['slots_required', 'slots_optional', 'slots_collect'] as $k) {
            foreach ((array)($s[$k] ?? []) as $sl) if (!isset($slots[$sl])) $E[] = "$w.$k: слот «{$sl}» не описан в slots";
        }
        foreach ((array)($s['tools'] ?? []) as $tool) if (!in_array($tool, KNOWN_TOOLS, true)) $E[] = "$w.tools: неизвестный инструмент «{$tool}»";
        $checkActions($s['on_enter'] ?? null, "$w.on_enter");
        $checkActions($s['on_exit'] ?? null, "$w.on_exit");
        if (isset($s['on_error'])) $checkTarget($s['on_error']['to'] ?? null, "$w.on_error");
        foreach ((array)($s['transitions'] ?? []) as $i => $t) {
            $checkCond((array)($t['on'] ?? []), "$w.transitions[$i]");
            $checkTarget($t['to'] ?? null, "$w.transitions[$i]");
            if (is_string($t['to'] ?? null)) $reachable[$t['to']] = true;
            if (str_contains((string)($t['to'] ?? ''), '{{')) foreach ($states as $n2 => $_) $reachable[$n2] ??= false; // шаблон — может вести куда угодно
        }
        foreach ((array)($s['timers'] ?? []) as $i => $tm) {
            if (empty($tm['id'])) $E[] = "$w.timers[$i]: нет id";
            if (!isset($tm['after_minutes']) && !isset($tm['at_slot'])) $E[] = "$w.timers[$i]: нужен after_minutes или at_slot";
            if (isset($tm['at_slot']) && !isset($slots[$tm['at_slot']])) $E[] = "$w.timers[$i]: слот «{$tm['at_slot']}» не описан";
        }
        if ($type === 'transit' && !array_filter((array)($s['transitions'] ?? []), fn($t) => !empty($t['on']['always']))) {
            $W[] = "$w: транзитное состояние без перехода {\"always\": true} — диалог в нём застрянет";
        }
        if ($type === 'conversation' && empty($s['max_turns'])) $W[] = "$w: не задан max_turns — нет защиты от зацикливания";
    }
    foreach ($states as $name => $_) if (!isset($reachable[$name]) && $name !== $entry) $W[] = "states.$name: в это состояние нет переходов";
    return [$E, $W];
}

/** Сводка по TODO для админки. */
function scenario_todos(array $sc): array
{
    $out = [];
    $walk = function ($v, string $path) use (&$walk, &$out) {
        if (is_array($v)) { foreach ($v as $k => $x) $walk($x, $path === '' ? (string)$k : "$path.$k"); return; }
        if (is_todo($v) && !str_starts_with($path, 'open_questions')) $out[] = [$path, (string)$v];
    };
    $walk($sc, '');
    return $out;
}
