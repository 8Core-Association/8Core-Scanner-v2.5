<?php
/**
 * 8Core Scanner v2.5.3 — Admin: Karantena
 * (c) 2026 Tomislav Galić <tomislav@8core.hr>
 * Web: https://8core.hr
 * Kontakt: info@8core.hr | Tel: +385 099 851 0717
 * Sva prava pridržana. Ovaj softver je vlasnički i zabranjeno ga je
 * distribuirati ili mijenjati bez izričitog dopuštenja autora.
 */
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require_admin();

$user    = current_user();
$qbase   = rtrim($config['quarantine_path'] ?? '/home/8core_quarantine', '/') . '/';
$message = '';
$msgType = 'ok';

// ── HELPER: sanitize IDs from array or comma-string ───────────────────────────
function parse_ids($raw): array {
    if (is_array($raw)) {
        $ids = $raw;
    } else {
        $ids = preg_split('/[\s,]+/', (string)$raw);
    }
    $out = [];
    foreach ($ids as $v) {
        $i = (int)$v;
        if ($i > 0) $out[] = $i;
    }
    return array_unique($out);
}

function make_in_clause(array $ids): string {
    return implode(',', array_map('intval', $ids));
}

// ── POST AKCIJE ───────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $isBulk = isset($_POST['bulk_action']);

    // ── BULK AKCIJA ───────────────────────────────────────────────────────────
    if ($isBulk) {
        $bulkAction = $_POST['bulk_action'] ?? '';
        $rawIds     = $_POST['ids'] ?? [];
        $ids        = parse_ids($rawIds);

        if (empty($ids)) {
            $message = 'Nije odabran niti jedan nalaz.';
            $msgType = 'error';
        } elseif (!in_array($bulkAction, ['restore_requested', 'purge_requested', 'ignore_hash'], true)) {
            $message = 'Neispravna bulk akcija.';
            $msgType = 'error';
        } else {
            $inClause = make_in_clause($ids);
            $sent = 0; $skipped = 0; $hashAdded = 0; $hashSkipped = 0;

            if ($bulkAction === 'restore_requested') {
                $stmt = $pdo->prepare("
                    UPDATE findings
                    SET action_status='restore_requested', action_error=NULL,
                        action_at=NOW(), action_by=?
                    WHERE id IN ($inClause) AND action_status='quarantined'
                ");
                $stmt->execute([$user['username']]);
                $sent    = $stmt->rowCount();
                $skipped = count($ids) - $sent;
                $message = "Restore zahtjev poslan za $sent nalaz/a." . ($skipped ? " Preskočeno: $skipped (pogrešan status)." : '');

            } elseif ($bulkAction === 'purge_requested') {
                $stmt = $pdo->prepare("
                    UPDATE findings
                    SET action_status='purge_requested', action_error=NULL,
                        action_at=NOW(), action_by=?
                    WHERE id IN ($inClause) AND action_status='quarantined'
                ");
                $stmt->execute([$user['username']]);
                $sent    = $stmt->rowCount();
                $skipped = count($ids) - $sent;
                $message = "Purge zahtjev poslan za $sent nalaz/a." . ($skipped ? " Preskočeno: $skipped (pogrešan status)." : '');

            } elseif ($bulkAction === 'ignore_hash') {
                $rows = $pdo->prepare("
                    SELECT id, quarantine_path FROM findings
                    WHERE id IN ($inClause) AND action_status='quarantined'
                ");
                $rows->execute();
                $rows = $rows->fetchAll();

                $skipped = count($ids) - count($rows);

                $dupCheck = $pdo->prepare("SELECT COUNT(*) FROM scanner_ignore_list WHERE category='hash' AND value=?");
                $insStmt  = $pdo->prepare("
                    INSERT INTO scanner_ignore_list (category, value, note, created_by)
                    VALUES ('hash', ?, ?, ?)
                ");

                foreach ($rows as $r) {
                    $qp = $r['quarantine_path'] ?? '';
                    if (empty($qp) || strpos($qp, $qbase) !== 0 || !file_exists($qp)) {
                        $skipped++;
                        continue;
                    }
                    $sha = hash_file('sha256', $qp);
                    if (!$sha) { $skipped++; continue; }

                    $dupCheck->execute([$sha]);
                    if ((int)$dupCheck->fetchColumn() > 0) {
                        $hashSkipped++;
                        continue;
                    }
                    $insStmt->execute([
                        $sha,
                        'Added from quarantine bulk ignore, finding ID ' . (int)$r['id'],
                        $user['username'],
                    ]);
                    $hashAdded++;
                }

                $msg = "Ignore hash: dodano $hashAdded hash/eva.";
                if ($hashSkipped) $msg .= " Duplikati preskočeni: $hashSkipped.";
                if ($skipped)     $msg .= " Preskočeno zbog statusa/path/fajla: $skipped.";
                $message = $msg;
            }
        }

        if (empty($message)) { $message = 'Neispravna akcija.'; $msgType = 'error'; }

        $_SESSION['quar_flash']      = $message;
        $_SESSION['quar_flash_type'] = $msgType;
        header('Location: quarantine.php?' . http_build_query([
            'account' => $_POST['f_account'] ?? '',
            'risk'    => $_POST['f_risk']    ?? '',
            'rule'    => $_POST['f_rule']    ?? '',
            'q'       => $_POST['f_q']       ?? '',
            'status'  => $_POST['f_status']  ?? 'quarantined',
            'ids'     => $_POST['f_ids']     ?? '',
        ]));
        exit;
    }

    // ── SINGLE AKCIJA ─────────────────────────────────────────────────────────
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    if ($id > 0 && in_array($action, ['restore_requested', 'purge_requested', 'ignore_hash'], true)) {
        $finding = $pdo->prepare("SELECT * FROM findings WHERE id = ? LIMIT 1");
        $finding->execute([$id]);
        $f = $finding->fetch();

        if (!$f) {
            $message = 'Nalaz nije pronađen.';
            $msgType = 'error';
        } else {
            $qpath = $f['quarantine_path'] ?? '';

            if ($action === 'restore_requested') {
                if ($f['action_status'] !== 'quarantined') {
                    $message = 'Nalaz nije u statusu quarantined.';
                    $msgType = 'error';
                } elseif (empty($qpath)) {
                    $message = 'quarantine_path je prazan — ne može se obnoviti.';
                    $msgType = 'error';
                } else {
                    $pdo->prepare("
                        UPDATE findings
                        SET action_status='restore_requested', action_error=NULL,
                            action_at=NOW(), action_by=?
                        WHERE id=?
                    ")->execute([$user['username'], $id]);
                    $pdo->prepare("
                        INSERT INTO scanner_actions (finding_id, action, note, created_at, created_by)
                        VALUES (?, 'restore_requested', NULL, NOW(), ?)
                    ")->execute([$id, $user['username']]);
                    $message = "Zahtjev za obnavljanje (ID=$id) poslan workeru.";
                }

            } elseif ($action === 'purge_requested') {
                if (!in_array($f['action_status'], ['quarantined', 'restore_failed'], true)) {
                    $message = 'Nalaz nije u statusu koji dozvoljava purge.';
                    $msgType = 'error';
                } elseif (empty($qpath)) {
                    $message = 'quarantine_path je prazan.';
                    $msgType = 'error';
                } elseif (strpos($qpath, $qbase) !== 0) {
                    $message = 'Nesigurna putanja karantene — purge odbijen.';
                    $msgType = 'error';
                } else {
                    $pdo->prepare("
                        UPDATE findings
                        SET action_status='purge_requested', action_error=NULL,
                            action_at=NOW(), action_by=?
                        WHERE id=?
                    ")->execute([$user['username'], $id]);
                    $pdo->prepare("
                        INSERT INTO scanner_actions (finding_id, action, note, created_at, created_by)
                        VALUES (?, 'purge_requested', NULL, NOW(), ?)
                    ")->execute([$id, $user['username']]);
                    $message = "Zahtjev za trajno brisanje (ID=$id) poslan workeru.";
                }

            } elseif ($action === 'ignore_hash') {
                if (empty($qpath)) {
                    $message = 'quarantine_path je prazan.';
                    $msgType = 'error';
                } elseif (strpos($qpath, $qbase) !== 0) {
                    $message = 'Nesigurna putanja karantene — odbijen.';
                    $msgType = 'error';
                } elseif (!file_exists($qpath)) {
                    $message = 'Fajl u karanteni nije pronađen na disku.';
                    $msgType = 'error';
                } else {
                    $sha256 = hash_file('sha256', $qpath);
                    if (!$sha256) {
                        $message = 'Nije moguće izračunati SHA256.';
                        $msgType = 'error';
                    } else {
                        $dup = $pdo->prepare("SELECT COUNT(*) FROM scanner_ignore_list WHERE category='hash' AND value=?");
                        $dup->execute([$sha256]);
                        if ((int)$dup->fetchColumn() > 0) {
                            $message = "Hash već postoji u ignore listi ($sha256).";
                        } else {
                            $pdo->prepare("
                                INSERT INTO scanner_ignore_list (category, value, note, created_by)
                                VALUES ('hash', ?, ?, ?)
                            ")->execute([
                                $sha256,
                                'Dodano iz karantene (ID=' . $id . ')',
                                $user['username'],
                            ]);
                            $message = "Hash dodan u ignore listu: $sha256";
                        }
                    }
                }
            }
        }
    }

    if (empty($message)) { $message = 'Neispravna akcija.'; $msgType = 'error'; }

    $_SESSION['quar_flash']      = $message;
    $_SESSION['quar_flash_type'] = $msgType;
    header('Location: quarantine.php?' . http_build_query([
        'account' => $_POST['f_account'] ?? '',
        'risk'    => $_POST['f_risk']    ?? '',
        'rule'    => $_POST['f_rule']    ?? '',
        'q'       => $_POST['f_q']       ?? '',
        'status'  => $_POST['f_status']  ?? 'quarantined',
        'ids'     => $_POST['f_ids']     ?? '',
    ]));
    exit;
}

// ── FLASH ─────────────────────────────────────────────────────────────────────

if (!empty($_SESSION['quar_flash'])) {
    $message = $_SESSION['quar_flash'];
    $msgType = $_SESSION['quar_flash_type'] ?? 'ok';
    unset($_SESSION['quar_flash'], $_SESSION['quar_flash_type']);
}

// ── FILTERI ───────────────────────────────────────────────────────────────────

$fAccount = trim($_GET['account'] ?? '');
$fRisk    = trim($_GET['risk']    ?? '');
$fRule    = trim($_GET['rule']    ?? '');
$fQ       = trim($_GET['q']       ?? '');
$fStatus  = trim($_GET['status']  ?? 'quarantined');
$fIdsRaw  = trim($_GET['ids']     ?? '');
$fIds     = parse_ids($fIdsRaw);

$allowedStatuses = ['quarantined', 'restore_requested', 'restore_failed', 'purge_requested', 'purged', 'restored'];
if (!in_array($fStatus, $allowedStatuses, true)) $fStatus = 'quarantined';

$where  = [];
$params = [];

if ($fStatus !== '') {
    $where[]  = 'action_status = ?';
    $params[] = $fStatus;
}
if ($fAccount !== '') {
    $where[]  = 'account_name = ?';
    $params[] = $fAccount;
}
if ($fRisk !== '') {
    $where[]  = 'risk = ?';
    $params[] = $fRisk;
}
if ($fRule !== '') {
    $where[]  = 'rule_name = ?';
    $params[] = $fRule;
}
if ($fQ !== '') {
    $where[]  = '(file_path LIKE ? OR quarantine_path LIKE ?)';
    $params[] = "%$fQ%";
    $params[] = "%$fQ%";
}
if (!empty($fIds)) {
    $where[] = 'id IN (' . make_in_clause($fIds) . ')';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$findings = $pdo->prepare("
    SELECT id, account_name, risk, rule_name, file_path, quarantine_path,
           action_status, action_error, detected_at, sha256
    FROM findings
    $whereSql
    ORDER BY id DESC
    LIMIT 500
");
$findings->execute($params);
$findings = $findings->fetchAll();

// Dropdown data
$accounts = $pdo->query("
    SELECT DISTINCT account_name FROM findings
    WHERE account_name IS NOT NULL AND account_name != ''
    ORDER BY account_name
")->fetchAll(PDO::FETCH_COLUMN);

$rules = $pdo->query("
    SELECT DISTINCT rule_name FROM findings
    WHERE rule_name IS NOT NULL AND rule_name != ''
    ORDER BY rule_name
")->fetchAll(PDO::FETCH_COLUMN);

// ── PREVIEW ───────────────────────────────────────────────────────────────────

$preview   = null;
$previewId = (int)($_GET['preview_id'] ?? 0);

if ($previewId > 0) {
    $pf = $pdo->prepare("SELECT id, file_path, quarantine_path, account_name FROM findings WHERE id = ? LIMIT 1");
    $pf->execute([$previewId]);
    $pf = $pf->fetch();

    if ($pf && !empty($pf['quarantine_path'])) {
        $qpath = $pf['quarantine_path'];

        if (strpos($qpath, $qbase) !== 0) {
            $preview = ['error' => 'Nesigurna putanja — preview odbijen.'];
        } elseif (!file_exists($qpath)) {
            $preview = ['error' => 'Fajl nije pronađen na disku.'];
        } else {
            $sha256   = hash_file('sha256', $qpath);
            $rawSize  = filesize($qpath);
            $maxRead  = 200 * 1024;
            $raw      = file_get_contents($qpath, false, null, 0, $maxRead);
            $sample   = substr($raw, 0, 8192);
            $isBinary = strpos($sample, "\0") !== false;

            $preview = [
                'id'        => $pf['id'],
                'file_path' => $pf['file_path'],
                'qpath'     => $qpath,
                'sha256'    => $sha256,
                'size'      => $rawSize,
                'binary'    => $isBinary,
                'truncated' => ($rawSize > $maxRead),
                'content'   => $isBinary ? null : $raw,
            ];
        }
    } else {
        $preview = ['error' => 'Nalaz nije pronađen ili quarantine_path je prazan.'];
    }
}

$backParams = http_build_query([
    'account' => $fAccount,
    'risk'    => $fRisk,
    'rule'    => $fRule,
    'q'       => $fQ,
    'status'  => $fStatus,
    'ids'     => $fIdsRaw,
]);
?>
<!doctype html>
<html lang="hr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>8Core Scanner – Karantena</title>
<link rel="stylesheet" href="../assets/css/scanner.css">
<style>
/* ── Preview overlay ────────────────────────────────────────────────────── */
.quar-preview-overlay {
  position: fixed; inset: 0;
  background: rgba(15,23,42,.72);
  z-index: 900;
  display: flex;
  align-items: flex-start;
  justify-content: center;
  padding: 40px 20px;
  overflow-y: auto;
}
.quar-preview-box {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  box-shadow: var(--shadow-md);
  width: 100%;
  max-width: 900px;
  padding: 24px;
}
.quar-preview-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 16px;
  gap: 16px;
}
.quar-preview-title { font-size: 14px; font-weight: 600; word-break: break-all; }
.quar-preview-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 12px;
  font-size: 12px;
  color: var(--text-muted);
}
.quar-preview-meta span {
  background: var(--surface2);
  border: 1px solid var(--border);
  border-radius: 5px;
  padding: 3px 8px;
}
.quar-preview-code {
  background: #0f172a;
  color: #e2e8f0;
  border-radius: 7px;
  padding: 14px 16px;
  font-family: 'JetBrains Mono', 'Fira Code', monospace;
  font-size: 12px;
  line-height: 1.6;
  max-height: 480px;
  overflow-y: auto;
  white-space: pre-wrap;
  word-break: break-all;
}
.quar-preview-binary {
  padding: 24px;
  text-align: center;
  color: var(--text-muted);
  font-size: 13px;
  background: var(--surface2);
  border-radius: 7px;
  border: 1px dashed var(--border);
}
.quar-sha { font-family: monospace; font-size: 11px; word-break: break-all; }
/* ── Bulk bar ────────────────────────────────────────────────────────────── */
.bulk-bar {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  background: var(--bg2);
  border: 1px solid var(--border-dark);
  border-radius: 8px;
  padding: 10px 14px;
  margin-bottom: 12px;
  color: var(--text-inv);
  font-size: 13px;
}
.bulk-bar select { font-size: 13px; }
.bulk-bar-count { font-weight: 600; min-width: 90px; }
/* ── Action buttons ─────────────────────────────────────────────────────── */
.action-btns { display: flex; gap: 6px; flex-wrap: wrap; }
.btn-purge  { background: #7f1d1d; color: #fff; border-color: #7f1d1d; }
.btn-purge:hover { background: #991b1b; border-color: #991b1b; }
.btn-restore-q { background: #065f46; color: #fff; border-color: #065f46; }
.btn-restore-q:hover { background: #047857; border-color: #047857; }
/* ── Counter ─────────────────────────────────────────────────────────────── */
.quar-counter {
  font-size: 12px;
  color: var(--text-muted);
  margin-bottom: 8px;
}
</style>
</head>
<body>
<div class="layout">

<!-- SIDEBAR -->
<?php include __DIR__ . '/sidebar.php'; ?>

<!-- MAIN -->
<div class="main">
  <div class="topbar">
    <div class="topbar-title">Karantena</div>
    <div class="topbar-meta">
      <a href="../logout.php" style="color:var(--text-muted);font-size:12px;">Odjava</a>
    </div>
  </div>

  <div class="content">

    <?php if ($message): ?>
      <div class="notice <?= h($msgType) ?>"><?= h($message) ?></div>
    <?php endif; ?>

    <!-- FILTERI -->
    <form class="filters" method="get" id="filter-form">
      <select name="status">
        <?php foreach ($allowedStatuses as $s): ?>
          <option value="<?= h($s) ?>" <?= $fStatus === $s ? 'selected' : '' ?>><?= h($s) ?></option>
        <?php endforeach; ?>
      </select>

      <select name="account">
        <option value="">Svi accounti</option>
        <?php foreach ($accounts as $a): ?>
          <option value="<?= h($a) ?>" <?= $fAccount === $a ? 'selected' : '' ?>><?= h($a) ?></option>
        <?php endforeach; ?>
      </select>

      <select name="risk">
        <option value="">Svi rizici</option>
        <?php foreach (['CRITICAL', 'HIGH', 'MEDIUM', 'LOW'] as $r): ?>
          <option value="<?= h($r) ?>" <?= $fRisk === $r ? 'selected' : '' ?>><?= h($r) ?></option>
        <?php endforeach; ?>
      </select>

      <select name="rule">
        <option value="">Sva pravila</option>
        <?php foreach ($rules as $rl): ?>
          <option value="<?= h($rl) ?>" <?= $fRule === $rl ? 'selected' : '' ?>><?= h($rl) ?></option>
        <?php endforeach; ?>
      </select>

      <input type="text" name="q" value="<?= h($fQ) ?>" placeholder="Pretraži path...">
      <input type="text" name="ids" value="<?= h($fIdsRaw) ?>" placeholder="ID-evi: 1,2,3" style="width:140px;">
      <button type="submit" class="btn btn-primary btn-sm">Filtriraj</button>
      <a href="quarantine.php" class="btn btn-ghost btn-sm">Reset</a>
    </form>

    <!-- COUNTER -->
    <?php $quarantinedCount = count(array_filter($findings, fn($f) => $f['action_status'] === 'quarantined')); ?>
    <div class="quar-counter">
      Prikazano <strong><?= count($findings) ?></strong> nalaza.
      <?php if ($quarantinedCount > 0): ?>
        Označeno <strong id="selected-count">0</strong> od <?= $quarantinedCount ?> quarantined.
      <?php endif; ?>
    </div>

    <!-- BULK BAR -->
    <?php if ($quarantinedCount > 0): ?>
    <div class="bulk-bar">
      <span class="bulk-bar-count">Bulk akcija:</span>
      <select id="bulk-action-select">
        <option value="">-- Odaberi --</option>
        <option value="restore_requested">Vrati označene</option>
        <option value="purge_requested">Trajno obriši označene iz karantene</option>
        <option value="ignore_hash">Dodaj hash u ignore za označene</option>
      </select>
      <button type="button" class="btn btn-primary btn-sm" onclick="submitBulkAction()">Primijeni</button>
      <button type="button" class="btn btn-ghost btn-sm" onclick="selectAllVisible(false)">Odznači sve</button>
    </div>

    <!-- Skrivena bulk forma koja se puni JS-om -->
    <form id="bulk-form" method="post" style="display:none;">
      <?= csrf_field() ?>
      <input type="hidden" name="bulk_action" id="bulk-action-hidden">
      <input type="hidden" name="f_account" value="<?= h($fAccount) ?>">
      <input type="hidden" name="f_risk"    value="<?= h($fRisk) ?>">
      <input type="hidden" name="f_rule"    value="<?= h($fRule) ?>">
      <input type="hidden" name="f_q"       value="<?= h($fQ) ?>">
      <input type="hidden" name="f_status"  value="<?= h($fStatus) ?>">
      <input type="hidden" name="f_ids"     value="<?= h($fIdsRaw) ?>">
      <div id="bulk-ids-container"></div>
    </form>
    <?php endif; ?>

    <!-- TABLICA -->
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <?php if ($quarantinedCount > 0): ?>
            <th style="width:32px;">
              <input type="checkbox" id="chk-all" title="Odaberi sve quarantined"
                     onchange="selectAllVisible(this.checked)">
            </th>
            <?php else: ?>
            <th style="width:32px;"></th>
            <?php endif; ?>
            <th style="width:55px;">#</th>
            <th>Account</th>
            <th>Risk</th>
            <th>Pravilo</th>
            <th>Originalni path</th>
            <th>Quarantine path</th>
            <th>Status</th>
            <th>detected_at</th>
            <th>Akcije</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($findings)): ?>
          <tr><td colspan="10" class="rules-empty">Nema nalaza za odabrane filtre.</td></tr>
        <?php endif; ?>
        <?php foreach ($findings as $f): ?>
          <?php $isQuarantined = ($f['action_status'] === 'quarantined'); ?>
          <tr>
            <td>
              <?php if ($isQuarantined): ?>
              <input type="checkbox" class="quar-chk" value="<?= (int)$f['id'] ?>"
                     onchange="updateSelectedCount()">
              <?php endif; ?>
            </td>
            <td class="small mono"><?= (int)$f['id'] ?></td>
            <td><?= h($f['account_name']) ?></td>
            <td><span class="badge <?= risk_class($f['risk']) ?>"><?= h($f['risk']) ?></span></td>
            <td class="small"><?= h($f['rule_name']) ?></td>
            <td class="small mono" style="max-width:200px;word-break:break-all;"><?= h($f['file_path']) ?></td>
            <td class="small mono" style="max-width:200px;word-break:break-all;">
              <?= h($f['quarantine_path']) ?>
              <?php if (!empty($f['action_error'])): ?>
                <div style="color:#b91c1c;font-size:11px;margin-top:2px;"><?= h($f['action_error']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <span class="status-pill <?= action_class($f['action_status']) ?>"><?= h($f['action_status']) ?></span>
            </td>
            <td class="small"><?= h(substr($f['detected_at'], 0, 16)) ?></td>
            <td>
              <div class="action-btns">

                <?php if ($isQuarantined): ?>

                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action"    value="restore_requested">
                    <input type="hidden" name="id"        value="<?= (int)$f['id'] ?>">
                    <input type="hidden" name="f_account" value="<?= h($fAccount) ?>">
                    <input type="hidden" name="f_risk"    value="<?= h($fRisk) ?>">
                    <input type="hidden" name="f_rule"    value="<?= h($fRule) ?>">
                    <input type="hidden" name="f_q"       value="<?= h($fQ) ?>">
                    <input type="hidden" name="f_status"  value="<?= h($fStatus) ?>">
                    <input type="hidden" name="f_ids"     value="<?= h($fIdsRaw) ?>">
                    <button type="submit" class="btn btn-restore-q btn-sm"
                            onclick="return confirm('Obnoviti fajl ID=<?= (int)$f['id'] ?>?')">
                      Vrati
                    </button>
                  </form>

                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action"    value="purge_requested">
                    <input type="hidden" name="id"        value="<?= (int)$f['id'] ?>">
                    <input type="hidden" name="f_account" value="<?= h($fAccount) ?>">
                    <input type="hidden" name="f_risk"    value="<?= h($fRisk) ?>">
                    <input type="hidden" name="f_rule"    value="<?= h($fRule) ?>">
                    <input type="hidden" name="f_q"       value="<?= h($fQ) ?>">
                    <input type="hidden" name="f_status"  value="<?= h($fStatus) ?>">
                    <input type="hidden" name="f_ids"     value="<?= h($fIdsRaw) ?>">
                    <button type="submit" class="btn btn-purge btn-sm"
                            onclick="return confirm('Trajno obrisati fajl ID=<?= (int)$f['id'] ?> iz karantene?')">
                      Obriši
                    </button>
                  </form>

                  <?php if (!empty($f['quarantine_path'])): ?>
                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action"    value="ignore_hash">
                    <input type="hidden" name="id"        value="<?= (int)$f['id'] ?>">
                    <input type="hidden" name="f_account" value="<?= h($fAccount) ?>">
                    <input type="hidden" name="f_risk"    value="<?= h($fRisk) ?>">
                    <input type="hidden" name="f_rule"    value="<?= h($fRule) ?>">
                    <input type="hidden" name="f_q"       value="<?= h($fQ) ?>">
                    <input type="hidden" name="f_status"  value="<?= h($fStatus) ?>">
                    <input type="hidden" name="f_ids"     value="<?= h($fIdsRaw) ?>">
                    <button type="submit" class="btn btn-ghost btn-sm"
                            onclick="return confirm('Dodati SHA256 fajla ID=<?= (int)$f['id'] ?> u ignore listu?')">
                      Ignore hash
                    </button>
                  </form>

                  <a href="quarantine.php?<?= h(http_build_query([
                      'preview_id' => $f['id'],
                      'account'    => $fAccount,
                      'risk'       => $fRisk,
                      'rule'       => $fRule,
                      'q'          => $fQ,
                      'status'     => $fStatus,
                      'ids'        => $fIdsRaw,
                  ])) ?>" class="btn btn-ghost btn-sm">Sadržaj</a>
                  <?php endif; ?>

                <?php elseif ($f['action_status'] === 'restore_failed'): ?>

                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action"    value="purge_requested">
                    <input type="hidden" name="id"        value="<?= (int)$f['id'] ?>">
                    <input type="hidden" name="f_account" value="<?= h($fAccount) ?>">
                    <input type="hidden" name="f_risk"    value="<?= h($fRisk) ?>">
                    <input type="hidden" name="f_rule"    value="<?= h($fRule) ?>">
                    <input type="hidden" name="f_q"       value="<?= h($fQ) ?>">
                    <input type="hidden" name="f_status"  value="<?= h($fStatus) ?>">
                    <input type="hidden" name="f_ids"     value="<?= h($fIdsRaw) ?>">
                    <button type="submit" class="btn btn-purge btn-sm"
                            onclick="return confirm('Trajno obrisati iz karantene?')">
                      Obriši
                    </button>
                  </form>

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

<?php if ($preview !== null): ?>
<!-- PREVIEW OVERLAY -->
<?php
$closeUrl = 'quarantine.php?' . h($backParams);
?>
<div class="quar-preview-overlay"
     onclick="if(event.target===this)window.location='<?= $closeUrl ?>'">
  <div class="quar-preview-box">
    <div class="quar-preview-header">
      <div class="quar-preview-title">Preview fajla &nbsp;·&nbsp; ID <?= $previewId ?></div>
      <a href="<?= $closeUrl ?>" class="btn btn-ghost btn-sm">Zatvori &times;</a>
    </div>

    <?php if (isset($preview['error'])): ?>
      <div class="notice error"><?= h($preview['error']) ?></div>
    <?php else: ?>
      <div class="quar-preview-meta">
        <span><b>Original:</b> <?= h($preview['file_path']) ?></span>
        <span><b>Karantena:</b> <?= h($preview['qpath']) ?></span>
        <span><b>Veličina:</b> <?= number_format($preview['size']) ?> B<?= $preview['truncated'] ? ' (prikazano prvih 200 KB)' : '' ?></span>
      </div>
      <div class="quar-preview-meta">
        <span style="width:100%;"><b>SHA-256:</b>
          <span class="quar-sha"><?= h($preview['sha256']) ?></span>
        </span>
      </div>
      <?php if ($preview['binary']): ?>
        <div class="quar-preview-binary">
          <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor"
               stroke-width="2" style="vertical-align:-5px;margin-right:6px;">
            <circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>
          </svg>
          Binary file / preview disabled
        </div>
      <?php else: ?>
        <pre class="quar-preview-code"><?= h($preview['content']) ?></pre>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<script>
function updateSelectedCount() {
    var n = document.querySelectorAll('.quar-chk:checked').length;
    var el = document.getElementById('selected-count');
    if (el) el.textContent = n;
    var chkAll = document.getElementById('chk-all');
    if (chkAll) {
        var all = document.querySelectorAll('.quar-chk');
        chkAll.indeterminate = (n > 0 && n < all.length);
        chkAll.checked = (all.length > 0 && n === all.length);
    }
}

function selectAllVisible(checked) {
    document.querySelectorAll('.quar-chk').forEach(function(c) { c.checked = checked; });
    updateSelectedCount();
}

function submitBulkAction() {
    var action = document.getElementById('bulk-action-select').value;
    if (!action) { alert('Odaberi akciju iz padajućeg izbornika.'); return; }

    var checked = document.querySelectorAll('.quar-chk:checked');
    if (!checked.length) { alert('Nije odabran niti jedan nalaz.'); return; }

    if (action === 'purge_requested') {
        if (!confirm('Trajno obrisati označene fajlove iz karantene? Ova akcija je nepovratna.')) return;
    }

    document.getElementById('bulk-action-hidden').value = action;

    var container = document.getElementById('bulk-ids-container');
    container.innerHTML = '';
    checked.forEach(function(c) {
        var inp = document.createElement('input');
        inp.type  = 'hidden';
        inp.name  = 'ids[]';
        inp.value = c.value;
        container.appendChild(inp);
    });

    document.getElementById('bulk-form').submit();
}
</script>
<script src="../assets/js/scanner.js"></script>
</body>
</html>
