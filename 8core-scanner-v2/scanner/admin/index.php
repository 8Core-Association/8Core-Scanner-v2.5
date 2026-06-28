<?php
/**
 * 8Core Scanner v2.0 — Admin Dashboard
 * (c) 2026 Tomislav Galić <tomislav@8core.hr>
 * Web: https://8core.hr
 * Kontakt: info@8core.hr | Tel: +385 099 851 0717
 * Sva prava pridržana. Ovaj softver je vlasnički i zabranjeno ga je
 * distribuirati ili mijenjati bez izričitog dopuštenja autora.
 */
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require_admin();

$totalUsers    = (int)$pdo->query("SELECT COUNT(*) FROM scanner_users")->fetchColumn();
$activeUsers   = (int)$pdo->query("SELECT COUNT(*) FROM scanner_users WHERE active = 1")->fetchColumn();
$totalAccounts = (int)$pdo->query("SELECT COUNT(DISTINCT account_name) FROM findings WHERE account_name IS NOT NULL AND account_name != ''")->fetchColumn();
$totalFindings = (int)$pdo->query("SELECT COUNT(*) FROM findings")->fetchColumn();
$critFindings  = (int)$pdo->query("SELECT COUNT(*) FROM findings WHERE risk = 'CRITICAL'")->fetchColumn();
$highFindings  = (int)$pdo->query("SELECT COUNT(*) FROM findings WHERE risk = 'HIGH'")->fetchColumn();
$newFindings   = (int)$pdo->query("SELECT COUNT(*) FROM findings WHERE action_status = 'new'")->fetchColumn();

$recentUsers = $pdo->query("SELECT username, role, active, last_login FROM scanner_users ORDER BY id DESC LIMIT 5")->fetchAll();
?>
<!doctype html>
<html lang="hr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>8Core Scanner – Admin</title>
<link rel="stylesheet" href="../assets/css/scanner.css">
</head>
<body>
<div class="layout">

<!-- SIDEBAR -->
<?php include __DIR__ . '/sidebar.php'; ?>

<!-- MAIN -->
<div class="main">
  <div class="topbar">
    <div class="topbar-title">Admin Panel</div>
    <div class="topbar-meta">
      <a href="../logout.php" style="color:var(--text-muted);font-size:12px;">Odjava</a>
    </div>
  </div>

  <div class="content">

    <!-- STAT CARDS -->
    <div class="cards">
      <div class="card card-critical">
        <div class="label">Critical</div>
        <div class="num"><?= $critFindings ?></div>
      </div>
      <div class="card card-high">
        <div class="label">High</div>
        <div class="num"><?= $highFindings ?></div>
      </div>
      <div class="card card-action">
        <div class="label">Neobrađeni</div>
        <div class="num"><?= $newFindings ?></div>
      </div>
      <div class="card" style="border-color:var(--border);">
        <div class="label">Ukupno nalaza</div>
        <div class="num"><?= $totalFindings ?></div>
      </div>
      <div class="card" style="border-color:var(--border);">
        <div class="label">Accounti</div>
        <div class="num"><?= $totalAccounts ?></div>
      </div>
      <div class="card" style="border-color:var(--border);">
        <div class="label">Korisnici (aktivni)</div>
        <div class="num"><?= $activeUsers ?>/<?= $totalUsers ?></div>
      </div>
    </div>

    <!-- BRZE RADNJE -->
    <div class="panel" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:0;">
      <a href="users.php" class="btn btn-primary">
        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
        Dodaj korisnika
      </a>
      <a href="../index.php" class="btn btn-ghost">
        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        Otvori Scanner
      </a>
    </div>

    <!-- NEDAVNI KORISNICI -->
    <div class="panel" style="margin-top:20px;">
      <h2 style="margin:0 0 14px;font-size:14px;font-weight:600;color:var(--text);">Nedavno dodani korisnici</h2>
      <div class="table-wrap" style="margin:0;">
        <table>
          <thead>
            <tr>
              <th>Username</th>
              <th>Role</th>
              <th>Status</th>
              <th>Zadnji login</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($recentUsers as $u): ?>
          <tr>
            <td><b><?= h($u['username']) ?></b></td>
            <td><span class="badge <?= $u['role'] === 'admin' ? 'risk-medium' : 'risk-low' ?>"><?= h($u['role']) ?></span></td>
            <td>
              <?php if ($u['active']): ?>
                <span class="user-active">Aktivan</span>
              <?php else: ?>
                <span class="user-inactive">Neaktivan</span>
              <?php endif; ?>
            </td>
            <td class="small"><?= h($u['last_login'] ?? '—') ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div><!-- .content -->
</div><!-- .main -->
</div><!-- .layout -->
</body>
</html>
