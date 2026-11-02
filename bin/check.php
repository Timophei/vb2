<?php
declare(strict_types=1);
/**
 * Проверка установки: php bin/check.php
 *   — config.php, база, сценарий (ошибки/предупреждения), OpenAI, Telegram, Битрикс24 (права вебхука).
 */
require __DIR__ . '/../lib/app.php';

$ok = fn($m) => print("  ✓ $m\n");
$bad = fn($m) => print("  ✗ $m\n");
echo "vb2 — проверка\n";
try { db(); $ok('база: ' . data_dir() . '/vb2.sqlite'); } catch (Throwable $e) { $bad('база: ' . $e->getMessage()); exit(1); }

$sc = scenario_active();
[$E, $W] = scenario_validate($sc);
($E ? $bad : $ok)("сценарий «{$sc['meta']['name']}» v{$sc['meta']['version']}: ошибок " . count($E) . ', предупреждений ' . count($W));
foreach ($E as $e) echo "      ! $e\n";
echo '  · каталог: ' . count(catalog_all()) . " товаров\n";

try {
    $r = openai('chat/completions', chat_payload([['role' => 'user', 'content' => 'Ответь одним словом: ок']]));
    $ok('OpenAI: ' . ($r['model'] ?? cfg('chat_model')) . ' отвечает');
} catch (Throwable $e) { $bad('OpenAI: ' . $e->getMessage()); }

if (cfg('telegram_token')) {
    $me = tg_api('getMe', []);
    ($me['ok'] ?? false) ? $ok('Telegram: @' . $me['result']['username']) : $bad('Telegram: токен не принят');
    $wh = tg_api('getWebhookInfo', []);
    $url = $wh['result']['url'] ?? '';
    $url === public_url('telegram.php') ? $ok("вебхук Telegram: $url") : $bad('вебхук Telegram: ' . ($url ?: 'не установлен') . ' — нужен ' . public_url('telegram.php'));
} else echo "  · Telegram: токен не задан\n";

if (b24_configured()) {
    try {
        $scope = b24('scope', [])['result'] ?? [];
        $ok('Битрикс24 ' . b24_portal() . ': права ' . implode(', ', $scope) . ' · режим ' . cfg('bitrix_mode', 'dry'));
        if (!in_array('crm', $scope, true)) $bad('у вебхука нет права crm');
    } catch (Throwable $e) { $bad('Битрикс24: ' . $e->getMessage()); }
} else echo "  · Битрикс24: вебхук не задан\n";

trim((string)shell_exec('command -v qrencode')) !== '' ? $ok('qrencode: QR-коды счетов') : $bad('qrencode не установлен — счёт уйдёт без QR-кода: sudo apt install -y qrencode');
$hb = @filemtime(data_dir() . '/worker.heartbeat');
$hb && time() - $hb < 90 ? $ok('воркер таймеров работает') : $bad('воркер таймеров не запущен: sudo systemctl enable --now vb2-worker');
