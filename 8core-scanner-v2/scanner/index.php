<?php
/**
 * 8Core Scanner v2.0 — Dashboard (Nalazi)
 * (c) 2026 Tomislav Galić <tomislav@8core.hr>
 * Web: https://8core.hr
 * Kontakt: info@8core.hr | Tel: +385 099 851 0717
 * Sva prava pridržana. Ovaj softver je vlasnički i zabranjeno ga je
 * distribuirati ili mijenjati bez izričitog dopuštenja autora.
 */
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';
require_login();

try {
    $user = current_user();

    $hasAction      = has_column($pdo, 'findings', 'action_status');
    $hasAccount     = has_column($pdo, 'findings', 'account_name');
    $hasRel         = has_column($pdo, 'findings', 'relative_path');
    $hasCtime       = has_column($pdo, 'findings', 'ctime');
    $hasBirth       = has_column($pdo, 'findings', 'birth_time');
    $hasDetected    = has_column($pdo, 'findings', 'detected_at');
    $hasSourceGuess = has_column($pdo, 'findings', 'source_guess');
    $hasSourceType  = has_column($pdo, 'findings', 'source_type');
    $hasExt         = has_column($pdo, 'findings', 'file_ext');

    $accountCol  = $hasAccount  ? 'account_name' : 'owner_name';
    $relCol      = $hasRel      ? 'relative_path' : 'file_path';
    $detectedCol = $hasDetected ? 'detected_at'   : 'created_at';

    $risk    = isset($_GET['risk'])    ? $_GET['risk']           : '';
    $account = isset($_GET['account']) ? $_GET['account']        : '';
    $status  = isset($_GET['status'])  ? $_GET['status']         : '';
    $q       = isset($_GET['q'])       ? trim($_GET['q'])         : '';

    $where  = [];
    $params = [];

    if (!is_admin()) {
        $accounts = user_accounts();
        if (empty($accounts)) {
            $where[] = "1 = 0";
        } else {
            $placeholders = implode(',', array_fill(0, count($accounts), '?'));
            $where[]      = "$accountCol IN ($placeholders)";
            foreach ($accounts as $a) $params[] = $a;
        }
        $account = implode(', ', $accounts);
    } else if ($account !== '') {
        $where[]  = "$accountCol = ?";
        $params[] = $account;
    }

    if ($risk !== '')                     { $where[] = "risk = ?";          $params[] = $risk; }
    if ($hasAction && $status !== '')     { $where[] = "action_status = ?"; $params[] = $status; }
    if ($q !== '') {
        $where[]  = "(file_path LIKE ? OR file_name LIKE ? OR rule_name LIKE ?)";
        $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%";
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $statsStmt = $pdo->prepare("SELECT risk, COUNT(*) total FROM findings $whereSql GROUP BY risk");
    $statsStmt->execute($params);
    $stats = $statsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $actionStats = [];
    if ($hasAction) {
        $as = $pdo->prepare("SELECT action_status, COUNT(*) total FROM findings $whereSql GROUP BY action_status");
        $as->execute($params);
        $actionStats = $as->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    if (is_admin()) {
        $accounts = $pdo->query("
            SELECT $accountCol AS account_name, COUNT(*) total
            FROM findings
            WHERE $accountCol IS NOT NULL AND $accountCol != ''
            GROUP BY $accountCol
            ORDER BY total DESC
        ")->fetchAll();
    } else {
        $accounts = [['account_name' => $user['account_name'], 'total' => 0]];
    }

    $stmt = $pdo->prepare("
        SELECT
            id, scan_id, rule_name, risk,
            $accountCol AS account_name,
            owner_name, group_name, perms, file_size,
            file_name,
            " . ($hasExt ? "file_ext" : "'' AS file_ext") . ",
            file_path,
            $relCol AS relative_path,
            mtime,
            " . ($hasCtime ? "ctime"       : "NULL AS ctime") . ",
            " . ($hasBirth ? "birth_time"  : "NULL AS birth_time") . ",
            $detectedCol AS detected_at,
            " . ($hasSourceGuess ? "source_guess" : "'' AS source_guess") . ",
            " . ($hasSourceType  ? "source_type"  : "'' AS source_type") . ",
            " . ($hasAction      ? "action_status" : "'new' AS action_status") . ",
            " . ($hasAction      ? "action_note"   : "'' AS action_note") . ",
            sha256,
            created_at
        FROM findings
        $whereSql
        ORDER BY id DESC
        LIMIT 300
    ");
    $stmt->execute($params);
    $findings = $stmt->fetchAll();

    $lastScan = $pdo->query("SELECT * FROM scans ORDER BY id DESC LIMIT 1")->fetch();

} catch (Throwable $e) {
    http_response_code(500);
    echo '<!doctype html><html><head><meta charset="utf-8"><title>8Core Scanner greška</title></head><body>';
    echo '<h2>8Core Scanner - PHP greška</h2>';
    echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    echo '<p>Pokreni <a href="install/">installer</a> ili <a href="install/migrate.php">migrate.php</a>.</p>';
    echo '</body></html>';
    exit;
}
?>
<!doctype html>
<html lang="hr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>8Core Scanner</title>
<link rel="stylesheet" href="assets/css/scanner.css">
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
    <a class="sidebar-link active" href="index.php">
      <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
      Dashboard
    </a>
    <a class="sidebar-link" href="scan.php">
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
        <div class="user-role"><?= h($user['role']) ?><?php if (!is_admin()): $accs = user_accounts(); if (!empty($accs)): ?> &middot; <?= h(implode(', ', $accs)) ?><?php endif; endif; ?></div>
      </div>
    </div>
  </div>
</aside>

<!-- MAIN -->
<div class="main">
  <div class="topbar">
    <div class="topbar-title">Nalazi</div>
    <div class="topbar-meta">
      <?php if ($lastScan): ?>
        <span class="scan-dot <?= $lastScan['status'] === 'RUNNING' ? 'running' : '' ?>"></span>
        Zadnji scan: <?= h($lastScan['started_at']) ?>
        &nbsp;&middot;&nbsp; <?= h($lastScan['status']) ?>
        &nbsp;&middot;&nbsp; <?= (int)$lastScan['files_found'] ?> nalaza
      <?php else: ?>
        <span class="scan-dot" style="background:#94a3b8"></span>
        Nema podataka o scanu
      <?php endif; ?>
      &nbsp;&nbsp;
      <?php if (is_admin()): ?>
      <a href="scan.php" class="btn btn-primary btn-sm" style="font-size:12px;padding:4px 12px;">
        <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-1px;margin-right:4px;"><polygon points="5 3 19 12 5 21 5 3"/></svg>Pokreni scan
      </a>
      &nbsp;
      <?php endif; ?>
      <a href="logout.php" style="color:var(--text-muted);font-size:12px;">Odjava</a>
    </div>
  </div>

  <div class="content">

    <?php if (!$hasAction): ?>
      <div class="notice">Baza nema action stupce. Otvori <a href="install/migrate.php">migrate.php</a>.</div>
    <?php endif; ?>

    <!-- STAT CARDS -->
    <div class="cards">
      <div class="card card-critical">
        <div class="label">Critical</div>
        <div class="num"><?= (int)($stats['CRITICAL'] ?? 0) ?></div>
      </div>
      <div class="card card-high">
        <div class="label">High</div>
        <div class="num"><?= (int)($stats['HIGH'] ?? 0) ?></div>
      </div>
      <div class="card card-medium">
        <div class="label">Medium</div>
        <div class="num"><?= (int)($stats['MEDIUM'] ?? 0) ?></div>
      </div>
      <div class="card card-action">
        <div class="label">Ignored</div>
        <div class="num"><?= (int)($actionStats['ignore'] ?? 0) ?></div>
      </div>
      <div class="card card-action">
        <div class="label">Quarantine req.</div>
        <div class="num"><?= (int)($actionStats['quarantine_requested'] ?? 0) ?></div>
      </div>
      <div class="card card-action">
        <div class="label">Delete req.</div>
        <div class="num"><?= (int)($actionStats['delete_requested'] ?? 0) ?></div>
      </div>
    </div>

    <!-- FILTERI -->
    <form class="filters" method="get">
      <select name="risk">
        <option value="">Svi rizici</option>
        <?php foreach (['CRITICAL','HIGH','MEDIUM','LOW'] as $r): ?>
          <option value="<?= h($r) ?>" <?= $risk === $r ? 'selected' : '' ?>><?= h($r) ?></option>
        <?php endforeach; ?>
      </select>

      <?php if (is_admin()): ?>
      <select name="account">
        <option value="">Svi accounti</option>
        <?php foreach ($accounts as $a): ?>
          <option value="<?= h($a['account_name']) ?>" <?= $account === $a['account_name'] ? 'selected' : '' ?>>
            <?= h($a['account_name']) ?> (<?= (int)$a['total'] ?>)
          </option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>

      <select name="status">
        <option value="">Svi statusi</option>
        <?php foreach (['new','checked','ignore','quarantine_requested','delete_requested'] as $s): ?>
          <option value="<?= h($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= h($s) ?></option>
        <?php endforeach; ?>
      </select>

      <input type="text" name="q" value="<?= h($q) ?>" placeholder="Pretraga file / path / rule">
      <button type="submit" class="btn btn-primary btn-sm">Filtriraj</button>
      <a href="index.php" class="btn btn-ghost btn-sm">Reset</a>
    </form>

    <!-- BULK BAR -->
    <div class="bulk-bar" id="bulk-bar">
      <span class="bulk-count" id="bulk-count">0 odabrano</span>
      <select id="bulk-action">
        <option value="">-- Odaberi akciju --</option>
        <option value="checked">Checked</option>
        <option value="ignore">Ignore</option>
        <option value="quarantine_requested">Quarantine</option>
        <option value="delete_requested">Delete</option>
        <option value="new">Reset na new</option>
      </select>
      <button type="button" class="btn btn-primary btn-sm" onclick="submitBulk()">Primijeni na odabrane</button>
      <button type="button" class="btn btn-ghost btn-sm" onclick="clearSelection()">Odznači sve</button>
    </div>

    <!-- TABLICA NALAZA -->
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th style="width:32px">
              <input type="checkbox" id="chk-all" title="Odaberi sve" onclick="toggleAll(this)">
            </th>
            <th style="width:20px"></th>
            <th>Risk</th>
            <th>Status</th>
            <th>Account</th>
            <th>File</th>
            <th>Rule</th>
            <th>Source</th>
            <th>Akcije</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($findings as $f): ?>
          <tr class="data-row" data-id="<?= (int)$f['id'] ?>">
            <td>
              <input type="checkbox" class="row-chk" name="ids[]" value="<?= (int)$f['id'] ?>"
                     onclick="event.stopPropagation(); updateBulkBar()">
            </td>
            <td onclick="toggleRow(<?= (int)$f['id'] ?>)">
              <span class="expand-toggle">
                <svg viewBox="0 0 12 12"><line x1="6" y1="1" x2="6" y2="11"/><line x1="1" y1="6" x2="11" y2="6"/></svg>
              </span>
            </td>
            <td onclick="toggleRow(<?= (int)$f['id'] ?>)">
              <span class="badge <?= risk_class($f['risk']) ?>"><?= h($f['risk']) ?></span>
            </td>
            <td onclick="toggleRow(<?= (int)$f['id'] ?>)">
              <span class="status-pill <?= action_class($f['action_status']) ?>"><?= h($f['action_status']) ?></span>
            </td>
            <td onclick="toggleRow(<?= (int)$f['id'] ?>)">
              <?= h($f['account_name']) ?>
              <div class="small">owner: <?= h($f['owner_name']) ?></div>
            </td>
            <td onclick="toggleRow(<?= (int)$f['id'] ?>)">
              <b><?= h($f['file_name']) ?></b>
              <?php if ($f['file_ext']): ?><div class="small">.<?= h($f['file_ext']) ?></div><?php endif; ?>
            </td>
            <td onclick="toggleRow(<?= (int)$f['id'] ?>)"><?= h($f['rule_name']) ?></td>
            <td onclick="toggleRow(<?= (int)$f['id'] ?>)">
              <?= h($f['source_guess']) ?>
              <div class="small"><?= h($f['source_type']) ?></div>
            </td>
            <td>
              <div class="action-drop" id="drop-<?= (int)$f['id'] ?>">
                <button class="action-drop-btn" type="button"
                        onclick="toggleDrop('drop-<?= (int)$f['id'] ?>', event)">
                  Akcija
                  <svg class="chevron" viewBox="0 0 12 12"><polyline points="2,4 6,8 10,4"/></svg>
                </button>
                <div class="action-menu">
                  <?php foreach ([
                    'checked'              => ['Checked',    'act-checked'],
                    'ignore'               => ['Ignore',     'act-ignore'],
                    'quarantine_requested' => ['Quarantine', 'act-quarantine'],
                    'delete_requested'     => ['Delete',     'act-delete'],
                  ] as $act => $meta): ?>
                  <form method="post" action="action.php">
                    <input type="hidden" name="id"     value="<?= (int)$f['id'] ?>">
                    <input type="hidden" name="action" value="<?= h($act) ?>">
                    <button type="submit" class="action-menu-item <?= h($meta[1]) ?>">
                      <span class="dot"></span><?= h($meta[0]) ?>
                    </button>
                  </form>
                  <?php endforeach; ?>
                </div>
              </div>
            </td>
          </tr>
          <!-- DETALJI -->
          <tr class="detail-row hidden" id="detail-<?= (int)$f['id'] ?>">
            <td colspan="9">
              <div class="detail-panel">
                <div class="detail-item">
                  <span class="detail-label">Full path</span>
                  <span class="detail-value mono"><?= h($f['file_path']) ?></span>
                </div>
                <div class="detail-item">
                  <span class="detail-label">Relative path</span>
                  <span class="detail-value mono"><?= h($f['relative_path']) ?></span>
                </div>
                <div class="detail-item">
                  <span class="detail-label">mtime</span>
                  <span class="detail-value"><?= h($f['mtime']) ?></span>
                </div>
                <div class="detail-item">
                  <span class="detail-label">ctime</span>
                  <span class="detail-value"><?= h($f['ctime']) ?></span>
                </div>
                <div class="detail-item">
                  <span class="detail-label">birth</span>
                  <span class="detail-value"><?= h($f['birth_time']) ?></span>
                </div>
                <div class="detail-item">
                  <span class="detail-label">detected_at</span>
                  <span class="detail-value"><?= h($f['detected_at']) ?></span>
                </div>
                <div class="detail-item">
                  <span class="detail-label">Dozvole</span>
                  <span class="detail-value mono"><?= h($f['perms']) ?></span>
                </div>
                <div class="detail-item">
                  <span class="detail-label">Veličina</span>
                  <span class="detail-value"><?= number_format((int)$f['file_size']) ?> B</span>
                </div>
                <div class="detail-item">
                  <span class="detail-label">Grupa</span>
                  <span class="detail-value"><?= h($f['group_name']) ?></span>
                </div>
                <?php if ($f['sha256']): ?>
                <div class="detail-item" style="grid-column:1/-1">
                  <span class="detail-label">SHA-256</span>
                  <span class="detail-value mono"><?= h($f['sha256']) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($f['action_note'])): ?>
                <div class="detail-item" style="grid-column:1/-1">
                  <span class="detail-label">Napomena</span>
                  <span class="detail-value"><?= h($f['action_note']) ?></span>
                </div>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

  </div><!-- .content -->
</div><!-- .main -->
</div><!-- .layout -->

<script src="assets/js/scanner.js"></script>
</body>
</html>
