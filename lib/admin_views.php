<?php
declare(strict_types=1);

/** Экраны админки. Подключается из public/admin/index.php (там же h(), csrf_field()). */

/* ------------------------------------------------------------------ */
/*  Диалоги                                                            */
/* ------------------------------------------------------------------ */

function view_dialogs(): void
{
    $sc = scenario_active();
    $f = ['channel' => (string)($_GET['channel'] ?? ''), 'state' => (string)($_GET['state'] ?? ''), 'q' => trim((string)($_GET['q'] ?? ''))];
    if (!isset(CHANNEL_NAMES[$f['channel']])) $f['channel'] = '';
    $list = recent_dialogs($f);
    $qs = fn(array $p) => '?' . http_build_query(array_merge(['tab' => 'dialogs'], $f, $p), '', '&amp;');
    ?>
    <nav class="chips">
      <a href="<?= $qs(['channel' => '']) ?>" class="<?= $f['channel'] === '' ? 'on' : '' ?>">Все каналы</a>
      <?php foreach (['web', 'telegram', 'sandbox'] as $ch): ?><a href="<?= $qs(['channel' => $ch]) ?>" class="<?= $f['channel'] === $ch ? 'on' : '' ?>"><?= h(CHANNEL_NAMES[$ch]) ?></a><?php endforeach; ?>
    </nav>
    <form method="get" class="card search">
      <input type="hidden" name="tab" value="dialogs"><input type="hidden" name="channel" value="<?= h($f['channel']) ?>">
      <select name="state" style="width:auto;margin:0"><option value="">Все состояния</option>
        <?php foreach ($sc['states'] as $k => $s): ?><option value="<?= h($k) ?>" <?= $f['state'] === $k ? 'selected' : '' ?>><?= h($s['title'] ?? $k) ?></option><?php endforeach; ?>
      </select>
      <input name="q" value="<?= h($f['q']) ?>" placeholder="Поиск: имя, телефон, @ник, данные слотов">
      <button>Найти</button>
    </form>
    <div class="card">
      <h2>Диалоги (<?= count($list) ?>)</h2>
      <p class="hint">Время — <?= h(tz_label()) ?>. Песочница по умолчанию скрыта.</p>
      <?php if (!$list): ?><p class="hint">Пока нет диалогов.</p><?php else: ?>
      <table class="clients">
        <tr><th>Клиент</th><th>Канал</th><th>Состояние</th><th>Последнее сообщение</th><th>Сообщ.</th><th>Обновлён</th></tr>
        <?php foreach ($list as $d): $dsc = scenario_for($d); ?>
          <tr onclick="location='?tab=dialogs&id=<?= (int)$d['id'] ?>'">
            <td><a href="?tab=dialogs&id=<?= (int)$d['id'] ?>"><?= h(client_display_name($d + ['client_id' => $d['client_id']])) ?></a><br><small class="muted">#<?= (int)$d['id'] ?></small></td>
            <td class="nw"><?= h(CHANNEL_NAMES[$d['channel']] ?? $d['channel']) ?></td>
            <td><span class="badge t-<?= h(sc_state_type($dsc, $d['state'])) ?>"><?= h(sc_state_title($dsc, $d['state'])) ?></span>
              <?= $d['paused_until'] && $d['paused_until'] > now_utc() ? '<br><small class="muted">бот на паузе</small>' : '' ?></td>
            <td><small class="muted"><?= h(ROLE_NAMES[$d['last_role']] ?? '') ?>:</small> <?= h(mb_strimwidth((string)$d['last_text'], 0, 120, '…')) ?></td>
            <td><?= (int)$d['messages'] ?></td>
            <td class="nw"><?= h(fmt_dt($d['last_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </table><?php endif; ?>
    </div>
    <?php
}

/** Карточка диалога: переписка + состояние, слоты, таймеры, журналы, управление. Она же — песочница. */
function view_dialog_card(int $id, bool $sandbox): void
{
    try { $d = dialog_load($id); } catch (Throwable $e) { echo '<p class="err">' . h($e->getMessage()) . '</p>'; return; }
    $sc = scenario_for($d);
    $active = scenario_active();
    $c = get_client((int)$d['client_id']);
    $state = sc_state($sc, $d['state']);
    $back = $sandbox ? 'tab=sandbox' : 'tab=dialogs&id=' . $id;
    $msgs = dialog_messages($id, 300);
    $q = fn(string $sql) => db()->query($sql)->fetchAll();
    $transitions = $q("SELECT * FROM transitions WHERE dialog_id = $id ORDER BY id DESC LIMIT 40");
    $actions = $q("SELECT * FROM actions_log WHERE dialog_id = $id ORDER BY id DESC LIMIT 60");
    $timers = $q("SELECT * FROM timers WHERE dialog_id = $id ORDER BY active DESC, id DESC LIMIT 20");
    $llm = $q("SELECT * FROM llm_calls WHERE dialog_id = $id ORDER BY id DESC LIMIT 12");
    $req = (array)($state['slots_required'] ?? []);
    $opt = array_merge((array)($state['slots_optional'] ?? []), (array)($state['slots_collect'] ?? []));
    $paused = $d['paused_until'] && $d['paused_until'] > now_utc();
    ?>
    <?php if (!$sandbox): ?><p><a href="?tab=dialogs">← Все диалоги</a></p><?php endif; ?>
    <?php if ($sc['_id'] !== $active['_id']): ?>
      <div class="warn">Диалог идёт по версии сценария #<?= (int)$sc['_id'] ?> (<?= h($sc['_version']) ?>), активна #<?= (int)$active['_id'] ?>.
        <form method="post" class="inline-btn"><?= csrf_field('dlg_upgrade', ['dialog' => $id, 'back' => $back]) ?><button class="sec">Перевести на активную</button></form></div>
    <?php endif; ?>
    <div class="two">
      <div class="card">
        <h2><?= $sandbox ? 'Песочница' : h(client_display_name($c)) ?> <small class="muted">· диалог #<?= $id ?> · <?= h(CHANNEL_NAMES[$d['channel']] ?? $d['channel']) ?></small></h2>
        <?php if ($sandbox): ?>
          <p class="hint">Пишите как клиент. Ответ идёт по активному сценарию; в Битрикс24 <?= setting('b24_sandbox_live') === '1' && b24_live('sandbox') ? '<b class="bad">пишется по-настоящему</b>' : 'ничего не уходит — в журнале видно, что ушло бы' ?>.</p>
        <?php else: ?>
          <p class="hint"><?= h(trim(implode(' · ', array_filter([$c['phone'], $c['email'], $c['messenger_contact'], $c['tg_username'] ? '@' . $c['tg_username'] : ''])))) ?: 'контактов нет' ?>
            <?php if ((int)$d['lead_id'] > 0): ?> · <a href="<?= h(b24_lead_url((int)$d['lead_id'])) ?>" target="_blank">лид #<?= (int)$d['lead_id'] ?> в Б24</a><?php endif; ?></p>
        <?php endif; ?>
        <div class="chatbox"><div class="chatlog">
          <?php $prevState = null; foreach ($msgs as $m): $a = $m['attachment'] ? jdec($m['attachment']) : null; ?>
            <?php if ($m['state'] !== '' && $m['state'] !== $prevState && $m['role'] === 'assistant'): $prevState = $m['state']; ?>
              <div class="msg system">— <?= h(sc_state_title($sc, $m['state'])) ?> —</div>
            <?php endif; ?>
            <div class="msg <?= h($m['role']) ?>"><small><?= h(ROLE_NAMES[$m['role']] ?? $m['role']) ?> · <?= h(fmt_dt($m['created_at'], 'd.m H:i:s')) ?></small>
              <div class="body"><?php if ($a && ($a['kind'] ?? '') === 'qr'): ?><img src="../qr.php?c=<?= h($a['code']) ?>" alt="QR" style="width:160px;display:block;margin-bottom:6px;border-radius:6px"><?php endif; ?><?= h($m['content']) ?><?php if ($a && ($a['kind'] ?? '') !== 'qr'): ?>
                <br>📎 <?= h($a['name'] ?? 'файл') ?><?php endif; ?></div></div>
          <?php endforeach; ?>
          <span id="end"></span>
        </div></div>
        <?php if ($sandbox): ?>
          <form method="post" enctype="multipart/form-data" class="send-row" data-busy="…">
            <?= csrf_field('sandbox_send', ['back' => $back]) ?>
            <textarea name="text" rows="2" placeholder="Сообщение клиента… (Enter — отправить, Shift+Enter — перенос)" data-submit autofocus></textarea>
            <label title="Файл (резюме)" style="margin:0"><input type="file" name="file" style="width:120px;font-size:12px"></label>
            <button>Отправить</button>
          </form>
          <div class="ctl" style="margin-top:10px">
            <form method="post" onsubmit="return confirm('Начать новый тестовый диалог? Текущий удалится.')"><?= csrf_field('sandbox_new') ?><button class="sec">Новый тестовый диалог</button></form>
            <?php if (b24_configured() && cfg('bitrix_mode') === 'live'): ?>
              <form method="post"><?= csrf_field('sandbox_b24', ['back' => $back, 'on' => setting('b24_sandbox_live') === '1' ? '' : '1']) ?>
                <button class="sec"><?= setting('b24_sandbox_live') === '1' ? 'Отключить запись в Б24 из песочницы' : 'Писать из песочницы в Б24' ?></button></form>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <form method="post" class="send-row"><?= csrf_field('dlg_operator', ['dialog' => $id, 'back' => $back]) ?>
            <textarea name="text" rows="2" placeholder="Ответить клиенту от имени менеджера (бот встанет на паузу)"></textarea><button>Отправить</button></form>
        <?php endif; ?>
      </div>

      <div>
        <div class="card">
          <h2>Состояние: <span class="badge t-<?= h($state['type'] ?? 'conversation') ?>"><?= h($state['title'] ?? $d['state']) ?></span></h2>
          <p class="hint"><code><?= h($d['state']) ?></code> · <?= h($state['type'] ?? 'conversation') ?> · ходов в состоянии: <?= (int)$d['state_turns'] ?><?= !empty($state['max_turns']) ? ' из ' . (int)$state['max_turns'] : '' ?> · с <?= h(fmt_dt($d['state_entered_at'], 'd.m H:i')) ?>
            <?php if ($paused): ?><br><b>Бот на паузе до <?= h(fmt_dt($d['paused_until'], 'H:i')) ?></b>
              <form method="post" class="inline-btn"><?= csrf_field('dlg_unpause', ['dialog' => $id, 'back' => $back]) ?><button class="sec">Снять паузу</button></form><?php endif; ?></p>
          <?php if (!empty($state['goal'])): ?><p class="hint">Цель: <?= h($state['goal']) ?></p><?php endif; ?>
          <div class="ctl">
            <form method="post"><?= csrf_field('dlg_event', ['dialog' => $id, 'back' => $back]) ?>
              <select name="event">
                <?php foreach (scenario_events($sc) as $ev): ?><option value="<?= h($ev) ?>"><?= h(EVENT_NAMES[$ev] ?? $ev) ?></option><?php endforeach; ?>
              </select><button class="sec">Событие</button></form>
            <form method="post"><?= csrf_field('dlg_ff', ['dialog' => $id, 'back' => $back]) ?>
              <input type="number" name="minutes" value="15" min="1" step="1"><button class="sec">Прошло, мин</button></form>
          </div>
          <div class="ctl">
            <form method="post"><?= csrf_field('dlg_jump', ['dialog' => $id, 'back' => $back]) ?>
              <select name="state"><?php foreach ($sc['states'] as $k => $s): ?><option value="<?= h($k) ?>" <?= $k === $d['state'] ? 'selected' : '' ?>><?= h($s['title'] ?? $k) ?></option><?php endforeach; ?></select>
              <button class="sec" onclick="return confirm('Перевести диалог в это состояние? Выполнятся действия входа (в т. ч. Битрикс).')">Перейти</button></form>
            <form method="post" onsubmit="return confirm('Сбросить состояние и слоты? Переписка останется.')"><?= csrf_field('dlg_reset', ['dialog' => $id, 'back' => $back]) ?><button class="sec">Сброс</button></form>
            <?php if (!$sandbox): ?><form method="post" onsubmit="return confirm('Удалить диалог целиком?')"><?= csrf_field('dlg_delete', ['dialog' => $id]) ?><button class="danger">Удалить</button></form><?php endif; ?>
          </div>
        </div>

        <div class="card">
          <h2>Слоты</h2>
          <p class="hint"><span class="bad">*</span> — обязательные для текущего состояния; подсвечены незаполненные.</p>
          <table class="kv">
            <?php
            $names = array_unique(array_merge($req, $opt, array_keys($d['slots'])));
            foreach ($names as $name):
                $def = sc_slot($sc, $name);
                $v = $d['slots'][$name] ?? null;
                $isReq = in_array($name, $req, true);
                $filled = slot_filled($v ?? ($def['default'] ?? null), $def);
            ?>
              <tr class="<?= $isReq ? 'req' : '' ?> <?= $isReq && !$filled ? 'miss' : '' ?>"><td><?= h($name) ?></td>
                <td><?= $v === null ? '<span class="empty">—</span>' : h(value_text($v)) ?><?php if (is_array($v) && isset($v['file'])): ?> <small class="muted">(<?= round(($v['size'] ?? 0) / 1024) ?> КБ)</small><?php endif; ?></td></tr>
            <?php endforeach; ?>
          </table>
          <details><summary>Записать слот вручную</summary>
            <form method="post" class="ctl" style="margin-top:8px"><?= csrf_field('dlg_slot', ['dialog' => $id, 'back' => $back]) ?>
              <select name="slot"><?php foreach (array_keys((array)($sc['slots'] ?? [])) as $n): ?><option><?= h($n) ?></option><?php endforeach; ?></select>
              <input name="value" placeholder="значение (пусто — очистить)" style="width:180px"><button class="sec">Записать</button></form>
          </details>
          <?php if ($d['counters']): ?><p class="hint" style="margin-top:8px">Счётчики: <?= h(implode(', ', array_map(fn($k, $v) => "$k=$v", array_keys($d['counters']), $d['counters']))) ?></p><?php endif; ?>
        </div>

        <div class="card">
          <h2>Таймеры</h2>
          <?php if (!$timers): ?><p class="hint">Нет.</p><?php else: ?>
          <table class="feed">
            <?php foreach ($timers as $t): $spec = jdec($t['spec']); ?>
              <tr><td class="t"><?= h(fmt_dt($t['fire_at'], 'd.m H:i')) ?></td>
                <td><b><?= h($t['timer_id']) ?></b> <small class="muted">(<?= h(sc_state_title($sc, $t['state'])) ?>)</small><br>
                  <small><?= h(isset($spec['action']['say']) ? '«' . mb_strimwidth((string)$spec['action']['say'], 0, 60, '…') . '»' : 'событие ' . ($spec['action']['event'] ?? '?')) ?></small></td>
                <td><?= $t['active'] ? '<span class="st st-new">ждёт</span>' : '<small class="muted">' . h($t['result']) . '</small>' ?></td></tr>
            <?php endforeach; ?>
          </table><?php endif; ?>
        </div>

        <div class="card">
          <h2>Переходы</h2>
          <?php if (!$transitions): ?><p class="hint">Пока не было.</p><?php else: ?>
          <table class="feed">
            <?php foreach ($transitions as $t): ?>
              <tr><td class="t"><?= h(fmt_dt($t['created_at'], 'H:i:s')) ?></td>
                <td><?= h(sc_state_title($sc, $t['from_state'])) ?> <span class="arrow">→</span> <b><?= h(sc_state_title($sc, $t['to_state'])) ?></b><br><small class="muted"><?= h($t['reason']) ?></small></td></tr>
            <?php endforeach; ?>
          </table><?php endif; ?>
        </div>

        <div class="card">
          <h2>Действия и инструменты</h2>
          <?php if (!$actions): ?><p class="hint">Пока не было.</p><?php else: ?>
          <table class="feed">
            <?php foreach ($actions as $a): ?>
              <tr><td class="t"><?= h(fmt_dt($a['created_at'], 'H:i:s')) ?></td>
                <td class="<?= $a['ok'] ? '' : 'fail' ?>"><b><?= h($a['action']) ?></b> — <?= h(mb_strimwidth($a['result'], 0, 220, '…')) ?>
                  <?php if ($a['params'] !== ''): ?><details><summary><small>параметры</small></summary><pre><?= h(json_encode(json_decode($a['params'], true), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?></td></tr>
            <?php endforeach; ?>
          </table><?php endif; ?>
        </div>

        <div class="card">
          <h2>Вызовы модели</h2>
          <p class="hint">classify — разбор реплики (интент и слоты), respond — ответ клиенту. Последние 12.</p>
          <?php foreach ($llm as $l): $req = jdec($l['request']); ?>
            <details><summary><small class="muted"><?= h(fmt_dt($l['created_at'], 'H:i:s')) ?></small> <b><?= h($l['kind']) ?></b> · <?= (int)$l['tokens'] ?> ток. · <?= round($l['ms'] / 1000, 1) ?> с<?= $l['error'] ? ' · <span class="bad">' . h($l['error']) . '</span>' : '' ?></summary>
              <p class="hint" style="margin-top:6px">Ответ модели:</p><pre><?= h(pretty_json_or_text($l['response'])) ?></pre>
              <p class="hint">Что ушло в модель:</p>
              <?php foreach ((array)($req['messages'] ?? []) as $m): if (!is_array($m)) continue; ?>
                <pre><b><?= h($m['role'] ?? '') ?>:</b> <?= h(is_string($m['content'] ?? null) ? $m['content'] : jenc($m['tool_calls'] ?? $m['content'] ?? '')) ?></pre>
              <?php endforeach; ?>
            </details>
          <?php endforeach; ?>
          <?php if (!$llm): ?><p class="hint">Пока не было.</p><?php endif; ?>
        </div>
      </div>
    </div>
    <?php
}

const EVENT_NAMES = [
    'payment.paid' => 'оплата прошла', 'payment.failed' => 'оплата не прошла', 'payment_timeout' => 'истекло время оплаты',
    'manager_took_over' => 'менеджер взял диалог', 'followup_due' => 'пора напомнить (отложено)', 'turn_limit_exceeded' => 'лимит ходов',
    'invoice_sent' => 'счёт отправлен', 'operator_message' => 'оператор написал', 'client_message' => 'клиент написал',
];

/** События, упомянутые в сценарии (для выпадающего списка). */
function scenario_events(array $sc): array
{
    $ev = [];
    $walk = function ($v) use (&$walk, &$ev) {
        if (!is_array($v)) return;
        if (isset($v['event']) && is_string($v['event'])) $ev[$v['event']] = true;
        foreach ($v as $x) $walk($x);
    };
    $walk($sc['states'] ?? []);
    $walk($sc['global_transitions'] ?? []);
    unset($ev['client_message'], $ev['operator_message'], $ev['invoice_sent']);
    return array_keys($ev);
}

function pretty_json_or_text(string $s): string
{
    $j = json_decode($s, true);
    return is_array($j) ? (string)json_encode($j, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : $s;
}

/* ------------------------------------------------------------------ */
/*  Сценарий                                                           */
/* ------------------------------------------------------------------ */

function view_scenario(array $view): void
{
    $sc = scenario_active();
    [$E, $W] = $view['report'] ?? scenario_validate($sc);
    $json = $view['json'] ?? json_encode(array_diff_key($sc, ['_id' => 1, '_version' => 1]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $todos = scenario_todos($sc);
    ?>
    <div class="card">
      <h2>Активный сценарий: <?= h($sc['meta']['name'] ?? '') ?> <small class="muted">v<?= h($sc['meta']['version'] ?? '') ?> · версия #<?= (int)$sc['_id'] ?></small></h2>
      <p class="hint">Новые диалоги идут по активной версии; начатые — по своей (в карточке диалога его можно перевести на активную).
        <?= !empty($sc['settings']['test_mode']) ? '<b>Тестовый режим:</b> счета демонстрационные.' : '' ?></p>
      <table class="states">
        <tr><th>Состояние</th><th>Тип</th><th>Переходы</th><th>Действия</th></tr>
        <?php foreach ($sc['states'] as $k => $s): ?>
          <tr><td><b><?= h($s['title'] ?? $k) ?></b><br><code><?= h($k) ?></code></td><td><span class="badge t-<?= h($s['type'] ?? 'conversation') ?>"><?= h($s['type'] ?? 'conversation') ?></span></td>
            <td><?php foreach ((array)($s['transitions'] ?? []) as $t): ?><div><span class="arrow">→</span> <b><?= h($t['to'] ?? '·') ?></b> <small class="muted"><?= h(cond_text((array)($t['on'] ?? []))) ?></small></div><?php endforeach; ?></td>
            <td><small><?= h(implode(', ', array_map(fn($a) => $a['action'] ?? '?', array_merge((array)($s['on_enter'] ?? []), (array)($s['on_exit'] ?? []))))) ?><?= !empty($s['timers']) ? '<br>⏱ ' . h(implode(', ', array_column($s['timers'], 'id'))) : '' ?></small></td></tr>
        <?php endforeach; ?>
        <tr><td colspan="4" class="muted"><small>Глобальные (из любого состояния): <?php foreach ((array)($sc['global_transitions'] ?? []) as $t): ?>· <?= h(cond_text((array)($t['on'] ?? []))) ?> → <?= h($t['to'] ?? ($t['action'] ?? '')) ?> <?php endforeach; ?></small></td></tr>
      </table>
    </div>
    <?php if ($todos): ?>
      <details class="card"><summary>Не заполнено в сценарии (TODO): <?= count($todos) ?></summary>
        <ul class="list"><?php foreach ($todos as [$p, $v]): ?><li><code><?= h($p) ?></code> — <?= h($v) ?></li><?php endforeach; ?></ul>
      </details>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="card">
      <?= csrf_field('scenario_save') ?>
      <h2>Редактор</h2>
      <?php if (!empty($view['error'])): ?><p class="err" style="white-space:pre-wrap"><?= h($view['error']) ?></p><?php endif; ?>
      <?php if ($E || $W): ?>
        <div class="<?= $E ? 'err' : 'warn' ?>">
          <?= $E ? '<b>Ошибки (сохранить нельзя):</b><ul class="list">' . implode('', array_map(fn($x) => '<li>' . h($x) . '</li>', $E)) . '</ul>' : '' ?>
          <?= $W ? '<details><summary>Предупреждения: ' . count($W) . '</summary><ul class="list">' . implode('', array_map(fn($x) => '<li>' . h($x) . '</li>', $W)) . '</ul></details>' : '' ?>
        </div>
      <?php elseif (isset($view['report'])): ?><p class="ok">Проверка пройдена.</p><?php endif; ?>
      <textarea name="json" class="code" spellcheck="false"><?= h($json) ?></textarea>
      <div class="grid" style="margin-top:10px">
        <label>Комментарий к версии<input name="note" placeholder="что поменяли"></label>
        <label>…или загрузить файл JSON<input type="file" name="file" accept=".json,application/json"></label>
      </div>
      <label class="row"><input type="checkbox" name="activate" value="1" checked> Сделать активной сразу</label>
      <button>Сохранить как новую версию</button>
      <button class="sec" formaction="?tab=scenario" name="do" value="scenario_check">Только проверить</button>
    </form>

    <div class="card">
      <h2>Версии</h2>
      <table>
        <tr><th>#</th><th>Версия</th><th>Комментарий</th><th>Кто</th><th>Когда</th><th>Диалогов</th><th></th></tr>
        <?php foreach (scenario_versions() as $v): ?>
          <tr class="<?= $v['active'] ? '' : 'off' ?>"><td><?= (int)$v['id'] ?></td><td><?= h($v['version']) ?></td><td><?= h($v['note']) ?></td><td><?= h($v['created_by']) ?></td>
            <td class="nw"><?= h(fmt_dt($v['created_at'])) ?></td><td><?= (int)$v['dialogs'] ?></td>
            <td><?php if ($v['active']): ?><span class="st st-done">активна</span><?php else: ?>
              <form method="post"><?= csrf_field('scenario_activate', ['id' => $v['id']]) ?><button class="sec" style="margin:0">Сделать активной</button></form><?php endif; ?></td></tr>
        <?php endforeach; ?>
      </table>
    </div>
    <?php
}

/* ------------------------------------------------------------------ */
/*  Каталог                                                            */
/* ------------------------------------------------------------------ */

function catalog_from_post(array $p): array
{
    $rows = function (string $key, array $cols) use ($p): array {
        $out = [];
        $data = (array)($p[$key] ?? []);
        $n = count((array)($data[$cols[0]] ?? []));
        for ($i = 0; $i < $n; $i++) {
            $r = [];
            foreach ($cols as $c) $r[$c] = trim((string)($data[$c][$i] ?? ''));
            if ($r[$cols[0]] === '') continue;
            $out[] = $r;
        }
        return $out;
    };
    $variants = $rows('v', ['id', 'name', 'price', 'currency', 'term', 'includes']);
    foreach ($variants as &$v) { if ($v['currency'] === '') unset($v['currency']); }
    unset($v);
    $options = $rows('o', ['id', 'name', 'type', 'price_delta', 'values']);
    foreach ($options as &$o) {
        $o['values'] = array_values(array_filter(array_map('trim', explode(',', $o['values']))));
        if ($o['type'] !== 'choice') unset($o['values']);
    }
    unset($o);
    $params = trim((string)($p['params'] ?? ''));
    $paramsArr = $params === '' ? [] : json_decode($params, true);
    if (!is_array($paramsArr)) throw new RuntimeException('Параметры — должен быть JSON-объект (или пусто): ' . json_last_error_msg());
    return [
        'id' => str_replace('-', '_', strtolower(trim((string)$p['id']))),
        'name' => trim((string)$p['name']) ?: trim((string)$p['id']),
        'section' => (string)$p['section'],
        'active' => !empty($p['active']),
        'currency' => (string)($p['currency'] ?? 'RUB'),
        'price_per' => ($p['price_per'] ?? 'order') === 'participant' ? 'participant' : 'order',
        'sale_mode' => ($p['sale_mode'] ?? 'bot') === 'manager' ? 'manager' : 'bot',
        'variants' => $variants,
        'options' => $options,
        'params' => $paramsArr,
        'bot_info' => trim(str_replace("\r\n", "\n", (string)($p['bot_info'] ?? ''))),
        '_sort' => (int)($p['sort'] ?? 100),
    ];
}

function view_catalog(): void
{
    $all = catalog_all();
    $id = (string)($_GET['id'] ?? '');
    $p = $id === 'new' ? ['id' => '', 'name' => '', 'section' => 'general', 'active' => true, 'currency' => 'RUB', 'price_per' => 'order', 'variants' => [], 'options' => [], 'params' => [], 'bot_info' => '', '_sort' => 100] : ($all[$id] ?? null);
    ?>
    <div class="card">
      <h2>Каталог</h2>
      <p class="hint">Цены и условия бот берёт только отсюда. Сумму счёта считает программа: (цена варианта + опции) × участники, если цена «за участника». Пустая цена — бот говорит «стоимость уточнит менеджер» и счёт не выставляет.</p>
      <table class="clients">
        <tr><th>Товар</th><th>Раздел</th><th>Варианты</th><th>Продаёт</th><th>Обновлён</th></tr>
        <?php foreach ($all as $x): ?>
          <tr class="<?= $x['active'] ? '' : 'off' ?>" onclick="location='?tab=catalog&id=<?= urlencode($x['id']) ?>'">
            <td><a href="?tab=catalog&id=<?= urlencode($x['id']) ?>"><?= h($x['name'] ?? $x['id']) ?></a><br><code><?= h($x['id']) ?></code></td>
            <td><?= h($x['section']) ?></td>
            <td><small><?= h(implode('; ', array_map(fn($v) => ($v['name'] ?? $v['id']) . ' — ' . money_fmt($v['price'] ?? null, (string)($v['currency'] ?? $x['currency'] ?? 'RUB')), $x['variants'] ?? []))) ?></small></td>
            <td><?= ($x['sale_mode'] ?? 'bot') === 'manager' ? 'менеджер' : 'бот' ?></td>
            <td class="nw"><?= h(fmt_dt($x['_updated'])) ?></td></tr>
        <?php endforeach; ?>
      </table>
      <p><a href="?tab=catalog&id=new">+ Добавить товар</a></p>
    </div>
    <?php if (!$p) return; ?>
    <form method="post" class="card">
      <?= csrf_field('catalog_save', ['orig_id' => $p['id']]) ?>
      <h2><?= $p['id'] === '' ? 'Новый товар' : h($p['name'] ?? $p['id']) ?></h2>
      <div class="grid">
        <label>Код (латиница)<input name="id" value="<?= h($p['id']) ?>" required pattern="[A-Za-z0-9_\-]{2,40}" title="Латиница, цифры, _ (2–40 символов). Заглавные станут строчными." oninput="this.value=this.value.toLowerCase().replace(/-/g,'_')"></label>
        <label>Название<input name="name" value="<?= h($p['name'] ?? '') ?>"></label>
        <label>Раздел<input name="section" value="<?= h($p['section']) ?>" placeholder="cards / green_card / visa"></label>
        <label>Валюта<select name="currency"><?php foreach (['RUB', 'USD', 'EUR'] as $cur): ?><option <?= ($p['currency'] ?? 'RUB') === $cur ? 'selected' : '' ?>><?= $cur ?></option><?php endforeach; ?></select></label>
        <label>Цена указана<select name="price_per"><option value="order">за заказ</option><option value="participant" <?= ($p['price_per'] ?? '') === 'participant' ? 'selected' : '' ?>>за каждого участника</option></select></label>
        <label>Кто продаёт<select name="sale_mode"><option value="bot">бот выставляет счёт</option><option value="manager" <?= ($p['sale_mode'] ?? '') === 'manager' ? 'selected' : '' ?>>менеджер (бот только консультирует)</option></select></label>
        <label>Порядок<input name="sort" value="<?= (int)($p['_sort'] ?? 100) ?>"></label>
        <label class="row" style="margin-top:28px"><input type="checkbox" name="active" value="1" <?= !empty($p['active']) ? 'checked' : '' ?>> Активен (бот предлагает)</label>
      </div>
      <h2 style="margin-top:8px">Варианты / тарифы <small class="muted">— выбирается один</small></h2>
      <table class="vtab" id="vt">
        <tr><th>Код</th><th>Название</th><th>Цена</th><th>Валюта</th><th>Срок</th><th>Что входит</th><th></th></tr>
        <?php foreach (array_merge($p['variants'] ?? [], [[]]) as $v): ?>
          <tr><td><input name="v[id][]" value="<?= h($v['id'] ?? '') ?>" style="width:110px"></td><td><input name="v[name][]" value="<?= h($v['name'] ?? '') ?>"></td>
            <td><input name="v[price][]" value="<?= h(isset($v['price']) && $v['price'] !== null ? (string)$v['price'] : '') ?>" style="width:90px" placeholder="пусто"></td>
            <td><input name="v[currency][]" value="<?= h($v['currency'] ?? '') ?>" style="width:55px" placeholder="—"></td>
            <td><input name="v[term][]" value="<?= h($v['term'] ?? '') ?>" style="width:110px"></td><td><input name="v[includes][]" value="<?= h($v['includes'] ?? '') ?>"></td>
            <td><button type="button" class="sec" onclick="this.closest('tr').remove()">✕</button></td></tr>
        <?php endforeach; ?>
      </table>
      <button type="button" class="sec" onclick="addRow('vt')">+ вариант</button>
      <h2 style="margin-top:14px">Опции <small class="muted">— можно несколько</small></h2>
      <table class="vtab" id="ot">
        <tr><th>Код</th><th>Название</th><th>Тип</th><th>Надбавка</th><th>Значения (для «выбор»)</th><th></th></tr>
        <?php foreach (array_merge($p['options'] ?? [], [[]]) as $o): ?>
          <tr><td><input name="o[id][]" value="<?= h($o['id'] ?? '') ?>" style="width:110px"></td><td><input name="o[name][]" value="<?= h($o['name'] ?? '') ?>"></td>
            <td><select name="o[type][]"><option value="addon">доплата</option><option value="choice" <?= ($o['type'] ?? '') === 'choice' ? 'selected' : '' ?>>выбор</option></select></td>
            <td><input name="o[price_delta][]" value="<?= h(isset($o['price_delta']) && $o['price_delta'] !== null ? (string)$o['price_delta'] : '') ?>" style="width:90px" placeholder="пусто"></td>
            <td><input name="o[values][]" value="<?= h(implode(', ', (array)($o['values'] ?? []))) ?>" placeholder="RUB, USD"></td>
            <td><button type="button" class="sec" onclick="this.closest('tr').remove()">✕</button></td></tr>
        <?php endforeach; ?>
      </table>
      <button type="button" class="sec" onclick="addRow('ot')">+ опция</button>
      <label style="margin-top:14px">Для бота — описание, что входит, условия, ограничения<textarea name="bot_info" rows="5" style="font-family:inherit"><?= h($p['bot_info'] ?? '') ?></textarea></label>
      <label>Параметры (JSON) — период регистрации, акции и т. п.<textarea name="params" rows="6"><?= h($p['params'] ? json_encode($p['params'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '') ?></textarea></label>
      <button>Сохранить</button>
    </form>
    <?php if ($p['id'] !== ''): ?>
      <form method="post" class="inline" onsubmit="return confirm('Удалить товар из каталога?')"><?= csrf_field('catalog_delete', ['id' => $p['id']]) ?><button class="danger">Удалить товар</button></form>
    <?php endif; ?>
    <script>
    function addRow(id) {
      const t = document.getElementById(id), last = t.rows[t.rows.length - 1], r = last.cloneNode(true);
      r.querySelectorAll('input').forEach(i => i.value = ''); t.tBodies[0].appendChild(r);
    }
    </script>
    <?php
}

/* ------------------------------------------------------------------ */
/*  Материалы                                                          */
/* ------------------------------------------------------------------ */

function view_docs(array $view): void
{
    $docs = db()->query('SELECT * FROM documents ORDER BY section, id DESC')->fetchAll();
    $sc = scenario_active();
    // разделы, которые ждёт сценарий
    $wanted = [];
    foreach ($sc['states'] as $k => $s) foreach ((array)($s['knowledge'] ?? []) as $ref) {
        if (!str_starts_with($ref, 'kb/')) continue;
        $sec = normalize_section(preg_replace('/\{\{[^}]+\}\}/', '<страна>', $ref));
        $wanted[$sec][] = $s['title'] ?? $k;
    }
    $have = array_column(kb_sections(), null, 'section');
    $known = array_unique(array_merge(array_keys($have), array_filter(array_keys($wanted), fn($s) => !str_contains($s, '<'))));
    sort($known);
    ?>
    <div class="card">
      <h2>Разделы базы знаний</h2>
      <p class="hint">Каждое состояние ищет только в своих разделах (поле <code>knowledge</code> сценария). Раздел включает вложенные: <code>visa</code> → <code>visa/italy</code>. Для визы по стране: <code>visa/&lt;страна&gt;</code> — страна как её называет клиент (например <code>visa/италия</code>).</p>
      <table>
        <tr><th>Раздел</th><th>Кто использует</th><th>Материалов</th></tr>
        <?php foreach (array_unique(array_merge(array_keys($wanted), array_keys($have))) as $s): ?>
          <tr><td><code><?= h($s) ?></code></td><td><small><?= h(implode(', ', array_unique($wanted[$s] ?? []))) ?: '<span class="muted">не используется сценарием</span>' ?></small></td>
            <td><?= isset($have[$s]) ? (int)$have[$s]['docs'] . ' (' . (int)$have[$s]['chunks'] . ' фрагм.)' : '<span class="bad">пусто</span>' ?></td></tr>
        <?php endforeach; ?>
      </table>
    </div>
    <form method="post" enctype="multipart/form-data" class="card" data-busy="Индексирую… не закрывайте страницу">
      <?= csrf_field('upload') ?>
      <h2>Загрузить материал</h2>
      <p class="hint">PDF, Word, TXT/Markdown, HTML, JSON (вопрос-ответ), Excel/CSV, ZIP. Лимит: <?= h(upload_limit_text()) ?>.</p>
      <div class="grid">
        <label>Раздел<input name="section" list="secs" required placeholder="visa/general"><datalist id="secs"><?php foreach ($known as $s): ?><option value="<?= h($s) ?>"><?php endforeach; ?></datalist></label>
        <label>Название<input name="title" placeholder="Как бот будет называть источник"></label>
      </div>
      <label>Файл<input type="file" name="file" accept=".<?= implode(',.', SUPPORTED_EXT) ?>" required></label>
      <label>Доверие<select name="trust"><option value="main">Проверенный — база компании, регламенты</option><option value="aux">Вспомогательный — черновики, ответы нейросетей</option></select></label>
      <button>Загрузить</button>
    </form>
    <div class="card">
      <h2>Материалы (<?= count($docs) ?>)</h2>
      <?php if (!$docs): ?><p class="hint">Пока пусто.</p><?php else: ?>
      <table>
        <tr><th>Раздел</th><th>Название</th><th>Фрагм.</th><th>Доверие</th><th>Добавлен</th><th></th></tr>
        <?php foreach ($docs as $d): ?>
          <tr class="<?= $d['active'] ? '' : 'off' ?>">
            <td><form method="post" style="display:flex;gap:4px"><?= csrf_field('doc_section', ['id' => $d['id']]) ?><input name="section" value="<?= h($d['section']) ?>" list="secs" style="width:140px;padding:4px 6px"><button class="sec" style="margin:0;padding:3px 8px">✓</button></form></td>
            <td><?= h($d['title']) ?> <small class="muted"><?= h(strtoupper($d['format'])) ?></small></td><td><?= (int)$d['chunks'] ?></td>
            <td><?= $d['trust'] === 'aux' ? 'вспомог.' : 'проверен.' ?></td><td class="nw"><?= h(fmt_dt($d['created_at'], 'd.m.Y')) ?></td>
            <td class="act">
              <form method="post"><?= csrf_field('doc_toggle', ['id' => $d['id']]) ?><button class="sec"><?= $d['active'] ? 'Выкл' : 'Вкл' ?></button></form>
              <form method="post"><?= csrf_field('doc_trust', ['id' => $d['id']]) ?><button class="sec">Доверие</button></form>
              <form method="post" onsubmit="return confirm('Удалить материал?')"><?= csrf_field('doc_delete', ['id' => $d['id']]) ?><button class="danger">✕</button></form>
            </td></tr>
        <?php endforeach; ?>
      </table><?php endif; ?>
    </div>
    <form method="post" class="card"><?= csrf_field('kb_test') ?>
      <h2>Проверить поиск</h2>
      <div class="grid"><label>Вопрос<input name="q" value="<?= h($view['kb_q'] ?? '') ?>" required></label>
        <label>Разделы через запятую<input name="sections" value="<?= h($view['kb_sections'] ?? 'visa') ?>"></label></div>
      <button>Найти</button>
      <?php if (isset($view['kb_res'])): ?>
        <p class="hint" style="margin-top:10px">Найдено: <?= count($view['kb_res']) ?></p>
        <?php foreach ($view['kb_res'] as $i => $c): ?><details><summary>[<?= $i + 1 ?>] <code><?= h($c['section']) ?></code> <?= h(chunk_label($c)) ?></summary><pre><?= h($c['text']) ?></pre></details><?php endforeach; ?>
      <?php endif; ?>
    </form>
    <?php
}

/* ------------------------------------------------------------------ */
/*  Настройки                                                          */
/* ------------------------------------------------------------------ */

function view_settings(array $view): void
{
    $sc = scenario_active();
    $smart = (int)(sc_setting($sc, 'hr.smart_entity_type_id') ?? 0);
    $hb = @filemtime(data_dir() . '/worker.heartbeat');
    ?>
    <form method="post" class="card"><?= csrf_field('settings_save') ?>
      <h2>Приветствие</h2>
      <p class="hint">Первое сообщение в веб-чате и по /start в Telegram. Оно сохраняется в диалог, и бот знает, что уже поздоровался.</p>
      <textarea name="welcome" rows="3" style="font-family:inherit"><?= h(setting('welcome')) ?></textarea>
      <h2 style="margin-top:12px">Кнопки-подсказки в веб-чате</h2>
      <p class="hint">По одной на строку. Показываются под приветствием.</p>
      <textarea name="suggestions" rows="4" style="font-family:inherit"><?= h(setting('suggestions')) ?></textarea>
      <button>Сохранить</button>
    </form>

    <div class="card">
      <h2>Состояние системы</h2>
      <ul class="list">
        <li>Модель: <code><?= h(cfg('chat_model', 'gpt-5-mini')) ?></code><?= cfg('openai_api_key', 'sk-...') === 'sk-...' ? ' — <b class="bad">ключ OpenAI не задан</b>' : '' ?></li>
        <li>Telegram: <?= cfg('telegram_token') ? 'бот @' . h(cfg('telegram_bot_username', '?')) : '<span class="bad">токен не задан</span>' ?> · уведомления менеджерам: <?= cfg('manager_chat_ids') ? count((array)cfg('manager_chat_ids')) . ' чат(ов)' : '<span class="muted">не настроены</span>' ?></li>
        <li>Воркер таймеров: <?= $hb && time() - $hb < 90 ? '<span class="good">работает</span>' : '<b class="bad">не запущен' . ($hb ? ' (последний раз ' . date('d.m H:i', $hb) . ')' : '') . '</b> — sudo systemctl start vb2-worker' ?></li>
        <li>Битрикс24: <?= b24_configured() ? h(b24_portal()) . ' · режим <b>' . h(cfg('bitrix_mode', 'dry')) . '</b>' . (cfg('bitrix_mode') !== 'live' ? ' (запросы не отправляются, только журнал)' : '') : '<span class="muted">вебхук не задан</span>' ?></li>
        <li>Часовой пояс: <?= h(tz_label()) ?></li>
      </ul>
    </div>

    <?php
}


/* ------------------------------------------------------------------ */
/*  Битрикс24: поля лида, справочник                                   */
/* ------------------------------------------------------------------ */

function view_bitrix(array $view): void
{
    $sc = scenario_active();
    $smart = (int)(sc_setting($sc, 'hr.smart_entity_type_id') ?? 0);
    $fields = jdec(setting('b24_lead_fields'));
    $map = b24_map();
    $at = setting('b24_lead_fields_at');
    // поля бота: известные + слоты сценария, которых нет в списке
    $botFields = BOT_FIELDS;
    foreach ((array)($sc['slots'] ?? []) as $n => $def) {
        if (!isset($botFields[$n]) && !preg_match(BOT_INTERNAL_SLOTS, $n) && !in_array($def['type'] ?? '', ['counter', 'state_ref'], true)) $botFields[$n] = [$def['label'] ?? $n, 'Другое'];
    }
    $groups = [];
    foreach ($botFields as $n => [$label, $g]) $groups[$g][$n] = $label;
    $taken = array_values(array_filter($map, fn($v) => $v !== '' && $v !== '-')); // одно поле Битрикса — одному полю бота
    $opt = function (string $sel) use ($fields): string {
        $o = '<option value=""' . ($sel === '' ? ' selected' : '') . '>— в комментарий лида —</option><option value="-"' . ($sel === '-' ? ' selected' : '') . '>— не передавать —</option>';
        foreach (['Стандартные поля' => false, 'Пользовательские поля' => true] as $title => $custom) {
            $o .= '<optgroup label="' . $title . '">';
            foreach ($fields as $code => $f) if ($f['custom'] === $custom) {
                $o .= '<option value="' . h($code) . '"' . ($sel === $code ? ' selected' : '') . '>' . h($f['title']) . ' · ' . h($code) . ' (' . h($f['type']) . ($f['multiple'] ? ', множ.' : '') . ')</option>';
            }
            $o .= '</optgroup>';
        }
        if ($sel !== '' && $sel !== '-' && !isset($fields[$sel])) $o .= '<option value="' . h($sel) . '" selected>' . h($sel) . ' (нет в загруженном списке)</option>';
        return $o;
    };
    ?>
    <div class="card">
      <h2>Битрикс24</h2>
      <p class="hint"><?= b24_configured() ? 'Портал: ' . h(b24_portal()) . ' · режим <b>' . h(cfg('bitrix_mode', 'dry')) . '</b>' . (cfg('bitrix_mode') !== 'live' ? ' — в лиды ничего не пишется, в журнале диалога видно, что ушло бы' : ' — лиды пишутся по-настоящему') : '<b class="bad">вебхук не задан в config.php</b>' ?></p>
      <form method="post" class="inline-btn" style="display:inline"><?= csrf_field('b24_fields_load') ?><button <?= b24_configured() ? '' : 'disabled' ?>><?= $fields ? 'Обновить поля лида с портала' : 'Загрузить поля лида с портала' ?></button></form>
      <?php if ($at): ?><span class="hint"> загружено <?= h(fmt_dt($at)) ?>: <?= count($fields) ?> полей, из них пользовательских <?= count(array_filter($fields, fn($f) => $f['custom'])) ?></span><?php endif; ?>
    </div>

    <form method="post" class="card"><?= csrf_field('b24_map_save') ?>
      <h2>Поля бота → поля лида</h2>
      <p class="hint">Для каждого поля, которое собирает бот, выберите существующее поле лида. «В комментарий» — текстом в блок бота в «Комментарии» лида (заметки менеджеров не затираются). Списки Битрикса заполняются по совпадению текста; да/нет — галочкой; числа — числом.
        <?= $fields ? '' : '<br><b>Сначала загрузите поля лида с портала.</b>' ?> <span style="background:#fff8e6;padding:0 4px">Подсвечено</span> — подобрано автоматически по названию, проверьте.</p>
      <table class="kv">
        <?php foreach ($groups as $g => $items): ?>
          <tr><td colspan="2" style="padding-top:14px;font-family:inherit;font-size:14px;color:#1d2330"><b><?= h($g) ?></b></td></tr>
          <?php foreach ($items as $n => $label):
            if (isset(BOT_FIELDS_FIXED[$n])): ?>
              <tr><td><?= h($label) ?><br><code><?= h($n) ?></code></td><td class="muted"><?= h(BOT_FIELDS_FIXED[$n]) ?></td></tr>
            <?php continue; endif;
            $cur = bot_field_target($sc, $n);
            $sug = '';
            if (!array_key_exists($n, $map) && $cur === '' && $fields) { $sug = bot_field_suggest($n, $fields, $taken); if ($sug !== '') $cur = $sug; }
            if ($cur !== '' && $cur !== '-') $taken[] = $cur;
          ?>
            <tr<?= $sug ? ' style="background:#fff8e6"' : '' ?>><td><?= h($label) ?><br><code><?= h($n) ?></code></td>
              <td><select name="map[<?= h($n) ?>]" style="margin:0"><?= $opt($cur) ?></select></td></tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </table>
      <button>Сохранить сопоставление</button>
    </form>

    <form method="post" class="card"><?= csrf_field('b24_reference') ?>
      <h2>Справочник Битрикс24</h2>
      <p class="hint">Покажет права вебхука, стадии лидов и воронки/стадии смарт-процесса <?= $smart ?: '?' ?> — чтобы вписать коды в сценарий (settings.hr). Ничего не меняет.</p>
      <button <?= b24_configured() ? '' : 'disabled' ?>>Запросить</button>
      <?php if ($r = $view['b24ref'] ?? null): ?>
        <?php foreach ($r['errors'] as $e): ?><p class="err"><?= h($e) ?></p><?php endforeach; ?>
        <p style="margin-top:12px"><b>Права вебхука:</b> <?= h(implode(', ', (array)$r['scope'])) ?: '—' ?></p>
        <?php if ($r['lead_statuses']): ?>
          <p><b>Стадии лидов</b> (STATUS_ID):</p>
          <table class="kv"><?php foreach ($r['lead_statuses'] as $s): ?><tr><td><?= h($s['id']) ?></td><td><?= h($s['name']) ?> <small class="muted"><?= h($s['semantics'] === 'F' ? 'провал' : ($s['semantics'] === 'S' ? 'успех' : '')) ?></small></td></tr><?php endforeach; ?></table>
        <?php endif; ?>
        <?php if ($r['smart']): ?><p style="margin-top:12px"><b>Смарт-процесс <?= $smart ?>:</b> <?= h($r['smart']['title'] ?? '') ?> <?= !empty($r['smart']['relations']) ? '' : '' ?></p><?php endif; ?>
        <?php foreach ($r['smart_categories'] as $c): ?>
          <p><b>Воронка «<?= h($c['name']) ?>»</b> — categoryId: <code><?= (int)$c['id'] ?></code><?= $c['default'] ? ' (по умолчанию)' : '' ?></p>
          <table class="kv"><?php foreach ($c['stages'] as $s): ?><tr><td><?= h($s['id']) ?></td><td><?= h($s['name']) ?></td></tr><?php endforeach; ?></table>
        <?php endforeach; ?>
      <?php endif; ?>
    </form>
    <?php
}

/* ------------------------------------------------------------------ */
/*  Пользователи                                                       */
/* ------------------------------------------------------------------ */

function view_users(array $me): void
{
    ?>
    <div class="card"><h2>Пользователи админки</h2>
      <table class="users">
        <tr><th>Логин</th><th>Имя</th><th>Роль</th><th>Активен</th><th>Новый пароль</th><th>Последний вход</th><th></th></tr>
        <?php foreach (list_users() as $u): $f = 'u' . $u['id']; ?>
          <tr class="<?= $u['active'] ? '' : 'off' ?>">
            <td><b><?= h($u['login']) ?></b><?= (int)$u['id'] === (int)$me['id'] ? ' <span class="hint">(вы)</span>' : '' ?></td>
            <td><input name="name" form="<?= $f ?>" value="<?= h($u['name']) ?>"></td>
            <td><select name="role" form="<?= $f ?>"><?php foreach (ROLES as $k => $v): ?><option value="<?= $k ?>"<?= $u['role'] === $k ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></td>
            <td><input type="checkbox" name="active" value="1" form="<?= $f ?>"<?= $u['active'] ? ' checked' : '' ?>></td>
            <td><input type="password" name="password" form="<?= $f ?>" autocomplete="new-password" minlength="8" placeholder="не менять"></td>
            <td class="nw"><?= h($u['last_login_at'] ? fmt_dt($u['last_login_at']) : '—') ?></td>
            <td class="act">
              <form method="post" id="<?= $f ?>"><?= csrf_field('user_save', ['id' => $u['id']]) ?><button>Сохранить</button></form>
              <?php if ((int)$u['id'] !== (int)$me['id']): ?><form method="post" onsubmit="return confirm('Удалить пользователя?')"><?= csrf_field('user_delete', ['id' => $u['id']]) ?><button class="danger">Удалить</button></form><?php endif; ?>
            </td></tr>
        <?php endforeach; ?>
      </table>
    </div>
    <form method="post" class="card narrow"><?= csrf_field('user_add') ?>
      <h2>Добавить пользователя</h2>
      <label>Логин<input name="login" autocomplete="off" autocapitalize="none" spellcheck="false" required></label>
      <label>Имя<input name="name"></label>
      <label>Пароль<input type="password" name="password" autocomplete="new-password" minlength="8" required></label>
      <label>Роль<select name="role"><?php foreach (ROLES as $k => $v): ?><option value="<?= $k ?>"<?= $k === 'manager' ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></label>
      <button>Добавить</button>
    </form>
    <?php
}

function view_me(array $me): void
{
    ?>
    <form method="post" class="card narrow"><?= csrf_field('my_password') ?>
      <input type="text" name="login" value="<?= h($me['login']) ?>" autocomplete="username" hidden>
      <h2>Сменить пароль</h2>
      <label>Текущий пароль<input type="password" name="old" autocomplete="current-password" required></label>
      <label>Новый пароль<input type="password" name="password" autocomplete="new-password" minlength="8" required></label>
      <label>Ещё раз<input type="password" name="password2" autocomplete="new-password" minlength="8" required></label>
      <button>Сменить</button>
    </form>
    <form method="post" class="card narrow" onsubmit="return confirm('Выйти на всех запомненных компьютерах?')"><?= csrf_field('my_forget') ?>
      <h2>Запомненные компьютеры</h2><button class="sec">Забыть все компьютеры</button>
    </form>
    <?php
}
