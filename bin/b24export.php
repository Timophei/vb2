<?php
declare(strict_types=1);
/**
 * Фоновая выгрузка из Битрикс24 для анализа. Запускает админка (Настройки → Выгрузка):
 *   php bin/b24export.php <id задания>
 * Ход и ошибки — в data/exports/<id>.json, вывод процесса — в data/b24export.log.
 */
require __DIR__ . '/../lib/app.php';
if (PHP_SAPI !== 'cli') exit;
@set_time_limit(0);
ignore_user_abort(true);
b24x_job_run((string)($argv[1] ?? ''));
