<?php
/**
 * 8Core Scanner v2.0 — Admin: Changelog
 * (c) 2026 Tomislav Galić <tomislav@8core.hr>
 * Sva prava pridržana.
 */
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require_admin();

// Traži changelog.md: uz scanner/, pa jedan nivo iznad (paketni root)
$candidates = [
    __DIR__ . '/../../changelog.md',
    __DIR__ . '/../changelog.md',
    dirname(dirname(__DIR__)) . '/changelog.md',
];
$changelogFile = null;
foreach ($candidates as $c) {
    if (file_exists($c)) { $changelogFile = $c; break; }
}

$rawContent = $changelogFile ? file_get_contents($changelogFile) : null;

// Minimalni Markdown → HTML konverter (headings, code, lists, bold)
function render_changelog(string $md): string {
    $lines   = explode("\n", $md);
    $html    = '';
    $inCode  = false;
    $inList  = false;

    foreach ($lines as $line) {
        // Fenced code block
        if (preg_match('/^```/', $line)) {
            if ($inList) { $html .= '</ul>'; $inList = false; }
            if (!$inCode) {
                $html   .= '<pre class="cl-code">';
                $inCode  = true;
            } else {
                $html   .= '</pre>';
                $inCode  = false;
            }
            continue;
        }
        if ($inCode) {
            $html .= htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . "\n";
            continue;
        }

        $escaped = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');

        // Headings
        if (preg_match('/^### (.+)/', $line, $m)) {
            if ($inList) { $html .= '</ul>'; $inList = false; }
            $html .= '<h3 class="cl-h3">' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '</h3>';
            continue;
        }
        if (preg_match('/^## (.+)/', $line, $m)) {
            if ($inList) { $html .= '</ul>'; $inList = false; }
            $html .= '<h2 class="cl-h2">' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '</h2>';
            continue;
        }
        if (preg_match('/^# (.+)/', $line, $m)) {
            if ($inList) { $html .= '</ul>'; $inList = false; }
            $html .= '<h1 class="cl-h1">' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '</h1>';
            continue;
        }

        // Horizontal rule
        if (preg_match('/^---+$/', trim($line))) {
            if ($inList) { $html .= '</ul>'; $inList = false; }
            $html .= '<hr class="cl-hr">';
            continue;
        }

        // List items
        if (preg_match('/^\* (.+)/', $line, $m) || preg_match('/^- (.+)/', $line, $m)) {
            if (!$inList) { $html .= '<ul class="cl-list">'; $inList = true; }
            $item = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
            $item = preg_replace('/`([^`]+)`/', '<code>$1</code>', $item);
            $item = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $item);
            $html .= '<li>' . $item . '</li>';
            continue;
        }

        if ($inList) { $html .= '</ul>'; $inList = false; }

        // Inline code + bold in paragraph
        $escaped = preg_replace('/`([^`]+)`/', '<code>$1</code>', $escaped);
        $escaped = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $escaped);

        if (trim($line) === '') {
            $html .= '<div class="cl-spacer"></div>';
        } else {
            $html .= '<p class="cl-p">' . $escaped . '</p>';
        }
    }
    if ($inList)  $html .= '</ul>';
    if ($inCode)  $html .= '</pre>';
    return $html;
}
?>
<!doctype html>
<html lang="hr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>8Core Scanner – Changelog</title>
<link rel="stylesheet" href="../assets/css/scanner.css">
<style>
.cl-wrap { max-width:820px; }
.cl-h1 { font-size:18px; font-weight:700; color:var(--text); margin:24px 0 4px; }
.cl-h2 { font-size:15px; font-weight:700; color:var(--accent,#2563eb); margin:22px 0 4px; padding-bottom:6px; border-bottom:1px solid var(--border); }
.cl-h3 { font-size:12px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.06em; margin:14px 0 4px; }
.cl-p  { font-size:13px; color:var(--text); margin:2px 0; line-height:1.6; }
.cl-list { margin:4px 0 4px 18px; padding:0; font-size:13px; color:var(--text); line-height:1.7; }
.cl-list li { margin:1px 0; }
.cl-code { background:var(--bg); border:1px solid var(--border); border-radius:6px; padding:10px 12px; font-size:11px; color:#86efac; overflow-x:auto; margin:6px 0; font-family:var(--font-mono,monospace); }
.cl-hr { border:none; border-top:1px solid var(--border); margin:16px 0; }
.cl-spacer { height:6px; }
.cl-source { font-size:11px; color:var(--text-muted); margin-bottom:14px; }
code { background:var(--bg); padding:1px 5px; border-radius:4px; font-size:12px; font-family:var(--font-mono,monospace); }
</style>
</head>
<body>
<div class="layout">
<?php include __DIR__ . '/sidebar.php'; ?>
<div class="main">
  <div class="topbar">
    <div class="topbar-title">Changelog</div>
    <div class="topbar-meta"><a href="../logout.php" class="topbar-logout">Odjava</a></div>
  </div>
  <div class="content cl-wrap">

    <?php if ($rawContent === null): ?>
      <div class="notice error">Changelog fajl nije pronađen.</div>
    <?php else: ?>
      <?php if ($changelogFile): ?>
        <div class="cl-source">Izvor: <?= h(realpath($changelogFile)) ?></div>
      <?php endif; ?>
      <div class="panel" style="padding:20px 24px;">
        <?= render_changelog($rawContent) ?>
      </div>
    <?php endif; ?>

  </div>
</div>
</div>
</body>
</html>
