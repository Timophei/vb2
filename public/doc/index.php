<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/app.php';

/**
 * Публичная страница документа: /doc/?slug  (PDF: /doc/?slug&pdf)
 * Документы пишутся в админке → «Страницы».
 */

$slug = '';
foreach ($_GET as $k => $v) { if ($v === '' && preg_match(PAGE_SLUG_RE, (string)$k)) { $slug = (string)$k; break; } }
$slug = $slug ?: (string)($_GET['s'] ?? '');
$p = $slug !== '' ? page_get($slug) : null;

header('X-Robots-Tag: noindex, nofollow');
if (!$p || !$p['published']) {
    http_response_code(404);
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Не найдено</title>'
        . '<body style="font:16px/1.5 system-ui,sans-serif;display:grid;place-items:center;min-height:90vh;color:#555;margin:0"><p>Документ не найден или снят с публикации.</p></body>';
    exit;
}

if (isset($_GET['pdf'])) {
    try {
        $bytes = page_pdf($p);
    } catch (Throwable $e) {
        error_log('vb2 pdf: ' . $e->getMessage());
        http_response_code(500);
        echo 'Не удалось собрать PDF.';
        exit;
    }
    $name = page_pdf_name($p);
    header('Content-Type: application/pdf');
    header("Content-Disposition: attachment; filename=\"" . $p['slug'] . '_' . page_now('Y-m-d') . ".pdf\"; filename*=UTF-8''" . rawurlencode($name));
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
}

