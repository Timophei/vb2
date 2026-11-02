<?php
declare(strict_types=1);

/** Telegram Bot API. */

function tg_api(string $method, array $params): ?array
{
    if (function_exists('tg_mock')) return tg_mock($method, $params); // тесты
    $ch = curl_init(rtrim((string)cfg('telegram_api_url', 'https://api.telegram.org'), '/') . '/bot' . cfg('telegram_token') . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE),
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    return is_string($raw) ? json_decode($raw, true) : null;
}

/** Текст модели → HTML для Telegram (**жирный**). */
function tg_html(string $s): string
{
    $s = htmlspecialchars($s, ENT_NOQUOTES, 'UTF-8');
    return preg_replace('/\*\*(.+?)\*\*/u', '<b>$1</b>', $s);
}

function tg_send(int|string $chatId, string $text, ?array $markup = null): void
{
    foreach (mb_str_split($text, 3800) as $part) {
        $p = ['chat_id' => $chatId, 'text' => tg_html($part), 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
        if ($markup) { $p['reply_markup'] = $markup; $markup = null; }
        $r = tg_api('sendMessage', $p);
        if (!($r['ok'] ?? false)) { unset($p['parse_mode']); $p['text'] = $part; tg_api('sendMessage', $p); }
    }
}

/** Скачивает файл из Telegram в data/uploads. */
function tg_download(string $fileId, string $name): array
{
    $info = tg_api('getFile', ['file_id' => $fileId]);
    $path = $info['result']['file_path'] ?? null;
    if (!$path) throw new RuntimeException('Telegram не отдал файл (возможно, больше 20 МБ).');
    $url = rtrim((string)cfg('telegram_api_url', 'https://api.telegram.org'), '/') . '/file/bot' . cfg('telegram_token') . '/' . $path;
    $tmp = tempnam(sys_get_temp_dir(), 'tg');
    $fp = fopen($tmp, 'wb');
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_FILE => $fp, CURLOPT_TIMEOUT => 120, CURLOPT_FOLLOWLOCATION => true]);
    $ok = curl_exec($ch);
    curl_close($ch);
    fclose($fp);
    if (!$ok) { @unlink($tmp); throw new RuntimeException('Не удалось скачать файл из Telegram.'); }
    return store_upload($tmp, $name ?: basename($path));
}

/** Уведомление менеджерам в Telegram (manager_chat_ids). */
function notify_managers(string $html): bool
{
    $ids = (array)cfg('manager_chat_ids', []);
    if (!$ids || !cfg('telegram_token')) return false;
    foreach ($ids as $id) {
        $r = tg_api('sendMessage', ['chat_id' => $id, 'text' => $html, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true]);
        if (!($r['ok'] ?? false)) app_log('notify ' . $id . ': ' . jenc($r));
    }
    return true;
}

/** Проверка подписи «Войти через Telegram». */
function verify_telegram_login(array $data): ?array
{
    $token = (string)cfg('telegram_token', '');
    if ($token === '' || empty($data['hash']) || empty($data['id'])) return null;
    $hash = (string)$data['hash'];
    unset($data['hash']);
    ksort($data);
    $check = implode("\n", array_map(fn($k, $v) => "$k=$v", array_keys($data), $data));
    $calc = hash_hmac('sha256', $check, hash('sha256', $token, true));
    if (!hash_equals($calc, $hash)) return null;
    if (time() - (int)($data['auth_date'] ?? 0) > 86400) return null;
    return $data;
}
