<?php
declare(strict_types=1);

/**
 * Клиенты, диалоги, сообщения.
 *
 * Клиент узнаётся по ключам: «web:<cookie>», «tg:<telegram id>», «sandbox:<admin id>».
 * Диалог хранит состояние сценария: state, slots (JSON), counters (JSON).
 */

const CLIENT_FIELDS = ['first_name', 'last_name', 'middle_name', 'phone', 'email', 'messenger_contact'];

function get_client(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM clients WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function client_by_key(string $key): array
{
    $st = db()->prepare('SELECT client_id FROM client_keys WHERE key = ?');
    $st->execute([$key]);
    $id = $st->fetchColumn();
    if ($id === false) {
        db()->exec('INSERT INTO clients DEFAULT VALUES');
        $id = (int)db()->lastInsertId();
        db()->prepare('INSERT INTO client_keys(key, client_id) VALUES(?, ?)')->execute([$key, $id]);
    }
    db()->prepare("UPDATE clients SET last_seen_at = datetime('now') WHERE id = ?")->execute([$id]);
    return get_client((int)$id);
}

function normalize_phone(string $p): string
{
    $d = preg_replace('/\D+/', '', $p);
    if (strlen($d) === 11 && $d[0] === '8') $d = '7' . substr($d, 1);
    if (strlen($d) === 10 && $d[0] === '9') $d = '7' . $d;
    if (strlen($d) < 10 || strlen($d) > 15) return '';
    return '+' . $d;
}

function find_phone(string $text): string
{
    if (preg_match('/(?:\+?\d[\s\-()]*){10,15}/u', $text, $m)) return normalize_phone($m[0]);
    return '';
}

/** $onlyEmpty — заполнять только пустые поля (данные из Telegram не затирают сказанное клиентом). */
function update_client(int $id, array $data, bool $onlyEmpty = false): array
{
    $client = get_client($id);
    $set = [];
    foreach (CLIENT_FIELDS as $f) {
        if (!array_key_exists($f, $data)) continue;
        $v = trim((string)$data[$f]);
        if ($f === 'phone' && $v !== '') $v = normalize_phone($v);
        if ($f === 'email' && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) $v = '';
        $v = mb_substr($v, 0, 120);
        if ($v === '' || $v === $client[$f]) continue;
        if ($onlyEmpty && $client[$f] !== '') continue;
        $set[$f] = $v;
    }
    if (isset($data['notes'])) $set['notes'] = (string)$data['notes'];
    if ($set) {
        $sql = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($set)));
        db()->prepare("UPDATE clients SET $sql, updated_at = datetime('now') WHERE id = :id")->execute($set + ['id' => $id]);
    }
    return get_client($id);
}

function merge_clients(int $keepId, int $dropId): void
{
    if ($keepId === $dropId) return;
    $pdo = db();
    $drop = get_client($dropId);
    if (!$drop) return;
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE client_keys SET client_id = ? WHERE client_id = ?')->execute([$keepId, $dropId]);
    $pdo->prepare('UPDATE dialogs SET client_id = ? WHERE client_id = ?')->execute([$keepId, $dropId]);
    $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$dropId]);
    $pdo->commit();
    update_client($keepId, array_intersect_key($drop, array_flip(CLIENT_FIELDS)), true);
}

/** Привязка Telegram к клиенту (вход через Telegram на сайте / сообщение боту). */
function attach_telegram(int $clientId, array $tg): int
{
    $tgId = (int)$tg['id'];
    $st = db()->prepare('SELECT client_id FROM client_keys WHERE key = ?');
    $st->execute(['tg:' . $tgId]);
    $existing = $st->fetchColumn();
    if ($existing !== false && (int)$existing !== $clientId) {
        merge_clients((int)$existing, $clientId);
        $clientId = (int)$existing;
    } elseif ($existing === false) {
        db()->prepare('INSERT INTO client_keys(key, client_id) VALUES(?, ?)')->execute(['tg:' . $tgId, $clientId]);
    }
    $username = (string)($tg['username'] ?? '');
    db()->prepare("UPDATE clients SET tg_id = ?, tg_username = ?, updated_at = datetime('now') WHERE id = ?")->execute([$tgId, $username, $clientId]);
    update_client($clientId, [
        'first_name' => $tg['first_name'] ?? '',
        'last_name' => $tg['last_name'] ?? '',
        'messenger_contact' => $username !== '' ? 'Telegram @' . $username : '',
    ], true);
    return $clientId;
}

function client_display_name(array $c): string
{
    $n = trim(($c['last_name'] ?? '') . ' ' . ($c['first_name'] ?? ''));
    if ($n !== '') return $n;
    if (($c['phone'] ?? '') !== '') return $c['phone'];
    if (($c['tg_username'] ?? '') !== '') return '@' . $c['tg_username'];
    return 'Клиент #' . ($c['client_id'] ?? $c['id']);
}

function delete_client(int $id): void
{
    db()->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]); // каскадом: ключи, диалоги, сообщения, журналы
}

/* ------------------------------------------------------------------ */
/*  Диалоги                                                            */
/* ------------------------------------------------------------------ */

