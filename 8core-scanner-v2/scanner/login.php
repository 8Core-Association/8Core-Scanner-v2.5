<?php
/**
 * 8Core Scanner v2.0 — Prijava
 * (c) 2026 Tomislav Galić <tomislav@8core.hr>
 * Web: https://8core.hr
 * Kontakt: info@8core.hr | Tel: +385 099 851 0717
 * Sva prava pridržana. Ovaj softver je vlasnički i zabranjeno ga je
 * distribuirati ili mijenjati bez izričitog dopuštenja autora.
 */
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

$error = '';

if (is_logged_in()) {
    header('Location: ' . (is_admin() ? 'admin/index.php' : 'index.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    if (login_user($pdo, $username, $password)) {
        header('Location: ' . (is_admin() ? 'admin/index.php' : 'index.php'));
        exit;
    }

    $error = 'Pogrešan username ili lozinka.';
}
?>
<!doctype html>
<html lang="hr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>8Core Scanner – Prijava</title>
<link rel="stylesheet" href="assets/css/scanner.css">
</head>
<body>
<div class="login-bg">
  <div class="login-card">

    <div class="login-logo">
      <div class="logo-icon">
        <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      </div>
      <div class="login-logo-text">
        <div class="name">8Core Scanner</div>
        <div class="sub">Security Dashboard v2.0</div>
      </div>
    </div>

    <h2>Prijava</h2>
    <p class="login-desc">Sigurnosni panel za pregled nalaza.</p>

    <?php if ($error): ?>
      <div class="login-error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="on">
      <div class="login-field">
        <label for="username">Username</label>
        <input type="text" id="username" name="username"
               placeholder="Unesite username"
               autocomplete="username" required>
      </div>
      <div class="login-field">
        <label for="password">Lozinka</label>
        <input type="password" id="password" name="password"
               placeholder="Unesite lozinku"
               autocomplete="current-password" required>
      </div>
      <button class="login-btn" type="submit">Prijava</button>
    </form>

    <div class="login-footer">8Core &copy; <?= date('Y') ?></div>
  </div>
</div>
</body>
</html>
