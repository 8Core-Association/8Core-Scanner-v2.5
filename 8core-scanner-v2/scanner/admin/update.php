<?php
/**
 * 8Core Scanner v2.0 — Admin: Update
 * (c) 2026 Tomislav Galić <tomislav@8core.hr>
 * Sva prava pridržana.
 *
 * Update proces:
 *   1. Upload ZIP paketa
 *   2. Dry-run prikaz fajlova
 *   3. Apply web update (preskoči config.php i install.lock)
 *   4. Apply DB migracije
 *   5. Prikaz root engine update skripte
 */
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require_admin();

$config      = require __DIR__ . '/../includes/config.php';
$versionFile = __DIR__ . '/../VERSION';
$lockFile    = __DIR__ . '/../install/install.lock';
$webRoot     = realpath(__DIR__ . '/..');

$packageVersion   = file_exists($versionFile) ? trim(file_get_contents($versionFile)) : '2.0.x';
$installedVersion = '—';
try {
    $row = $pdo->query("SELECT setting_value FROM scanner_settings WHERE setting_key='installed_version'")->fetch();
    if ($row) $installedVersion = $row['setting_value'];
} catch (Throwable $e) {}

// ── Konstante ──────────────────────────────────────────────
$SKIP_FILES = [
    'includes/config.php',
    'install/install.lock',
];
$PROTECTED_PREFIXES = ['install/install.lock'];

// ── Helpers ────────────────────────────────────────────────
function is_safe_zip_path(string $path): bool {
    if (strpos($path, '..') !== false) return false;
    if (strpos($path, "\0") !== false) return false;
    if (substr($path, 0, 1) === '/') return false;
    return true;
}

function should_skip(string $relPath, array $skipList): bool {
    foreach ($skipList as $skip) {
        if ($relPath === $skip || strpos($relPath, $skip) === 0) return true;
    }
    return false;
}

function sh_quote_update(string $value): string {
    return "'" . str_replace("'", "'\"'\"'", $value) . "'";
}

