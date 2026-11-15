<?php
declare(strict_types=1);
/**
 * Оценка качества готовых диалогов другой моделью — например, ваших ручных тестов в песочнице.
 *
 *   sudo -u www-data php bin/judge.php --dialogs=27,29,30
 *   sudo -u www-data php bin/judge.php --last=5                 последние 5 диалогов песочницы
 *   sudo -u www-data php bin/judge.php --dialogs=30 --model=gpt-5
 *
 * Критерии — settings.quality_rubric сценария (или встроенные). Оценка пишется и в журнал действий диалога.
 * Отчёт — в консоль и в data/judge/<дата>.txt.
 */
require __DIR__ . '/../lib/app.php';

$args = getopt('', ['dialogs:', 'last:', 'model:']);
$ids = [];
if (isset($args['dialogs'])) $ids = array_values(array_filter(array_map('intval', explode(',', (string)$args['dialogs']))));
if (isset($args['last'])) {
    $n = max(1, (int)$args['last']);
    $ids = array_merge($ids, array_reverse(db()->query("SELECT id FROM dialogs WHERE channel = 'sandbox' ORDER BY id DESC LIMIT {$n}")->fetchAll(PDO::FETCH_COLUMN)));
}
if (!$ids) { fwrite(STDERR, "Укажите --dialogs=27,29 или --last=5\n"); exit(1); }
$model = isset($args['model']) ? (string)$args['model'] : null;

$now = new DateTimeImmutable('now', app_tz());
$L = ['Оценка диалогов · ' . $now->format('d.m.Y H:i'), ''];
$table = [];
foreach (array_unique(array_map('intval', $ids)) as $id) {
    echo "Диалог #{$id}…\n";
    try {
        $j = judge_dialog($id, $model);
    } catch (Throwable $e) {
        $L[] = "#{$id}: ошибка — " . $e->getMessage();
        continue;
    }
    $sc = scenario_for(dialog_load($id));
    $L[] = "=== Диалог #{$id} ===";
    $L[] = judge_text($j, judge_rubric($sc));
    $L[] = '';
    $table[] = sprintf('#%-5d общая %d/5 · средняя по критериям %.1f', $id, (int)$j['overall'], judge_avg($j));
}
$L[] = 'Итог:';
foreach ($table as $t) $L[] = '  ' . $t;

@mkdir(data_dir() . '/judge', 0770, true);
$file = data_dir() . '/judge/' . $now->format('Y-m-d_His') . '.txt';
file_put_contents($file, implode("\n", $L) . "\n");
echo "\n" . implode("\n", $L) . "\n\nОтчёт: {$file}\n";
