<?php
declare(strict_types=1);

/**
 * Выгрузка для анализа напрямую из базы Битрикс24 (только чтение), через SSH-туннель.
 *
 * Зачем: REST тяжёлые выборки гоняет через PHP портала, и неудачный запрос может его положить.
 * Здесь — несколько выборок по индексам прямо в MySQL; портал (PHP) не участвует.
 *
 * Безопасность:
 *  - туннель поднимается на время выгрузки и закрывается сразу после;
 *  - пароль MySQL не хранится: вводится в форме, передаётся фоновому процессу через окружение и сразу стирается;
 *  - SSH-ключ на сервере бота (data/ssh) на стороне Битрикса ограничен в authorized_keys:
 *    только проброс на 127.0.0.1:3306, без консоли и команд;
 *  - пользователь MySQL — только SELECT на нужные таблицы (у b_user — только имя и тип).
 *
 * config.php:
 *   'b24_db' => ['ssh_host' => 'crm.milli-mind.ru', 'ssh_port' => 22, 'ssh_user' => 'vb2tunnel',
 *                'db_name' => 'sitemanager', 'db_user' => 'vb2_read', 'db_host' => '127.0.0.1', 'db_port' => 3306,
 *                'timezone' => ''],   // часовой пояс дат в базе; пусто — определяется по MySQL
 *
 * Собирает те же данные, что b24x_collect() (REST), — файлы строятся теми же функциями.
 */

function b24db_cfg(): array
{
    $c = (array)cfg('b24_db', []);
    return $c + ['ssh_port' => 22, 'db_host' => '127.0.0.1', 'db_port' => 3306, 'db_name' => 'sitemanager', 'timezone' => ''];
}

function b24db_configured(): bool
{
    $c = b24db_cfg();
    return !empty($c['ssh_host']) && !empty($c['ssh_user']) && !empty($c['db_user']);
}

function b24db_ssh_dir(): string
{
    $d = data_dir() . '/ssh';
    if (!is_dir($d)) mkdir($d, 0700, true);
    return $d;
}

function b24db_key_path(): string { return b24db_ssh_dir() . '/id_ed25519'; }

/** Создать ключ (если нет) и вернуть строку для authorized_keys на сервере Битрикса. */
function b24db_key_line(bool $create = false): string
{
    $k = b24db_key_path();
    if (!is_file($k)) {
        if (!$create) return '';
        exec('ssh-keygen -q -t ed25519 -N ' . escapeshellarg('') . ' -C vb2-b24export -f ' . escapeshellarg($k) . ' 2>&1', $out, $rc);
        if ($rc !== 0) throw new RuntimeException('ssh-keygen: ' . implode(' ', $out));
        @chmod($k, 0600);
    }
    $pub = trim((string)@file_get_contents($k . '.pub'));
    $c = b24db_cfg();
    $dest = $c['db_host'] . ':' . (int)$c['db_port'];
    return 'restrict,port-forwarding,permitopen="' . $dest . '",command="/bin/false" ' . $pub;
}

/* ------------------------------------------------------------------ */
/*  Туннель и соединение                                               */
/* ------------------------------------------------------------------ */

final class B24dbTunnel
{
    private $proc = null;
    private array $pipes = [];
    public int $port = 0;