function generate_root_update_script(array $cfg, string $packageVersion): string {
    $rootPath = sh_quote_update($cfg['root_engine_path'] ?? '/root/8core_scanner');
    $logPath  = sh_quote_update(dirname($cfg['scan_log']  ?? '/root/8core_scanner/logs/x'));
    $quarPath = sh_quote_update($cfg['quarantine_path']   ?? '/home/8core_quarantine');
    $ts       = date('YmdHis');

    $nl = "\n";
    return '#!/bin/bash' . $nl
         . '# 8Core Scanner v2.0 — Root engine UPDATE skripta' . $nl
         . '# Paket verzija: ' . $packageVersion . $nl
         . '# Generirano: ' . date('Y-m-d H:i:s') . $nl
         . '# POKRENUTI KAO ROOT: bash /root/update_8core_scanner.sh' . $nl
         . $nl
         . 'set -e' . $nl
         . $nl
         . 'ROOT_ENGINE_PATH=' . $rootPath . $nl
         . 'LOG_PATH=' . $logPath . $nl
         . 'QUARANTINE_BASE_PATH=' . $quarPath . $nl
         . 'BACKUP_PATH="${ROOT_ENGINE_PATH}_backup_' . $ts . '"' . $nl
         . $nl
         . '# ENGINE_SOURCE mora biti putanja do raspakirane mape 8core_scanner/ iz ZIP paketa' . $nl
         . 'ENGINE_SOURCE="/ZAMIJENI/putanjom/do/8core_scanner"' . $nl
         . $nl
         . '# ─── Provjera ───────────────────────────────────────────────────────' . $nl
         . 'if [ ! -d "$ROOT_ENGINE_PATH" ]; then' . $nl
         . '    echo "GREŠKA: Root engine ne postoji: $ROOT_ENGINE_PATH"' . $nl
         . '    exit 1' . $nl
         . 'fi' . $nl
         . 'if [ ! -f "$ENGINE_SOURCE/ioc_scan.sh" ] || [ ! -f "$ENGINE_SOURCE/scanner_worker.sh" ]; then' . $nl
         . '    echo "GREŠKA: ENGINE_SOURCE ne sadrži ioc_scan.sh / scanner_worker.sh"' . $nl
         . '    exit 1' . $nl
         . 'fi' . $nl
         . 'echo "Provjere OK"' . $nl
         . $nl
         . '# ─── Backup ─────────────────────────────────────────────────────────' . $nl
         . 'echo "Kreiranje backupa: $BACKUP_PATH"' . $nl
         . 'cp -a "$ROOT_ENGINE_PATH" "$BACKUP_PATH"' . $nl
         . 'echo "Backup kreiran."' . $nl
         . $nl
         . '# ─── Kopiranje engine fajlova ─────────────────────────────────────' . $nl
         . 'echo "Kopiranje novih engine fajlova..."' . $nl
         . '# Kopira fajlove, NE dira scanner-db.conf, logs/ i quarantine/' . $nl
         . 'rsync -a --exclude="scanner-db.conf" --exclude="logs/" --exclude="quarantine/" "$ENGINE_SOURCE/" "$ROOT_ENGINE_PATH/"' . $nl
         . 'echo "Kopiranje završeno."' . $nl
         . $nl
         . '# ─── Permisije ───────────────────────────────────────────────────' . $nl
         . 'echo "Postavljanje permisija..."' . $nl
         . 'chown -R root:root "$ROOT_ENGINE_PATH"' . $nl
         . 'chmod 700 "$ROOT_ENGINE_PATH"' . $nl
         . '[ -f "$ROOT_ENGINE_PATH/scanner-db.conf" ] && chmod 600 "$ROOT_ENGINE_PATH/scanner-db.conf"' . $nl
         . 'chmod +x "$ROOT_ENGINE_PATH/ioc_scan.sh"' . $nl
         . 'chmod +x "$ROOT_ENGINE_PATH/scanner_worker.sh"' . $nl
         . $nl
         . '# ─── Provjera sintakse ───────────────────────────────────────────' . $nl
         . 'bash -n "$ROOT_ENGINE_PATH/ioc_scan.sh"       && echo "  ioc_scan.sh: OK"' . $nl
         . 'bash -n "$ROOT_ENGINE_PATH/scanner_worker.sh" && echo "  scanner_worker.sh: OK"' . $nl
         . $nl
         . 'echo ""' . $nl
         . 'echo "=== Root engine ažuriran ==="' . $nl
         . 'echo "  Putanja:  $ROOT_ENGINE_PATH"' . $nl
         . 'echo "  Backup:   $BACKUP_PATH"' . $nl
         . 'echo "  Obriši backup kad si siguran: rm -rf $BACKUP_PATH"' . $nl
         . 'echo "  Obriši ovu skriptu: rm /root/update_8core_scanner.sh"' . $nl;
}

// ── Učitaj pending DB migracije ────────────────────────────
function load_pending_migrations(PDO $pdo): array {
    $dir = __DIR__ . '/../install/migrations/';
    if (!is_dir($dir)) return [];

    $files = glob($dir . '*.sql');
    if (!$files) return [];
    sort($files);

    $applied = [];
    try {
        $rows = $pdo->query("SELECT migration_name FROM scanner_migrations")->fetchAll(PDO::FETCH_COLUMN);
        $applied = array_flip($rows);
    } catch (Throwable $e) {}

    $pending = [];
    foreach ($files as $f) {
        $name = basename($f);
        if (!isset($applied[$name])) $pending[] = ['name' => $name, 'path' => $f];
    }
    return $pending;
}

