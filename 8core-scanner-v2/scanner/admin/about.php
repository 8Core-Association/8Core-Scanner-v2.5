<?php
/**
 * 8Core Scanner v2.0 — Admin: O scanneru (About)
 * (c) 2026 Tomislav Galić <tomislav@8core.hr>
 * Sva prava pridržana.
 */
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require_admin();

$config     = require __DIR__ . '/../includes/config.php';
$versionFile = __DIR__ . '/../VERSION';
$lockFile    = __DIR__ . '/../install/install.lock';

$packageVersion   = file_exists($versionFile) ? trim(file_get_contents($versionFile)) : '—';
$installLockExists = file_exists($lockFile);

// Instalirani version iz settings tablice
$installedVersion = '—';
$installedAt      = '—';
$lastUpdatedAt    = '—';
try {
    $row = $pdo->query("SELECT setting_value FROM scanner_settings WHERE setting_key = 'installed_version'")->fetch();
    if ($row) $installedVersion = $row['setting_value'];
    $row = $pdo->query("SELECT setting_value FROM scanner_settings WHERE setting_key = 'installed_at'")->fetch();
    if ($row) $installedAt = $row['setting_value'];
    $row = $pdo->query("SELECT setting_value FROM scanner_settings WHERE setting_key = 'last_updated_at'")->fetch();
    if ($row) $lastUpdatedAt = $row['setting_value'];
} catch (Throwable $e) {}

// Health checks
$checks = [];

// config.php
$checks[] = ['label' => 'config.php postoji',          'ok' => file_exists(__DIR__ . '/../includes/config.php'), 'note' => ''];
$checks[] = ['label' => 'install.lock postoji',         'ok' => $installLockExists, 'note' => $installLockExists ? 'Installer zaključan (sigurno)' : 'Upozorenje: installer nije zaključan'];

// DB konekcija
$dbOk   = false;
$dbNote = '';
try {
    $pdo->query("SELECT 1");
    $dbOk   = true;
    $dbNote = 'Konekcija OK';
} catch (Throwable $e) {
    $dbNote = 'Greška: ' . $e->getMessage();
}
$checks[] = ['label' => 'Konekcija na bazu', 'ok' => $dbOk, 'note' => $dbNote];

// Potrebne tablice
$requiredTables = ['scans','findings','scanner_users','scanner_rules','scanner_ignore_list','scanner_scan_requests','scanner_migrations','scanner_settings'];
foreach ($requiredTables as $tbl) {
    $exists = false;
    try {
        $pdo->query("SELECT 1 FROM `$tbl` LIMIT 0");
        $exists = true;
    } catch (Throwable $e) {}
    $checks[] = ['label' => "Tablica: $tbl", 'ok' => $exists, 'note' => ''];
}

// Root engine path konfiguriran
$rootEngPath    = $config['root_engine_path'] ?? '';
$quarPath       = $config['quarantine_path']  ?? '';
$logPath        = $config['scan_log']         ?? '';

$checks[] = ['label' => 'root_engine_path konfiguriran', 'ok' => $rootEngPath !== '', 'note' => $rootEngPath ?: 'Nije postavljeno'];
$checks[] = ['label' => 'quarantine_path konfiguriran',  'ok' => $quarPath !== '',    'note' => $quarPath ?: 'Nije postavljeno'];