/** Текущий диалог клиента в канале; $new — начать новый (старый остаётся в истории). */
function current_dialog(int $clientId, string $channel, bool $new = false, string $extId = ''): int
{
    if (!$new) {
        $st = db()->prepare('SELECT id FROM dialogs WHERE client_id = ? AND channel = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$clientId, $channel]);
        if ($id = $st->fetchColumn()) return (int)$id;
    } else {
        $st = db()->prepare('SELECT id FROM dialogs WHERE client_id = ? AND channel = ? AND messages = 0 ORDER BY id DESC LIMIT 1');
        $st->execute([$clientId, $channel]);
        if ($id = $st->fetchColumn()) { dialog_reset((int)$id); return (int)$id; }
    }
    $sc = scenario_active();
    db()->prepare('INSERT INTO dialogs(client_id, channel, ext_id, scenario_id, state) VALUES(?, ?, ?, ?, ?)')
        ->execute([$clientId, $channel, $extId, $sc['_id'], $sc['meta']['entry_state']]);
    $id = (int)db()->lastInsertId();
    // таймеры стартового состояния (если есть)
    $d = dialog_load($id);
    timers_start_state($d, $sc, $d['state'], null);
    return $id;
}

function dialog_load(int $id): array
{
    $st = db()->prepare('SELECT * FROM dialogs WHERE id = ?');
    $st->execute([$id]);
    $d = $st->fetch();
    if (!$d) throw new RuntimeException("Диалог #$id не найден");
    $d['slots'] = jdec($d['slots']);
    $d['counters'] = jdec($d['counters']);
    return $d;
}

function dialog_save(array $d): void
{
    db()->prepare('UPDATE dialogs SET scenario_id = ?, state = ?, slots = ?, counters = ?, state_turns = ?, state_entered_at = ?, paused_until = ?, lead_id = ?, last_at = datetime(\'now\') WHERE id = ?')
        ->execute([$d['scenario_id'], $d['state'], jenc((object)$d['slots']), jenc((object)$d['counters']), $d['state_turns'], $d['state_entered_at'], $d['paused_until'], $d['lead_id'], $d['id']]);
}

/** Сброс диалога в начальное состояние текущей версии сценария (песочница). */
function dialog_reset(int $id, bool $clearMessages = false): void
{
    $sc = scenario_active();
    db()->prepare("UPDATE timers SET active = 0, result = 'сброс' WHERE dialog_id = ? AND active = 1")->execute([$id]);
    db()->prepare("UPDATE dialogs SET scenario_id = ?, state = ?, slots = '{}', counters = '{}', state_turns = 0, state_entered_at = datetime('now'), paused_until = NULL, lead_id = 0 WHERE id = ?")
        ->execute([$sc['_id'], $sc['meta']['entry_state'], $id]);
    if ($clearMessages) {
        foreach (['messages', 'transitions', 'llm_calls', 'actions_log', 'timers'] as $t) db()->prepare("DELETE FROM $t WHERE dialog_id = ?")->execute([$id]);
        db()->prepare('UPDATE dialogs SET messages = 0 WHERE id = ?')->execute([$id]);
    }
}

function save_message(int $dialogId, string $role, string $content, ?array $attachment = null, string $state = ''): int
{
    db()->prepare('INSERT INTO messages(dialog_id, role, content, attachment, state) VALUES(?,?,?,?,?)')
        ->execute([$dialogId, $role, $content, $attachment ? jenc($attachment) : null, $state]);
    $id = (int)db()->lastInsertId();
    db()->prepare("UPDATE dialogs SET messages = messages + 1, last_at = datetime('now') WHERE id = ?")->execute([$dialogId]);
    return $id;
}

function dialog_messages(int $dialogId, ?int $limit = null, int $afterId = 0): array
{
    $sql = 'SELECT id, role, content, attachment, state, created_at FROM messages WHERE dialog_id = ? AND id > ? ORDER BY id DESC' . ($limit ? ' LIMIT ' . (int)$limit : '');
    $st = db()->prepare($sql);
    $st->execute([$dialogId, $afterId]);
    return array_reverse($st->fetchAll());
}

function recent_dialogs(array $f = [], int $limit = 300): array
{
    $sql = "SELECT d.*, c.first_name, c.last_name, c.phone, c.tg_username,
                   (SELECT content FROM messages m WHERE m.dialog_id = d.id ORDER BY m.id DESC LIMIT 1) AS last_text,
                   (SELECT role FROM messages m WHERE m.dialog_id = d.id ORDER BY m.id DESC LIMIT 1) AS last_role
            FROM dialogs d JOIN clients c ON c.id = d.client_id WHERE d.messages > 0";
    $args = [];
    if (($f['channel'] ?? '') !== '') { $sql .= ' AND d.channel = ?'; $args[] = $f['channel']; }
    else $sql .= " AND d.channel != 'sandbox'";
    if (($f['state'] ?? '') !== '') { $sql .= ' AND d.state = ?'; $args[] = $f['state']; }
    if (($f['q'] ?? '') !== '') {
        $sql .= ' AND (c.first_name LIKE ? OR c.last_name LIKE ? OR c.phone LIKE ? OR c.tg_username LIKE ? OR d.slots LIKE ?)';
        array_push($args, ...array_fill(0, 5, '%' . $f['q'] . '%'));
    }
    $sql .= ' ORDER BY d.last_at DESC LIMIT ' . (int)$limit;
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}