    public function open(): void
    {
        $c = b24db_cfg();
        if (!is_file(b24db_key_path())) throw new RuntimeException('Нет SSH-ключа — создайте его в админке (Настройки → Выгрузка).');
        // свободный локальный порт
        $s = stream_socket_server('tcp://127.0.0.1:0', $en, $es);
        if (!$s) throw new RuntimeException("Нет свободного порта: $es");
        $this->port = (int)substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
        fclose($s);
        $dir = b24db_ssh_dir();
        $cmd = ['ssh', '-N', '-T', '-F', '/dev/null', '-i', b24db_key_path(),
            '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-o', 'ExitOnForwardFailure=yes', '-o', 'ConnectTimeout=15',
            '-o', 'ServerAliveInterval=15', '-o', 'ServerAliveCountMax=3',
            '-o', 'StrictHostKeyChecking=accept-new', '-o', 'UserKnownHostsFile=' . $dir . '/known_hosts',
            '-p', (string)(int)$c['ssh_port'],
            '-L', '127.0.0.1:' . $this->port . ':' . $c['db_host'] . ':' . (int)$c['db_port'],
            $c['ssh_user'] . '@' . $c['ssh_host']];
        $this->proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes);
        if (!is_resource($this->proc)) throw new RuntimeException('Не удалось запустить ssh.');
        stream_set_blocking($this->pipes[2], false);
        $deadline = microtime(true) + 20;
        while (microtime(true) < $deadline) {
            $st = proc_get_status($this->proc);
            if (!$st['running']) {
                $err = trim((string)stream_get_contents($this->pipes[2]));
                $this->close();
                throw new RuntimeException('SSH-туннель не открылся: ' . ($err ?: 'код ' . $st['exitcode']));
            }
            $fp = @fsockopen('127.0.0.1', $this->port, $en, $es, 0.5);
            if ($fp) { fclose($fp); return; }
            usleep(300000);
        }
        $this->close();
        throw new RuntimeException('SSH-туннель не открылся за 20 с.');
    }

    public function close(): void
    {
        if (!$this->proc) return;
        @proc_terminate($this->proc, 15);
        foreach ($this->pipes as $p) @fclose($p);
        for ($i = 0; $i < 20 && (proc_get_status($this->proc)['running'] ?? false); $i++) usleep(100000);
        if (proc_get_status($this->proc)['running'] ?? false) @proc_terminate($this->proc, 9);
        @proc_close($this->proc);
        $this->proc = null;
    }

    public function __destruct() { $this->close(); }
}

/** Открыть туннель и соединение. Вызывающий обязан закрыть туннель (finally). */
function b24db_connect(string $password, B24dbTunnel $t): PDO
{
    if (!extension_loaded('pdo_mysql')) throw new RuntimeException('На сервере бота нет php8.3-mysql: sudo apt install php8.3-mysql');
    if ($password === '') throw new RuntimeException('Введите пароль MySQL.');
    $c = b24db_cfg();
    $t->open();
    try {
        $pdo = new PDO('mysql:host=127.0.0.1;port=' . $t->port . ';dbname=' . $c['db_name'] . ';charset=utf8mb4', (string)$c['db_user'], $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 15,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]);
    } catch (PDOException $e) {
        throw new RuntimeException('MySQL: ' . $e->getMessage());
    }
    // предохранители: только чтение, ни один запрос не дольше 60 с (MySQL 5.7+ / MariaDB)
    foreach (['SET SESSION TRANSACTION READ ONLY', 'SET SESSION MAX_EXECUTION_TIME = 60000', 'SET SESSION max_statement_time = 60'] as $q) {
        try { $pdo->exec($q); } catch (Throwable $e) { /* не поддерживается этой версией */ }
    }
    return $pdo;
}

/** Часовой пояс дат в базе: из config или по смещению MySQL (NOW() − UTC). */
function b24db_tz(PDO $pdo): DateTimeZone
{
    $tz = (string)b24db_cfg()['timezone'];
    if ($tz !== '') { try { return new DateTimeZone($tz); } catch (Throwable $e) {} }
    $sec = (int)$pdo->query('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())')->fetchColumn();
    $sec = (int)(round($sec / 900) * 900);
    return new DateTimeZone(sprintf('%s%02d:%02d', $sec < 0 ? '-' : '+', intdiv(abs($sec), 3600), intdiv(abs($sec) % 3600, 60)));
}

/* ------------------------------------------------------------------ */
/*  Выборки                                                            */
/* ------------------------------------------------------------------ */

function b24db_q(PDO $pdo, string $sql, array $args = []): array
{
    if (is_callable($GLOBALS['B24X_HOOK'] ?? null)) ($GLOBALS['B24X_HOOK'])('sql');
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

/** Запрос, ошибка которого не критична (таблицы нет в этой версии). */
function b24db_try(PDO $pdo, string $sql, array $args, B24xRef $ref, string $what): array
{
    try { return b24db_q($pdo, $sql, $args); }
    catch (Throwable $e) { b24x_rethrow($e); $ref->warnings[] = $what . ': ' . $e->getMessage(); return []; }
}

function b24db_in(array $ids): string
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    return $ids ? implode(',', $ids) : '0';
}