function apply_migration(PDO $pdo, string $name, string $path): array {
    $sql = file_get_contents($path);
    $messages = [];
    try {
        $pdo->exec($sql);
        $pdo->prepare("INSERT IGNORE INTO scanner_migrations (migration_name, applied_at) VALUES (?, NOW())")->execute([$name]);
        $messages[] = ['ok' => true,  'text' => "Migracija primjenjena: $name"];
    } catch (Throwable $e) {
        $messages[] = ['ok' => false, 'text' => "GREŠKA ($name): " . $e->getMessage()];
    }
    return $messages;
}

// ── Stanja stranice ────────────────────────────────────────
$view     = $_GET['view']   ?? 'main';   // main | dryrun | applied
$messages = [];
$dryFiles = [];
$pendingMigrations = load_pending_migrations($pdo);
$rootUpdateScript  = generate_root_update_script($config, $packageVersion);

// ── POST: Primjena web updatea ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply_web') {
    $tmpDir = $_POST['tmp_dir'] ?? '';
    $tmpDir = realpath($tmpDir);
    if (!$tmpDir || !is_dir($tmpDir) || strpos($tmpDir, sys_get_temp_dir()) !== 0) {
        $messages[] = ['ok' => false, 'text' => 'Nevažeći temp direktorij.'];
    } else {
        $scannerSrc = $tmpDir . '/scanner';
        if (!is_dir($scannerSrc)) {
            $messages[] = ['ok' => false, 'text' => 'Nema scanner/ mape u temp direktoriju.'];
        } else {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($scannerSrc, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            $copied   = 0;
            $skipped  = 0;
            foreach ($iter as $src) {
                $relPath = ltrim(str_replace($scannerSrc, '', (string)$src), '/\\');
                $relPath = str_replace('\\', '/', $relPath);
                if (!is_safe_zip_path($relPath))       { $skipped++; continue; }
                if (should_skip($relPath, $SKIP_FILES)) { $skipped++; continue; }
                $dst = $webRoot . '/' . $relPath;
                $dstDir = dirname($dst);
                if (!is_dir($dstDir)) mkdir($dstDir, 0755, true);
                if (copy((string)$src, $dst)) {
                    $messages[] = ['ok' => true, 'text' => "Kopirano: $relPath"];
                    $copied++;
                } else {
                    $messages[] = ['ok' => false, 'text' => "Greška kopiranja: $relPath"];
                }
            }
            $messages[] = ['ok' => true, 'text' => "Web update završen. Kopirano: $copied, Preskočeno: $skipped"];

            // Ažuriraj verziju u settings
            $newVer = trim(@file_get_contents($tmpDir . '/scanner/VERSION') ?: $packageVersion);
            try {
                $pdo->prepare("INSERT INTO scanner_settings (setting_key, setting_value) VALUES ('installed_version', ?) ON DUPLICATE KEY UPDATE setting_value=?, updated_at=NOW()")->execute([$newVer, $newVer]);
                $pdo->prepare("INSERT INTO scanner_settings (setting_key, setting_value) VALUES ('last_updated_at', NOW()) ON DUPLICATE KEY UPDATE setting_value=NOW(), updated_at=NOW()")->execute([]);
            } catch (Throwable $e) {}
        }
        // Obriši temp
        array_map('unlink', glob("$tmpDir/scanner/install/migrations/*.sql") ?: []);
    }
    $view = 'applied';
}

// ── POST: Primjena DB migracija ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply_migrations') {
    $pending = load_pending_migrations($pdo);
    if (empty($pending)) {
        $messages[] = ['ok' => true, 'text' => 'Nema pending migracija.'];
    }
    foreach ($pending as $mig) {
        $msgs = apply_migration($pdo, $mig['name'], $mig['path']);
        $messages = array_merge($messages, $msgs);
    }
    $pendingMigrations = load_pending_migrations($pdo);
    $view = 'applied';
}

