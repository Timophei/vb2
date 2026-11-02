<?php
declare(strict_types=1);

/**
 * vb2 — движок бота-консультанта, исполняющий сценарий состояний (JSON).
 *
 * Этот файл: конфиг, база, миграции, настройки, OpenAI, служебные функции.
 * Подключает остальные модули — точка входа для всех скриптов: require lib/app.php.
 */

const BOT_ROOT = __DIR__ . '/..';

require_once __DIR__ . '/extract.php';
require_once __DIR__ . '/kb.php';
require_once __DIR__ . '/clients.php';
require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/bitrix.php';
require_once __DIR__ . '/b24map.php';
require_once __DIR__ . '/../engine/scenario.php';
require_once __DIR__ . '/../engine/engine.php';
require_once __DIR__ . '/../engine/llm.php';
require_once __DIR__ . '/../engine/actions.php';
require_once __DIR__ . '/../engine/timers.php';

const CHANNEL_NAMES = ['web' => 'сайт', 'telegram' => 'Telegram', 'bitrix' => 'открытая линия Б24', 'sandbox' => 'песочница'];
const ROLE_NAMES = ['user' => 'Клиент', 'assistant' => 'Бот', 'operator' => 'Менеджер', 'system' => 'Служебное'];

function cfg(?string $key = null, $default = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $file = getenv('VB2_CONFIG') ?: BOT_ROOT . '/config.php';
        if (!is_file($file)) throw new RuntimeException('Нет config.php — скопируйте config.example.php в config.php и заполните.');
        $cfg = require $file;
        $tz = (string)($cfg['timezone'] ?? 'Europe/Moscow');
        try { new DateTimeZone($tz); } catch (Throwable $e) { $cfg['timezone'] = 'Europe/Moscow'; }
    }
    return $key === null ? $cfg : ($cfg[$key] ?? $default);
}

