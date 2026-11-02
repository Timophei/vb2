<?php
declare(strict_types=1);
require __DIR__ . '/../lib/app.php';
// QR-код счёта (картинка для чата, Telegram и страницы оплаты)
$code = (string)($_GET['c'] ?? '');
if (!preg_match('/^[a-f0-9]{16}$/', $code)) { http_response_code(404); exit; }
$st = db()->prepare('SELECT id, demo FROM payments WHERE code = ?');
$st->execute([$code]);
$p = $st->fetch();
if (!$p) { http_response_code(404); exit; }
$rel = payment_qr($code, invoice_no((int)$p['id']), (bool)$p['demo']);
if (!$rel) { http_response_code(404); exit; }
header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
readfile(data_dir() . '/' . $rel);
