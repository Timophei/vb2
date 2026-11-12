<?php
declare(strict_types=1);

/**
 * База знаний по разделам.
 *
 * Материал загружается в раздел (visa/general, visa/italy, cards, green_card, jobs, general …).
 * Состояние сценария перечисляет свои разделы в "knowledge": ["kb/visa/general", "kb/visa/{{visa.country}}"],
 * и поиск идёт только по ним. Раздел совпадает, если равен или вложен: "visa" включает "visa/italy".
 * Индексация и гибридный поиск (FTS5 + эмбеддинги) — как в v1.
 */

function pack_vec(array $v): string { return pack('g*', ...$v); }
function unpack_vec(string $b): array { return array_values(unpack('g*', $b)); }

function normalize_section(string $s): string
{
    $s = mb_strtolower(trim($s));
    $s = preg_replace('#^kb/#u', '', $s);
    $s = preg_replace('#\s*/\s*#u', '/', $s);
    $s = preg_replace('/\s+/u', '_', $s);
    return trim($s, '/') ?: 'general';
}

function pdf_pages(string $path): array
{
    $text = shell_exec('pdftotext -layout -enc UTF-8 ' . escapeshellarg($path) . ' - 2>/dev/null');
    if (!is_string($text) || trim($text) === '') {
        throw new RuntimeException('Не удалось извлечь текст из PDF. Если это скан — его нужно сначала распознать (OCR). Проверьте, что установлен poppler-utils.');
    }
    $pages = explode("\f", $text);
    if (trim((string)end($pages)) === '') array_pop($pages);
    return $pages;
}

function normalize_text(string $t): string
{
    $t = str_replace(["\r", "\t", "\u{00A0}"], ['', ' ', ' '], $t);
    $t = preg_replace('/-\n(\p{Ll})/u', '$1', $t);
    $t = preg_replace('/[ ]{2,}/u', ' ', $t);
    $t = preg_replace('/\n{3,}/u', "\n\n", $t);
    return trim($t);
}

function make_chunks(array $segments): array
{
    $size = (int)cfg('chunk_chars', 1200);
    $overlap = (int)cfg('chunk_overlap', 200);
    $chunks = [];
    foreach ($segments as $i => $s) {
        $text = normalize_text($s['text']);
        $min = $s['atomic'] ? 10 : 15;
        if (mb_strlen($text) < $min) continue;
        $base = ['page' => $i + 1, 'source' => mb_substr((string)$s['label'], 0, 250)];
        if ($s['atomic'] && mb_strlen($text) <= $size * 3) { $chunks[] = $base + ['text' => $text]; continue; }
        $pos = 0;
        $len = mb_strlen($text);
        while ($pos < $len) {
            $piece = mb_substr($text, $pos, $size);
            if ($pos + $size < $len) {
                $cut = max(mb_strrpos($piece, "\n\n") ?: 0, mb_strrpos($piece, '. ') ?: 0);
                if ($cut <= $size * 0.5) $cut = mb_strrpos($piece, "\n") ?: 0;
                if ($cut > $size * 0.5) $piece = mb_substr($piece, 0, $cut + 1);
            }
            $piece = trim($piece);
            if (mb_strlen($piece) >= $min) $chunks[] = $base + ['text' => $piece];
            if ($pos + $size >= $len) break;
            $pos += max(mb_strlen($piece) - $overlap, (int)($size / 2));
        }
    }
    return $chunks;
}

/** Индексирует материал в раздел. $trust: main — проверенный, aux — вспомогательный. */
function index_file(string $path, string $origName, string $title, string $section, string $trust = 'main', ?callable $log = null): int
{
    $log ??= fn($m) => null;
    $section = normalize_section($section);
    $segments = extract_segments($path, $origName);
    $chunks = make_chunks($segments);
    if (!$chunks) throw new RuntimeException('В файле не найдено текста.');
    $pages = count($segments);
    unset($segments);
    $useEmb = (bool)cfg('use_embeddings', true);
    $total = count($chunks);

    $pdo = db();
    $pdo->prepare('INSERT INTO documents(title, filename, section, pages, chunks, has_embeddings, trust, format, active) VALUES(?,?,?,?,?,?,?,?,0)')
        ->execute([$title, basename($path), $section, $pages, $total, $useEmb ? 1 : 0, $trust === 'aux' ? 'aux' : 'main', strtolower(pathinfo($origName, PATHINFO_EXTENSION))]);
    $docId = (int)$pdo->lastInsertId();
    try {
        kb_insert_chunks($docId, $title, $chunks, $log);
    } catch (Throwable $e) {
        delete_document($docId);
        throw $e;
    }
    $pdo->prepare('UPDATE documents SET active = 1 WHERE id = ?')->execute([$docId]);
    $log($pages . ' разделов, ' . $total . ' фрагментов');
    return $docId;
}

