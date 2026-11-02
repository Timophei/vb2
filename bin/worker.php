<?php
declare(strict_types=1);
/**
 * Воркер: раз в 20 секунд запускает просроченные таймеры (напоминания, ожидание оплаты, «отложено»).
 * Работает службой systemd (deploy/vb2-worker.service). Разовый прогон: php bin/worker.php --once
 */
require __DIR__ . '/../lib/app.php';

$once = in_array('--once', $argv, true);
$stop = false;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function () use (&$stop) { $stop = true; });
    pcntl_signal(SIGINT, function () use (&$stop) { $stop = true; });
}
$started = time();
do {
    @touch(data_dir() . '/worker.heartbeat');
    try {
        $n = timers_run_due();
        if ($n) echo date('H:i:s') . " таймеров: $n\n";
        if (time() - ($lastWatch ?? 0) >= 60) { // раз в минуту: не забрал ли менеджер клиента в Битриксе
            $lastWatch = time();
            $w = crm_watch_takeover();
            if ($w) echo date('H:i:s') . " смена ответственного: $w\n";
        }
    } catch (Throwable $e) {
        fwrite(STDERR, date('H:i:s') . ' ' . $e->getMessage() . "\n");
        app_log('worker: ' . $e->getMessage());
    }
    if ($once) break;
    for ($i = 0; $i < 20 && !$stop; $i++) sleep(1);
    // перезапуск раз в час — подхватить обновлённый код (systemd поднимет снова)
} while (!$stop && time() - $started < 3600);