[$body, $toc] = page_render($p['md']);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$pdfUrl = '/doc/?' . rawurlencode($p['slug']) . '&pdf';
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $h($p['title']) ?></title>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<style>
  :root { --bg:#f5f6f9; --paper:#fff; --text:#1c2230; --muted:#687083; --accent:#1f4e8c; --accent-soft:#eaf0f9; --line:#e2e5ec; }
  @media (prefers-color-scheme: dark) { :root { --bg:#12151b; --paper:#1a1e26; --text:#e4e7ee; --muted:#98a0b0; --accent:#86aee8; --accent-soft:#1f2a3b; --line:#2b303b; } }
  * { box-sizing: border-box; }
  html { scroll-behavior: smooth; -webkit-text-size-adjust: 100%; }
  body { margin:0; background:var(--bg); color:var(--text); font:17px/1.65 -apple-system, "Segoe UI", Inter, Roboto, "Helvetica Neue", Arial, sans-serif; }
  .bar { position:sticky; top:0; z-index:5; background:color-mix(in srgb, var(--bg) 88%, transparent); backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px); border-bottom:1px solid var(--line); }
  .bar .in { max-width:1120px; margin:0 auto; padding:10px 16px; display:flex; align-items:center; gap:12px; }
  .bar .t { flex:1; min-width:0; font-weight:600; font-size:15px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; opacity:0; transition:opacity .2s; }
  .bar.scrolled .t { opacity:1; }
  .btn { display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border-radius:9px; font-family:inherit; font-size:14px; font-weight:600; line-height:1.2; text-decoration:none; border:1px solid var(--line); background:var(--paper); color:var(--text); cursor:pointer; white-space:nowrap; }
  .btn.main { background:var(--accent); border-color:var(--accent); color:#fff; }
  @media (prefers-color-scheme: dark) { .btn.main { color:#12151b; } }
  .btn svg { width:16px; height:16px; }
  .wrap { max-width:1120px; margin:0 auto; padding:28px 16px 64px; display:grid; grid-template-columns:minmax(0,1fr); gap:32px; }
  @media (min-width: 1000px) { .wrap { grid-template-columns:240px minmax(0,1fr); } }
  aside { display:none; }
  @media (min-width: 1000px) { aside { display:block; position:sticky; top:72px; align-self:start; max-height:calc(100vh - 90px); overflow:auto; font-size:14px; line-height:1.4; } }
  aside a { display:block; padding:6px 10px; border-radius:7px; color:var(--muted); text-decoration:none; border-left:2px solid transparent; }
  aside a.l3 { padding-left:22px; font-size:13px; }
  aside a:hover { color:var(--text); background:var(--accent-soft); }
  aside a.on { color:var(--accent); border-left-color:var(--accent); background:var(--accent-soft); }
  article { background:var(--paper); border:1px solid var(--line); border-radius:16px; padding:40px 56px 48px; max-width:780px; min-width:0; }
  @media (max-width: 700px) { article { padding:24px 18px 32px; border-radius:12px; } body { font-size:16px; } }
  .meta { color:var(--muted); font-size:14px; margin:0 0 6px; }
  h1 { font-size:clamp(26px, 4.2vw, 36px); line-height:1.2; margin:0 0 24px; letter-spacing:-.01em; }
  details.toc { margin:0 0 24px; border:1px solid var(--line); border-radius:10px; padding:10px 14px; font-size:15px; }
  details.toc summary { cursor:pointer; font-weight:600; }
  details.toc a { display:block; padding:4px 0; color:var(--text); text-decoration:none; }
  details.toc a.l3 { padding-left:14px; color:var(--muted); }
  @media (min-width: 1000px) { details.toc { display:none; } }
  article h2 { font-size:24px; line-height:1.3; margin:40px 0 12px; padding-top:20px; border-top:1px solid var(--line); scroll-margin-top:72px; }
  article h2:first-of-type { border-top:0; padding-top:0; }
  article h3 { font-size:19px; margin:28px 0 8px; scroll-margin-top:72px; }
  article p { margin:0 0 14px; }
  article ul, article ol { margin:0 0 16px; padding-left:24px; }
  article li { margin-bottom:6px; }
  article li::marker { color:var(--accent); }
  article strong { font-weight:650; }
  article hr { border:0; border-top:1px solid var(--line); margin:32px 0; }
  article blockquote { margin:16px 0; padding:10px 16px; border-left:3px solid var(--accent); background:var(--accent-soft); border-radius:0 8px 8px 0; }
  article a { color:var(--accent); }
  article table { border-collapse:collapse; width:100%; margin:8px 0 18px; font-size:15px; display:block; overflow-x:auto; }
  article th, article td { border:1px solid var(--line); padding:8px 10px; text-align:left; vertical-align:top; }
  article th { background:var(--accent-soft); }
  article code { font-size:.9em; background:var(--accent-soft); padding:1px 5px; border-radius:4px; }
  footer { grid-column:1/-1; text-align:center; color:var(--muted); font-size:13px; }
  @media (min-width: 1000px) { footer { grid-column:2; max-width:780px; } }
  @media print {
    .bar, aside, details.toc, footer { display:none !important; }
    body { background:#fff; color:#000; font-size:11pt; }
    .wrap { display:block; padding:0; }
    article { border:0; padding:0; max-width:none; }
  }
</style>
</head>
<body>
<div class="bar" id="bar"><div class="in">
  <div class="t"><?= $h($p['title']) ?></div>
  <button class="btn" id="share" type="button" title="Скопировать ссылку"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg><span>Ссылка</span></button>
  <a class="btn main" href="<?= $h($pdfUrl) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>PDF</a>
</div></div>
<div class="wrap">
  <?php if ($toc): ?><aside><nav id="toc"><?php foreach ($toc as [$id, $t, $l]): ?><a href="#<?= $id ?>" class="l<?= $l ?>"><?= $h($t) ?></a><?php endforeach; ?></nav></aside><?php endif; ?>
  <article>
    <p class="meta">Редакция от <?= $h(fmt_dt($p['updated_at'], 'd.m.Y')) ?></p>
    <h1 id="top"><?= $h($p['title']) ?></h1>
    <?php if (count($toc) > 2): ?><details class="toc"><summary>Содержание</summary><?php foreach ($toc as [$id, $t, $l]): ?><a href="#<?= $id ?>" class="l<?= $l ?>"><?= $h($t) ?></a><?php endforeach; ?></details><?php endif; ?>
    <?= $body ?>
  </article>
  <footer><?= $h($_SERVER['HTTP_HOST'] ?? '') ?><?= $h($p['slug'] === 'memo' ? '/memo/' : '/doc/?' . $p['slug']) ?></footer>
</div>
<script>
  const bar = document.getElementById('bar'), h1 = document.getElementById('top');
  const onScroll = () => bar.classList.toggle('scrolled', h1.getBoundingClientRect().bottom < 60);
  addEventListener('scroll', onScroll, { passive: true }); onScroll();
  const links = [...document.querySelectorAll('#toc a')];
  if (links.length && 'IntersectionObserver' in window) {
    const io = new IntersectionObserver(es => es.forEach(e => {
      if (e.isIntersecting) links.forEach(a => a.classList.toggle('on', a.hash === '#' + e.target.id));
    }), { rootMargin: '-80px 0px -70% 0px' });
    links.forEach(a => { const t = document.getElementById(a.hash.slice(1)); if (t) io.observe(t); });
  }
  document.querySelectorAll('details.toc a').forEach(a => a.addEventListener('click', () => a.closest('details').open = false));
  document.getElementById('share').addEventListener('click', async e => {
    const b = e.currentTarget, s = b.querySelector('span'), url = location.href.split('#')[0];
    try {
      if (navigator.share && matchMedia('(pointer: coarse)').matches) { await navigator.share({ title: document.title, url }); return; }
      await navigator.clipboard.writeText(url); s.textContent = 'Скопировано';
    } catch (_) { s.textContent = url; }
    setTimeout(() => s.textContent = 'Ссылка', 2000);
  });
</script>
</body>
</html>