// ── POST: Upload i dry-run ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    $file = $_FILES['update_zip'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        $messages[] = ['ok' => false, 'text' => 'Upload fajla nije uspio.'];
    } elseif (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'zip') {
        $messages[] = ['ok' => false, 'text' => 'Dozvoljeni su samo ZIP fajlovi.'];
    } else {
        $tmpDir = sys_get_temp_dir() . '/8core_update_' . bin2hex(random_bytes(8));
        mkdir($tmpDir, 0700, true);

        $zip = new ZipArchive();
        if ($zip->open($file['tmp_name']) !== true) {
            $messages[] = ['ok' => false, 'text' => 'Ne mogu otvoriti ZIP fajl.'];
        } else {
            // Provjera strukture i path traversal
            $hasScanner      = false;
            $hasEngine       = false;
            $pathTraversal   = false;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->getNameIndex($i);
                if (!is_safe_zip_path($entry)) { $pathTraversal = true; break; }
                // Normalizacija: ukloni leading 8core-scanner-v2/
                $norm = preg_replace('#^8core-scanner-v2/#', '', $entry);
                if (strpos($norm, 'scanner/') === 0) $hasScanner = true;
                if (strpos($norm, '8core_scanner/') === 0) $hasEngine = true;
            }

            if ($pathTraversal) {
                $messages[] = ['ok' => false, 'text' => 'ZIP sadrži opasne putanje (path traversal). Odbijeno.'];
            } elseif (!$hasScanner) {
                $messages[] = ['ok' => false, 'text' => 'ZIP ne sadrži očekivanu strukturu (nedostaje scanner/ mapa).'];
            } else {
                $zip->extractTo($tmpDir);
                $zip->close();

                // Normalizacija: ako je upakovano kao 8core-scanner-v2/scanner/...
                // pronalazimo stvarni scanner/ direktorij
                $scannerDir = $tmpDir . '/scanner';
                if (!is_dir($scannerDir)) {
                    // Pokušaj s prefixom
                    $dirs = glob($tmpDir . '/*/scanner', GLOB_ONLYDIR);
                    if ($dirs) {
                        // Premjesti na očekivanu lokaciju
                        rename(dirname($dirs[0]), $tmpDir . '/_pkg');
                        rename($tmpDir . '/_pkg/scanner', $tmpDir . '/scanner');
                        if (is_dir($tmpDir . '/_pkg/8core_scanner')) {
                            rename($tmpDir . '/_pkg/8core_scanner', $tmpDir . '/8core_scanner');
                        }
                    }
                }

                // Dry-run: prikupi fajlove
                if (is_dir($tmpDir . '/scanner')) {
                    $iter = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($tmpDir . '/scanner', RecursiveDirectoryIterator::SKIP_DOTS)
                    );
                    foreach ($iter as $f2) {
                        $relPath = ltrim(str_replace($tmpDir . '/scanner', '', (string)$f2), '/\\');
                        $relPath = str_replace('\\', '/', $relPath);
                        if (!is_safe_zip_path($relPath)) continue;
                        $skipped = should_skip($relPath, $SKIP_FILES);
                        $exists  = file_exists($webRoot . '/' . $relPath);
                        $dryFiles[] = ['path' => $relPath, 'skipped' => $skipped, 'exists' => $exists];
                    }
                    $messages[] = ['ok' => true, 'text' => 'ZIP uspješno raspakiran. Pregled ispod.'];
                    $view = 'dryrun';
                    // Provjera migracija u paketu
                    $pkgMigDir = $tmpDir . '/scanner/install/migrations/';
                    if (is_dir($pkgMigDir)) {
                        $pkgMigs = glob($pkgMigDir . '*.sql') ?: [];
                        $messages[] = ['ok' => true, 'text' => count($pkgMigs) . ' SQL migracij(a) pronađeno u paketu.'];
                    }
                    // Čuvamo tmpDir u sesiji za apply
                    $_SESSION['update_tmp_dir'] = $tmpDir;
                } else {
                    $messages[] = ['ok' => false, 'text' => 'scanner/ mapa nije pronađena u raspakiranom ZIP-u.'];
                }
            }
        }
    }
}

