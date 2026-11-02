<?php
declare(strict_types=1);

/**
 * Таймеры состояний.
 *
 *  - after_minutes — от входа в состояние (или от события start_on, напр. «бот ответил»: перезапускается);
 *  - at_slot       — в момент из слота (followup.at);
 *  - condition     — проверяется в момент срабатывания (slot_set / slot_not …);
 *  - max_fires     — сколько раз за пребывание в состоянии;
 *  - action        — {say: "текст"} или {event: "имя"}.
 * При выходе из состояния его таймеры гасятся. Срабатывание — bin/worker.php (раз в 20 с).
 */

function timers_cancel_state(array $d, string $state): void
{
    db()->prepare("UPDATE timers SET active = 0, result = 'выход из состояния' WHERE dialog_id = ? AND state = ? AND active = 1")->execute([$d['id'], $state]);
}

function timer_upsert(array $d, string $state, array $tm, string $fireAt): void
{
    db()->prepare("UPDATE timers SET active = 0, result = 'перезапущен' WHERE dialog_id = ? AND state = ? AND timer_id = ? AND active = 1")->execute([$d['id'], $state, $tm['id']]);
    db()->prepare('INSERT INTO timers(dialog_id, state, timer_id, fire_at, spec) VALUES(?,?,?,?,?)')->execute([$d['id'], $state, $tm['id'], $fireAt, jenc($tm)]);
}

/** $trigger: null — вход в состояние; иначе событие start_on (bot_answered_question). */
function timers_start_state(array $d, array $sc, string $state, ?string $trigger): void
{
    foreach ((array)(sc_state($sc, $state)['timers'] ?? []) as $tm) {
        if (empty($tm['id']) || !isset($tm['after_minutes'])) continue;
        if (($tm['start_on'] ?? null) !== $trigger) continue;
        $fired = (int)($d['counters']['timer:' . $tm['id']] ?? 0);
        if ($fired >= (int)($tm['max_fires'] ?? 1)) continue;
        timer_upsert($d, $state, $tm, utc_plus_minutes((float)$tm['after_minutes']));
    }
}

/** Таймеры «в момент из слота»: ставятся, как только слот заполнен. */
function timers_sync_slots(array $d, array $sc): void
{
    foreach ((array)(sc_state($sc, $d['state'])['timers'] ?? []) as $tm) {
        if (empty($tm['at_slot'])) continue;
        $at = $d['slots'][$tm['at_slot']] ?? null;
        if (!is_string($at) || $at === '') continue;
        $st = db()->prepare('SELECT fire_at FROM timers WHERE dialog_id = ? AND state = ? AND timer_id = ? AND active = 1');
        $st->execute([$d['id'], $d['state'], $tm['id']]);
        $cur = $st->fetchColumn();
        if ($cur === $at) continue;
        if ((int)($d['counters']['timer:' . $tm['id']] ?? 0) >= (int)($tm['max_fires'] ?? 1)) continue;
        timer_upsert($d, $d['state'], $tm, $at);
    }
}

/** Срабатывание просроченных таймеров. $dialogId — только для одного диалога (песочница). */
function timers_run_due(?int $dialogId = null): int
{
    $sql = "SELECT * FROM timers WHERE active = 1 AND fire_at <= ?" . ($dialogId ? ' AND dialog_id = ' . (int)$dialogId : '') . ' ORDER BY fire_at LIMIT 50';
    $st = db()->prepare($sql);
    $st->execute([now_utc()]);
    $n = 0;
    foreach ($st->fetchAll() as $row) {
        // захватываем таймер (на случай двух воркеров)
        $lock = db()->prepare("UPDATE timers SET active = 0, result = 'срабатывает' WHERE id = ? AND active = 1");
        $lock->execute([$row['id']]);
        if ($lock->rowCount() === 0) continue;
        try {
            $result = timer_fire($row);
        } catch (Throwable $e) {
            $result = 'ошибка: ' . $e->getMessage();
            app_log('timer ' . $row['id'] . ': ' . $e->getMessage());
        }
        db()->prepare('UPDATE timers SET result = ? WHERE id = ?')->execute([$result, $row['id']]);
        $n++;
    }
    return $n;
}

function timer_fire(array $row): string
{
    $d = dialog_load((int)$row['dialog_id']);
    if ($d['state'] !== $row['state']) return 'пропущен: диалог уже в другом состоянии';
    $tm = jdec($row['spec']);
    $sc = scenario_for($d);
    $T = turn_init($d, 0);
    if (!empty($tm['condition']) && !cond_match($T, (array)$tm['condition'], [])) return 'пропущен: условие не выполнено';
    $key = 'timer:' . $tm['id'];
    $d['counters'][$key] = (int)($d['counters'][$key] ?? 0) + 1;
    dialog_save($d);
    $action = (array)($tm['action'] ?? []);
    if (isset($action['say'])) {
        $text = (string)tpl($T, $action['say']);
        save_message((int)$d['id'], 'assistant', $text, null, $d['state']);
        channel_push($d, [$text]);
        db()->prepare('INSERT INTO actions_log(dialog_id, action, params, result) VALUES(?,?,?,?)')->execute([$d['id'], 'timer:' . $tm['id'], jenc($tm), 'отправлено: ' . $text]);
        return 'сообщение отправлено';
    }
    if (isset($action['event'])) {
        $out = engine_event((int)$d['id'], (string)$action['event'], true);
        return 'событие ' . $action['event'] . ($out ? ', бот написал' : '');
    }
    return 'нет действия';
}

/** Песочница: «прошло N минут» — сдвигает таймеры диалога и запускает просроченные. */
function timers_fast_forward(int $dialogId, float $minutes): int
{
    $rows = db()->prepare('SELECT id, fire_at FROM timers WHERE dialog_id = ? AND active = 1');
    $rows->execute([$dialogId]);
    foreach ($rows->fetchAll() as $r) {
        $t = strtotime($r['fire_at'] . ' UTC') - (int)round($minutes * 60);
        db()->prepare('UPDATE timers SET fire_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', $t), $r['id']]);
    }
    return timers_run_due($dialogId);
}
