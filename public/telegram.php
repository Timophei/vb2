<?php
declare(strict_types=1);
require __DIR__ . '/../lib/app.php';

// Telegram присылает секрет в заголовке — чужие запросы не пройдут
$secret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if (!cfg('telegram_token') || !hash_equals((string)cfg('telegram_secret'), $secret)) { http_response_code(403); exit; }

$update = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$msg = $update['message'] ?? null;
if (!$msg || !isset($msg['chat']['id'], $msg['from']['id'])) { echo 'ok'; exit; }

// /id — узнать id чата (для manager_chat_ids), работает и в группах
if (preg_match('#^/id(@\w+)?$#', trim((string)($msg['text'] ?? '')))) {
    echo 'ok';
    tg_api('sendMessage', ['chat_id' => $msg['chat']['id'], 'text' => 'id этого чата: ' . $msg['chat']['id'] . "\nВпишите его в manager_chat_ids в config.php, чтобы сюда приходили уведомления."]);
    exit;
}
if (($msg['chat']['type'] ?? '') !== 'private') { echo 'ok'; exit; }

// Отвечаем Telegram сразу, дальше работаем (иначе он пришлёт повтор)
http_response_code(200);
echo 'ok';
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

$chatId = $msg['chat']['id'];
$from = $msg['from'];
$text = trim((string)($msg['text'] ?? $msg['caption'] ?? ''));
$phoneKeyboard = ['keyboard' => [[['text' => '📱 Отправить мой номер', 'request_contact' => true]]], 'resize_keyboard' => true, 'one_time_keyboard' => true];
$removeKeyboard = ['remove_keyboard' => true];

try {
    $client = client_by_key('tg:' . $from['id']);
    $cid = attach_telegram((int)$client['id'], $from);
    $client = get_client($cid);
    $isNew = $text === '/new';

    if ($text === '/start' || str_starts_with($text, '/start ') || $isNew) {
        $dialog = current_dialog($cid, 'telegram', $isNew, (string)$chatId);
        db()->prepare('UPDATE dialogs SET ext_id = ? WHERE id = ?')->execute([(string)$chatId, $dialog]);
        $w = setting('welcome');
        if ($client['first_name'] !== '') $w = preg_replace('/^Здравствуйте!?/u', 'Здравствуйте, ' . $client['first_name'] . '!', $w, 1);
        if (!dialog_messages($dialog, 1) || $isNew) save_message($dialog, 'assistant', $w, null, dialog_load($dialog)['state']);
        tg_send($chatId, $w, $removeKeyboard);
        exit;
    }
    if (rate_limited('tg:' . $from['id'])) { tg_send($chatId, 'Слишком много сообщений подряд. Подождите минуту.'); exit; }

    $dialog = current_dialog($cid, 'telegram', false, (string)$chatId);
    db()->prepare("UPDATE dialogs SET ext_id = ? WHERE id = ? AND ext_id = ''")->execute([(string)$chatId, $dialog]);

    // Клиент поделился своим номером кнопкой — передаём как текст
    if (isset($msg['contact']) && (int)($msg['contact']['user_id'] ?? 0) === (int)$from['id']) {
        update_client($cid, ['phone' => (string)$msg['contact']['phone_number']]);
        $text = 'Мой телефон: ' . normalize_phone((string)$msg['contact']['phone_number']);
    }

    // Файл (резюме): документ или фото
    $att = null;
    if (isset($msg['document'])) {
        $att = tg_download((string)$msg['document']['file_id'], (string)($msg['document']['file_name'] ?? 'файл'));
    } elseif (isset($msg['photo'])) {
        $ph = end($msg['photo']);
        $att = tg_download((string)$ph['file_id'], 'фото_' . date('Ymd_His') . '.jpg');
    }
    if ($text === '' && !$att) { tg_send($chatId, 'Пока я понимаю текст и файлы. Напишите, пожалуйста, ваш вопрос.'); exit; }

    tg_api('sendChatAction', ['chat_id' => $chatId, 'action' => 'typing']);
    $replies = engine_message($dialog, $text, $att);

    $client = get_client($cid);
    foreach ($replies as $i => $r) {
        $askPhone = $i === count($replies) - 1 && $client['phone'] === '' && preg_match('/телефон|номер для связи/ui', $r);
        tg_send($chatId, $r, $askPhone ? $phoneKeyboard : null);
    }
} catch (Throwable $e) {
    app_log('tg: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    tg_send($chatId, 'Консультант временно недоступен. Попробуйте чуть позже.');
}
