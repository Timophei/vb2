<?php
declare(strict_types=1);

/**
 * Страницы-документы: markdown → адаптивная страница /doc/?slug и PDF с датой скачивания.
 * Пишутся в админке (вкладка «Страницы»), .md можно просто перетащить.
 */

const PAGE_SLUG_RE = '/^[a-z0-9][a-z0-9_-]{1,39}$/';

function page_get(string $slug): ?array
{
    $st = db()->prepare('SELECT * FROM pages WHERE slug = ?');
    $st->execute([$slug]);
    return $st->fetch() ?: null;
}

function page_list(): array
{
    return db()->query('SELECT slug, title, published, created_at, updated_at, updated_by, length(md) AS size FROM pages ORDER BY updated_at DESC')->fetchAll();
}

/** Заголовок — первый «# …» в тексте. */
function page_title_from_md(string $md): string
{
    if (preg_match('/^\s*#\s+(.+)$/mu', $md, $m)) return trim(strip_tags(str_replace(['**', '__', '*'], '', $m[1])));
    return '';
}

function page_save(string $slug, string $md, bool $published, string $by, ?string $origSlug = null): string
{
    $slug = strtolower(trim($slug));
    if (!preg_match(PAGE_SLUG_RE, $slug)) throw new RuntimeException('Адрес — латиница, цифры, «-» и «_», от 2 до 40 символов.');
    $md = str_replace("\r\n", "\n", $md);
    if (trim($md) === '') throw new RuntimeException('Пустой документ.');
    $title = page_title_from_md($md) ?: $slug;
    $origSlug = $origSlug !== null && $origSlug !== '' ? $origSlug : null;
    if ($origSlug === null && page_get($slug)) throw new RuntimeException("Адрес «{$slug}» уже занят — выберите другой или откройте ту страницу.");
    if ($origSlug !== null && $origSlug !== $slug && page_get($slug)) throw new RuntimeException("Адрес «{$slug}» уже занят.");
    if ($origSlug !== null && page_get($origSlug)) {
        db()->prepare("UPDATE pages SET slug = ?, title = ?, md = ?, published = ?, updated_at = datetime('now'), updated_by = ? WHERE slug = ?")
            ->execute([$slug, $title, $md, $published ? 1 : 0, $by, $origSlug]);
    } else {
        db()->prepare('INSERT INTO pages (slug, title, md, published, updated_by) VALUES (?, ?, ?, ?, ?)')->execute([$slug, $title, $md, $published ? 1 : 0, $by]);
    }
    return $slug;
}

function page_delete(string $slug): void
{
    db()->prepare('DELETE FROM pages WHERE slug = ?')->execute([$slug]);
}

function page_url(string $slug): string
{
    return $slug === 'memo' ? '/memo/' : '/doc/?' . $slug;
}

/**
 * Markdown → HTML. Сырой HTML в тексте экранируется (safe mode).
 * Возвращает [html, оглавление [[id, текст, уровень]]]; первый «# заголовок» убирается — он в шапке страницы.
 */
function page_render(string $md): array
{
    require_once __DIR__ . '/vendor/Parsedown.php';
    $pd = new Parsedown();
    $pd->setSafeMode(true);
    $pd->setBreaksEnabled(false);
    $md = preg_replace('/^\s*#\s+.+$/mu', '', $md, 1);
    $html = $pd->text((string)$md);
    $toc = [];
    $n = 0;
    $html = preg_replace_callback('/<h([23])>(.*?)<\/h\1>/su', function ($m) use (&$toc, &$n) {
        $id = 's' . (++$n);
        $toc[] = [$id, trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES, 'UTF-8')), (int)$m[1]];
        return '<h' . $m[1] . ' id="' . $id . '">' . $m[2] . '</h' . $m[1] . '>';
    }, $html);
    // внешние ссылки — в новой вкладке
    $html = preg_replace('/<a href="(https?:)/u', '<a target="_blank" rel="noopener" href="$1', $html);
    return [$html, $toc];
}

/** Время по часовому поясу проекта. */
function page_now(string $format): string
{
    return fmt_dt(now_utc(), $format);
}

/** PDF документа: шапка с датой скачивания, номера страниц. */
function page_pdf(array $p): string
{
    require_once __DIR__ . '/vendor/dompdf/autoload.inc.php';
    [$body] = page_render($p['md']);
    $cache = data_dir() . '/dompdf';
    if (!is_dir($cache)) mkdir($cache, 0770, true);
    $title = htmlspecialchars($p['title'], ENT_QUOTES);
    $stamp = 'Скачано ' . page_now('d.m.Y H:i') . ' · редакция от ' . fmt_dt($p['updated_at'], 'd.m.Y');
    $html = '<!doctype html><html><head><meta charset="utf-8"><style>
      @page { margin: 22mm 18mm 20mm 18mm; }
      body { font-family: "DejaVu Sans", sans-serif; font-size: 10.5pt; line-height: 1.42; color: #1d2433; }
      .stamp { font-size: 8pt; color: #6b7385; border-bottom: 1px solid #d6dae3; padding-bottom: 4pt; margin-bottom: 14pt; }
      h1 { font-size: 19pt; line-height: 1.25; margin: 0 0 12pt; color: #1f4e8c; }
      h2 { font-size: 14pt; color: #1f4e8c; margin: 18pt 0 6pt; page-break-after: avoid; }
      h3 { font-size: 11.5pt; margin: 12pt 0 4pt; page-break-after: avoid; }
      p { margin: 0 0 7pt; }
      ul, ol { margin: 0 0 8pt; padding-left: 16pt; }
      li { margin-bottom: 2pt; }
      hr { border: 0; border-top: 1px solid #d6dae3; margin: 14pt 0; }
      blockquote { margin: 8pt 0; padding: 4pt 10pt; border-left: 3px solid #1f4e8c; background: #f3f6fb; }
      table { border-collapse: collapse; width: 100%; margin: 6pt 0 10pt; }
      th, td { border: 1px solid #d6dae3; padding: 4pt 6pt; text-align: left; vertical-align: top; }
      th { background: #f3f6fb; }
      code { font-family: "DejaVu Sans Mono", monospace; font-size: 9pt; }
      a { color: #1f4e8c; }
    </style></head><body><div class="stamp">' . htmlspecialchars($stamp) . '</div><h1>' . $title . '</h1>' . $body . '</body></html>';
    $opt = new Dompdf\Options();
    $opt->set('fontCache', $cache);
    $opt->set('tempDir', $cache);
    $opt->set('isRemoteEnabled', false);
    $opt->set('defaultFont', 'DejaVu Sans');
    $opt->setChroot([__DIR__ . '/vendor/dompdf', $cache]);
    $pdf = new Dompdf\Dompdf($opt);
    $pdf->loadHtml($html, 'UTF-8');
    $pdf->setPaper('A4');
    $pdf->render();
    $canvas = $pdf->getCanvas();
    $font = $pdf->getFontMetrics()->getFont('DejaVu Sans');
    $canvas->page_text(510, 810, '{PAGE_NUM} / {PAGE_COUNT}', $font, 8, [0.42, 0.45, 0.52]);
    return (string)$pdf->output();
}

/** Имя PDF: «Заголовок_2026-11-24.pdf». */
function page_pdf_name(array $p): string
{
    $t = preg_replace('/[^\p{L}\p{N}]+/u', '_', mb_substr($p['title'], 0, 60));
    return trim((string)$t, '_') . '_' . page_now('Y-m-d') . '.pdf';
}
