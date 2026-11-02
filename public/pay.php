<?php
declare(strict_types=1);
require __DIR__ . '/../lib/app.php';

/**
 * Страница оплаты по ссылке из чата.
 * Пока эквайринг не подключён — демо: кнопки «Оплатить» / «Ошибка оплаты» имитируют результат,
 * бот получает событие payment.paid / payment.failed и пишет клиенту.
 */

$code = (string)($_GET['c'] ?? $_POST['c'] ?? '');
$st = db()->prepare('SELECT * FROM payments WHERE code = ?');
$st->execute([$code]);
$p = $code !== '' ? $st->fetch() : null;
$msg = '';
if ($p && $_SERVER['REQUEST_METHOD'] === 'POST' && $p['demo'] && $p['status'] !== 'paid') {
    $paid = ($_POST['result'] ?? '') === 'paid';
    try {
        payment_mark($code, $paid);
        $msg = $paid ? 'Оплата прошла (демо). Вернитесь в чат — бот уже написал.' : 'Оплата не прошла (демо). Вернитесь в чат.';
    } catch (Throwable $e) {
        app_log('pay: ' . $e->getMessage());
        $msg = 'Ошибка: ' . $e->getMessage();
    }
    $st->execute([$code]);
    $p = $st->fetch();
}
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$color = (string)cfg('accent_color', '#1f4e8c');
?><!doctype html>
<html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Оплата</title><link rel="icon" href="favicon.svg" type="image/svg+xml">
<style>
  body { margin: 0; background: #f4f6fa; font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #1d2330; display: grid; place-items: center; min-height: 100vh; padding: 16px; box-sizing: border-box; }
  .card { background: #fff; border: 1px solid #e2e7ef; border-radius: 16px; padding: 28px; max-width: 440px; width: 100%; box-shadow: 0 6px 24px rgba(0,0,0,.06); }
  h1 { font-size: 20px; margin: 0 0 6px; }
  .sum { font-size: 32px; font-weight: 700; margin: 14px 0; }
  .demo { background: #fff6dd; border: 1px solid #f0d78c; border-radius: 10px; padding: 10px 12px; font-size: 14px; margin: 14px 0; }
  .st { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 14px; background: #eef1f6; }
  .st.paid { background: #e3f6e8; color: #17692f; } .st.failed { background: #fdecec; color: #a32020; }
  form { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 16px; }
  button { border: 0; border-radius: 10px; padding: 12px 18px; font: inherit; cursor: pointer; background: <?= $h($color) ?>; color: #fff; flex: 1; }
  button.sec { background: #eef1f6; color: #4a5466; }
  .ok { margin-top: 14px; font-weight: 600; }
</style>
<body><div class="card">
<?php if (!$p): ?>
  <h1>Счёт не найден</h1><p>Проверьте ссылку или вернитесь в чат и попросите выставить счёт заново.</p>
<?php else: ?>
  <h1>Оплата заказа</h1>
  <div><?= $h($p['description']) ?></div>
  <div class="sum"><?= $h(money_fmt((float)$p['amount'], $p['currency'])) ?></div>
  <span class="st <?= $h($p['status']) ?>"><?= ['pending' => 'ожидает оплаты', 'paid' => 'оплачено', 'failed' => 'оплата не прошла'][$p['status']] ?? $h($p['status']) ?></span>
  <?php if ($p['demo']): ?>
    <div class="demo"><b>Тестовый режим.</b> Это демонстрационный счёт: деньги не списываются. Кнопки ниже имитируют результат оплаты.</div>
    <?php if ($p['status'] !== 'paid'): ?>
      <form method="post"><input type="hidden" name="c" value="<?= $h($code) ?>">
        <button name="result" value="paid">Оплатить (демо)</button>
        <button name="result" value="failed" class="sec">Ошибка оплаты</button>
      </form>
    <?php endif; ?>
  <?php else: ?>
    <p>Подключение платёжной системы — в работе. Менеджер пришлёт реквизиты.</p>
  <?php endif; ?>
  <?php if ($msg): ?><p class="ok"><?= $h($msg) ?></p><?php endif; ?>
<?php endif; ?>
</div></body></html>