function data_dir(): string
{
    $dir = (string)cfg('data_dir', BOT_ROOT . '/data');
    if (!is_dir($dir)) mkdir($dir, 0770, true);
    return $dir;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . data_dir() . '/vb2.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=8000; PRAGMA foreign_keys=ON;');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $pdo->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);

    CREATE TABLE IF NOT EXISTS documents (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        filename TEXT NOT NULL,
        section TEXT NOT NULL DEFAULT 'general',
        format TEXT NOT NULL DEFAULT '',
        trust TEXT NOT NULL DEFAULT 'main',
        pages INTEGER NOT NULL DEFAULT 0,
        chunks INTEGER NOT NULL DEFAULT 0,
        has_embeddings INTEGER NOT NULL DEFAULT 0,
        active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS chunks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        doc_id INTEGER NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
        page INTEGER NOT NULL,
        source TEXT NOT NULL DEFAULT '',
        text TEXT NOT NULL,
        embedding BLOB
    );
    CREATE INDEX IF NOT EXISTS chunks_doc ON chunks(doc_id);
    CREATE VIRTUAL TABLE IF NOT EXISTS chunks_fts USING fts5(text, tokenize="unicode61 remove_diacritics 2");

    CREATE TABLE IF NOT EXISTS clients (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        first_name TEXT NOT NULL DEFAULT '',
        last_name TEXT NOT NULL DEFAULT '',
        middle_name TEXT NOT NULL DEFAULT '',
        phone TEXT NOT NULL DEFAULT '',
        email TEXT NOT NULL DEFAULT '',
        messenger_contact TEXT NOT NULL DEFAULT '',
        tg_id INTEGER UNIQUE,
        tg_username TEXT NOT NULL DEFAULT '',
        notes TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now')),
        last_seen_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS client_keys (
        key TEXT PRIMARY KEY,
        client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE
    );
    CREATE INDEX IF NOT EXISTS client_keys_client ON client_keys(client_id);

    -- Диалог = клиент в канале + состояние сценария
    CREATE TABLE IF NOT EXISTS dialogs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
        channel TEXT NOT NULL,
        ext_id TEXT NOT NULL DEFAULT '',
        scenario_id INTEGER NOT NULL DEFAULT 0,
        state TEXT NOT NULL DEFAULT '',
        slots TEXT NOT NULL DEFAULT '{}',
        counters TEXT NOT NULL DEFAULT '{}',
        state_turns INTEGER NOT NULL DEFAULT 0,
        state_entered_at TEXT NOT NULL DEFAULT (datetime('now')),
        paused_until TEXT,
        lead_id INTEGER NOT NULL DEFAULT 0,
        messages INTEGER NOT NULL DEFAULT 0,
        started_at TEXT NOT NULL DEFAULT (datetime('now')),
        last_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE INDEX IF NOT EXISTS dialogs_client ON dialogs(client_id, id);
    CREATE INDEX IF NOT EXISTS dialogs_last ON dialogs(last_at);

    CREATE TABLE IF NOT EXISTS messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        dialog_id INTEGER NOT NULL REFERENCES dialogs(id) ON DELETE CASCADE,
        role TEXT NOT NULL,
        content TEXT NOT NULL,
        attachment TEXT,
        state TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE INDEX IF NOT EXISTS messages_dialog ON messages(dialog_id, id);

    -- Журналы для карточки диалога
    CREATE TABLE IF NOT EXISTS transitions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        dialog_id INTEGER NOT NULL REFERENCES dialogs(id) ON DELETE CASCADE,
        message_id INTEGER,
        from_state TEXT NOT NULL,
        to_state TEXT NOT NULL,
        reason TEXT NOT NULL DEFAULT '',
        set_json TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE INDEX IF NOT EXISTS transitions_dialog ON transitions(dialog_id, id);
    CREATE TABLE IF NOT EXISTS llm_calls (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        dialog_id INTEGER,
        message_id INTEGER,
        kind TEXT NOT NULL,
        model TEXT NOT NULL DEFAULT '',
        request TEXT NOT NULL DEFAULT '',
        response TEXT NOT NULL DEFAULT '',
        tokens INTEGER NOT NULL DEFAULT 0,
        ms INTEGER NOT NULL DEFAULT 0,
        error TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE INDEX IF NOT EXISTS llm_dialog ON llm_calls(dialog_id, id);
    CREATE TABLE IF NOT EXISTS actions_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        dialog_id INTEGER,
        action TEXT NOT NULL,
        params TEXT NOT NULL DEFAULT '',
        result TEXT NOT NULL DEFAULT '',
        ok INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE INDEX IF NOT EXISTS actions_dialog ON actions_log(dialog_id, id);
    CREATE TABLE IF NOT EXISTS timers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        dialog_id INTEGER NOT NULL REFERENCES dialogs(id) ON DELETE CASCADE,
        state TEXT NOT NULL,
        timer_id TEXT NOT NULL,
        fire_at TEXT NOT NULL,
        active INTEGER NOT NULL DEFAULT 1,
        spec TEXT NOT NULL DEFAULT '{}',
        result TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE INDEX IF NOT EXISTS timers_due ON timers(active, fire_at);
    CREATE INDEX IF NOT EXISTS timers_dialog ON timers(dialog_id, id);

    -- Сценарии (версии) и каталог
    CREATE TABLE IF NOT EXISTS scenarios (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT NOT NULL,
        version TEXT NOT NULL DEFAULT '',
        name TEXT NOT NULL DEFAULT '',
        json TEXT NOT NULL,
        active INTEGER NOT NULL DEFAULT 0,
        note TEXT NOT NULL DEFAULT '',
        created_by TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS catalog (
        id TEXT PRIMARY KEY,
        section TEXT NOT NULL DEFAULT '',
        data TEXT NOT NULL,
        active INTEGER NOT NULL DEFAULT 1,
        sort INTEGER NOT NULL DEFAULT 100,
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT NOT NULL UNIQUE,
        dialog_id INTEGER NOT NULL,
        product_id TEXT NOT NULL,
        variant_id TEXT NOT NULL DEFAULT '',
        options TEXT NOT NULL DEFAULT '[]',
        quantity INTEGER NOT NULL DEFAULT 1,
        amount REAL NOT NULL,
        currency TEXT NOT NULL DEFAULT 'RUB',
        description TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'pending',
        demo INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        paid_at TEXT
    );
    CREATE TABLE IF NOT EXISTS rate (k TEXT NOT NULL, ts INTEGER NOT NULL);
    CREATE INDEX IF NOT EXISTS rate_k ON rate(k, ts);
    SQL);
}

/* ------------------------------------------------------------------ */
/*  Настройки                                                          */
/* ------------------------------------------------------------------ */

const SETTING_DEFAULTS = [
    'welcome'     => 'Здравствуйте! Я консультант Milli Mind. Помогу с визами, банковскими картами зарубежных банков и Green Card США. Подскажите, что вас интересует?',
    'suggestions' => "Нужна виза\nБанковская карта зарубежного банка\nGreen Card США\nВакансии",
    'b24_sandbox_live' => '0',
];

function setting(string $key): string
{
    $st = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v !== false ? (string)$v : (SETTING_DEFAULTS[$key] ?? '');
}

function set_setting(string $key, string $value): void
{
    db()->prepare('INSERT INTO settings(key, value) VALUES(?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
        ->execute([$key, $value]);
}

/* ------------------------------------------------------------------ */
/*  OpenAI                                                             */
/* ------------------------------------------------------------------ */

function openai(string $path, array $payload, int $timeout = 120): array
{
    if (function_exists('openai_mock')) return openai_mock($path, $payload); // тесты
    $base = rtrim((string)cfg('openai_base_url', 'https://api.openai.com/v1'), '/');
    $ch = curl_init($base . '/' . $path);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . cfg('openai_api_key')],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new RuntimeException('OpenAI: нет соединения: ' . $err);
    $data = json_decode((string)$raw, true);
    if ($code >= 400 || !is_array($data)) {
        $msg = $data['error']['message'] ?? substr((string)$raw, 0, 300);
        throw new RuntimeException("OpenAI HTTP $code: $msg", $code);
    }
    return $data;
}

/** Базовые параметры запроса к модели чата (лимиты, reasoning_effort у gpt-5). */
function chat_payload(array $messages): array
{
    $p = [
        'model'                 => (string)cfg('chat_model', 'gpt-5-mini'),
        'messages'              => $messages,
        'max_completion_tokens' => (int)cfg('max_output_tokens', 4000),
    ];
    if (preg_match('/^(gpt-5|o\d)/', $p['model'])) {
        $p['max_completion_tokens'] = max($p['max_completion_tokens'], 3000);
        $effort = cfg('reasoning_effort', 'minimal');
        if ($effort === 'minimal' && !str_starts_with($p['model'], 'gpt-5')) $effort = 'low';
        if ($effort !== null) $p['reasoning_effort'] = $effort;
    }
    return $p;
}

function embed(array $texts): array
{
    $out = [];
    foreach (array_chunk($texts, 64) as $batch) {
        $res = openai('embeddings', ['model' => cfg('embedding_model', 'text-embedding-3-small'), 'input' => array_values($batch)], 120);
        foreach ($res['data'] as $row) $out[] = $row['embedding'];
    }
    return $out;
}

/* ------------------------------------------------------------------ */
/*  Служебное                                                          */
/* ------------------------------------------------------------------ */

function now_utc(): string { return gmdate('Y-m-d H:i:s'); }
function utc_plus_minutes(float $min): string { return gmdate('Y-m-d H:i:s', time() + (int)round($min * 60)); }
function app_tz(): DateTimeZone { return new DateTimeZone((string)cfg('timezone', 'Europe/Moscow')); }

/** Время из базы (UTC) → местное. */
function fmt_dt(?string $utc, string $format = 'd.m.Y H:i'): string
{
    if (!$utc) return '';
    try {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(app_tz())->format($format);
    } catch (Throwable $e) {
        return $utc;
    }
}

function tz_label(): string
{
    $tz = app_tz();
    return $tz->getName() . ', UTC' . (new DateTimeImmutable('now', $tz))->format('P');
}

function jenc($v): string { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
function jdec(?string $s): array { $v = json_decode((string)$s, true); return is_array($v) ? $v : []; }

function app_log(string $msg): void
{
    $file = data_dir() . '/vb2.log';
    if (is_file($file) && filesize($file) > 3_000_000) @rename($file, $file . '.1');
    @file_put_contents($file, date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND);
}

function rate_limited(string $key): bool
{
    $now = time();
    $pdo = db();
    $pdo->prepare('DELETE FROM rate WHERE ts < ?')->execute([$now - 900]); // 900 с — окно защиты входа в админку
    $st = $pdo->prepare('SELECT COUNT(*) FROM rate WHERE k = ? AND ts >= ?');
    $st->execute([$key, $now - 60]);
    if ((int)$st->fetchColumn() >= (int)cfg('rate_limit_per_min', 20)) return true;
    $pdo->prepare('INSERT INTO rate(k, ts) VALUES(?, ?)')->execute([$key, $now]);
    return false;
}

function public_url(string $path = ''): string
{
    return rtrim((string)cfg('public_url', ''), '/') . '/' . ltrim($path, '/');
}

/** Сохранённый файл клиента: возвращает описание вложения. */
function store_upload(string $tmpPath, string $origName, bool $moveUploaded = false): array
{
    $max = (int)cfg('max_upload_mb', 15) * 1048576;
    $size = (int)@filesize($tmpPath);
    if ($size <= 0) throw new RuntimeException('Пустой файл.');
    if ($size > $max) throw new RuntimeException('Файл больше ' . cfg('max_upload_mb', 15) . ' МБ.');
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!preg_match('/^[a-z0-9]{1,8}$/', $ext)) $ext = 'bin';
    if (in_array($ext, ['php', 'phtml', 'phar', 'html', 'htm', 'js', 'svg', 'exe', 'sh'], true)) throw new RuntimeException('Такой тип файла не принимается.');
    $dir = data_dir() . '/uploads/' . gmdate('Ym');
    if (!is_dir($dir)) mkdir($dir, 0770, true);
    $dest = $dir . '/' . gmdate('dHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $ok = $moveUploaded ? move_uploaded_file($tmpPath, $dest) : rename($tmpPath, $dest);
    if (!$ok) throw new RuntimeException('Не удалось сохранить файл.');
    $name = preg_replace('/[^\p{L}\p{N}._ ()-]+/u', '_', mb_substr($origName, 0, 120)) ?: ('file.' . $ext);
    return ['file' => substr($dest, strlen(data_dir()) + 1), 'name' => $name, 'size' => $size];
}

function upload_path(array $att): string
{
    $p = data_dir() . '/' . ltrim((string)($att['file'] ?? ''), '/');
    if (str_contains($p, '..')) throw new RuntimeException('bad path');
    return $p;
}
