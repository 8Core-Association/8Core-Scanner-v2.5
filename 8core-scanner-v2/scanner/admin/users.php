<?php
/**
 * 8Core Scanner v2.0 — Admin: Korisnici
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = $_POST['form_action'] ?? '';

    if ($formAction === 'create') {
        $username         = trim($_POST['username'] ?? '');
        $password         = $_POST['password'] ?? '';
        $role             = $_POST['role']     ?? 'user';
        $email            = trim($_POST['email'] ?? '');
        $active           = isset($_POST['active']) ? 1 : 0;
        $selectedAccounts = isset($_POST['accounts']) && is_array($_POST['accounts']) ? $_POST['accounts'] : [];

        if ($username !== '' && $password !== '' && in_array($role, ['admin','user'], true)) {
            $hash  = password_hash($password, PASSWORD_DEFAULT);
            $stmt  = $pdo->prepare("
                INSERT INTO scanner_users (username, password_hash, role, email, account_name, active, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $firstAccount = !empty($selectedAccounts) ? $selectedAccounts[0] : null;
            $stmt->execute([$username, $hash, $role, $email !== '' ? $email : null, $firstAccount, $active]);
            $newId = (int)$pdo->lastInsertId();

            foreach ($selectedAccounts as $acc) {
                $acc = trim($acc);
                if ($acc === '') continue;
                $pdo->prepare("INSERT IGNORE INTO scanner_user_accounts (user_id, account_name) VALUES (?, ?)")->execute([$newId, $acc]);
            }
            $message = "Korisnik \"$username\" kreiran.";
        } else {
            $message     = 'Nedostaje username/password ili role nije ispravan.';
            $messageType = 'error';
        }
    }

    if ($formAction === 'accounts') {
        $id               = (int)($_POST['id'] ?? 0);
        $selectedAccounts = isset($_POST['accounts']) && is_array($_POST['accounts']) ? $_POST['accounts'] : [];

        if ($id > 0) {
            $pdo->prepare("DELETE FROM scanner_user_accounts WHERE user_id = ?")->execute([$id]);
            $firstAccount = null;
            foreach ($selectedAccounts as $acc) {
                $acc = trim($acc);
                if ($acc === '') continue;
                $pdo->prepare("INSERT IGNORE INTO scanner_user_accounts (user_id, account_name) VALUES (?, ?)")->execute([$id, $acc]);
                if ($firstAccount === null) $firstAccount = $acc;
            }
            $pdo->prepare("UPDATE scanner_users SET account_name = ? WHERE id = ?")->execute([$firstAccount, $id]);
            $message = 'Accounti ažurirani.';
        }
    }

    if ($formAction === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE scanner_users SET active = IF(active=1,0,1) WHERE id = ?")->execute([$id]);
        $message = 'Status promijenjen.';
    }

    if ($formAction === 'email') {
        $id    = (int)($_POST['id'] ?? 0);
        $email = trim($_POST['email'] ?? '');
        if ($id > 0) {
            $pdo->prepare("UPDATE scanner_users SET email = ? WHERE id = ?")->execute([$email !== '' ? $email : null, $id]);
            $message = 'Email ažuriran.';
        }
    }

    if ($formAction === 'password') {
        $id       = (int)($_POST['id'] ?? 0);
        $password = $_POST['password'] ?? '';
        if ($id > 0 && $password !== '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE scanner_users SET password_hash = ? WHERE id = ?")->execute([$hash, $id]);
            $message = 'Lozinka promijenjena.';
        }
    }

    if ($formAction === 'delete') {
        $id        = (int)($_POST['id'] ?? 0);
        $currentId = (int)(current_user()['id'] ?? 0);
        if ($id > 0 && $id !== $currentId) {
            $pdo->prepare("DELETE FROM scanner_user_accounts WHERE user_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM scanner_users WHERE id = ?")->execute([$id]);
            $message = 'Korisnik obrisan.';
        } elseif ($id === $currentId) {
            $message     = 'Ne možeš obrisati vlastiti račun.';
            $messageType = 'error';
        }
    }
}

$users             = $pdo->query("SELECT * FROM scanner_users ORDER BY id ASC")->fetchAll();
$availableAccounts = $pdo->query("SELECT DISTINCT account_name FROM findings WHERE account_name IS NOT NULL AND account_name != '' ORDER BY account_name")->fetchAll(PDO::FETCH_COLUMN);

$userAccountsMap = [];
$rows = $pdo->query("SELECT user_id, account_name FROM scanner_user_accounts ORDER BY account_name")->fetchAll();
foreach ($rows as $r) {
    $userAccountsMap[$r['user_id']][] = $r['account_name'];
}
?>
<!doctype html>
<html lang="hr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>8Core Scanner – Korisnici</title>
<link rel="stylesheet" href="../assets/css/scanner.css">
<style>
.accounts-grid { display:flex; flex-wrap:wrap; gap:6px; margin-top:6px; }
.account-check { display:flex; align-items:center; gap:5px; background:var(--surface2); border:1px solid var(--border); border-radius:6px; padding:4px 10px; font-size:12px; cursor:pointer; transition:border-color .15s, background .15s; }
.account-check input { margin:0; cursor:pointer; }
.account-check:has(input:checked) { background:var(--accent-dim, rgba(0,100,255,.12)); border-color:var(--accent); color:var(--accent); font-weight:600; }
.edit-accounts-panel { display:none; margin-top:10px; background:var(--surface2); border:1px solid var(--border); border-radius:8px; padding:14px; }
.edit-accounts-panel.open { display:block; }
.user-accounts-pills { display:flex; flex-wrap:wrap; gap:4px; }
.user-accounts-pill { background:var(--accent-dim, rgba(0,100,255,.1)); color:var(--accent); border-radius:20px; padding:2px 10px; font-size:11px; font-weight:600; }
</style>
</head>
<body>
<div class="layout">

<!-- SIDEBAR -->
<?php include __DIR__ . '/sidebar.php'; ?>

<!-- MAIN -->
<div class="main">
  <div class="topbar">
    <div class="topbar-title">Korisnici</div>
    <div class="topbar-meta">
      <a href="../logout.php" style="color:var(--text-muted);font-size:12px;">Odjava</a>
    </div>
  </div>

  <div class="content">

    <?php if ($message): ?>
      <div class="notice <?= $messageType === 'error' ? '' : 'ok' ?>"><?= h($message) ?></div>
    <?php endif; ?>

    <!-- FORMA ZA DODAVANJE -->
    <div class="panel">
      <h2>Dodaj korisnika</h2>
      <form method="post">
        <input type="hidden" name="form_action" value="create">
        <div class="form-row" style="flex-wrap:wrap;gap:8px;">
          <input type="text"     name="username" placeholder="username" required style="flex:1;min-width:130px;">
          <input type="password" name="password" placeholder="password" required style="flex:1;min-width:130px;">
          <input type="email"    name="email"    placeholder="email (opcionalno)" style="flex:1;min-width:170px;">
          <select name="role" style="flex:0 0 auto;">
            <option value="user">user</option>
            <option value="admin">admin</option>
          </select>
          <label style="display:flex;align-items:center;gap:5px;font-size:13px;">
            <input type="checkbox" name="active" checked> aktivan
          </label>
        </div>
        <?php if (!empty($availableAccounts)): ?>
        <div style="margin-top:12px;">
          <div style="font-size:12px;color:var(--text-muted);margin-bottom:6px;">Dodijeli accounte:</div>
          <div class="accounts-grid">
            <?php foreach ($availableAccounts as $acc): ?>
              <label class="account-check">
                <input type="checkbox" name="accounts[]" value="<?= h($acc) ?>">
                <?= h($acc) ?>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
        <div style="margin-top:12px;">
          <button type="submit" class="btn btn-primary">Kreiraj korisnika</button>
        </div>
      </form>
    </div>

    <!-- TABLICA KORISNIKA -->
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>Username</th>
            <th>Role</th>
            <th>Email</th>
            <th>Accounti</th>
            <th>Status</th>
            <th>Zadnji login</th>
            <th>Akcije</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): ?>
        <?php $uAccounts = $userAccountsMap[(int)$u['id']] ?? []; ?>
        <tr>
          <td class="small mono"><?= (int)$u['id'] ?></td>
          <td><b><?= h($u['username']) ?></b></td>
          <td><span class="badge <?= $u['role'] === 'admin' ? 'risk-medium' : 'risk-low' ?>"><?= h($u['role']) ?></span></td>
          <td>
            <?php if (!empty($u['email'])): ?>
              <span class="small"><?= h($u['email']) ?></span>
            <?php else: ?>
              <span style="color:var(--text-muted);font-size:12px;">—</span>
            <?php endif; ?>
            <button type="button" class="btn btn-ghost btn-sm" style="margin-top:4px;display:block;"
                    onclick="toggleEl('email-<?= (int)$u['id'] ?>')">
              Uredi email
            </button>
            <div id="email-<?= (int)$u['id'] ?>" style="display:none;margin-top:6px;">
              <form method="post" style="display:flex;gap:5px;align-items:center;">
                <input type="hidden" name="form_action" value="email">
                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <input type="email" name="email" value="<?= h($u['email'] ?? '') ?>"
                       placeholder="email@domena.hr"
                       style="padding:5px 8px;font-size:12px;border:1px solid var(--border);border-radius:6px;background:var(--surface2);min-width:160px;">
                <button type="submit" class="btn btn-primary btn-sm">Spremi</button>
              </form>
            </div>
          </td>
          <td>
            <?php if (!empty($uAccounts)): ?>
              <div class="user-accounts-pills">
                <?php foreach ($uAccounts as $a): ?>
                  <span class="user-accounts-pill"><?= h($a) ?></span>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <span style="color:var(--text-muted);font-size:12px;">—</span>
            <?php endif; ?>
            <?php if (!empty($availableAccounts)): ?>
            <button type="button" class="btn btn-ghost btn-sm" style="margin-top:6px;"
                    onclick="toggleAccounts('acc-<?= (int)$u['id'] ?>')">
              Uredi accounte
            </button>
            <div class="edit-accounts-panel" id="acc-<?= (int)$u['id'] ?>">
              <form method="post">
                <input type="hidden" name="form_action" value="accounts">
                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <div class="accounts-grid">
                  <?php foreach ($availableAccounts as $acc): ?>
                    <label class="account-check">
                      <input type="checkbox" name="accounts[]" value="<?= h($acc) ?>"
                             <?= in_array($acc, $uAccounts, true) ? 'checked' : '' ?>>
                      <?= h($acc) ?>
                    </label>
                  <?php endforeach; ?>
                </div>
                <div style="margin-top:10px;">
                  <button type="submit" class="btn btn-primary btn-sm">Spremi</button>
                  <button type="button" class="btn btn-ghost btn-sm"
                          onclick="toggleAccounts('acc-<?= (int)$u['id'] ?>')">Odustani</button>
                </div>
              </form>
            </div>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($u['active']): ?>
              <span class="user-active">Aktivan</span>
            <?php else: ?>
              <span class="user-inactive">Neaktivan</span>
            <?php endif; ?>
          </td>
          <td class="small"><?= h($u['last_login'] ?? '—') ?></td>
          <td>
            <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
              <form method="post" style="display:inline">
                <input type="hidden" name="form_action" value="toggle">
                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <button type="submit" class="btn btn-ghost btn-sm">
                  <?= $u['active'] ? 'Deaktiviraj' : 'Aktiviraj' ?>
                </button>
              </form>
              <form method="post" style="display:inline;display:flex;gap:5px;align-items:center;">
                <input type="hidden" name="form_action" value="password">
                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <input type="password" name="password" placeholder="nova lozinka"
                       style="padding:5px 8px;font-size:12px;border:1px solid var(--border);border-radius:6px;background:var(--surface2);" required>
                <button type="submit" class="btn btn-ghost btn-sm">Postavi</button>
              </form>
              <?php if ((int)$u['id'] !== (int)(current_user()['id'] ?? 0)): ?>
              <form method="post" class="inline-form"
                    onsubmit="return confirm('Trajno obrisati korisnika <?= h(addslashes($u['username'])) ?>?')">
                <input type="hidden" name="form_action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm">Ukloni</button>
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

<script>
function toggleAccounts(id) {
    var el = document.getElementById(id);
    el.classList.toggle('open');
}
function toggleEl(id) {
    var el = document.getElementById(id);
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
</script>
</body>
</html>
