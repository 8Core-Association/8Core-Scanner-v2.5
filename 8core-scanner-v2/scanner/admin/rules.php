<?php
/**
 * 8Core Scanner v2.0 — Admin: Pravila i definicije
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
$editRule    = null;

$TYPES = [
    'filename'      => 'Naziv datoteke',
    'path'          => 'Putanja',
    'regex'         => 'Regex',
    'regex_content' => 'Regex sadržaja',
    'sha256'        => 'SHA256',
    'chmod'         => 'Dozvole (chmod)',
    'extension'     => 'Ekstenzija',
    'filesize'      => 'Veličina datoteke',
];

$RISKS = ['CRITICAL', 'HIGH', 'MEDIUM', 'LOW', 'INFO'];

$formAction = $_POST['form_action'] ?? '';
$user       = current_user();

/* ── CSV izvoz (prije bilo kakvog outputa) ── */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $all = $pdo->query("SELECT name, type, pattern, extensions, risk, active, description, note, created_at FROM scanner_rules ORDER BY id ASC")->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="scanner_rules_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['name','type','pattern','extensions','risk','active','description','note','created_at']);
    foreach ($all as $r) {
        fputcsv($out, [$r['name'],$r['type'],$r['pattern'],$r['extensions'],$r['risk'],$r['active'],$r['description'],$r['note'],$r['created_at']]);
    }
    fclose($out);
    exit;
}

/* ── POST handleri ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($formAction === 'create' || $formAction === 'update') {
        $name       = trim($_POST['name']        ?? '');
        $desc       = trim($_POST['description'] ?? '');
        $type       = $_POST['type']    ?? 'regex';
        $pattern    = trim($_POST['pattern']     ?? '');
        $extensions = trim($_POST['extensions']  ?? '');
        $risk       = $_POST['risk']    ?? 'MEDIUM';
        $active     = isset($_POST['active']) ? 1 : 0;
        $note       = trim($_POST['note'] ?? '');

        if (!array_key_exists($type, $TYPES)) $type = 'regex';
        if (!in_array($risk, $RISKS, true))   $risk = 'MEDIUM';

        if ($name === '' || $pattern === '') {
            $message     = 'Naziv i uzorak su obavezni.';
            $messageType = 'error';
        } elseif ($formAction === 'create') {
            $pdo->prepare("
                INSERT INTO scanner_rules (name, description, type, pattern, extensions, risk, active, note, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([$name, $desc, $type, $pattern, $extensions ?: null, $risk, $active, $note ?: null, $user['username']]);
            $message = "Pravilo \"$name\" kreirano.";
        } else {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare("
                    UPDATE scanner_rules SET name=?, description=?, type=?, pattern=?, extensions=?,
                    risk=?, active=?, note=?, updated_at=NOW() WHERE id=?
                ")->execute([$name, $desc, $type, $pattern, $extensions ?: null, $risk, $active, $note ?: null, $id]);
                $message = "Pravilo #$id ažurirano.";
            }
        }
    }

    if ($formAction === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("UPDATE scanner_rules SET active = IF(active=1,0,1) WHERE id=?")->execute([$id]);
            $message = 'Status pravila promijenjen.';
        }
    }

    if ($formAction === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM scanner_rules WHERE id=?")->execute([$id]);
            $message = "Pravilo #$id obrisano.";
        }
    }

    if ($formAction === 'copy') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $src = $pdo->prepare("SELECT * FROM scanner_rules WHERE id=?");
            $src->execute([$id]);
            $r = $src->fetch();
            if ($r) {
                $pdo->prepare("
                    INSERT INTO scanner_rules (name, description, type, pattern, extensions, risk, active, note, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)
                ")->execute(["Kopija — " . $r['name'], $r['description'], $r['type'], $r['pattern'],
                              $r['extensions'], $r['risk'], $r['note'], $user['username']]);
                $message = "Pravilo #$id kopirano (neaktivno).";
            }
        }
    }

    if ($formAction === 'import') {
        $file = $_FILES['csv_file'] ?? null;
        if ($file && $file['error'] === UPLOAD_ERR_OK) {
            $handle = fopen($file['tmp_name'], 'r');
            $count  = 0;
            fgetcsv($handle); // preskoči zaglavlje
            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) < 6) continue;
                [$name, $type, $pattern, $extensions, $risk, $active] = $row;
                $name    = trim($name);
                $type    = trim($type);
                $pattern = trim($pattern);
                $risk    = strtoupper(trim($risk));
                $active  = (int)trim($active);
                if (!array_key_exists($type, $TYPES)) continue;
                if (!in_array($risk, $RISKS, true))   $risk = 'MEDIUM';
                if ($name === '' || $pattern === '')   continue;
                $pdo->prepare("
                    INSERT INTO scanner_rules (name, type, pattern, extensions, risk, active, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ")->execute([$name, $type, $pattern, $extensions ?: null, $risk, $active, $user['username']]);
                $count++;
            }
            fclose($handle);
            $message = "Uvezeno $count pravila.";
        } else {
            $message     = 'CSV fajl nije učitan.';
            $messageType = 'error';
        }
    }

    if (!$message) {
        header('Location: rules.php');
        exit;
    }
}

/* ── Učitaj za uređivanje ── */
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    $s = $pdo->prepare("SELECT * FROM scanner_rules WHERE id=?");
    $s->execute([$editId]);
    $editRule = $s->fetch() ?: null;
}

