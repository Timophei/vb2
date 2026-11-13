<?php
declare(strict_types=1);
require __DIR__ . '/../../lib/app.php';
require __DIR__ . '/../../lib/auth.php';
require __DIR__ . '/../../lib/admin_views.php';

session_name('vb2_admin');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(16)); }
function csrf_field(string $do, array $extra = []): string
{
    $s = '<input type="hidden" name="csrf" value="' . csrf() . '"><input type="hidden" name="do" value="' . h($do) . '">';
    foreach ($extra as $k => $v) $s .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">';
    return $s;
}
function ini_bytes(string $v): int { $n = (int)$v; return $n * match (strtolower(substr(trim($v), -1))) { 'g' => 1 << 30, 'm' => 1 << 20, 'k' => 1 << 10, default => 1 }; }
function upload_limit_text(): string { return round(min(ini_bytes((string)ini_get('upload_max_filesize')), ini_bytes((string)ini_get('post_max_size')) ?: PHP_INT_MAX) / 1048576) . ' МБ'; }
function go(string $qs, string $flash = ''): never
{
    if ($flash !== '') $_SESSION['flash'] = $flash;
    header('Location: ./' . ($qs !== '' ? '?' . ltrim($qs, '?') : '')); exit;
}

/* ---------- вход ---------- */
function auth_page(string $title, string $body): never
{
    ?><!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= h($title) ?> — vb2</title><link rel="stylesheet" href="admin.css"><link rel="icon" href="favicon.svg" type="image/svg+xml">
    <body class="login"><?= $body ?></body></html><?php
    exit;
}
if (isset($_GET['logout'])) { logout(); header('Location: ./'); exit; }

if (!users_exist()) {
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals(csrf(), (string)($_POST['csrf'] ?? ''))) {
        try {
            if ((string)$_POST['password'] !== (string)$_POST['password2']) throw new RuntimeException('Пароли не совпадают.');
            $id = create_user((string)$_POST['login'], (string)$_POST['name'], (string)$_POST['password'], 'admin');
            start_user_session(user_by_id($id), true);
            header('Location: ./'); exit;
        } catch (Throwable $e) { $err = $e->getMessage(); }
    }
    auth_page('Первый вход', '<form method="post" class="card"><h1>Бот vb2</h1>'
        . '<p class="hint">Пользователей ещё нет. Создайте первого — он будет администратором.</p>'
        . ($err ? '<p class="err">' . h($err) . '</p>' : '')
        . '<input type="hidden" name="csrf" value="' . csrf() . '">'
        . '<label>Логин<input name="login" autocomplete="username" autocapitalize="none" spellcheck="false" required value="' . h($_POST['login'] ?? '') . '"></label>'
        . '<label>Имя<input name="name" value="' . h($_POST['name'] ?? '') . '"></label>'
        . '<label>Пароль<input type="password" name="password" autocomplete="new-password" minlength="8" required></label>'
        . '<label>Ещё раз<input type="password" name="password2" autocomplete="new-password" minlength="8" required></label>'
        . '<button>Создать и войти</button></form>');
}

$me = current_user();
if (!$me) {
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        usleep(400000);
        if (login_blocked()) $err = 'Слишком много неудачных попыток. Подождите 15 минут.';
        elseif ($u = attempt_login((string)($_POST['login'] ?? ''), (string)($_POST['password'] ?? ''))) {
            start_user_session($u, !empty($_POST['remember']));
            $back = (string)($_POST['back'] ?? '');
            header('Location: ' . (preg_match('/^\?[\w=&%.-]*$/', $back) ? './' . $back : './')); exit;
        } else $err = 'Неверный логин или пароль';
    }
    $back = $_POST['back'] ?? (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
    auth_page('Вход', '<form method="post" class="card"><h1>Бот vb2</h1>'
        . ($err ? '<p class="err">' . h($err) . '</p>' : '')
        . '<input type="hidden" name="back" value="' . h((string)$back) . '">'
        . '<label>Логин<input name="login" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus></label>'
        . '<label>Пароль<input type="password" name="password" autocomplete="current-password" required></label>'
        . '<label class="row"><input type="checkbox" name="remember" value="1" checked> Запомнить этот компьютер на ' . REMEMBER_DAYS . ' дней</label>'
        . '<button>Войти</button></form>');
}