// Root engine — web ne može pristupiti /root, informativno
$rootAccessNote = 'Root engine path se ne može provjeriti iz web konteksta. Ovo je normalno.';
$checks[] = ['label' => 'Root engine fajlovi (web provjera)', 'ok' => null, 'note' => $rootAccessNote];
?>
<!doctype html>
<html lang="hr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>8Core Scanner – O scanneru</title>
<link rel="stylesheet" href="../assets/css/scanner.css">
<style>
.about-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:20px; }
@media(max-width:700px){ .about-grid{grid-template-columns:1fr;} }
.about-section { background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:18px 20px; }
.about-section h3 { margin:0 0 12px; font-size:12px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.06em; }
.about-row { display:flex; justify-content:space-between; align-items:baseline; padding:5px 0; border-bottom:1px solid var(--bg); font-size:13px; gap:12px; }
.about-row:last-child { border-bottom:none; }
.about-label { color:var(--text-muted); flex-shrink:0; }
.about-val { color:var(--text); text-align:right; word-break:break-all; font-family:var(--font-mono,monospace); font-size:12px; }
.about-val.ok  { color:var(--risk-low,#4ade80); font-family:inherit; font-size:13px; }
.about-val.bad { color:var(--risk-critical,#f87171); font-family:inherit; font-size:13px; }
.about-val.warn { color:#fbbf24; font-family:inherit; font-size:13px; }
.about-val.na  { color:var(--text-muted); font-style:italic; font-size:12px; font-family:inherit; }
.health-item { display:flex; align-items:center; gap:10px; padding:6px 0; border-bottom:1px solid var(--bg); font-size:13px; }
.health-item:last-child { border-bottom:none; }
.health-icon { flex-shrink:0; width:16px; text-align:center; font-weight:700; }
.health-icon.ok   { color:#4ade80; }
.health-icon.bad  { color:#f87171; }
.health-icon.na   { color:#475569; }
.health-label { flex:1; color:var(--text); }
.health-note  { color:var(--text-muted); font-size:12px; }
</style>
</head>
<body>
<div class="layout">
<?php include __DIR__ . '/sidebar.php'; ?>
<div class="main">
  <div class="topbar">
    <div class="topbar-title">O scanneru</div>
    <div class="topbar-meta"><a href="../logout.php" class="topbar-logout">Odjava</a></div>
  </div>
  <div class="content">

    <div class="about-grid">

      <!-- Identitet -->
      <div class="about-section">
        <h3>8Core Scanner</h3>
        <div class="about-row"><span class="about-label">Naziv</span><span class="about-val">8Core Scanner</span></div>
        <div class="about-row"><span class="about-label">Verzija paketa</span><span class="about-val"><?= h($packageVersion) ?></span></div>
        <div class="about-row"><span class="about-label">Instalirana verzija</span><span class="about-val"><?= h($installedVersion) ?></span></div>
        <div class="about-row"><span class="about-label">Instalirano</span><span class="about-val"><?= h($installedAt) ?></span></div>
        <div class="about-row"><span class="about-label">Zadnji update</span><span class="about-val"><?= h($lastUpdatedAt) ?></span></div>
        <div class="about-row"><span class="about-label">Autor / Brand</span><span class="about-val" style="font-family:inherit;">8Core</span></div>
        <div class="about-row"><span class="about-label">Web</span><span class="about-val" style="font-family:inherit;"><a href="https://8core.hr" target="_blank" rel="noopener" style="color:var(--accent)">8core.hr</a></span></div>
      </div>

      <!-- Okruženje -->
      <div class="about-section">
        <h3>Okruženje</h3>
        <div class="about-row"><span class="about-label">PHP verzija</span><span class="about-val"><?= h(PHP_VERSION) ?></span></div>
        <div class="about-row"><span class="about-label">Server hostname</span><span class="about-val"><?= h(gethostname() ?: '—') ?></span></div>
        <div class="about-row"><span class="about-label">Web panel path</span><span class="about-val"><?= h(realpath(__DIR__ . '/..') ?: '—') ?></span></div>
        <div class="about-row"><span class="about-label">Install lock</span>
          <span class="about-val <?= $installLockExists ? 'ok' : 'warn' ?>"><?= $installLockExists ? 'Zaključano' : 'NIJE zaključano' ?></span>
        </div>
      </div>

      <!-- Putanje -->
      <div class="about-section">
        <h3>Konfigurirane putanje</h3>
        <div class="about-row"><span class="about-label">Root engine path</span><span class="about-val"><?= h($rootEngPath ?: '—') ?></span></div>
        <div class="about-row"><span class="about-label">Quarantine path</span><span class="about-val"><?= h($quarPath ?: '—') ?></span></div>
        <div class="about-row"><span class="about-label">Scan log</span><span class="about-val"><?= h($logPath ?: '—') ?></span></div>
        <div class="about-row"><span class="about-label">DB host</span><span class="about-val"><?= h($config['db_host'] ?? '—') ?></span></div>
        <div class="about-row"><span class="about-label">DB name</span><span class="about-val"><?= h($config['db_name'] ?? '—') ?></span></div>
      </div>

      <!-- Cron -->
      <div class="about-section">
        <h3>Cron hint</h3>
        <p style="font-size:12px;color:var(--text-muted);margin:0 0 8px;">Cron se ne može provjeriti iz web konteksta. Dodati kao root:</p>
        <code style="font-size:11px;word-break:break-all;color:var(--text);">* * * * * <?= h($rootEngPath ?: '/root/8core_scanner') ?>/scanner_worker.sh >> <?= h(dirname($logPath ?: '/root/8core_scanner/logs/x')) ?>/scanner_worker_cron.log 2>&amp;1</code>
      </div>

    </div>

    <!-- Health Check -->
    <div class="about-section" style="margin-bottom:20px;">
      <h3>Health Check</h3>
      <?php foreach ($checks as $chk): ?>
        <?php
        if ($chk['ok'] === null) {
            $icon = 'na'; $ico = '~';
        } elseif ($chk['ok']) {
            $icon = 'ok'; $ico = '✓';
        } else {
            $icon = 'bad'; $ico = '✗';
        }
        ?>
        <div class="health-item">
          <span class="health-icon <?= $icon ?>"><?= $ico ?></span>
          <span class="health-label"><?= h($chk['label']) ?></span>
          <?php if (!empty($chk['note'])): ?>
            <span class="health-note"><?= h($chk['note']) ?></span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

  </div>
</div>
</div>
</body>
</html>
