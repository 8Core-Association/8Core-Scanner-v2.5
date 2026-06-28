<?php
/**
 * 8Core Scanner v2.0 — Admin: Ignore lista
 * (c) 2026 Tomislav Galić <tomislav@8core.hr>
 * Web: https://8core.hr
 * Kontakt: info@8core.hr | Tel: +385 099 851 0717
 * Sva prava pridržana. Ovaj softver je vlasnički i zabranjeno ga je
 * distribuirati ili mijenjati bez izričitog dopuštenja autora.
 */
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require_admin();

$message     = '';
$messageType = 'ok';
$user        = current_user();

$CATEGORIES = [
    'file' => 'Ignorirane datoteke',
    'path' => 'Ignorirane putanje',
    'hash' => 'Ignorirani hash',
    'user' => 'Ignorirani korisnici',
];

// ── EXPORT ──────────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $rows = $pdo->query("SELECT category, value, note, created_at FROM scanner_ignore_list ORDER BY category, id")->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ignore_list_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['category', 'value', 'note', 'created_at']);
    foreach ($rows as $row) {
        fputcsv($out, [$row['category'], $row['value'], $row['note'] ?? '', $row['created_at']]);
    }
    fclose($out);
    exit;
}

$formAction   = $_POST['form_action'] ?? '';
$importResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    // ── ADD ─────────────────────────────────────────────────────────────────
    if ($formAction === 'add') {
        $category = $_POST['category'] ?? '';
        $value    = trim($_POST['value'] ?? '');
        $note     = trim($_POST['note']  ?? '');

        if (!array_key_exists($category, $CATEGORIES)) {
            $message     = 'Neispravna kategorija.';
            $messageType = 'error';
        } elseif ($value === '') {
            $message     = 'Vrijednost ne smije biti prazna.';
            $messageType = 'error';
        } else {
            $pdo->prepare("
                INSERT INTO scanner_ignore_list (category, value, note, created_by)
                VALUES (?, ?, ?, ?)
            ")->execute([$category, $value, $note ?: null, $user['username']]);
            $message = "Dodano u ignore listu ($category).";
        }
    }

    // ── DELETE ───────────────────────────────────────────────────────────────
    if ($formAction === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM scanner_ignore_list WHERE id=?")->execute([$id]);
            $message = "Zapis #$id obrisan.";
        }
    }

    // ── IMPORT ───────────────────────────────────────────────────────────────
    if ($formAction === 'import') {
        $added    = 0;
        $skipped  = 0;
        $errors   = [];

        $file = $_FILES['csv_file'] ?? null;
        $ok   = $file && $file['error'] === UPLOAD_ERR_OK && $file['size'] > 0;

        if (!$ok) {
            $message     = 'Greška pri uploadu datoteke.';
            $messageType = 'error';
        } else {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($ext !== 'csv') {
                $message     = 'Dozvoljene su samo .csv datoteke.';
                $messageType = 'error';
            } else {
                $handle = fopen($file['tmp_name'], 'r');
                $lineNo = 0;

                // Check duplicate by category + value
                $dupStmt = $pdo->prepare("SELECT COUNT(*) FROM scanner_ignore_list WHERE category=? AND value=?");
                $insStmt = $pdo->prepare("INSERT INTO scanner_ignore_list (category, value, note, created_by) VALUES (?, ?, ?, ?)");

                while (($row = fgetcsv($handle)) !== false) {
                    $lineNo++;

                    // Skip empty lines
                    if (count($row) === 0 || (count($row) === 1 && trim($row[0]) === '')) continue;

                    $category = isset($row[0]) ? trim($row[0]) : '';
                    $value    = isset($row[1]) ? trim($row[1]) : '';
                    $note     = isset($row[2]) ? trim($row[2]) : '';

                    // Skip header row
                    if ($lineNo === 1 && strtolower($category) === 'category') continue;

                    // Validate category
                    if (!array_key_exists($category, $CATEGORIES)) {
                        $errors[] = "Redak $lineNo: neispravna kategorija '$category'.";
                        continue;
                    }

                    // Validate value
                    if ($value === '') {
                        $errors[] = "Redak $lineNo: vrijednost je prazna.";
                        continue;
                    }

                    // Validate hash: exactly 64 lowercase hex chars
                    if ($category === 'hash' && !preg_match('/^[0-9a-f]{64}$/', $value)) {
                        $errors[] = "Redak $lineNo: hash mora biti 64 hex znaka (lowercase).";
                        continue;
                    }

                    // Normalize path: ensure trailing slash
                    if ($category === 'path' && substr($value, -1) !== '/') {
                        $value .= '/';
                    }

                    // Check duplicate
                    $dupStmt->execute([$category, $value]);
                    if ((int)$dupStmt->fetchColumn() > 0) {
                        $skipped++;
                        continue;
                    }

                    $insStmt->execute([$category, $value, $note ?: null, $user['username']]);
                    $added++;
                }

                fclose($handle);

                $importResult = ['added' => $added, 'skipped' => $skipped, 'errors' => $errors];
            }
        }

        // Don't redirect on import — show results on same page
        if (!$message) {
            // fall through to render
            goto render;
        }
    }

    if (!$message) {
        $redirect = isset($_POST['category']) ? '?tab=' . urlencode($_POST['category']) : 'ignore.php';
        header('Location: ' . $redirect);
        exit;
    }
}

