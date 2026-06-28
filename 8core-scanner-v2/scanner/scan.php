<?php
/**
 * 8Core Scanner v2.0 — Upravljanje skeniranjima
 * (c) 2026 Tomislav Galić <tomislav@8core.hr>
 * Web: https://8core.hr
 * Kontakt: info@8core.hr | Tel: +385 099 851 0717
 * Sva prava pridržana. Ovaj softver je vlasnički i zabranjeno ga je
 * distribuirati ili mijenjati bez izričitog dopuštenja autora.
 */
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

require_login();

$user = current_user();

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$error = '';

/**
 * Kreiraj queue tablicu ako ne postoji.
 * Root worker čita PENDING zahtjeve.
 */
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS scanner_scan_requests (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            requested_by VARCHAR(80) NOT NULL,
            requested_role VARCHAR(20) NOT NULL,
            target_type VARCHAR(30) NOT NULL DEFAULT 'account',
            target_value VARCHAR(255) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'PENDING',
            scan_id BIGINT UNSIGNED NULL,
            requested_at DATETIME NOT NULL,
            started_at DATETIME NULL,
            finished_at DATETIME NULL,
            note TEXT NULL,
            INDEX(status),
            INDEX(requested_by),
            INDEX(target_type),
            INDEX(target_value),
            INDEX(requested_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Throwable $e) {
    $error = 'Ne mogu kreirati scanner_scan_requests: ' . $e->getMessage();
}

$accounts = [];

try {
    if (is_admin()) {
        $accounts = $pdo->query("
            SELECT DISTINCT account_name
            FROM findings
            WHERE account_name IS NOT NULL AND account_name != ''
            ORDER BY account_name
        ")->fetchAll(PDO::FETCH_COLUMN);
    } else {
        if (!empty($user['account_name'])) {
            $accounts = [$user['account_name']];
        }
    }
} catch (Throwable $e) {
    $accounts = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['request_scan'] ?? '') === '1') {
    $targetType  = $_POST['target_type']  ?? 'account';
    $targetValue = trim($_POST['target_value'] ?? '');

    if (!is_admin()) {
        $targetType  = 'account';
        $targetValue = $user['account_name'] ?? '';
    }

    if ($targetType === 'all' && !is_admin()) {
        $error = 'Nemaš pravo pokrenuti globalni scan.';
    } elseif ($targetType === 'custom_path' && !is_admin()) {
        $error = 'Nemaš pravo pokrenuti custom path scan.';
    } elseif ($targetType === 'account' && $targetValue === '') {
        $error = 'Account nije odabran.';
    } elseif ($targetType === 'account' && !is_admin() && $targetValue !== ($user['account_name'] ?? '')) {
        $error = 'Ne možeš pokrenuti scan za tuđi account.';
    } elseif ($targetType === 'custom_path' && strpos($targetValue, '/home/') !== 0) {
        $error = 'Custom path mora početi sa /home/.';
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO scanner_scan_requests
            (requested_by, requested_role, target_type, target_value, status, requested_at)
            VALUES (?, ?, ?, ?, 'PENDING', NOW())
        ");
        $stmt->execute([
            $user['username'],
            $user['role'],
            $targetType,
            $targetType === 'all' ? '/home' : $targetValue,
        ]);

        $_SESSION['flash'] = 'Scan zahtjev je dodan u queue. Root worker će ga izvršiti.';
        header('Location: scan.php');
        exit;
    }
}

$lastScan = null;
try {
    $lastScan = $pdo->query("SELECT * FROM scans ORDER BY id DESC LIMIT 1")->fetch();
} catch (Throwable $e) {}

$requests = [];
try {
    if (is_admin()) {
        $requests = $pdo->query("SELECT * FROM scanner_scan_requests ORDER BY id DESC LIMIT 20")->fetchAll();
    } else {
        $stmt = $pdo->prepare("SELECT * FROM scanner_scan_requests WHERE requested_by = ? ORDER BY id DESC LIMIT 20");
        $stmt->execute([$user['username']]);
        $requests = $stmt->fetchAll();
    }
} catch (Throwable $e) {
    $requests = [];
}

function scan_status_class($s) {
    $s = strtolower($s);
    if ($s === 'running') return 'status-pill-running';
    if ($s === 'done')    return 'status-pill-done';
    if ($s === 'error')   return 'status-pill-error';
    return 'status-pill-pending';
}
?>
<!doctype html>
<html lang="hr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>8Core Scanner – Skeniranja</title>
<link rel="stylesheet" href="assets/css/scanner.css">
<style>
.scan-target-row { display:flex; align-items:flex-start; gap:16px; flex-wrap:wrap; }
.scan-target-group { display:flex; flex-direction:column; gap:4px; min-width:180px; }
.scan-target-group label { font-size:11.5px; font-weight:600; color:var(--text-muted); text-transform:uppercase; letter-spacing:.04em; }
.scan-target-group select,
.scan-target-group input[type="text"] { padding:8px 10px; border:1px solid var(--border); border-radius:7px; font-family:inherit; font-size:13px; color:var(--text); background:var(--surface2); outline:none; transition:border-color .13s, box-shadow .13s; }
.scan-target-group select:focus,
.scan-target-group input:focus { border-color:var(--accent); box-shadow:0 0 0 3px rgba(37,99,235,.1); }
.scan-info-box { display:flex; align-items:center; gap:10px; background:var(--surface2); border:1px solid var(--border); border-radius:8px; padding:11px 14px; font-size:13px; color:var(--text-muted); margin-bottom:16px; }
.scan-info-box svg { flex-shrink:0; stroke:var(--text-muted); fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
.status-pill-pending  { background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; }
.status-pill-running  { background:#fffbeb; color:#b45309; border:1px solid #fde68a; }
.status-pill-done     { background:#f0fdf4; color:#15803d; border:1px solid #bbf7d0; }
.status-pill-error    { background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; }
.scan-last-table td:first-child { color:var(--text-muted); font-size:11.5px; font-weight:600; text-transform:uppercase; letter-spacing:.04em; white-space:nowrap; width:110px; }
</style>
</head>
<body>
<div class="layout">

<!-- SIDEBAR -->
<aside class="sidebar">
  <div class="sidebar-logo">
    <div class="logo-mark">
      <div class="logo-icon">
        <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      </div>
      <span class="logo-text">8Core Scanner</span>
    </div>
    <div class="logo-version">IOC Scanner v2.0</div>
  </div>

  <nav class="sidebar-nav">
    <div class="sidebar-section-label">Menu</div>
    <a class="sidebar-link" href="index.php">
      <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
      Dashboard
    </a>
    <a class="sidebar-link active" href="scan.php">
      <svg viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"/></svg>
      Skeniranja
    </a>
    <?php if (is_admin()): ?>
    <a class="sidebar-link" href="admin/index.php">
      <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      Admin panel
    </a>
    <?php endif; ?>
  </nav>

  <div class="sidebar-footer">
    <div class="sidebar-user">
      <div class="avatar"><?= h(mb_strtoupper(mb_substr($user['username'], 0, 1))) ?></div>
      <div class="user-info">
        <div class="user-name"><?= h($user['username']) ?></div>
        <div class="user-role"><?= h($user['role']) ?><?= !is_admin() && !empty($user['account_name']) ? ' · ' . h($user['account_name']) : '' ?></div>
      </div>
    </div>
  </div>
</aside>

<!-- MAIN -->
<div class="main">
  <div class="topbar">
    <div class="topbar-title">Skeniranja</div>
    <div class="topbar-meta">
      <?php if ($lastScan): ?>
        <span class="scan-dot <?= $lastScan['status'] === 'RUNNING' ? 'running' : '' ?>"></span>
        Zadnji scan: <?= h(substr($lastScan['started_at'] ?? '', 0, 16)) ?>
        &nbsp;&middot;&nbsp;
        <span class="status-pill <?= scan_status_class($lastScan['status']) ?>"><?= h($lastScan['status']) ?></span>
      <?php else: ?>
        <span class="scan-dot" style="background:#94a3b8"></span>
        Nema podataka o scanu
      <?php endif; ?>
      &nbsp;&nbsp;
      <a href="logout.php" class="topbar-logout">Odjava</a>
    </div>
  </div>

  <div class="content">

    <?php if ($flash): ?>
      <div class="notice ok"><?= h($flash) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="notice error"><?= h($error) ?></div>
    <?php endif; ?>

    <!-- TRIGGER PANEL -->
    <div class="panel">
      <h2>Novi scan zahtjev</h2>

      <div class="scan-info-box">
        <svg viewBox="0 0 24 24" width="16" height="16"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        Zahtjev se dodaje u queue. Root worker preuzima i izvršava sken.
      </div>

      <form method="post">
        <input type="hidden" name="request_scan" value="1">

        <?php if (is_admin()): ?>
          <div class="scan-target-row">
            <div class="scan-target-group">
              <label>Target tip</label>
              <select name="target_type" id="target_type">
                <option value="account">Jedan account</option>
                <option value="all">Svi accounti</option>
                <option value="custom_path">Custom path</option>
              </select>
            </div>
            <div class="scan-target-group" id="group_account">
              <label>Account</label>
              <select name="target_value" id="account_select">
                <?php foreach ($accounts as $acc): ?>
                  <option value="<?= h($acc) ?>"><?= h($acc) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="scan-target-group" id="group_custom" style="display:none;">
              <label>Custom path</label>
              <input type="text" id="custom_path" placeholder="/home/account/public_html">
            </div>
          </div>
          <div style="margin-top:16px;">
            <button type="submit" class="btn btn-primary"
                    onclick="return confirm('Dodati scan zahtjev u queue?')">
              <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-2px;"><polygon points="5 3 19 12 5 21 5 3"/></svg>
              Pokreni scan
            </button>
          </div>
        <?php else: ?>
          <input type="hidden" name="target_type"  value="account">
          <input type="hidden" name="target_value" value="<?= h($user['account_name']) ?>">
          <div class="notice ok" style="margin-bottom:14px;">
            Scan za account: <strong><?= h($user['account_name']) ?></strong>
          </div>
          <button type="submit" class="btn btn-primary"
                  onclick="return confirm('Dodati scan zahtjev u queue?')">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-2px;"><polygon points="5 3 19 12 5 21 5 3"/></svg>
            Pokreni scan
          </button>
        <?php endif; ?>
      </form>
    </div>

    <!-- ZADNJI SCAN -->
    <div class="panel">
      <h2>Zadnji završeni scan</h2>
      <?php if ($lastScan): ?>
        <table class="scan-last-table" style="width:auto;border-collapse:collapse;">
          <tr><td>ID</td><td class="mono"><?= h($lastScan['id']) ?></td></tr>
          <tr>
            <td>Status</td>
            <td><span class="status-pill <?= scan_status_class($lastScan['status']) ?>"><?= h($lastScan['status']) ?></span></td>
          </tr>
          <tr><td>Pokrenuto</td><td><?= h($lastScan['started_at'] ?? '—') ?></td></tr>
          <tr><td>Završeno</td><td><?= h($lastScan['finished_at'] ?? '—') ?></td></tr>
          <tr><td>Base path</td><td><code class="rule-pattern"><?= h($lastScan['base_path'] ?? '—') ?></code></td></tr>
          <tr><td>Nalazi</td><td><strong><?= h($lastScan['files_found'] ?? '—') ?></strong></td></tr>
        </table>
      <?php else: ?>
        <p class="small" style="margin:0;">Nema podataka o scanu.</p>
      <?php endif; ?>
    </div>

    <!-- SCAN QUEUE -->
    <div class="panel" style="padding:0;overflow:hidden;">
      <div style="padding:16px 20px 12px;border-bottom:1px solid var(--border);">
        <h2 style="margin:0;">Scan queue</h2>
      </div>
      <?php if ($requests): ?>
        <div class="table-wrap" style="border:none;border-radius:0;box-shadow:none;">
          <table>
            <thead>
              <tr>
                <th class="col-id">ID</th>
                <th>Zatražio</th>
                <th>Target</th>
                <th>Status</th>
                <th>Zatraženo</th>
                <th>Pokrenuto</th>
                <th>Završeno</th>
                <th>Napomena</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($requests as $r): ?>
              <tr>
                <td class="small mono"><?= h($r['id']) ?></td>
                <td>
                  <span style="font-weight:600;"><?= h($r['requested_by']) ?></span>
                  <div class="small"><?= h($r['requested_role']) ?></div>
                </td>
                <td>
                  <span class="rule-type-badge"><?= h($r['target_type']) ?></span>
                  <div class="small mono" style="margin-top:3px;"><?= h($r['target_value']) ?></div>
                </td>
                <td>
                  <span class="status-pill <?= scan_status_class($r['status']) ?>"><?= h($r['status']) ?></span>
                </td>
                <td class="small"><?= h(substr($r['requested_at'] ?? '', 0, 16)) ?></td>
                <td class="small"><?= $r['started_at']  ? h(substr($r['started_at'],  0, 16)) : '<span style="color:var(--border)">—</span>' ?></td>
                <td class="small"><?= $r['finished_at'] ? h(substr($r['finished_at'], 0, 16)) : '<span style="color:var(--border)">—</span>' ?></td>
                <td class="small"><?= $r['note'] ? h($r['note']) : '<span style="color:var(--border)">—</span>' ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <p class="small" style="padding:20px;margin:0;">Nema scan zahtjeva.</p>
      <?php endif; ?>
    </div>

  </div><!-- .content -->
</div><!-- .main -->
</div><!-- .layout -->

<script>
(function () {
  var typeEl    = document.getElementById('target_type');
  var accEl     = document.getElementById('account_select');
  var customEl  = document.getElementById('custom_path');
  var groupAcc  = document.getElementById('group_account');
  var groupCust = document.getElementById('group_custom');

  if (!typeEl) return;

  typeEl.addEventListener('change', function () {
    var t = this.value;
    if (t === 'custom_path') {
      groupAcc.style.display  = 'none';
      groupCust.style.display = 'flex';
      customEl.name = 'target_value';
      accEl.name    = 'target_value_disabled';
    } else if (t === 'all') {
      groupAcc.style.display  = 'none';
      groupCust.style.display = 'none';
      accEl.name    = 'target_value_disabled';
      customEl.name = 'target_value_disabled';
    } else {
      groupAcc.style.display  = 'flex';
      groupCust.style.display = 'none';
      accEl.name    = 'target_value';
      customEl.name = 'target_value_disabled';
    }
  });
}());
</script>
</body>
</html>
