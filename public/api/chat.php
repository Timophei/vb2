<?php
declare(strict_types=1);
require __DIR__ . '/../../lib/app.php';

/**
 * API веб-чата (виджет). Действия: init, new, message, file, poll, tg_login.
 * Сообщения возвращаются списком [{id, role, text}] — виджет помнит последний id
 * и раз в 15 с забирает новые (напоминания по таймерам, ответ после оплаты).
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(array $d, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$host = $_SERVER['HTTP_HOST'] ?? '';
$crossSite = $origin !== '' && parse_url($origin, PHP_URL_HOST) !== explode(':', $host)[0];
if ($crossSite) {
    if (!in_array($origin, (array)cfg('allowed_origins', []), true)) out(['error' => 'origin not allowed'], 403);
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['error' => 'POST only'], 405);

$isUpload = str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data');
$in = $isUpload ? $_POST : (json_decode(file_get_contents('php://input') ?: '', true) ?: []);

$valid = fn($v) => is_string($v) && preg_match('/^[a-f0-9]{32}$/', $v);
$uid = $valid($_COOKIE['vb2_uid'] ?? null) ? $_COOKIE['vb2_uid'] : ($valid($in['uid'] ?? null) ? $in['uid'] : bin2hex(random_bytes(16)));
setcookie('vb2_uid', $uid, [
    'expires' => time() + 365 * 86400, 'path' => '/', 'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) || $crossSite, 'samesite' => $crossSite ? 'None' : 'Lax',
]);

function msgs_out(array $rows): array
{
    $out = [];
    foreach ($rows as $m) {
        if (!in_array($m['role'], ['user', 'assistant', 'operator'], true)) continue;
        $text = $m['content'];
        if ($m['attachment']) { $a = jdec($m['attachment']); $text = ($text !== '(файл)' ? $text . "\n" : '') . '📎 ' . ($a['name'] ?? 'файл'); }
        $out[] = ['id' => (int)$m['id'], 'role' => $m['role'] === 'operator' ? 'assistant' : $m['role'], 'text' => $text];
    }
    return $out;
}

/** Новый диалог начинается с приветствия (сохраняется как реплика бота — модель видит, что уже поздоровались). */
function welcome_if_empty(int $dialog, array $client): void
{
    if (dialog_messages($dialog, 1)) return;
    $w = setting('welcome');
    if ($client['first_name'] !== '') $w = preg_replace('/^Здравствуйте!?/u', 'Здравствуйте, ' . $client['first_name'] . '!', $w, 1);
    save_message($dialog, 'assistant', $w, null, dialog_load($dialog)['state']);
}

try {
    $client = client_by_key('web:' . $uid);
    $cid = (int)$client['id'];
    $action = (string)($in['action'] ?? 'message');
    $info = fn(array $c) => ['name' => $c['first_name'], 'telegram' => $c['tg_id'] ? ($c['tg_username'] ? '@' . $c['tg_username'] : $c['first_name']) : null];

    switch ($action) {
        case 'init':
            $dialog = current_dialog($cid, 'web');
            welcome_if_empty($dialog, $client);
            out([
                'uid' => $uid,
                'client' => $info($client),
                'messages' => msgs_out(dialog_messages($dialog, 100)),
                'suggestions' => array_values(array_filter(array_map('trim', explode("\n", setting('suggestions'))))),
                'tg_bot' => cfg('telegram_token') ? (string)cfg('telegram_bot_username', '') : '',
                'privacy' => (string)cfg('privacy_url', ''),
                'max_mb' => (int)cfg('max_upload_mb', 15),
            ]);

        case 'new':
            $dialog = current_dialog($cid, 'web', true);
            welcome_if_empty($dialog, $client);
            out(['uid' => $uid, 'messages' => msgs_out(dialog_messages($dialog, 100))]);

        case 'poll':
            $dialog = current_dialog($cid, 'web');
            out(['uid' => $uid, 'messages' => msgs_out(array_filter(dialog_messages($dialog, 50, (int)($in['after'] ?? 0)), fn($m) => $m['role'] !== 'user'))]);

        case 'tg_login':
            $tg = verify_telegram_login((array)($in['data'] ?? []));
            if (!$tg) out(['error' => 'Не удалось подтвердить вход через Telegram'], 400);
            $cid = attach_telegram($cid, $tg);
            out(['uid' => $uid, 'client' => $info(get_client($cid))]);

        case 'message':
        case 'file':
            if (rate_limited('ip:' . ($_SERVER['REMOTE_ADDR'] ?? '?'))) out(['error' => 'Слишком много сообщений подряд. Подождите минуту.'], 429);
            $dialog = current_dialog($cid, 'web');
            $after = (int)($in['after'] ?? 0);
            $att = null;
            if ($action === 'file') {
                $f = $_FILES['file'] ?? null;
                if (!$f || $f['error'] !== UPLOAD_ERR_OK) out(['error' => 'Файл не загрузился. Максимум ' . cfg('max_upload_mb', 15) . ' МБ.'], 400);
                $att = store_upload($f['tmp_name'], (string)$f['name'], true);
            }
            $text = (string)($in['message'] ?? '');
            if (trim($text) === '' && !$att) out(['error' => 'Напишите, пожалуйста, ваш вопрос.'], 400);
            engine_message($dialog, $text, $att);
            out(['uid' => $uid, 'messages' => msgs_out(array_filter(dialog_messages($dialog, 50, $after), fn($m) => $m['role'] !== 'user')), 'client' => $info(get_client($cid))]);

        default:
            out(['error' => 'unknown action'], 400);
    }
} catch (Throwable $e) {
    app_log('chat: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    out(['error' => 'Консультант временно недоступен. Попробуйте чуть позже.'], 500);
}
