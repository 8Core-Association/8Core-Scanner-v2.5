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

$formAction = $_POST['form_action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

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

    if ($formAction === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM scanner_ignore_list WHERE id=?")->execute([$id]);
            $message = "Zapis #$id obrisan.";
        }
    }

    if (!$message) {
        $redirect = isset($_POST['category']) ? '?tab=' . urlencode($_POST['category']) : 'ignore.php';
        header('Location: ' . $redirect);
        exit;
    }
}

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
        <input type="text" name="value" required
               placeholder="<?php
                  if ($activeTab === 'file') echo 'npr. /var/www/html/wp-config.php';
                  elseif ($activeTab === 'path') echo 'npr. /var/www/html/cache/';
                  elseif ($activeTab === 'hash') echo 'SHA-256 hash (64 znaka)';
                  else echo 'korisničko ime ili UID';
               ?>"
               style="flex:1;min-width:250px;">
        <input type="text" name="note" placeholder="Napomena (opcionalno)" style="flex:1;min-width:150px;">
        <button type="submit" class="btn btn-primary btn-sm">Dodaj</button>
      </form>
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