function b24db_has_table(PDO $pdo, string $t): bool
{
    static $cache = [];
    return $cache[$t] ??= (bool)b24db_q($pdo, 'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t]);
}

/** Сущности CRM с пользовательскими полями и мультиполями (PHONE/EMAIL/IM) — в виде, как отдаёт REST. */
function b24db_entities(PDO $pdo, string $ent, string $where, array $args, B24xRef $ref): array
{
    [$table, $uts, $multi] = match ($ent) {
        'lead' => ['b_crm_lead', 'b_uts_crm_lead', 'LEAD'],
        'deal' => ['b_crm_deal', 'b_uts_crm_deal', null],
        'contact' => ['b_crm_contact', 'b_uts_crm_contact', 'CONTACT'],
    };
    $join = b24db_has_table($pdo, $uts) ? " LEFT JOIN `$uts` u ON u.VALUE_ID = e.ID" : '';
    $rows = b24db_q($pdo, "SELECT e.*" . ($join ? ', u.*' : '') . " FROM `$table` e$join WHERE $where ORDER BY e.ID", $args);
    $out = [];
    foreach ($rows as $r) {
        unset($r['VALUE_ID']);
        foreach ($r as $k => $v) if (str_starts_with($k, 'UF_') && is_string($v) && str_starts_with($v, 'a:')) {
            $u = @unserialize($v, ['allowed_classes' => false]);
            if (is_array($u)) $r[$k] = $u;
        }
        $out[(int)$r['ID']] = $r;
    }
    if ($multi && $out) {
        foreach (array_chunk(array_keys($out), 500) as $chunk) {
            foreach (b24db_q($pdo, "SELECT ELEMENT_ID, TYPE_ID, VALUE_TYPE, VALUE FROM b_crm_field_multi WHERE ENTITY_ID = ? AND ELEMENT_ID IN (" . b24db_in($chunk) . ")", [$multi]) as $m) {
                $out[(int)$m['ELEMENT_ID']][$m['TYPE_ID']][] = ['VALUE' => $m['VALUE'], 'VALUE_TYPE' => $m['VALUE_TYPE']];
            }
        }
    }
    if ($ent === 'lead' && $out && b24db_has_table($pdo, 'b_crm_utm')) {
        foreach (array_chunk(array_keys($out), 500) as $chunk) {
            foreach (b24db_try($pdo, "SELECT ENTITY_ID, CODE, VALUE FROM b_crm_utm WHERE ENTITY_TYPE_ID = 1 AND ENTITY_ID IN (" . b24db_in($chunk) . ")", [], $ref, 'UTM') as $m) {
                $out[(int)$m['ENTITY_ID']][$m['CODE']] = $m['VALUE'];
            }
        }
    }
    return $out;
}

/** Справочники из базы. */
function b24db_ref(PDO $pdo): B24xRef
{
    $ref = new B24xRef();
    foreach (b24db_q($pdo, 'SELECT * FROM b_crm_status') as $s) {
        $ref->status[$s['ENTITY_ID']][(string)$s['STATUS_ID']] = (string)$s['NAME'];
        $ref->semantic[$s['ENTITY_ID']][(string)$s['STATUS_ID']] = (string)($s['SEMANTICS'] ?? '');
    }
    $ref->categories = [0 => 'Общая'];
    if (b24db_has_table($pdo, 'b_crm_deal_category')) foreach (b24db_q($pdo, 'SELECT ID, NAME FROM b_crm_deal_category') as $c) $ref->categories[(int)$c['ID']] = (string)$c['NAME'];
    if (b24db_has_table($pdo, 'b_crm_item_category')) {
        foreach (b24db_try($pdo, 'SELECT * FROM b_crm_item_category WHERE ENTITY_TYPE_ID = 2', [], $ref, 'Воронки') as $c) {
            if (($c['IS_DEFAULT'] ?? 'N') === 'Y') $ref->categories[0] = (string)$c['NAME'];
            elseif (!isset($ref->categories[(int)$c['ID']])) $ref->categories[(int)$c['ID']] = (string)$c['NAME'];
        }
    }
    foreach (b24_deal_pipelines() as $id => $p) $ref->categories[(int)$id] = (string)$p['name']; // названия, загруженные во вкладке «Битрикс24»
    foreach (b24db_try($pdo, 'SELECT ID, LINE_NAME FROM b_imopenlines_config', [], $ref, 'Открытые линии') as $l) $ref->lines[(int)$l['ID']] = (string)$l['LINE_NAME'];
    $ents = ['CRM_LEAD' => 'lead', 'CRM_DEAL' => 'deal', 'CRM_CONTACT' => 'contact'];
    $uf = b24db_q($pdo, "SELECT ID, ENTITY_ID, FIELD_NAME, USER_TYPE_ID FROM b_user_field WHERE ENTITY_ID IN ('CRM_LEAD','CRM_DEAL','CRM_CONTACT')");
    $byId = [];
    foreach ($uf as $f) {
        $ref->fields[$ents[$f['ENTITY_ID']]][$f['FIELD_NAME']] = ['label' => $f['FIELD_NAME'], 'items' => [], 'type' => (string)$f['USER_TYPE_ID']];
        $byId[(int)$f['ID']] = [$ents[$f['ENTITY_ID']], $f['FIELD_NAME']];
    }
    if ($byId) {
        foreach (b24db_try($pdo, "SELECT USER_FIELD_ID, LIST_COLUMN_LABEL, EDIT_FORM_LABEL FROM b_user_field_lang WHERE LANGUAGE_ID = 'ru' AND USER_FIELD_ID IN (" . b24db_in(array_keys($byId)) . ")", [], $ref, 'Названия полей') as $l) {
            [$e, $n] = $byId[(int)$l['USER_FIELD_ID']];
            $ref->fields[$e][$n]['label'] = (string)($l['LIST_COLUMN_LABEL'] ?: $l['EDIT_FORM_LABEL'] ?: $n);
        }
        foreach (b24db_try($pdo, "SELECT USER_FIELD_ID, ID, VALUE FROM b_user_field_enum WHERE USER_FIELD_ID IN (" . b24db_in(array_keys($byId)) . ")", [], $ref, 'Списки полей') as $v) {
            [$e, $n] = $byId[(int)$v['USER_FIELD_ID']];
            $ref->fields[$e][$n]['items'][(string)$v['ID']] = (string)$v['VALUE'];
        }
    }
    return $ref;
}

/** Сотрудники и клиенты-«пользователи» открытых линий: имя и тип. */
function b24db_users(PDO $pdo, array $ids): array
{
    $out = [];
    foreach (array_chunk(array_values(array_unique(array_filter(array_map('intval', $ids)))), 500) as $chunk) {
        foreach (b24db_q($pdo, 'SELECT ID, NAME, LAST_NAME, EXTERNAL_AUTH_ID FROM b_user WHERE ID IN (' . b24db_in($chunk) . ')') as $u) $out[(int)$u['ID']] = $u;
    }
    return $out;
}

/* ------------------------------------------------------------------ */
/*  Сбор                                                               */
/* ------------------------------------------------------------------ */

/** То же, что b24x_collect(), но из базы. Пароль не сохраняется. */
function b24db_collect(string $from, string $to, array $opt, ?callable $progress, string $password): array
{
    @set_time_limit(0);
    $say = $progress ?? fn($s) => null;
    $mask = $opt['mask'] ?? true;
    $system = !empty($opt['system']);
    [$a, $b, $tag] = b24x_period($from, $to);
    $say('SSH-туннель');
    $t = new B24dbTunnel();
    try {
        $pdo = b24db_connect($password, $t);
        $password = '';
        $tz = b24db_tz($pdo);
        $GLOBALS['B24X_SRC_TZ'] = $tz;               // даты без пояса из базы — в поясе сервера Битрикса
        $A = (new DateTimeImmutable($a))->setTimezone($tz)->format('Y-m-d H:i:s');
        $B = (new DateTimeImmutable($b))->setTimezone($tz)->format('Y-m-d H:i:s');
        $range = [$A, $B];

        $say('справочники');
        $ref = b24db_ref($pdo);

        $say('лиды');
        $leadsNew = b24db_entities($pdo, 'lead', 'e.DATE_CREATE BETWEEN ? AND ?', $range, $ref);
        $leadsClosed = b24db_entities($pdo, 'lead', "e.DATE_CLOSED BETWEEN ? AND ? AND e.STATUS_SEMANTIC_ID IN ('S','F')", $range, $ref);
        $say('лиды: ' . count($leadsNew) . ' созданных, ' . count($leadsClosed) . ' закрытых');

        $catSql = '';
        if (isset($opt['category']) && $opt['category'] !== '' && $opt['category'] !== null) $catSql = ' AND e.CATEGORY_ID = ' . (int)$opt['category'];
        $dealsNew = b24db_entities($pdo, 'deal', 'e.DATE_CREATE BETWEEN ? AND ?' . $catSql, $range, $ref);
        $dealsClosed = b24db_entities($pdo, 'deal', "e.CLOSED = 'Y' AND e.CLOSEDATE BETWEEN ? AND ?" . $catSql, $range, $ref);
        $say('сделки: ' . count($dealsNew) . ' созданных, ' . count($dealsClosed) . ' закрытых');

        $contactsNew = b24db_entities($pdo, 'contact', 'e.DATE_CREATE BETWEEN ? AND ?', $range, $ref);
        $say('контакты: ' . count($contactsNew));

        // звонки — дела CRM телефонии
        $calls = b24db_try($pdo, "SELECT ID, OWNER_TYPE_ID, OWNER_ID, CREATED, START_TIME, END_TIME, COMPLETED, DIRECTION, RESPONSIBLE_ID, SUBJECT, ASSOCIATED_ENTITY_ID
            FROM b_crm_act WHERE PROVIDER_ID = 'VOXIMPLANT_CALL' AND CREATED BETWEEN ? AND ? ORDER BY ID", $range, $ref, 'Звонки');
        $say('звонки: ' . count($calls));

        // сессии открытых линий — все, в том числе без дела в CRM
        $sessRows = b24db_q($pdo, 'SELECT * FROM b_imopenlines_session WHERE DATE_CREATE BETWEEN ? AND ? ORDER BY ID', $range);
        $say('сессии открытых линий: ' . count($sessRows));

        // привязки дел к лидам/сделкам/контактам
        $actIds = array_merge(array_column($calls, 'ID'), array_filter(array_map(fn($s) => (int)($s['CRM_ACTIVITY_ID'] ?? 0), $sessRows)));
        $actBind = [];
        foreach (array_chunk($actIds, 500) as $chunk) {
            foreach (b24db_q($pdo, 'SELECT ACTIVITY_ID, OWNER_TYPE_ID, OWNER_ID FROM b_crm_act_bind WHERE ACTIVITY_ID IN (' . b24db_in($chunk) . ')') as $r) {
                $tt = B24X_TYPE[(int)$r['OWNER_TYPE_ID']] ?? null;
                if ($tt) $actBind[(int)$r['ACTIVITY_ID']][$tt][] = (int)$r['OWNER_ID'];
            }
        }
        // номера звонков
        $callPhones = [];
        foreach (array_chunk(array_column($calls, 'ID'), 500) as $chunk) {
            foreach (b24db_try($pdo, "SELECT ACTIVITY_ID, VALUE FROM b_crm_act_comm WHERE TYPE = 'PHONE' AND ACTIVITY_ID IN (" . b24db_in($chunk) . ")", [], $ref, 'Номера звонков') as $r) $callPhones[(int)$r['ACTIVITY_ID']] = $r['VALUE'];
        }
        foreach ($calls as &$c) $c['COMMUNICATIONS'] = isset($callPhones[(int)$c['ID']]) ? [['TYPE' => 'PHONE', 'VALUE' => $callPhones[(int)$c['ID']]]] : [];
        unset($c);

        // сессии → в форму «дела» (как в REST), ключ — номер сессии
        $sessions = [];
        $bind = [];
        foreach ($sessRows as $s) {
            $sid = (int)$s['ID'];
            $closed = ($s['CLOSED'] ?? 'N') === 'Y' || (string)($s['DATE_CLOSE'] ?? '') !== '';
            $sessions[] = ['ID' => $sid, 'ASSOCIATED_ENTITY_ID' => $sid, 'OWNER_TYPE_ID' => 0, 'OWNER_ID' => 0,
                'CREATED' => $s['DATE_CREATE'], 'START_TIME' => $s['DATE_CREATE'], 'END_TIME' => $s['DATE_CLOSE'] ?? '',
                'COMPLETED' => $closed ? 'Y' : 'N', 'RESPONSIBLE_ID' => (int)($s['OPERATOR_ID'] ?? 0), 'SUBJECT' => '',
                'COMMUNICATIONS' => [['VALUE' => 'imol|' . ($s['USER_CODE'] ?? '')]], '_session' => $s];
            $bind[$sid] = $actBind[(int)($s['CRM_ACTIVITY_ID'] ?? 0)] ?? [];
        }
        foreach ($calls as $c) $bind[-(int)$c['ID']] = $actBind[(int)$c['ID']] ?? [];

        // переписка: кусками по 100 сессий, сообщения чата в диапазоне START_ID..END_ID сессии
        $hist = [];
        $authors = [];
        $done = 0;
        foreach (array_chunk($sessRows, 100) as $chunk) {
            $chats = array_map(fn($s) => (int)$s['CHAT_ID'], $chunk);
            $minId = min(array_map(fn($s) => (int)($s['START_ID'] ?? 0), $chunk));
            $msgs = b24db_q($pdo, 'SELECT ID, CHAT_ID, AUTHOR_ID, MESSAGE, DATE_CREATE FROM b_im_message WHERE CHAT_ID IN (' . b24db_in($chats) . ') AND ID >= ? ORDER BY ID', [$minId]);
            $files = [];
            foreach (array_chunk(array_column($msgs, 'ID'), 1000) as $mc) {
                foreach (b24db_try($pdo, "SELECT MESSAGE_ID, PARAM_VALUE FROM b_im_message_param WHERE PARAM_NAME = 'FILE_ID' AND MESSAGE_ID IN (" . b24db_in($mc) . ")", [], $ref, 'Вложения') as $p) $files[(int)$p['MESSAGE_ID']][] = $p['PARAM_VALUE'];
            }
            $byChat = [];
            foreach ($msgs as $m) $byChat[(int)$m['CHAT_ID']][] = $m;
            foreach ($chunk as $s) {
                $sid = (int)$s['ID'];
                $start = (int)($s['START_ID'] ?? 0);
                $end = (int)($s['END_ID'] ?? 0);
                $client = (int)($s['USER_ID'] ?? 0) ?: (int)(explode('|', (string)($s['USER_CODE'] ?? ''))[3] ?? 0);
                $h = ['chatId' => (int)$s['CHAT_ID'], 'message' => [], 'users' => [], 'client_id' => $client];
                foreach ($byChat[(int)$s['CHAT_ID']] ?? [] as $m) {
                    $id = (int)$m['ID'];
                    if ($start && $id < $start) continue;
                    if ($end && $id > $end) continue;
                    if (!$start && ($m['DATE_CREATE'] < $s['DATE_CREATE'] || ((string)($s['DATE_CLOSE'] ?? '') !== '' && $m['DATE_CREATE'] > $s['DATE_CLOSE']))) continue;
                    $h['message'][$id] = ['id' => $id, 'author_id' => (int)$m['AUTHOR_ID'], 'date' => $m['DATE_CREATE'], 'text' => (string)$m['MESSAGE'],
                        'params' => isset($files[$id]) ? ['FILE_ID' => $files[$id]] : []];
                    $authors[] = (int)$m['AUTHOR_ID'];
                }
                $hist[$sid] = $h;
            }
            $done += count($chunk);
            $say("переписка: $done из " . count($sessRows));
        }

        // связанные записи вне периода
        $say('связанные записи');
        $leads = $leadsNew + $leadsClosed;
        $deals = $dealsNew + $dealsClosed;
        $contacts = $contactsNew;
        $leadIds = $dealIds = $contactIds = [];
        foreach ($bind as $bb) { array_push($leadIds, ...($bb['lead'] ?? [])); array_push($dealIds, ...($bb['deal'] ?? [])); array_push($contactIds, ...($bb['contact'] ?? [])); }
        foreach ($calls as $c) match (B24X_TYPE[(int)$c['OWNER_TYPE_ID']] ?? '') {
            'lead' => $leadIds[] = (int)$c['OWNER_ID'], 'deal' => $dealIds[] = (int)$c['OWNER_ID'], 'contact' => $contactIds[] = (int)$c['OWNER_ID'], default => null,
        };
        $dealIds = array_diff($dealIds, array_keys($deals));
        if ($dealIds) $deals += b24db_entities($pdo, 'deal', 'e.ID IN (' . b24db_in($dealIds) . ')', [], $ref);
        // сделки, выросшие из лидов периода (в т. ч. созданные позже)
        $fromLeads = [];
        if ($leadsNew) {
            foreach (b24db_entities($pdo, 'deal', 'e.LEAD_ID IN (' . b24db_in(array_keys($leadsNew)) . ')' . $catSql, [], $ref) as $id => $d) {
                $deals[$id] ??= $d;
                $fromLeads[(int)$d['LEAD_ID']][] = $id;
            }
        }
        foreach ($deals as $d) { $leadIds[] = (int)$d['LEAD_ID']; $contactIds[] = (int)$d['CONTACT_ID']; }
        foreach ($leads as $l) $contactIds[] = (int)$l['CONTACT_ID'];
        $leadIds = array_diff($leadIds, array_keys($leads));
        if ($leadIds) $leads += b24db_entities($pdo, 'lead', 'e.ID IN (' . b24db_in($leadIds) . ')', [], $ref);
        foreach ($leads as $l) $contactIds[] = (int)$l['CONTACT_ID'];
        $contactIds = array_diff($contactIds, array_keys($contacts));
        if ($contactIds) $contacts += b24db_entities($pdo, 'contact', 'e.ID IN (' . b24db_in($contactIds) . ')', [], $ref);
        // семантика стадий — из самих сделок, если в справочнике её нет
        foreach ($deals as $d) {
            $e = (int)$d['CATEGORY_ID'] ? 'DEAL_STAGE_' . (int)$d['CATEGORY_ID'] : 'DEAL_STAGE';
            if (($ref->semantic[$e][(string)$d['STAGE_ID']] ?? '') === '' && !empty($d['STAGE_SEMANTIC_ID'])) $ref->semantic[$e][(string)$d['STAGE_ID']] = (string)$d['STAGE_SEMANTIC_ID'];
        }

        // имена: сотрудники, клиенты, боты
        $say('имена');
        $uids = $authors;
        foreach ([$leads, $deals, $contacts] as $set) foreach ($set as $r) { $uids[] = (int)($r['ASSIGNED_BY_ID'] ?? 0); $uids[] = (int)($r['CREATED_BY_ID'] ?? 0); }
        foreach ($sessions as $s) $uids[] = (int)$s['RESPONSIBLE_ID'];
        foreach ($calls as $c) $uids[] = (int)$c['RESPONSIBLE_ID'];
        $users = b24db_users($pdo, $uids);
        foreach ($users as $id => $u) $ref->users[$id] = trim($u['NAME'] . ' ' . $u['LAST_NAME']) ?: '#' . $id;
        foreach ($hist as &$h) {
            foreach ($h['message'] as $m) {
                $aid = (int)$m['author_id'];
                if (!$aid || isset($h['users'][$aid])) continue;
                $u = $users[$aid] ?? null;
                if (!$u) { // пользователя нет в b_user: клиент сессии — клиент, остальное — сотрудник
                    $h['users'][$aid] = ['id' => $aid, 'name' => '', 'connector' => $aid === $h['client_id']];
                    continue;
                }
                $ext = (string)$u['EXTERNAL_AUTH_ID'];
                $h['users'][$m['author_id']] = ['id' => (int)$u['ID'], 'name' => trim($u['NAME'] . ' ' . $u['LAST_NAME']), 'first_name' => (string)$u['NAME'],
                    'last_name' => (string)$u['LAST_NAME'], 'external_auth_id' => $ext, 'connector' => $ext === 'imconnector' || $aid === $h['client_id'], 'bot' => $ext === 'bot'];
            }
        }
        unset($h);
    } finally {
        $t->close();
        $password = '';
    }
    $say('туннель закрыт');

    $binding = function (array $act) use ($bind): array {
        $key = isset($act['_session']) ? (int)$act['ID'] : -(int)$act['ID'];
        $b = $bind[$key] ?? [];
        $tt = B24X_TYPE[(int)($act['OWNER_TYPE_ID'] ?? 0)] ?? null;
        if ($tt && !in_array((int)$act['OWNER_ID'], $b[$tt] ?? [], true)) $b[$tt][] = (int)$act['OWNER_ID'];
        return $b;
    };
    return ['ref' => $ref, 'mask' => $mask, 'system' => $system, 'tag' => $tag, 'from' => $from, 'to' => $to,
        'leadsNew' => array_values($leadsNew), 'leadsClosed' => array_values($leadsClosed), 'dealsNew' => array_values($dealsNew), 'dealsClosed' => array_values($dealsClosed),
        'contactsNew' => array_values($contactsNew), 'calls' => $calls, 'sessions' => $sessions, 'hist' => $hist,
        'leads' => $leads, 'deals' => $deals, 'contacts' => $contacts, 'fromLeads' => $fromLeads, 'binding' => $binding, 'source' => 'db'];
}

/** Проверка: туннель, вход в MySQL, наличие таблиц и прав, пробная сессия. */
function b24db_check(string $password): array
{
    $out = [];
    $t = new B24dbTunnel();
    try {
        $pdo = b24db_connect($password, $t);
        $out[] = ['SSH-туннель и вход в MySQL', 'ок, MySQL ' . $pdo->query('SELECT VERSION()')->fetchColumn() . ', база ' . b24db_cfg()['db_name'], true];
        $out[] = ['Часовой пояс дат в базе', b24db_tz($pdo)->getName(), true];
        $need = ['b_crm_lead', 'b_crm_deal', 'b_crm_contact', 'b_crm_status', 'b_crm_field_multi', 'b_user_field', 'b_imopenlines_session',
            'b_im_message', 'b_crm_act', 'b_crm_act_bind'];
        $opt = ['b_imopenlines_config', 'b_im_message_param', 'b_crm_act_comm', 'b_uts_crm_lead', 'b_uts_crm_deal', 'b_uts_crm_contact',
            'b_user_field_lang', 'b_user_field_enum', 'b_crm_utm', 'b_crm_deal_category', 'b_crm_item_category'];
        $probe = function (array $list) use ($pdo): array {
            $bad = [];
            foreach ($list as $tbl) { try { $pdo->query("SELECT 1 FROM `$tbl` LIMIT 1")->fetch(); } catch (Throwable $e) { $bad[] = $tbl; } }
            return $bad;
        };
        $bad = $probe($need);
        $out[] = ['Основные таблицы', $bad ? 'нет доступа: ' . implode(', ', $bad) : 'доступны', !$bad];
        $bad = $probe($opt);
        $out[] = ['Дополнительные таблицы', $bad ? 'нет в базе или нет права (не страшно, этих данных не будет): ' . implode(', ', $bad) : 'доступны', true];
        try { $pdo->query('SELECT ID, NAME, LAST_NAME, EXTERNAL_AUTH_ID FROM b_user LIMIT 1')->fetch(); $out[] = ['b_user (имя и тип)', 'доступно', true]; }
        catch (Throwable $e) { $out[] = ['b_user (имя и тип)', 'нет права — нужен GRANT SELECT (ID, NAME, LAST_NAME, EXTERNAL_AUTH_ID)', false]; }
        $s = $pdo->query('SELECT * FROM b_imopenlines_session ORDER BY ID DESC LIMIT 1')->fetch();
        if ($s) {
            $n = $pdo->prepare('SELECT COUNT(*) FROM b_im_message WHERE CHAT_ID = ? AND ID >= ?' . ((int)($s['END_ID'] ?? 0) ? ' AND ID <= ' . (int)$s['END_ID'] : ''));
            $n->execute([(int)$s['CHAT_ID'], (int)($s['START_ID'] ?? 0)]);
            $out[] = ['Последняя сессия ОЛ', '#' . $s['ID'] . ' от ' . $s['DATE_CREATE'] . ', сообщений: ' . $n->fetchColumn() . ', поля: ' . implode(', ', array_keys($s)), true];
        }
        try { $pdo->exec('CREATE TEMPORARY TABLE vb2_probe (x INT)'); $out[] = ['Только чтение', 'пользователь MySQL может создавать таблицы — лучше оставить ему только SELECT', false]; }
        catch (Throwable $e) { $out[] = ['Только чтение', 'да', true]; }
    } catch (Throwable $e) {
        $out[] = ['Подключение', $e->getMessage(), false];
    } finally {
        $t->close();
    }
    $out[] = ['Туннель', 'закрыт', true];
    return $out;
}