render:
$activeTab = $_GET['tab'] ?? 'file';
if (!array_key_exists($activeTab, $CATEGORIES)) $activeTab = 'file';

$counts = [];
foreach (array_keys($CATEGORIES) as $cat) {
    $cs = $pdo->prepare("SELECT COUNT(*) FROM scanner_ignore_list WHERE category=?");
    $cs->execute([$cat]);
    $counts[$cat] = (int)$cs->fetchColumn();
}

$search   = trim($_GET['q'] ?? '');
$params   = [$activeTab];
$sql      = "SELECT * FROM scanner_ignore_list WHERE category=?";
if ($search !== '') {
    $sql     .= " AND (value LIKE ? OR note LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$sql .= " ORDER BY id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();
?>
<!doctype html>
<html lang="hr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>8Core Scanner – Ignore lista</title>
<link rel="stylesheet" href="../assets/css/scanner.css">
<style>
.csv-toolbar { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.import-result { font-size:13px; line-height:1.6; }
.import-result .ir-stat { font-weight:600; }
.import-result .ir-errors { margin-top:8px; max-height:140px; overflow-y:auto; background:var(--surface2); border:1px solid var(--border); border-radius:6px; padding:8px 10px; font-size:12px; font-family:monospace; color:#b91c1c; }
</style>
</head>
<body>
<div class="layout">

<!-- SIDEBAR -->
<?php include __DIR__ . '/sidebar.php'; ?>

<!-- MAIN -->
<div class="main">
  <div class="topbar">
    <div class="topbar-title">Ignore lista</div>
    <div class="topbar-meta">
      <?php $total = array_sum($counts); ?>
      <span class="rule-stat"><?= $total ?> ukupno zapisa</span>
      &nbsp;&nbsp;
      <a href="../logout.php" class="topbar-logout">Odjava</a>
    </div>
  </div>

  <div class="content">

    <?php if ($message): ?>
      <div class="notice <?= $messageType ?>"><?= h($message) ?></div>
    <?php endif; ?>

    <?php if ($importResult !== null): ?>
      <div class="notice <?= empty($importResult['errors']) ? 'ok' : 'error' ?>">
        <div class="import-result">
          <span class="ir-stat">Dodano: <?= $importResult['added'] ?></span>
          &nbsp;&middot;&nbsp;
          <span class="ir-stat">Preskočeno duplikata: <?= $importResult['skipped'] ?></span>
          &nbsp;&middot;&nbsp;
          <span class="ir-stat">Greške: <?= count($importResult['errors']) ?></span>
          <?php if ($importResult['errors']): ?>
            <div class="ir-errors"><?= implode("\n", array_map('h', $importResult['errors'])) ?></div>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- TAB NAV -->
    <div class="ignore-tabs">
      <?php foreach ($CATEGORIES as $cat => $label): ?>
        <a href="ignore.php?tab=<?= h($cat) ?>"
           class="ignore-tab <?= $activeTab === $cat ? 'active' : '' ?>">
          <?= h($label) ?>
          <span class="ignore-tab-count"><?= $counts[$cat] ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- FORMA ZA DODAVANJE -->
    <div class="panel">
      <h2>Dodaj u: <?= h($CATEGORIES[$activeTab]) ?></h2>
      <form method="post" class="form-row">
        <input type="hidden" name="form_action" value="add">
        <input type="hidden" name="category"    value="<?= h($activeTab) ?>">
        <?= csrf_field() ?>
        <input type="text" name="value" required
               placeholder="<?php
                  if ($activeTab === 'file') echo 'npr. /var/www/html/wp-config.php';
                  elseif ($activeTab === 'path') echo 'npr. /var/www/html/cache/';
                  elseif ($activeTab === 'hash') echo 'SHA-256 hash (64 znaka)';
                  else echo 'korisničko ime (account name)';
               ?>"
               style="flex:1;min-width:250px;">
        <input type="text" name="note" placeholder="Napomena (opcionalno)" style="flex:1;min-width:150px;">
        <button type="submit" class="btn btn-primary btn-sm">Dodaj</button>
      </form>
    </div>

    <!-- CSV IMPORT / EXPORT -->
    <div class="panel">
      <h2>CSV uvoz / izvoz</h2>
      <div class="csv-toolbar">

        <!-- IMPORT -->
        <form method="post" enctype="multipart/form-data" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
          <input type="hidden" name="form_action" value="import">
          <?= csrf_field() ?>
          <input type="file" name="csv_file" accept=".csv,text/csv" required
                 style="font-size:13px;">
          <button type="submit" class="btn btn-primary btn-sm"
                  onclick="return confirm('Uvesti CSV u ignore listu?')">
            <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            Uvezi CSV
          </button>
        </form>

        <div style="width:1px;height:28px;background:var(--border);margin:0 4px;"></div>

        <!-- EXPORT -->
        <a href="ignore.php?export=csv" class="btn btn-ghost btn-sm">
          <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          Izvezi sve (CSV)
        </a>

      </div>

      <div style="margin-top:12px;font-size:12px;color:var(--text-muted);line-height:1.7;">
        <strong>Format CSV-a:</strong>
        <code style="display:block;margin-top:4px;background:var(--surface2);border:1px solid var(--border);border-radius:5px;padding:6px 10px;font-size:11.5px;">
          category,value,note<br>
          file,/home/account/public_html/wp-config.php,legit config<br>
          path,/home/account/cache/,cache folder<br>
          hash,aaaaaa...64hex...,known clean<br>
          user,accountname,ignore whole account
        </code>
        Dozvoljene kategorije: <strong>file, path, hash, user</strong>. Hash mora biti 64 hex znaka. Duplikati se preskaču automatski.
      </div>
    </div>

    <!-- PRETRAGA -->
    <form method="get" class="rules-filter-form" style="margin-bottom:12px;">
      <input type="hidden" name="tab" value="<?= h($activeTab) ?>">
      <input type="text" name="q" value="<?= h($search) ?>" placeholder="Pretraži...">
      <button type="submit" class="btn btn-ghost btn-sm">Filtriraj</button>
      <a href="ignore.php?tab=<?= h($activeTab) ?>" class="btn btn-ghost btn-sm">Reset</a>
    </form>

    <!-- TABLICA -->
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th class="col-id">#</th>
            <th>Vrijednost</th>
            <th>Napomena</th>
            <th>Dodao</th>
            <th>Datum</th>
            <th>Akcije</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($items)): ?>
          <tr><td colspan="6" class="rules-empty">Lista je prazna.</td></tr>
        <?php endif; ?>
        <?php foreach ($items as $item): ?>
          <tr>
            <td class="small mono"><?= (int)$item['id'] ?></td>
            <td><code class="rule-pattern"><?= h($item['value']) ?></code></td>
            <td class="small"><?= h($item['note'] ?? '—') ?></td>
            <td class="small"><?= h($item['created_by'] ?? '—') ?></td>
            <td class="small"><?= h(substr($item['created_at'], 0, 16)) ?></td>
            <td>
              <form method="post" class="inline-form" onsubmit="return confirm('Obrisati zapis?')">
                <input type="hidden" name="form_action" value="delete">
                <input type="hidden" name="id"          value="<?= (int)$item['id'] ?>">
                <input type="hidden" name="category"    value="<?= h($activeTab) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-danger btn-sm">Obriši</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

  </div><!-- .content -->
</div><!-- .main -->
</div><!-- .layout -->

<script src="../assets/js/scanner.js"></script>
</body>
</html>