/* ── Filteri i lista ── */
$search  = trim($_GET['q']      ?? '');
$fType   = $_GET['type']   ?? '';
$fRisk   = $_GET['risk']   ?? '';
$fActive = $_GET['active'] ?? '';

$where  = [];
$params = [];

if ($search !== '') {
    $where[]  = "(name LIKE ? OR pattern LIKE ? OR description LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
if ($fType !== '' && array_key_exists($fType, $TYPES)) { $where[] = "type = ?";   $params[] = $fType; }
if ($fRisk !== '' && in_array($fRisk, $RISKS, true))   { $where[] = "risk = ?";   $params[] = $fRisk; }
if ($fActive !== '')                                    { $where[] = "active = ?"; $params[] = (int)$fActive; }

$sql = "SELECT * FROM scanner_rules";
if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY active DESC, risk DESC, name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rules = $stmt->fetchAll();

$totalRules  = (int)$pdo->query("SELECT COUNT(*) FROM scanner_rules")->fetchColumn();
$activeRules = (int)$pdo->query("SELECT COUNT(*) FROM scanner_rules WHERE active=1")->fetchColumn();
?>
<!doctype html>
<html lang="hr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>8Core Scanner – Pravila</title>
<link rel="stylesheet" href="../assets/css/scanner.css">
</head>
<body>
<div class="layout">

<!-- SIDEBAR -->
<?php include __DIR__ . '/sidebar.php'; ?>

<!-- MAIN -->
<div class="main">
  <div class="topbar">
    <div class="topbar-title">Pravila i definicije</div>
    <div class="topbar-meta">
      <span class="rule-stat"><?= $activeRules ?> aktivnih</span>
      &nbsp;&middot;&nbsp;
      <span class="rule-stat-total"><?= $totalRules ?> ukupno</span>
      &nbsp;&nbsp;
      <a href="../logout.php" class="topbar-logout">Odjava</a>
    </div>
  </div>

  <div class="content">

    <?php if ($message): ?>
      <div class="notice <?= $messageType ?>"><?= h($message) ?></div>
    <?php endif; ?>

    <!-- ALATNA TRAKA -->
    <div class="rules-toolbar">
      <button class="btn btn-primary btn-sm" onclick="toggleEl('form-create')">+ Novo pravilo</button>

      <form method="get" class="rules-filter-form">
        <input type="text" name="q" value="<?= h($search) ?>" placeholder="Pretraga...">
        <select name="type">
          <option value="">Svi tipovi</option>
          <?php foreach ($TYPES as $k => $v): ?>
            <option value="<?= h($k) ?>" <?= $fType === $k ? 'selected' : '' ?>><?= h($v) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="risk">
          <option value="">Svi rizici</option>
          <?php foreach ($RISKS as $r): ?>
            <option value="<?= h($r) ?>" <?= $fRisk === $r ? 'selected' : '' ?>><?= h($r) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="active">
          <option value="">Svi statusi</option>
          <option value="1" <?= $fActive === '1' ? 'selected' : '' ?>>Aktivna</option>
          <option value="0" <?= $fActive === '0' ? 'selected' : '' ?>>Neaktivna</option>
        </select>
        <button type="submit" class="btn btn-ghost btn-sm">Filtriraj</button>
        <a href="rules.php" class="btn btn-ghost btn-sm">Reset</a>
      </form>

      <div class="rules-toolbar-right">
        <a href="rules.php?export=csv" class="btn btn-ghost btn-sm">Izvoz CSV</a>
        <button class="btn btn-ghost btn-sm" onclick="toggleEl('form-import')">Uvoz CSV</button>
      </div>
    </div>

    <!-- FORMA ZA UVOZ -->
    <div id="form-import" class="panel rules-collapsible">
      <h2>Uvoz pravila (CSV)</h2>
      <p class="rules-help-text">Stupci: name, type, pattern, extensions, risk, active (0/1)</p>
      <form method="post" enctype="multipart/form-data" class="form-row">
        <input type="hidden" name="form_action" value="import">
        <input type="file" name="csv_file" accept=".csv" required>
        <button type="submit" class="btn btn-primary btn-sm">Uvezi</button>
        <button type="button" class="btn btn-ghost btn-sm" onclick="toggleEl('form-import')">Zatvori</button>
      </form>
    </div>

    <!-- FORMA ZA KREIRANJE / UREĐIVANJE -->
    <div id="form-create" class="panel rules-collapsible <?= $editRule ? 'open' : '' ?>">
      <h2><?= $editRule ? 'Uredi pravilo #' . (int)$editRule['id'] : 'Novo pravilo' ?></h2>
      <form method="post" class="rules-form" id="rule-form">
        <input type="hidden" name="form_action" value="<?= $editRule ? 'update' : 'create' ?>">
        <?php if ($editRule): ?>
          <input type="hidden" name="id" value="<?= (int)$editRule['id'] ?>">
        <?php endif; ?>

        <div class="rules-form-grid">
          <div class="rules-form-field rules-field-wide">
            <label>Naziv pravila *</label>
            <input type="text" name="name" value="<?= h($editRule['name'] ?? '') ?>" required placeholder="npr. Suspicious PHP shell">
          </div>
          <div class="rules-form-field">
            <label>Tip pravila *</label>
            <select name="type" id="rule-type" onchange="updatePatternHint()">
              <?php foreach ($TYPES as $k => $v): ?>
                <option value="<?= h($k) ?>" <?= ($editRule['type'] ?? 'regex') === $k ? 'selected' : '' ?>><?= h($v) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="rules-form-field">
            <label>Rizik</label>
            <select name="risk">
              <?php foreach ($RISKS as $r): ?>
                <option value="<?= h($r) ?>" <?= ($editRule['risk'] ?? 'MEDIUM') === $r ? 'selected' : '' ?>><?= h($r) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="rules-form-field rules-field-wide">
            <label>Uzorak * <span class="rules-hint" id="pattern-hint"></span></label>
            <input type="text" name="pattern" value="<?= h($editRule['pattern'] ?? '') ?>" required
                   placeholder="npr. shell_exec|passthru|proc_open" class="rules-pattern-input">
          </div>
          <div class="rules-form-field">
            <label>Ekstenzije <span class="rules-hint">(razdvojene razmakom, npr: php PHP pHP)</span></label>
            <input type="text" name="extensions" value="<?= h($editRule['extensions'] ?? '') ?>" placeholder="php PHP">
          </div>
          <div class="rules-form-field">
            <label>Opis</label>
            <input type="text" name="description" value="<?= h($editRule['description'] ?? '') ?>" placeholder="Kratki opis">
          </div>
          <div class="rules-form-field rules-field-wide">
            <label>Napomena</label>
            <textarea name="note" rows="2" placeholder="Interna napomena..."><?= h($editRule['note'] ?? '') ?></textarea>
          </div>
          <div class="rules-form-field">
            <label class="rules-check-label">
              <input type="checkbox" name="active" <?= ($editRule['active'] ?? 1) ? 'checked' : '' ?>>
              Aktivno
            </label>
          </div>
        </div>

        <div class="rules-form-actions">
          <button type="submit" class="btn btn-primary"><?= $editRule ? 'Spremi izmjene' : 'Kreiraj pravilo' ?></button>
          <a href="rules.php" class="btn btn-ghost">Odustani</a>
        </div>
      </form>
    </div>

    <!-- TABLICA PRAVILA -->
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th class="col-active">Aktivno</th>
            <th>Naziv</th>
            <th>Tip</th>
            <th>Rizik</th>
            <th>Uzorak</th>
            <th>Ekstenzije</th>
            <th>Datum</th>
            <th>Akcije</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($rules)): ?>
          <tr><td colspan="8" class="rules-empty">Nema pravila. Dodajte novo ili uvezite CSV.</td></tr>
        <?php endif; ?>
        <?php foreach ($rules as $rule): ?>
          <tr class="<?= $rule['active'] ? '' : 'rule-inactive-row' ?>">
            <td>
              <form method="post" class="inline-form">
                <input type="hidden" name="form_action" value="toggle">
                <input type="hidden" name="id" value="<?= (int)$rule['id'] ?>">
                <button type="submit" class="toggle-btn <?= $rule['active'] ? 'toggle-on' : 'toggle-off' ?>"
                        title="<?= $rule['active'] ? 'Klikni za deaktivaciju' : 'Klikni za aktivaciju' ?>">
                  <span class="toggle-dot"></span>
                </button>
              </form>
            </td>
            <td>
              <span class="rule-name"><?= h($rule['name']) ?></span>
              <?php if ($rule['description']): ?>
                <div class="small"><?= h($rule['description']) ?></div>
              <?php endif; ?>
            </td>
            <td><span class="rule-type-badge"><?= h($TYPES[$rule['type']] ?? $rule['type']) ?></span></td>
            <td><span class="badge <?= risk_class($rule['risk']) ?>"><?= h($rule['risk']) ?></span></td>
            <td><code class="rule-pattern"><?= h(mb_strimwidth($rule['pattern'], 0, 60, '…')) ?></code></td>
            <td class="small"><?= h($rule['extensions'] ?? '—') ?></td>
            <td class="small"><?= h(substr($rule['created_at'] ?? '', 0, 10)) ?></td>
            <td>
              <div class="rules-actions">
                <a href="rules.php?edit=<?= (int)$rule['id'] ?>" class="btn btn-ghost btn-sm">Uredi</a>
                <form method="post" class="inline-form">
                  <input type="hidden" name="form_action" value="copy">
                  <input type="hidden" name="id" value="<?= (int)$rule['id'] ?>">
                  <button type="submit" class="btn btn-ghost btn-sm">Kopiraj</button>
                </form>
                <form method="post" class="inline-form" onsubmit="return confirm('Obrisati pravilo?')">
                  <input type="hidden" name="form_action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$rule['id'] ?>">
                  <button type="submit" class="btn btn-danger btn-sm">Obriši</button>
                </form>
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

<script src="../assets/js/scanner.js"></script>
<script src="../assets/js/rules.js"></script>
</body>
</html>