$sandboxKey = 'sandbox:' . $me['id'];
function sandbox_dialog(string $key, bool $new = false): int
{
    $c = client_by_key($key);
    return current_dialog((int)$c['id'], 'sandbox', $new);
}

/* ---------- действия ---------- */
$tab = (string)($_GET['tab'] ?? 'dialogs');
$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
$view = []; // данные для отображения после POST без редиректа (проверка сценария)

if (isset($_GET['export_txt'])) {
    $eid = (int)$_GET['export_txt'];
    $tmp = tempnam(sys_get_temp_dir(), 'dlg');
    file_put_contents($tmp, dialog_export_txt($eid));
    send_download($tmp, 'dialog_' . $eid . '_' . date('Ymd_Hi') . '.txt', 'text/plain; charset=utf-8');
}

if ($tab === 'catalog' && isset($_GET['export'])) {
    $sec = (string)$_GET['export'];
    $tmp = tempnam(sys_get_temp_dir(), 'cat');
    xlsx_write($tmp, catalog_export_rows($sec === '' ? null : $sec));
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="catalog_' . ($sec === '' ? 'all' : preg_replace('/[^\w-]/', '_', $sec)) . '_' . date('Ymd') . '.xlsx"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp); @unlink($tmp); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) go('tab=' . $tab, 'Ошибка: файл больше лимита сервера (' . upload_limit_text() . ').');
    if (!hash_equals(csrf(), (string)($_POST['csrf'] ?? ''))) { http_response_code(400); exit('CSRF'); }
    $do = (string)($_POST['do'] ?? '');
    $back = (string)($_POST['back'] ?? 'tab=' . $tab);
    if (!preg_match('/^[\w=&%.\-]*$/', $back)) $back = '';
    $dlgId = (int)($_POST['dialog'] ?? 0);
    try {
        switch ($do) {
            /* --- песочница --- */
            case 'sandbox_send':
                $id = sandbox_dialog($sandboxKey);
                $text = trim((string)($_POST['text'] ?? ''));
                $att = null;
                if (!empty($_FILES['file']['name']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) $att = store_upload($_FILES['file']['tmp_name'], $_FILES['file']['name'], true);
                if ($text === '' && !$att) go($back);
                set_time_limit(180);
                engine_message($id, $text, $att);
                go($back . '#end');
            case 'sandbox_new':
                delete_client((int)client_by_key($sandboxKey)['id']); // клиент, диалоги и журналы — каскадом
                go('tab=sandbox', 'Начат новый тестовый диалог (клиент тоже новый).');
            case 'sandbox_b24':
                set_setting('b24_sandbox_live', !empty($_POST['on']) ? '1' : '0');
                go($back, !empty($_POST['on']) ? 'Песочница теперь пишет в Битрикс24 по-настоящему (если bitrix_mode = live).' : 'Песочница больше не пишет в Битрикс24.');

            /* --- управление диалогом (карточка и песочница) --- */
            case 'dlg_event':
                $ev = (string)$_POST['event'];
                if (in_array($ev, ['payment.paid', 'payment.failed'], true)) {
                    $d = dialog_load($dlgId);
                    if (!empty($d['slots']['payment.invoice_id'])) { payment_mark((string)$d['slots']['payment.invoice_id'], $ev === 'payment.paid'); go($back . '#end', "Событие: $ev"); }
                }
                engine_event($dlgId, $ev, true);
                go($back . '#end', "Событие: $ev");
            case 'dlg_ff':
                $n = timers_fast_forward($dlgId, (float)$_POST['minutes']);
                go($back . '#end', 'Прошло ' . (float)$_POST['minutes'] . ' мин. Сработало таймеров: ' . $n . '.');
            case 'dlg_jump':
                $d = dialog_load($dlgId);
                $T = turn_init($d, 0);
                $to = (string)$_POST['state'];
                if (!isset($T['sc']['states'][$to])) throw new RuntimeException('Нет такого состояния');
                transition($T, $to, 'вручную из админки (' . $me['login'] . ')');
                crm_sync($T);
                dialog_save($T['d']);
                go($back . '#end', 'Диалог переведён в «' . sc_state_title($T['sc'], $to) . '» (действия входа выполнены).');
            case 'dlg_slot':
                $d = dialog_load($dlgId);
                $sc = scenario_for($d);
                $name = (string)$_POST['slot'];
                $raw = (string)($_POST['value'] ?? '');
                if ($raw === '') unset($d['slots'][$name]);
                else {
                    $type = sc_slot($sc, $name)['type'] ?? 'string';
                    $v = in_array($type, ['counter', 'money'], true) ? (float)$raw + 0 : (in_array($type, ['state_ref'], true) ? $raw : slot_coerce($sc, $name, $raw, $d['slots'][$name] ?? null));
                    if ($v === null) throw new RuntimeException("Значение «{$raw}» не подходит слоту {$name} ({$type})");
                    $d['slots'][$name] = $v;
                }
                dialog_save($d);
                go($back, "Слот {$name} " . ($raw === '' ? 'очищен' : 'записан') . '. Переходы проверятся при следующем сообщении.');
            case 'dlg_reset':
                dialog_reset($dlgId);
                go($back, 'Диалог сброшен в начальное состояние (переписка сохранена).');
            case 'dlg_upgrade':
                $d = dialog_load($dlgId);
                $sc = scenario_active();
                $d['scenario_id'] = $sc['_id'];
                if (!isset($sc['states'][$d['state']])) $d['state'] = $sc['meta']['entry_state'];
                dialog_save($d);
                go($back, 'Диалог переведён на активную версию сценария.');
            case 'dlg_operator':
                $text = trim((string)$_POST['text']);
                if ($text !== '') engine_operator_message($dlgId, $text, (string)$me['login']);
                go($back . '#end', 'Сообщение менеджера отправлено. Бот на паузе по правилам сценария.');
            case 'dlg_unpause':
                $d = dialog_load($dlgId); $d['paused_until'] = null; dialog_save($d);
                go($back, 'Пауза снята.');
            case 'dlg_export':
                $ids = (array)($_POST['ids'] ?? []);
                if (!$ids) go('tab=dialogs', 'Отметьте галочками диалоги для выгрузки.');
                send_download(dialogs_export_zip($ids), 'dialogs_' . date('Ymd_Hi') . '_' . count($ids) . '.zip', 'application/zip');
            case 'dlg_delete':
                $d = dialog_load($dlgId);
                db()->prepare('DELETE FROM dialogs WHERE id = ?')->execute([$dlgId]);
                go('tab=dialogs', 'Диалог #' . $dlgId . ' удалён.');

            /* --- сценарий --- */
            case 'scenario_check':
                $json = str_replace("\r\n", "\n", (string)$_POST['json']);
                $sc = json_decode($json, true);
                $view['json'] = $json;
                $view['report'] = is_array($sc) ? scenario_validate($sc) : [['Это не JSON: ' . json_last_error_msg()], []];
                $tab = 'scenario';
                break;
            case 'scenario_save':
                $json = str_replace("\r\n", "\n", (string)$_POST['json']);
                if (!empty($_FILES['file']['name'])) {
                    if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Файл не загрузился');
                    $json = (string)file_get_contents($_FILES['file']['tmp_name']);
                }
                try {
                    $id = scenario_save($json, trim((string)($_POST['note'] ?? '')), (string)$me['login'], !empty($_POST['activate']));
                } catch (RuntimeException $e) {
                    $view['json'] = $json; $view['error'] = $e->getMessage(); $tab = 'scenario';
                    break;
                }
                go('tab=scenario', 'Сохранена версия #' . $id . (!empty($_POST['activate']) ? ' — она активна: новые диалоги идут по ней.' : ' (не активна).'));
            case 'scenario_activate':
                scenario_activate((int)$_POST['id']);
                go('tab=scenario', 'Версия #' . (int)$_POST['id'] . ' активна. Идущие диалоги остаются на своих версиях.');

            /* --- каталог --- */
            case 'catalog_save':
                $p = catalog_from_post($_POST);
                if (($_POST['orig_id'] ?? '') !== '' && $_POST['orig_id'] !== $p['id']) catalog_delete((string)$_POST['orig_id']);
                catalog_save($p);
                go('tab=catalog&id=' . urlencode($p['id']), 'Товар «' . $p['name'] . '» сохранён. Бот уже видит новые данные.');
            case 'catalog_delete':
                catalog_delete((string)$_POST['id']);
                go('tab=catalog', 'Товар удалён.');
            case 'section_save':
                $code = normalize_section((string)($_POST['code'] ?? ''));
                if ($code === '') throw new RuntimeException('Нужен код раздела.');
                $qs = [];
                $q = (array)($_POST['q'] ?? []);
                foreach ((array)($q['code'] ?? []) as $i => $c) $qs[] = ['code' => $c, 'question' => $q['question'][$i] ?? '', 'options' => $q['options'][$i] ?? '', 'affects' => $q['affects'][$i] ?? ''];
                section_save($code, (string)($_POST['name'] ?? ''), $qs);
                go('tab=catalog&sec=' . urlencode($code), 'Раздел «' . $code . '» сохранён. Вопросы подхватятся в новых ходах диалога.');
            case 'catalog_import':
                $f = $_FILES['file'] ?? null;
                if (!$f || $f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Файл не загрузился (код ' . ($f['error'] ?? '?') . ').');
                if (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'xlsx') throw new RuntimeException('Нужен файл .xlsx.');
                $rep = catalog_import_xlsx($f['tmp_name'], !empty($_POST['prune']));
                $_SESSION['import_report'] = $rep;
                go('tab=catalog', "Загружено: товаров {$rep['products']}, тарифов {$rep['variants']}, опций {$rep['options']}, вопросов {$rep['questions']}"
                    . ($rep['removed'] ? '; удалено: ' . implode(', ', $rep['removed']) : '') . '.');

            /* --- материалы --- */
            case 'upload':
                set_time_limit(0);
                ignore_user_abort(true);
                @ini_set('memory_limit', '1024M');
                $f = $_FILES['file'] ?? null;
                if (!$f || $f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Файл не загрузился (код ' . ($f['error'] ?? '?') . ').');
                $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, SUPPORTED_EXT, true)) throw new RuntimeException("Формат .$ext не поддерживается. Можно: " . implode(', ', SUPPORTED_EXT));
                $dir = data_dir() . '/files';
                if (!is_dir($dir)) mkdir($dir, 0770, true);
                $dest = $dir . '/' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                move_uploaded_file($f['tmp_name'], $dest);
                $title = trim((string)($_POST['title'] ?? '')) ?: pathinfo($f['name'], PATHINFO_FILENAME);
                $section = trim((string)($_POST['section'] ?? '')) ?: (string)($_POST['section_new'] ?? 'general');
                $info = '';
                try {
                    index_file($dest, $f['name'], $title, $section, (string)($_POST['trust'] ?? 'main'), function ($m) use (&$info) { $info = $m; });
                } catch (Throwable $e) { @unlink($dest); throw $e; }
                go('tab=docs', "«{$title}» → раздел " . normalize_section($section) . ": {$info}.");
            case 'doc_toggle':
                db()->prepare('UPDATE documents SET active = 1 - active WHERE id = ?')->execute([(int)$_POST['id']]);
                go('tab=docs');
            case 'doc_trust':
                db()->prepare("UPDATE documents SET trust = CASE trust WHEN 'aux' THEN 'main' ELSE 'aux' END WHERE id = ?")->execute([(int)$_POST['id']]);
                go('tab=docs');
            case 'doc_section':
                db()->prepare('UPDATE documents SET section = ? WHERE id = ?')->execute([normalize_section((string)$_POST['section']), (int)$_POST['id']]);
                go('tab=docs', 'Раздел изменён.');
            case 'doc_delete':
                $st = db()->prepare('SELECT filename FROM documents WHERE id = ?'); $st->execute([(int)$_POST['id']]);
                if ($fn = $st->fetchColumn()) @unlink(data_dir() . '/files/' . basename($fn));
                delete_document((int)$_POST['id']);
                go('tab=docs', 'Удалено.');
            case 'note_save':
                $nid = save_note((int)($_POST['id'] ?? 0), (string)$_POST['title'], (string)$_POST['section'], (string)$_POST['text'], (string)($_POST['trust'] ?? 'main'));
                go('tab=docs&note=' . $nid, 'Заметка сохранена — бот уже видит новый текст.');
            case 'kb_test':
                $view['kb_q'] = (string)$_POST['q'];
                $view['kb_sections'] = (string)$_POST['sections'];
                $view['kb_res'] = retrieve($view['kb_q'], array_filter(array_map('trim', explode(',', $view['kb_sections']))));
                $tab = 'docs';
                break;

            /* --- настройки --- */
            case 'settings_save':
                foreach (['welcome', 'suggestions'] as $k) if (isset($_POST[$k])) set_setting($k, str_replace("\r\n", "\n", trim((string)$_POST[$k])));
                go('tab=settings', 'Сохранено.');
            case 'b24_reference':
                $view['b24ref'] = b24_reference((int)(sc_setting(scenario_active(), 'hr.smart_entity_type_id') ?? 0));
                $tab = 'bitrix';
                break;
            case 'b24_fields_load':
                $n = [];
                foreach (array_keys(B24_ENTITIES) as $ent) $n[] = B24_ENTITIES[$ent] . ': ' . count(b24_fields($ent, true));
                $p = b24_deal_pipelines(true);
                go('tab=bitrix', 'Загружено с портала — поля: ' . implode(', ', $n) . '; воронок сделок: ' . count($p) . '.');
            case 'b24_map_save':
                $ent = isset(B24_ENTITIES[$_POST['entity'] ?? '']) ? (string)$_POST['entity'] : 'lead';
                $map = [];
                foreach ((array)($_POST['map'] ?? []) as $k => $v) {
                    $k = (string)$k; $v = (string)$v;
                    if (!isset(BOT_FIELDS[$k]) && !isset(scenario_active()['slots'][$k])) continue;
                    if ($v !== '' && $v !== '-' && !preg_match('/^[A-Z0-9_]+$/', $v)) continue;
                    $map[$k] = $v;
                }
                set_setting('b24_map_' . $ent, jenc((object)$map));
                $def = [];
                foreach ((array)($_POST['def'] ?? []) as $k => $v) if (preg_match('/^[A-Z0-9_]+$/', (string)$k) && trim((string)$v) !== '') $def[$k] = trim((string)$v);
                set_setting('b24_defaults_' . $ent, jenc((object)$def));
                go('tab=bitrix&ent=' . $ent, 'Сопоставление полей (' . B24_ENTITIES[$ent] . ') сохранено.');
            case 'b24_bot_user':
                set_setting('b24_bot_user', (string)max(0, (int)$_POST['bot_user']));
                go('tab=bitrix', 'Сотрудник «Бот»: #' . (int)$_POST['bot_user'] . '.');
            case 'dlg_return':
                $d = dialog_load($dlgId);
                $T = turn_init($d, 0);
                if (b24_bot_user() > 0) {
                    if ((int)$d['deal_id'] > 0) b24_call($T, 'b24_return', 'crm.deal.update', ['id' => (int)$d['deal_id'], 'fields' => ['ASSIGNED_BY_ID' => b24_bot_user()]]);
                    elseif ((int)$d['lead_id'] > 0) b24_call($T, 'b24_return', 'crm.lead.update', ['id' => (int)$d['lead_id'], 'fields' => ['ASSIGNED_BY_ID' => b24_bot_user()]]);
                }
                unset($T['d']['counters']['taken_by']);
                dialog_save($T['d']);
                go($back, 'Клиент возвращён боту' . (b24_bot_user() ? ' (ответственный в Битриксе — бот)' : '') . '.');
            case 'b24_flow_save':
                $flow = [];
                foreach ((array)($_POST['flow'] ?? []) as $prod => $f) {
                    if (!isset(FLOW_PRODUCTS[$prod])) continue;
                    $stages = [];
                    foreach ((array)($f['stages'] ?? []) as $st => $id) if ((string)$id !== '') $stages[(string)$st] = (string)$id;
                    $flow[$prod] = [
                        'entity' => ($f['entity'] ?? 'lead') === 'deal' ? 'deal' : 'lead',
                        'category' => (int)($f['category'] ?? 0),
                        'stage_new' => (string)($f['stage_new'] ?? ''),
                        'service' => (string)($f['service'] ?? ''),
                        'assigned' => (int)($f['assigned'] ?? 0),
                        'stages' => $stages,
                    ];
                }
                set_setting('b24_flow', jenc((object)$flow));
                go('tab=bitrix', 'Направления сохранены. Применится со следующего сообщения клиента.');

            /* --- пользователи --- */
            case 'my_password':
                if (!password_verify((string)$_POST['old'], $me['password_hash'])) throw new RuntimeException('Текущий пароль указан неверно.');
                if ((string)$_POST['password'] !== (string)$_POST['password2']) throw new RuntimeException('Новые пароли не совпадают.');
                set_user_password((int)$me['id'], (string)$_POST['password']);
                if (isset($_COOKIE[REMEMBER_COOKIE])) issue_remember_token((int)$me['id']);
                go('tab=me', 'Пароль изменён.');
            case 'my_forget':
                forget_all_devices((int)$me['id']); clear_remember_cookie();
                go('tab=me', 'Все запомненные компьютеры сброшены.');
            case 'user_add':
                if (!can('users')) throw new RuntimeException('Нет доступа.');
                create_user((string)$_POST['login'], (string)$_POST['name'], (string)$_POST['password'], (string)$_POST['role']);
                go('tab=users', 'Пользователь создан.');
            case 'user_save':
                if (!can('users')) throw new RuntimeException('Нет доступа.');
                update_user((int)$_POST['id'], ['name' => $_POST['name'] ?? '', 'role' => $_POST['role'] ?? '', 'active' => !empty($_POST['active'])]);
                if (trim((string)($_POST['password'] ?? '')) !== '') set_user_password((int)$_POST['id'], (string)$_POST['password']);
                go('tab=users', 'Сохранено.');
            case 'user_delete':
                if (!can('users')) throw new RuntimeException('Нет доступа.');
                if ((int)$_POST['id'] === (int)$me['id']) throw new RuntimeException('Себя удалить нельзя.');
                delete_user((int)$_POST['id']);
                go('tab=users', 'Пользователь удалён.');
            default:
                throw new RuntimeException('Неизвестное действие');
        }
    } catch (Throwable $e) {
        app_log('admin ' . $do . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        go($back, 'Ошибка: ' . $e->getMessage());
    }
}

$tabs = ['dialogs' => 'Диалоги', 'sandbox' => 'Песочница', 'scenario' => 'Сценарий', 'catalog' => 'Каталог', 'docs' => 'Материалы', 'bitrix' => 'Битрикс24', 'settings' => 'Настройки'];
if (can('users')) $tabs['users'] = 'Пользователи';
if (!isset($tabs[$tab]) && $tab !== 'me') $tab = 'dialogs';
$wide = in_array($tab, ['dialogs', 'sandbox'], true);
?><!doctype html>
<html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($tabs[$tab] ?? 'Админка') ?> — vb2</title><link rel="stylesheet" href="admin.css?v=2"><link rel="icon" href="favicon.svg" type="image/svg+xml">
<body>
<header><b>Бот vb2</b>
  <nav><?php foreach ($tabs as $k => $v): ?><a href="?tab=<?= $k ?>" class="<?= $tab === $k ? 'on' : '' ?>"><?= $v ?></a><?php endforeach; ?></nav>
  <span class="who"><a href="?tab=me" class="<?= $tab === 'me' ? 'on' : '' ?>"><?= h($me['name'] ?: $me['login']) ?></a><a href="?logout=1">Выйти</a></span>
</header>
<main class="<?= $wide ? 'wide' : '' ?>">
<?php if ($flash): ?><p class="<?= str_starts_with($flash, 'Ошибка') ? 'err' : 'ok' ?>" style="white-space:pre-wrap"><?= h($flash) ?></p><?php endif; ?>
<?php
switch ($tab) {
    case 'dialogs':
        if (isset($_GET['id'])) view_dialog_card((int)$_GET['id'], false);
        else view_dialogs();
        break;
    case 'sandbox':
        view_dialog_card(sandbox_dialog($sandboxKey), true);
        break;
    case 'scenario': view_scenario($view); break;
    case 'catalog': view_catalog(); break;
    case 'docs': view_docs($view); break;
    case 'settings': view_settings($view); break;
    case 'bitrix': view_bitrix($view); break;
    case 'users': view_users($me); break;
    case 'me': view_me($me); break;
}
?>
</main>
<script>
// чат в карточке — прокрутить к последнему сообщению
document.querySelectorAll('.chatbox').forEach(b => b.scrollTop = b.scrollHeight);
// Ctrl+Enter отправляет сообщение в песочнице
document.querySelectorAll('textarea[data-submit]').forEach(t => t.addEventListener('keydown', e => {
  if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); t.form.requestSubmit(); }
}));
document.querySelectorAll('form[data-busy]').forEach(f => f.addEventListener('submit', () => {
  const b = f.querySelector('button[type=submit],button:not([type])'); if (b) { b.disabled = true; b.textContent = f.dataset.busy; }
}));
</script>
</body></html>