/** Фрагменты документа в базу: текст, полнотекстовый индекс, эмбеддинги. */
function kb_insert_chunks(int $docId, string $title, array $chunks, ?callable $log = null): void
{
    $log ??= fn($m) => null;
    $useEmb = (bool)cfg('use_embeddings', true);
    $total = count($chunks);
    $pdo = db();
    $ins = $pdo->prepare('INSERT INTO chunks(doc_id, page, source, text, embedding) VALUES(?,?,?,?,?)');
    $fts = $pdo->prepare('INSERT INTO chunks_fts(rowid, text) VALUES(?, ?)');
    try {
        $done = 0;
        foreach (array_chunk($chunks, 64) as $batch) {
            $vectors = $useEmb
                ? embed(array_map(fn($c) => $title . ($c['source'] !== '' ? ' › ' . $c['source'] : '') . "\n" . $c['text'], $batch))
                : [];
            $pdo->beginTransaction();
            foreach ($batch as $i => $c) {
                $ins->bindValue(1, $docId, PDO::PARAM_INT);
                $ins->bindValue(2, $c['page'], PDO::PARAM_INT);
                $ins->bindValue(3, $c['source']);
                $ins->bindValue(4, $c['text']);
                $ins->bindValue(5, $useEmb ? pack_vec($vectors[$i]) : null, $useEmb ? PDO::PARAM_LOB : PDO::PARAM_NULL);
                $ins->execute();
                $fts->execute([(int)$pdo->lastInsertId(), $title . ' ' . $c['source'] . ' ' . $c['text']]);
            }
            $pdo->commit();
            $done += count($batch);
            unset($vectors);
            if ($useEmb) $log("эмбеддинги: $done из $total");
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Текстовая заметка из админки: создать ($id = 0) или обновить с переиндексацией.
 * Текст хранится в documents.body — его можно править. Заголовки «# …» делят заметку на разделы.
 */
function save_note(int $id, string $title, string $section, string $text, string $trust = 'main'): int
{
    $text = trim(str_replace("\r\n", "\n", $text));
    $title = trim($title);
    if ($title === '' || $text === '') throw new RuntimeException('Нужны заголовок и текст.');
    $section = normalize_section($section);
    $chunks = make_chunks(extract_markdown($text));
    if (!$chunks) throw new RuntimeException('Слишком короткий текст.');
    $pdo = db();
    if ($id > 0) {
        $pdo->prepare('DELETE FROM chunks_fts WHERE rowid IN (SELECT id FROM chunks WHERE doc_id = ?)')->execute([$id]);
        $pdo->prepare('DELETE FROM chunks WHERE doc_id = ?')->execute([$id]);
        $pdo->prepare("UPDATE documents SET title = ?, section = ?, body = ?, chunks = ?, pages = 1, trust = ?, has_embeddings = ? WHERE id = ? AND format = 'note'")
            ->execute([$title, $section, $text, count($chunks), $trust === 'aux' ? 'aux' : 'main', cfg('use_embeddings', true) ? 1 : 0, $id]);
    } else {
        $pdo->prepare("INSERT INTO documents(title, filename, section, format, trust, pages, chunks, has_embeddings, active, body) VALUES(?, '', ?, 'note', ?, 1, ?, ?, 1, ?)")
            ->execute([$title, $section, $trust === 'aux' ? 'aux' : 'main', count($chunks), cfg('use_embeddings', true) ? 1 : 0, $text]);
        $id = (int)$pdo->lastInsertId();
    }
    kb_insert_chunks($id, $title, $chunks);
    return $id;
}

function delete_document(int $id): void
{
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM chunks_fts WHERE rowid IN (SELECT id FROM chunks WHERE doc_id = ?)')->execute([$id]);
    $pdo->prepare('DELETE FROM chunks WHERE doc_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM documents WHERE id = ?')->execute([$id]);
    $pdo->commit();
}

/** Все разделы, где есть материалы, с количеством. */
function kb_sections(): array
{
    return db()->query('SELECT section, COUNT(*) AS docs, SUM(chunks) AS chunks, SUM(active) AS active FROM documents GROUP BY section ORDER BY section')->fetchAll();
}

/** SQL-условие «документ в одном из разделов» (равен или вложен). */
function section_where(array $sections, array &$args): string
{
    $parts = [];
    foreach (array_unique(array_map('normalize_section', $sections)) as $s) {
        $parts[] = '(d.section = ? OR d.section LIKE ?)';
        $args[] = $s;
        $args[] = $s . '/%';
    }
    return $parts ? '(' . implode(' OR ', $parts) . ')' : '1=0';
}

function fts_query(string $q): string
{
    preg_match_all('/[\p{L}\p{N}]{3,}/u', mb_strtolower($q), $m);
    $stop = ['как','что','это','для','мне','нам','мой','моя','или','при','где','какие','какой','нужно','нужен','нужна','можно','если','есть','чтобы','сколько','когда','тоже','также','так','вас','ваш'];
    $terms = [];
    foreach (array_unique($m[0]) as $w) {
        if (in_array($w, $stop, true)) continue;
        $l = mb_strlen($w);
        $stem = $l >= 6 ? mb_substr($w, 0, $l - 2) : ($l >= 5 ? mb_substr($w, 0, $l - 1) : $w);
        if (mb_strlen($stem) >= 4) $stem = preg_replace('/[аеёиоуыэюяйь]$/u', '', $stem);
        $terms[] = '"' . str_replace('"', '', $stem) . '"*';
    }
    return implode(' OR ', array_slice($terms, 0, 16));
}

function search_fts(string $q, array $sections, int $limit): array
{
    $match = fts_query($q);
    if ($match === '') return [];
    $args = [$match];
    $where = section_where($sections, $args);
    $args[] = $limit;
    $st = db()->prepare(
        "SELECT c.id FROM chunks_fts f JOIN chunks c ON c.id = f.rowid
         JOIN documents d ON d.id = c.doc_id AND d.active = 1
         WHERE chunks_fts MATCH ? AND $where ORDER BY bm25(chunks_fts) LIMIT ?"
    );
    $st->execute($args);
    return array_column($st->fetchAll(), 'id');
}

function search_vectors(string $q, array $sections, int $limit): array
{
    if (!cfg('use_embeddings', true)) return [];
    $args = [];
    $where = section_where($sections, $args);
    $st = db()->prepare("SELECT 1 FROM documents d WHERE d.active = 1 AND d.has_embeddings = 1 AND $where LIMIT 1");
    $st->execute($args);
    if (!$st->fetchColumn()) return [];

    $qv = embed([$q])[0];
    $qn = sqrt(array_sum(array_map(fn($x) => $x * $x, $qv)));
    $scores = [];
    $rows = db()->prepare("SELECT c.id, c.embedding FROM chunks c JOIN documents d ON d.id = c.doc_id AND d.active = 1 WHERE c.embedding IS NOT NULL AND $where");
    $rows->execute($args);
    foreach ($rows as $r) {
        $v = unpack_vec($r['embedding']);
        $dot = 0.0; $n = 0.0;
        foreach ($v as $i => $x) { $dot += $x * $qv[$i]; $n += $x * $x; }
        $scores[$r['id']] = $dot / (sqrt($n) * $qn + 1e-9);
    }
    arsort($scores);
    return array_slice(array_keys($scores), 0, $limit);
}

function chunk_label(array $c): string
{
    $src = (string)($c['source'] ?? '');
    return $c['title'] . ($src !== '' ? ' › ' . $src : ', стр. ' . $c['page']);
}

/** Гибридный поиск в разделах. */
function retrieve(string $q, array $sections, ?int $k = null): array
{
    if (!$sections || trim($q) === '') return [];
    $k ??= (int)cfg('top_k', 6);
    $lists = [search_fts($q, $sections, 20)];
    try { $lists[] = search_vectors($q, $sections, 20); } catch (Throwable $e) { app_log('embeddings: ' . $e->getMessage()); }

    $score = [];
    foreach ($lists as $list) foreach ($list as $rank => $id) $score[$id] = ($score[$id] ?? 0) + 1 / (60 + $rank);
    arsort($score);
    $ids = array_slice(array_keys($score), 0, $k);
    if (!$ids) return [];
    $in = implode(',', array_map('intval', $ids));
    $rows = db()->query("SELECT c.id, c.page, c.source, c.text, d.title, d.trust, d.section FROM chunks c JOIN documents d ON d.id = c.doc_id WHERE c.id IN ($in)")->fetchAll();
    $byId = array_column($rows, null, 'id');
    return array_values(array_filter(array_map(fn($id) => $byId[$id] ?? null, $ids)));
}