// Povrati tmpDir iz sesije za apply
$tmpDirForApply = $_SESSION['update_tmp_dir'] ?? '';
?>
<!doctype html>
<html lang="hr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>8Core Scanner – Update</title>
<link rel="stylesheet" href="../assets/css/scanner.css">
<style>
.upd-grid { display:grid; grid-template-columns:1fr; gap:16px; }
.upd-section { background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:20px 22px; }
.upd-section h3 { margin:0 0 12px; font-size:13px; font-weight:700; color:var(--text); display:flex; align-items:center; gap:8px; }
.upd-section h3 .badge-num { background:var(--accent,#2563eb); color:#fff; border-radius:999px; padding:1px 7px; font-size:11px; }
.upd-info-row { display:flex; gap:12px; align-items:baseline; font-size:13px; padding:4px 0; border-bottom:1px solid var(--bg); }
.upd-info-row:last-child { border-bottom:none; }
.upd-info-label { color:var(--text-muted); flex-shrink:0; width:150px; }
.upd-info-val { color:var(--text); font-family:var(--font-mono,monospace); font-size:12px; }
.dry-table { width:100%; border-collapse:collapse; font-size:12px; }
.dry-table th { text-align:left; padding:5px 8px; font-size:11px; font-weight:700; color:var(--text-muted); border-bottom:1px solid var(--border); }
.dry-table td { padding:4px 8px; border-bottom:1px solid var(--bg); font-family:var(--font-mono,monospace); word-break:break-all; }
.dry-skip   { color:var(--text-muted); }
.dry-new    { color:#4ade80; }
.dry-update { color:#60a5fa; }
.msg-ok   { color:#4ade80; }
.msg-err  { color:#f87171; }
.upd-script-wrap { position:relative; }
.upd-script-wrap textarea { width:100%; height:280px; background:#020617; border:1px solid var(--border); border-radius:7px; color:#86efac; font-family:var(--font-mono,monospace); font-size:11px; padding:12px; resize:vertical; outline:none; line-height:1.5; }
.script-copy-bar { display:flex; justify-content:flex-end; margin-bottom:6px; }
.btn-copy { background:var(--bg); color:#60a5fa; border:1px solid var(--border); border-radius:6px; padding:6px 12px; font-size:12px; font-family:inherit; cursor:pointer; }
.mig-item { display:flex; align-items:center; gap:10px; padding:5px 0; border-bottom:1px solid var(--bg); font-size:13px; }
.mig-item:last-child { border-bottom:none; }
.mig-name { font-family:var(--font-mono,monospace); font-size:12px; flex:1; }
.mig-status-pending { color:#fbbf24; font-size:11px; font-weight:700; }
.mig-status-applied { color:#4ade80; font-size:11px; font-weight:700; }
.upd-warning { background:rgba(251,191,36,.08); border:1px solid rgba(251,191,36,.3); border-radius:8px; padding:10px 14px; font-size:13px; color:#fbbf24; margin-bottom:14px; }
.msg-list { list-style:none; margin:0 0 14px; padding:0; }
.msg-list li { padding:3px 0; font-size:13px; border-bottom:1px solid var(--bg); font-family:var(--font-mono,monospace); }
.msg-list li:last-child { border-bottom:none; }
code { background:var(--bg); padding:1px 5px; border-radius:4px; font-size:12px; font-family:var(--font-mono,monospace); }
</style>
</head>
<body>
<div class="layout">
<?php include __DIR__ . '/sidebar.php'; ?>
<div class="main">
  <div class="topbar">
    <div class="topbar-title">Update</div>
    <div class="topbar-meta">
      <span style="font-size:12px;color:var(--text-muted);">Instalirana: <?= h($installedVersion) ?> &nbsp;|&nbsp; Paket: <?= h($packageVersion) ?></span>
      &nbsp;&nbsp;<a href="../logout.php" class="topbar-logout">Odjava</a>
    </div>
  </div>
  <div class="content">

    <?php if (!empty($messages)): ?>
      <ul class="msg-list">
        <?php foreach ($messages as $m): ?>
          <li class="<?= $m['ok'] ? 'msg-ok' : 'msg-err' ?>"><?= h($m['text']) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if ($view === 'dryrun'): ?>
      <!-- ── DRY-RUN PRIKAZ ── -->
      <div class="upd-section" style="margin-bottom:16px;">
        <h3>Dry-run — pregled fajlova</h3>
        <div class="upd-warning">Provjeri listu prije primjene. <code>config.php</code> i <code>install.lock</code> se nikad ne prepisuju.</div>
        <table class="dry-table">
          <thead><tr><th>Putanja</th><th>Akcija</th></tr></thead>
          <tbody>
          <?php foreach ($dryFiles as $df): ?>
            <?php
            if ($df['skipped']) { $cls = 'dry-skip'; $act = 'PRESKOČI (zaštićeno)'; }
            elseif (!$df['exists']) { $cls = 'dry-new'; $act = 'NOVI FAJL'; }
            else { $cls = 'dry-update'; $act = 'AŽURIRAJ'; }
            ?>
            <tr class="<?= $cls ?>"><td><?= h($df['path']) ?></td><td><?= $act ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <div style="margin-top:14px;display:flex;gap:10px;">
          <form method="post">
            <input type="hidden" name="action" value="apply_web">
            <input type="hidden" name="tmp_dir" value="<?= h($tmpDirForApply) ?>">
            <button type="submit" class="btn btn-primary" onclick="return confirm('Primijeniti web update?')">Primijeni web update</button>
          </form>
          <a href="update.php" class="btn btn-ghost">Odustani</a>
        </div>
      </div>

    <?php elseif ($view === 'applied'): ?>
      <div class="upd-section" style="margin-bottom:16px;">
        <h3>Update primijenjen</h3>
        <p style="color:var(--text-muted);font-size:13px;">Provjeri <a href="about.php">O scanneru</a> stranicu za novi zdravstveni pregled.</p>
        <a href="update.php" class="btn btn-ghost btn-sm">Natrag na Update</a>
      </div>

    <?php else: ?>
      <!-- ── GLAVNA STRANICA ── -->

      <!-- Informacije o verzijama -->
      <div class="upd-section" style="margin-bottom:16px;">
        <h3>Verzije</h3>
        <div class="upd-info-row"><span class="upd-info-label">Instalirana verzija</span><span class="upd-info-val"><?= h($installedVersion) ?></span></div>
        <div class="upd-info-row"><span class="upd-info-label">Paket verzija</span><span class="upd-info-val"><?= h($packageVersion) ?></span></div>
      </div>

    <?php endif; ?>

    <!-- ── A) WEB PANEL UPDATE ── -->
    <?php if ($view === 'main'): ?>
    <div class="upd-section" style="margin-bottom:16px;">
      <h3>A) Web panel update</h3>
      <div class="upd-warning">
        Update <strong>nikad ne prepisuje</strong>: <code>includes/config.php</code>, <code>install/install.lock</code>.
        Baza, korisnici, nalazi, pravila i ignore lista ostaju netaknuti.
      </div>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="upload">
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
          <input type="file" name="update_zip" accept=".zip" required style="font-size:13px;">
          <button type="submit" class="btn btn-primary">Upload i dry-run</button>
        </div>
        <p style="font-size:12px;color:var(--text-muted);margin:8px 0 0;">
          Uploadaj ZIP paket (npr. <code>8core-scanner-v2-structure-fix6.zip</code>).
          Stranica prikazuje dry-run prikaz prije primjene.
        </p>
      </form>
    </div>
    <?php endif; ?>

    <!-- ── B) DATABASE MIGRACIJE ── -->
    <div class="upd-section" style="margin-bottom:16px;">
      <h3>
        B) Migracije baze podataka
        <?php if (count($pendingMigrations) > 0): ?>
          <span class="badge-num"><?= count($pendingMigrations) ?> pending</span>
        <?php endif; ?>
      </h3>

      <?php
      // Sve migracije — primjenjene i pending
      $allMigFiles = glob(__DIR__ . '/../install/migrations/*.sql') ?: [];
      sort($allMigFiles);
      $appliedMigs = [];
      try {
          $rows = $pdo->query("SELECT migration_name, applied_at FROM scanner_migrations ORDER BY applied_at ASC")->fetchAll();
          foreach ($rows as $r) $appliedMigs[$r['migration_name']] = $r['applied_at'];
      } catch (Throwable $e) {}
      ?>

      <?php if (empty($allMigFiles)): ?>
        <p style="font-size:13px;color:var(--text-muted);">Nema SQL migration fajlova u <code>install/migrations/</code>.</p>
      <?php else: ?>
        <?php foreach ($allMigFiles as $mf): ?>
          <?php $mname = basename($mf); $applied = isset($appliedMigs[$mname]); ?>
          <div class="mig-item">
            <span class="mig-name"><?= h($mname) ?></span>
            <?php if ($applied): ?>
              <span class="mig-status-applied">PRIMJENJENO <?= h(substr($appliedMigs[$mname], 0, 10)) ?></span>
            <?php else: ?>
              <span class="mig-status-pending">PENDING</span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <?php if (!empty($pendingMigrations)): ?>
        <div style="margin-top:14px;">
          <form method="post" onsubmit="return confirm('Primijeniti <?= count($pendingMigrations) ?> pending migracij(a)?')">
            <input type="hidden" name="action" value="apply_migrations">
            <button type="submit" class="btn btn-primary">Primijeni pending migracije (<?= count($pendingMigrations) ?>)</button>
          </form>
        </div>
      <?php else: ?>
        <p style="font-size:12px;color:#4ade80;margin:10px 0 0;">Sve migracije su primjenjene.</p>
      <?php endif; ?>
    </div>

    <!-- ── C) ROOT ENGINE UPDATE ── -->
    <div class="upd-section">
      <h3>C) Root engine update</h3>
      <p style="font-size:13px;color:var(--text-muted);margin:0 0 10px;">
        Web panel ne može direktno pisati u root direktorije.
        Kopirajte skriptu i pokrenite je kao root.
        <strong>Root lozinka se ne unosi kroz browser.</strong>
      </p>
      <p style="font-size:12px;color:var(--text-muted);margin:0 0 10px;">
        Uredi <code>ENGINE_SOURCE</code> varijablu u skripti na stvarnu putanju raspakirane <code>8core_scanner/</code> mape iz paketa.
      </p>
      <div class="upd-script-wrap">
        <div class="script-copy-bar">
          <button class="btn-copy" onclick="copyScript()">Kopiraj skriptu</button>
        </div>
        <textarea id="rootUpdateScript" readonly spellcheck="false"><?= h($rootUpdateScript) ?></textarea>
      </div>
      <div style="margin-top:10px;font-size:12px;color:var(--text-muted);">
        Spremi kao <code>/root/update_8core_scanner.sh</code> i pokreni:<br>
        <code>chmod +x /root/update_8core_scanner.sh &amp;&amp; bash /root/update_8core_scanner.sh</code>
      </div>
    </div>

  </div>
</div>
</div>

<script>
function copyScript() {
    var ta  = document.getElementById('rootUpdateScript');
    var btn = document.querySelector('.btn-copy');
    if (!ta) return;
    ta.select();
    ta.setSelectionRange(0, 999999);
    try {
        navigator.clipboard.writeText(ta.value).then(function() {
            btn.textContent = 'Kopirano!';
            setTimeout(function(){ btn.textContent = 'Kopiraj skriptu'; }, 2500);
        });
    } catch (e) {
        document.execCommand('copy');
        btn.textContent = 'Kopirano!';
        setTimeout(function(){ btn.textContent = 'Kopiraj skriptu'; }, 2500);
    }
}
</script>
</body>
</html>
