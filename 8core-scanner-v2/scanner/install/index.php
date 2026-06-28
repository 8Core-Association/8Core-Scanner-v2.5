<?php
/**
 * 8Core Scanner v2.0 — Installer
 * (c) 2026 Tomislav Galić <tomislav@8core.hr>
 * Web: https://8core.hr
 * Kontakt: info@8core.hr | Tel: +385 099 851 0717
 * Sva prava pridržana. Ovaj softver je vlasnički i zabranjeno ga je
 * distribuirati ili mijenjati bez izričitog dopuštenja autora.
 *
 * SIGURNOST: Nakon uspješne instalacije automatski se kreira install.lock
 * koji onemogućuje ponovni pristup installeru.
 */

define('SCANNER_INSTALL', true);
define('APP_VERSION', '2.0.0');

$lockFile = __DIR__ . '/install.lock';

if (file_exists($lockFile)) {
    http_response_code(403);
    ?><!doctype html>
<html lang="hr"><head><meta charset="utf-8"><title>Installer zaključan</title>
<style>body{font-family:Arial,sans-serif;background:#0f172a;color:#f1f5f9;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;}
.box{background:#1e293b;border:1px solid #334155;border-radius:12px;padding:32px 28px;max-width:480px;text-align:center;}
h2{color:#f87171;margin:0 0 12px;}p{color:#94a3b8;font-size:14px;margin:0 0 10px;}
code{background:#0f172a;padding:2px 6px;border-radius:4px;font-size:12px;}
.warn{background:rgba(251,191,36,.08);border:1px solid rgba(251,191,36,.3);border-radius:8px;padding:12px 14px;margin:16px 0;color:#fbbf24;font-size:13px;text-align:left;}
a{color:#60a5fa;}</style>
</head><body><div class="box">
<h2>Installer je zaključan</h2>
<p>Instalacija je već provedena. Datoteka <code>install/install.lock</code> postoji.</p>
<div class="warn">
  <strong>Sigurnosna preporuka:</strong><br>
  Nakon provjere rada sustava preporučujemo brisanje ili preimenovanje mape <code>install/</code>.<br><br>
  <code>rm -rf /putanja/do/scanner/install/</code><br>
  ili: <code>mv install/ install_disabled/</code>
</div>
<p>Za ponovnu instalaciju administrator mora ručno obrisati <code>install.lock</code>.</p>
<p><a href="../login.php">Idi na prijavu</a></p>
</div></body></html>
    <?php
    exit;
}

require_once __DIR__ . '/checks.php';

$step             = (int)($_POST['step'] ?? $_GET['step'] ?? 1);
$errors           = [];
$success          = [];
$rootInstallScript = '';
$engineSourcePath  = '';
$engineSourceWarn  = '';

// Detektira putanju web aplikacije iz stvarne lokacije installera
$detectedWebPath = realpath(__DIR__ . '/../');
$detectedWebUrl  = '';
if (!empty($_SERVER['HTTP_HOST'])) {
    $proto     = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $scriptDir = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
    $detectedWebUrl = $proto . '://' . $_SERVER['HTTP_HOST'] . $scriptDir;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 2) {
    $dbHost           = trim($_POST['db_host']            ?? 'localhost');
    $dbName           = trim($_POST['db_name']            ?? '');
    $dbUser           = trim($_POST['db_user']            ?? '');
    $dbPass           = $_POST['db_pass']                 ?? '';
    $dbCharset        = trim($_POST['db_charset']         ?? 'utf8mb4');
    $webAppPath       = trim($_POST['web_app_path']       ?? $detectedWebPath);
    $webAppUrl        = rtrim(trim($_POST['web_app_url']  ?? $detectedWebUrl), '/');
    $rootEngPath      = trim($_POST['root_eng_path']         ?? '/root/8core_scanner');
    $quarPath         = trim($_POST['quarantine_base_path']  ?? '/home/8core_quarantine');
    $logPath          = trim($_POST['log_path']              ?? '/root/8core_scanner/logs');
    $engineSourcePath = trim($_POST['engine_source_path'] ?? '');
    $adminUser        = trim($_POST['admin_user']         ?? 'admin');
    $adminPass        = $_POST['admin_pass']              ?? '';
    $adminPass2       = $_POST['admin_pass2']             ?? '';

    if ($dbName === '')    $errors[] = 'Naziv baze je obavezan.';
    if ($dbUser === '')    $errors[] = 'Korisnik baze je obavezan.';
    if ($adminUser === '') $errors[] = 'Admin username je obavezan.';
    if (strlen($adminPass) < 8) $errors[] = 'Admin lozinka mora imati najmanje 8 znakova.';
    if ($adminPass !== $adminPass2) $errors[] = 'Lozinke se ne podudaraju.';

    // Provjera engine source putanje (upozorenje, ne blokira instalaciju)
    if ($engineSourcePath !== '') {
        if (!file_exists($engineSourcePath . '/ioc_scan.sh') ||
            !file_exists($engineSourcePath . '/scanner_worker.sh')) {
            $engineSourceWarn = 'ioc_scan.sh ili scanner_worker.sh nisu pronađeni u ENGINE_SOURCE_PATH. '
                              . 'Instalacija web dijela se nastavlja, ali provjeri putanju '
                              . 'i uredi root install script po potrebi.';
        }
    } else {
        $engineSourceWarn = 'ENGINE_SOURCE_PATH nije unesen. Root install script sadrži placeholder — '
                          . 'uredi ga prije pokretanja.';
    }

    if (empty($errors)) {
        try {
            $dsn     = "mysql:host=$dbHost;dbname=$dbName;charset=$dbCharset";
            $testPdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $success[] = 'Konekcija na bazu uspješna.';
        } catch (PDOException $e) {
            $errors[] = 'Greška konekcije na bazu: ' . $e->getMessage();
        }
    }

    if (empty($errors)) {
        $configContent = generateConfigPhp(
            $dbHost, $dbName, $dbUser, $dbPass, $dbCharset,
            $adminUser, $adminPass,
            $rootEngPath, $logPath, $quarPath
        );
        $configPath = __DIR__ . '/../includes/config.php';
        if (file_put_contents($configPath, $configContent) === false) {
            $errors[] = 'Ne mogu zapisati includes/config.php. Provjeri dozvole direktorija.';
        } else {
            $success[] = 'Konfiguracija zapisana: includes/config.php';
        }
    }

    if (empty($errors)) {
        try {
            $pdo = new PDO(
                "mysql:host=$dbHost;dbname=$dbName;charset=$dbCharset",
                $dbUser, $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $migrateMessages = runMigrations($pdo, $adminUser, $adminPass);
            foreach ($migrateMessages as $m) $success[] = $m;
        } catch (Throwable $e) {
            $errors[] = 'Greška migracije: ' . $e->getMessage();
        }
    }

    if (empty($errors)) {
        // Generira root install script — prikazuje se u textarea, ne sprema javno na disk
        $srcPlaceholder = $engineSourcePath ?: '/PUTANJA/DO/8core-scanner-v2/8core_scanner';
        $rootInstallScript = generateRootInstallScript(
            $srcPlaceholder,
            $rootEngPath, $quarPath, $logPath,
            $dbHost, $dbName, $dbUser, $dbPass, $dbCharset
        );

        file_put_contents($lockFile, date('Y-m-d H:i:s') . "\n");
        $step = 3;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// generateConfigPhp
// ─────────────────────────────────────────────────────────────────────────────
function generateConfigPhp($dbHost, $dbName, $dbUser, $dbPass, $dbCharset,
                            $adminUser, $adminPass,
                            $rootEngPath, $logPath, $quarPath) {
    return "<?php\n"
         . "/**\n"
         . " * 8Core Scanner v2.0 — Konfiguracija\n"
         . " * Generirano installerom: " . date('Y-m-d H:i:s') . "\n"
         . " * NE COMMITATI ovu datoteku u repozitorij!\n"
         . " */\n"
         . "return [\n"
         . "    'db_host'    => '" . addslashes($dbHost)    . "',\n"
         . "    'db_name'    => '" . addslashes($dbName)    . "',\n"
         . "    'db_user'    => '" . addslashes($dbUser)    . "',\n"
         . "    'db_pass'    => '" . addslashes($dbPass)    . "',\n"
         . "    'db_charset' => '" . addslashes($dbCharset) . "',\n"
         . "\n"
         . "    'default_admin_user' => '" . addslashes($adminUser) . "',\n"
         . "    'default_admin_pass' => '" . addslashes($adminPass) . "',\n"
         . "\n"
         . "    'root_engine_path' => '" . addslashes($rootEngPath) . "',\n"
         . "    'scan_script'      => '" . addslashes($rootEngPath) . "/ioc_scan.sh',\n"
         . "    'scan_log'         => '" . addslashes($logPath)     . "/ioc-scan-live.log',\n"
         . "    'scan_debug'       => '" . addslashes($logPath)     . "/ioc-debug.log',\n"
         . "    'quarantine_path'  => '" . addslashes($quarPath)    . "',\n"
         . "];\n";
}

// ─────────────────────────────────────────────────────────────────────────────
// sh_quote — shell-safe single-quote escaping za bash vrijednosti
// ─────────────────────────────────────────────────────────────────────────────
function sh_quote(string $value): string {
    return "'" . str_replace("'", "'\"'\"'", $value) . "'";
}

// ─────────────────────────────────────────────────────────────────────────────
// generateRootInstallScript
// ─────────────────────────────────────────────────────────────────────────────
function generateRootInstallScript(
    string $engineSourcePath,
    string $rootEngPath,
    string $quarPath,
    string $logPath,
    string $dbHost,
    string $dbName,
    string $dbUser,
    string $dbPass,
    string $dbCharset
): string {
    $q_src  = sh_quote($engineSourcePath);
    $q_dst  = sh_quote($rootEngPath);
    $q_quar = sh_quote($quarPath);
    $q_log  = sh_quote($logPath);

    // Vrijednosti za scanner-db.conf (single-quoted, bash-safe)
    $c_host    = sh_quote($dbHost);
    $c_name    = sh_quote($dbName);
    $c_user    = sh_quote($dbUser);
    $c_pass    = sh_quote($dbPass);
    $c_charset = sh_quote($dbCharset);
    $c_dst     = sh_quote($rootEngPath);
    $c_quar    = sh_quote($quarPath);
    $c_log     = sh_quote($logPath);

    $nl = "\n";
    $s  = '#!/bin/bash' . $nl
        . '# 8Core Scanner v2.0 — Root engine instalacija' . $nl
        . '# Generirano: ' . date('Y-m-d H:i:s') . $nl
        . '# POKRENUTI KAO ROOT: bash /root/install_8core_scanner.sh' . $nl
        . $nl
        . 'set -e' . $nl
        . $nl
        . '# Putanje' . $nl
        . 'ENGINE_SOURCE=' . $q_src  . $nl
        . 'ROOT_ENGINE_PATH=' . $q_dst  . $nl
        . 'QUARANTINE_BASE_PATH=' . $q_quar . $nl
        . 'LOG_PATH=' . $q_log  . $nl
        . $nl
        . '# ─── Provjera izvorne mape ────────────────────────────────────────' . $nl
        . 'if [ ! -f "$ENGINE_SOURCE/ioc_scan.sh" ] || [ ! -f "$ENGINE_SOURCE/scanner_worker.sh" ]; then' . $nl
        . '    echo "GREŠKA: ioc_scan.sh ili scanner_worker.sh nisu pronađeni u: $ENGINE_SOURCE"' . $nl
        . '    echo "Provjeri ENGINE_SOURCE putanju i pokušaj ponovo."' . $nl
        . '    exit 1' . $nl
        . 'fi' . $nl
        . 'echo "Engine source: OK ($ENGINE_SOURCE)"' . $nl
        . $nl
        . '# ─── Kreiranje direktorija ───────────────────────────────────────' . $nl
        . 'echo "Kreiranje direktorija..."' . $nl
        . 'mkdir -p "$ROOT_ENGINE_PATH"' . $nl
        . 'mkdir -p "$LOG_PATH"' . $nl
        . 'echo "Kreiranje QUARANTINE_BASE_PATH (van public_html i web roota)..."' . $nl
        . 'mkdir -p "$QUARANTINE_BASE_PATH"' . $nl
        . 'echo "Kopiranje engine fajlova..."' . $nl
        . 'cp -a "$ENGINE_SOURCE/." "$ROOT_ENGINE_PATH/"' . $nl
        . $nl
        . '# ─── Kreiranje scanner-db.conf ───────────────────────────────────' . $nl
        . 'echo "Kreiranje scanner-db.conf..."' . $nl
        . "cat > \"\$ROOT_ENGINE_PATH/scanner-db.conf\" << '_8CORE_CONF_END_'" . $nl
        . '# 8Core Scanner v2.0 — Root DB konfiguracija' . $nl
        . '# Generirano: ' . date('Y-m-d H:i:s') . $nl
        . '# chmod 600 scanner-db.conf' . $nl
        . $nl
        . 'DB_HOST=' . $c_host    . $nl
        . 'DB_NAME=' . $c_name    . $nl
        . 'DB_USER=' . $c_user    . $nl
        . 'DB_PASS=' . $c_pass    . $nl
        . 'DB_CHARSET=' . $c_charset . $nl
        . $nl
        . 'ROOT_ENGINE_PATH=' . $c_dst  . $nl
        . 'QUARANTINE_BASE_PATH=' . $c_quar . $nl
        . 'LOG_PATH=' . $c_log  . $nl
        . '_8CORE_CONF_END_' . $nl
        . $nl
        . '# ─── Permisije i vlasništvo ──────────────────────────────────────' . $nl
        . 'echo "Postavljanje permisija..."' . $nl
        . 'chown -R root:root "$ROOT_ENGINE_PATH"' . $nl
        . 'chmod 700 "$ROOT_ENGINE_PATH"' . $nl
        . 'chmod 700 "$LOG_PATH"' . $nl
        . 'chown root:root "$QUARANTINE_BASE_PATH"' . $nl
        . 'chmod 700 "$QUARANTINE_BASE_PATH"' . $nl
        . 'chmod 600 "$ROOT_ENGINE_PATH/scanner-db.conf"' . $nl
        . 'chmod +x  "$ROOT_ENGINE_PATH/ioc_scan.sh"' . $nl
        . 'chmod +x  "$ROOT_ENGINE_PATH/scanner_worker.sh"' . $nl
        . $nl
        . '# ─── Provjera ────────────────────────────────────────────────────' . $nl
        . 'echo ""' . $nl
        . 'echo "=== Root engine instaliran ==="' . $nl
        . 'echo "Putanja: $ROOT_ENGINE_PATH"' . $nl
        . 'ls -lah "$ROOT_ENGINE_PATH"' . $nl
        . 'echo ""' . $nl
        . 'echo "Provjera sintakse skripti:"' . $nl
        . 'bash -n "$ROOT_ENGINE_PATH/ioc_scan.sh"       && echo "  ioc_scan.sh: OK"' . $nl
        . 'bash -n "$ROOT_ENGINE_PATH/scanner_worker.sh" && echo "  scanner_worker.sh: OK"' . $nl
        . 'echo ""' . $nl
        . 'echo "Cron primjer (crontab -e):"' . $nl
        . 'echo "  * * * * * $ROOT_ENGINE_PATH/scanner_worker.sh >> $LOG_PATH/scanner_worker_cron.log 2>&1"' . $nl
        . 'echo ""' . $nl
        . 'echo "Instalacija završena. Obriši ovaj script: rm /root/install_8core_scanner.sh"' . $nl;

    return $s;
}

?><!doctype html>
<html lang="hr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>8Core Scanner v2.0 – Installer</title>
<style>
*, *::before, *::after { box-sizing: border-box; }
body { margin:0; font-family:'Segoe UI',Arial,sans-serif; font-size:14px; background:#0f172a; color:#e2e8f0; min-height:100vh; display:flex; align-items:flex-start; justify-content:center; padding:30px 16px; }
.wrap { width:100%; max-width:640px; }
.header { display:flex; align-items:center; gap:14px; margin-bottom:28px; }
.logo-icon { width:42px; height:42px; background:#2563eb; border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.logo-icon svg { width:21px; height:21px; fill:none; stroke:#fff; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
.logo-text .name { font-size:18px; font-weight:700; color:#f1f5f9; }
.logo-text .sub  { font-size:12px; color:#475569; margin-top:2px; }
.steps { display:flex; gap:0; margin-bottom:24px; }
.step { flex:1; padding:8px 0; text-align:center; font-size:12px; font-weight:600; border-bottom:2px solid #334155; color:#475569; }
.step.active  { border-color:#2563eb; color:#60a5fa; }
.step.done    { border-color:#22c55e; color:#4ade80; }
.card { background:#1e293b; border:1px solid #334155; border-radius:12px; padding:24px; margin-bottom:16px; }
.card h2 { margin:0 0 16px; font-size:15px; font-weight:700; color:#f1f5f9; }
.card h3 { margin:20px 0 8px; font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.06em; }
.card h3:first-of-type { margin-top:0; }
.field { margin-bottom:14px; }
.field label { display:block; font-size:11px; font-weight:600; color:#94a3b8; text-transform:uppercase; letter-spacing:.04em; margin-bottom:5px; }
.field input { width:100%; padding:9px 12px; background:#0f172a; border:1px solid #334155; border-radius:7px; color:#f1f5f9; font-family:inherit; font-size:13px; outline:none; transition:border-color .13s; }
.field input:focus { border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.2); }
.field .hint { font-size:11px; color:#475569; margin-top:4px; }
.field-row { display:flex; gap:10px; }
.field-row .field { flex:1; }
.btn { display:inline-flex; align-items:center; gap:6px; padding:10px 18px; border:none; border-radius:7px; font-family:inherit; font-size:13px; font-weight:600; cursor:pointer; transition:background .12s; text-decoration:none; }
.btn-primary { background:#2563eb; color:#fff; }
.btn-primary:hover { background:#1d4ed8; }
.btn-ghost { background:transparent; color:#94a3b8; border:1px solid #334155; }
.btn-copy { background:#0f172a; color:#60a5fa; border:1px solid #334155; border-radius:6px; padding:6px 12px; font-size:12px; font-family:inherit; cursor:pointer; transition:background .12s; }
.btn-copy:hover { background:#1e293b; }
.checks { margin-bottom:0; }
.check-item { display:flex; align-items:center; gap:10px; padding:8px 0; border-bottom:1px solid #0f172a; font-size:13px; }
.check-item:last-child { border-bottom:none; }
.check-ok   { color:#4ade80; font-weight:700; }
.check-fail { color:#f87171; font-weight:700; }
.check-warn { color:#fbbf24; font-weight:700; }
.msgs { list-style:none; margin:0; padding:0; }
.msgs li { padding:5px 0; font-size:13px; border-bottom:1px solid #0f172a; font-family:'Consolas','Monaco',monospace; }
.msgs li:last-child { border-bottom:none; }
.error-box   { background:rgba(220,38,38,.1);  border:1px solid rgba(220,38,38,.3);  border-radius:8px; padding:12px 14px; margin-bottom:14px; }
.error-box li { color:#fca5a5; }
.success-box { background:rgba(34,197,94,.1);  border:1px solid rgba(34,197,94,.3);  border-radius:8px; padding:12px 14px; margin-bottom:14px; }
.success-box li { color:#86efac; }
.warn-box    { background:rgba(251,191,36,.08); border:1px solid rgba(251,191,36,.3); border-radius:8px; padding:12px 14px; margin-bottom:14px; color:#fbbf24; font-size:13px; }
.info-box    { background:rgba(37,99,235,.08);  border:1px solid rgba(37,99,235,.3);  border-radius:8px; padding:12px 14px; margin-bottom:14px; color:#93c5fd; font-size:13px; }
.section-sep { font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:.08em; margin:18px 0 10px; }
.script-wrap { position:relative; }
.script-wrap textarea { width:100%; height:300px; background:#020617; border:1px solid #334155; border-radius:7px; color:#86efac; font-family:'Consolas','Monaco',monospace; font-size:11px; padding:12px; resize:vertical; outline:none; line-height:1.5; }
.script-copy-bar { display:flex; justify-content:flex-end; margin-bottom:6px; }
.cmd-block { background:#020617; border:1px solid #334155; border-radius:7px; padding:10px 14px; font-family:'Consolas','Monaco',monospace; font-size:12px; color:#86efac; margin:8px 0; word-break:break-all; white-space:pre-wrap; }
.checklist { list-style:none; margin:8px 0 0; padding:0; }
.checklist li { padding:5px 0; font-size:13px; color:#94a3b8; display:flex; align-items:baseline; gap:8px; border-bottom:1px solid #0f172a; }
.checklist li:last-child { border-bottom:none; }
.checklist li::before { content:'☐'; color:#475569; flex-shrink:0; }
code { background:#0f172a; padding:1px 5px; border-radius:4px; font-size:12px; font-family:'Consolas','Monaco',monospace; }
a { color:#60a5fa; }
</style>
</head>
<body>
<div class="wrap">

  <div class="header">
    <div class="logo-icon">
      <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
    </div>
    <div class="logo-text">
      <div class="name">8Core Scanner</div>
      <div class="sub">Installer v<?= APP_VERSION ?></div>
    </div>
  </div>

  <div class="steps">
    <div class="step <?= $step === 1 ? 'active' : ($step > 1 ? 'done' : '') ?>">1. Provjere</div>
    <div class="step <?= $step === 2 ? 'active' : ($step > 2 ? 'done' : '') ?>">2. Konfiguracija</div>
    <div class="step <?= $step === 3 ? 'done' : '' ?>">3. Gotovo</div>
  </div>

  <?php if (!empty($errors)): ?>
    <div class="error-box"><ul class="msgs"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <?php if ($step === 3): ?>
    <!-- ═══ KORAK 3: GOTOVO ════════════════════════════════════════════════════ -->

    <div class="card">
      <h2>Web instalacija završena</h2>
      <?php if (!empty($success)): ?>
        <div class="success-box"><ul class="msgs"><?php foreach ($success as $s): ?><li><?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul></div>
      <?php endif; ?>
      <p style="color:#94a3b8;font-size:13px;margin:0 0 16px;">Installer je zaključan (install.lock kreiran).</p>
      <a href="../login.php" class="btn btn-primary">Idi na prijavu</a>
    </div>

    <div class="card">
      <h2>Root engine instalacija</h2>

      <div class="info-box">
        Web installer ne može pisati u root direktorije niti tražiti root lozinku.
        Kopirajte generiranu skriptu u root terminal i pokrenite je kao root.
        <strong>Root lozinka se ne unosi kroz browser.</strong>
      </div>

      <?php if (!empty($engineSourceWarn)): ?>
        <div class="warn-box"><?= htmlspecialchars($engineSourceWarn, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>

      <h3>Korak 1 — Kopirajte skriptu u root terminal</h3>
      <p style="color:#94a3b8;font-size:13px;margin:0 0 10px;">
        Kopirajte cijeli sadržaj i zalijepite u root terminal ili sačuvajte kao fajl:
      </p>
      <div class="script-wrap">
        <div class="script-copy-bar">
          <button class="btn-copy" onclick="copyScript()">Kopiraj script</button>
        </div>
        <textarea id="rootScript" readonly spellcheck="false"><?= htmlspecialchars($rootInstallScript, ENT_QUOTES, 'UTF-8') ?></textarea>
      </div>
      <p style="color:#475569;font-size:12px;margin:8px 0 0;">
        Ili prenijeti SCP/SFTP-om i pokrenuti: <code>bash /root/install_8core_scanner.sh</code>
      </p>

      <h3>Korak 2 — Pokrenite skriptu kao root</h3>
      <div class="cmd-block">chmod +x /root/install_8core_scanner.sh
bash /root/install_8core_scanner.sh</div>

      <h3>Korak 3 — Dodajte cron job</h3>
      <p style="color:#94a3b8;font-size:13px;margin:0 0 6px;">Kao root (<code>crontab -e</code>):</p>
      <div class="cmd-block">* * * * * <?= htmlspecialchars($rootEngPath, ENT_QUOTES, 'UTF-8') ?>/scanner_worker.sh >> <?= htmlspecialchars($logPath, ENT_QUOTES, 'UTF-8') ?>/scanner_worker_cron.log 2>&1</div>

      <h3>Provjera</h3>
      <ul class="checklist">
        <li><code>ls -lah <?= htmlspecialchars($rootEngPath, ENT_QUOTES, 'UTF-8') ?></code></li>
        <li><code>stat <?= htmlspecialchars($rootEngPath, ENT_QUOTES, 'UTF-8') ?>/scanner-db.conf</code> — mora biti chmod 600</li>
        <li><code>stat <?= htmlspecialchars($quarPath, ENT_QUOTES, 'UTF-8') ?></code> — karantena mora biti chmod 700, vlasnik root</li>
        <li><code>bash -n <?= htmlspecialchars($rootEngPath, ENT_QUOTES, 'UTF-8') ?>/ioc_scan.sh</code></li>
        <li><code>bash -n <?= htmlspecialchars($rootEngPath, ENT_QUOTES, 'UTF-8') ?>/scanner_worker.sh</code></li>
        <li>Dodaj cron i provjeri log nakon minute</li>
        <li>Obriši instalacijski script: <code>rm /root/install_8core_scanner.sh</code></li>
      </ul>
    </div>

    <div class="card" style="border-color:rgba(251,191,36,.35);">
      <h2 style="color:#fbbf24;">Sigurnosna preporuka — mapa install/</h2>
      <p style="color:#94a3b8;font-size:13px;margin:0 0 10px;">
        Mapa <code>install/</code> je zaštićena putem <code>install.lock</code>, ali preporučujemo
        njezino brisanje ili preimenovanje nakon provjere rada sustava:
      </p>
      <div class="cmd-block"># Brisanje install/ mape (preporučeno):
rm -rf <?= htmlspecialchars(rtrim($detectedWebPath, '/'), ENT_QUOTES, 'UTF-8') ?>/install/

# Ili preimenovanje:
mv <?= htmlspecialchars(rtrim($detectedWebPath, '/'), ENT_QUOTES, 'UTF-8') ?>/install/ <?= htmlspecialchars(rtrim($detectedWebPath, '/'), ENT_QUOTES, 'UTF-8') ?>/install_disabled/</div>
      <p style="color:#475569;font-size:12px;margin:8px 0 0;">
        Za buduće nadogradnje sheme baze koristi: <a href="migrate.php">install/migrate.php</a> (zahtijeva admin prijavu).
      </p>
    </div>

  <?php elseif ($step === 2): ?>
    <!-- ═══ KORAK 2: KONFIGURACIJA ════════════════════════════════════════════ -->
    <div class="card">
      <h2>Konfiguracija baze podataka</h2>
      <form method="post">
        <input type="hidden" name="step" value="2">

        <div class="field-row">
          <div class="field">
            <label>DB host</label>
            <input type="text" name="db_host" value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost', ENT_QUOTES) ?>">
          </div>
          <div class="field">
            <label>DB charset</label>
            <input type="text" name="db_charset" value="<?= htmlspecialchars($_POST['db_charset'] ?? 'utf8mb4', ENT_QUOTES) ?>">
          </div>
        </div>
        <div class="field">
          <label>Naziv baze *</label>
          <input type="text" name="db_name" value="<?= htmlspecialchars($_POST['db_name'] ?? '', ENT_QUOTES) ?>" required placeholder="npr. 8core_scanner">
        </div>
        <div class="field-row">
          <div class="field">
            <label>Korisnik baze *</label>
            <input type="text" name="db_user" value="<?= htmlspecialchars($_POST['db_user'] ?? '', ENT_QUOTES) ?>" required>
          </div>
          <div class="field">
            <label>Lozinka baze</label>
            <input type="password" name="db_pass" value="">
          </div>
        </div>

        <div class="section-sep">Web aplikacija</div>

        <div class="field">
          <label>Web aplikacija — putanja (WEB_APP_PATH)</label>
          <input type="text" name="web_app_path" value="<?= htmlspecialchars($_POST['web_app_path'] ?? $detectedWebPath, ENT_QUOTES) ?>">
          <div class="hint">Detektirano automatski iz lokacije installera. Mijenjajte samo ako nije ispravno.</div>
        </div>
        <div class="field">
          <label>Web aplikacija — URL</label>
          <input type="text" name="web_app_url" value="<?= htmlspecialchars($_POST['web_app_url'] ?? $detectedWebUrl, ENT_QUOTES) ?>" placeholder="https://domena.hr/scanner">
          <div class="hint">Potpuni URL bez trailing slasha.</div>
        </div>

        <div class="section-sep">Root engine</div>

        <div class="field">
          <label>Izvorna mapa root enginea (ENGINE_SOURCE_PATH)</label>
          <input type="text" name="engine_source_path" value="<?= htmlspecialchars($_POST['engine_source_path'] ?? '', ENT_QUOTES) ?>" placeholder="/root/8core-scanner-install/8core-scanner-v2/8core_scanner">
          <div class="hint">Putanja do raspakirane mape <code>8core_scanner/</code> iz ZIP paketa. Koristi se za generiranje root install skripte.</div>
        </div>
        <div class="field">
          <label>Instalacijska putanja root enginea (ROOT_ENGINE_PATH)</label>
          <input type="text" name="root_eng_path" value="<?= htmlspecialchars($_POST['root_eng_path'] ?? '/root/8core_scanner', ENT_QUOTES) ?>">
          <div class="hint">Gdje će biti instaliran root engine. Van web roota. Default: /root/8core_scanner</div>
        </div>
        <div class="field-row">
          <div class="field">
            <label>Karantena (QUARANTINE_BASE_PATH)</label>
            <input type="text" name="quarantine_base_path" value="<?= htmlspecialchars($_POST['quarantine_base_path'] ?? '/home/8core_quarantine', ENT_QUOTES) ?>">
            <div class="hint">
              Ne mora biti unutar ROOT_ENGINE_PATH. Ne smije biti unutar <code>public_html</code> ili bilo kojeg web-dostupnog direktorija.
              Preporučena permisija: <code>700</code>, vlasnik <code>root:root</code>. Default: <code>/home/8core_quarantine</code>
            </div>
          </div>
          <div class="field">
            <label>Logovi (LOG_PATH)</label>
            <input type="text" name="log_path" value="<?= htmlspecialchars($_POST['log_path'] ?? '/root/8core_scanner/logs', ENT_QUOTES) ?>">
          </div>
        </div>

        <div class="section-sep">Zadani admin korisnik</div>

        <div class="field-row">
          <div class="field">
            <label>Admin username *</label>
            <input type="text" name="admin_user" value="<?= htmlspecialchars($_POST['admin_user'] ?? 'admin', ENT_QUOTES) ?>" required>
          </div>
        </div>
        <div class="field-row">
          <div class="field">
            <label>Admin lozinka * (min. 8 znakova)</label>
            <input type="password" name="admin_pass" required>
          </div>
          <div class="field">
            <label>Potvrda lozinke *</label>
            <input type="password" name="admin_pass2" required>
          </div>
        </div>

        <div style="margin-top:18px;display:flex;gap:10px;">
          <button type="submit" class="btn btn-primary">Instaliraj</button>
          <a href="?step=1" class="btn btn-ghost">Nazad</a>
        </div>
      </form>
    </div>

  <?php else: ?>
    <!-- ═══ KORAK 1: PROVJERE ═════════════════════════════════════════════════ -->
    <div class="card">
      <h2>Provjera okruženja</h2>
      <div class="checks">
        <?php
        $allOk = true;
        foreach (getChecks() as $check) {
            $ok   = $check['ok'];
            $warn = $check['warn'] ?? false;
            if (!$ok && !$warn) $allOk = false;
            $cls  = $ok ? 'check-ok' : ($warn ? 'check-warn' : 'check-fail');
            $icon = $ok ? 'OK' : ($warn ? 'UPOZORENJE' : 'GREŠKA');
            ?>
        <div class="check-item">
          <span class="<?= $cls ?>">[<?= $icon ?>]</span>
          <span><?= htmlspecialchars($check['label'], ENT_QUOTES, 'UTF-8') ?></span>
          <?php if (!empty($check['note'])): ?>
            <span style="color:#64748b;font-size:12px;"><?= htmlspecialchars($check['note'], ENT_QUOTES, 'UTF-8') ?></span>
          <?php endif; ?>
        </div>
        <?php } ?>
      </div>
    </div>

    <?php if ($allOk): ?>
      <a href="?step=2" class="btn btn-primary">Nastavi na konfiguraciju</a>
    <?php else: ?>
      <p style="color:#f87171;font-size:13px;">Ispravite greške prije nastavka instalacije.</p>
      <a href="?step=1" class="btn btn-ghost">Osvježi provjere</a>
    <?php endif; ?>
  <?php endif; ?>

</div>

<script>
function copyScript() {
    var ta  = document.getElementById('rootScript');
    var btn = document.querySelector('.btn-copy');
    ta.select();
    ta.setSelectionRange(0, 999999);
    try {
        navigator.clipboard.writeText(ta.value).then(function() {
            btn.textContent = 'Kopirano!';
            setTimeout(function(){ btn.textContent = 'Kopiraj script'; }, 2500);
        });
    } catch (e) {
        document.execCommand('copy');
        btn.textContent = 'Kopirano!';
        setTimeout(function(){ btn.textContent = 'Kopiraj script'; }, 2500);
    }
}
</script>
</body>
</html>
