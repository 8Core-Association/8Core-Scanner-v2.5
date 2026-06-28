<?php
/**
 * 8Core Scanner v2.0 — Standalone migrate (ažuriranje sheme)
 * (c) 2026 Tomislav Galić <tomislav@8core.hr>
 * Web: https://8core.hr
 * Kontakt: info@8core.hr | Tel: +385 099 851 0717
 * Sva prava pridržana. Ovaj softver je vlasnički i zabranjeno ga je
 * distribuirati ili mijenjati bez izričitog dopuštenja autora.
 *
 * NAPOMENA: Ovaj fajl sluzi za naknadne migracije sheme baze
 * (dodavanje stupaca koji nedostaju, itd.).
 * Nije zamjena za puni installer (install/index.php).
 */

// Zaštita: zahtijeva prijavljenog admina ili da se pokreće s komandne linije
if (php_sapi_name() !== 'cli') {
    $configPath = __DIR__ . '/../includes/config.php';
    if (!file_exists($configPath)) {
        die('Nije moguće pokrenuti migrate.php bez config.php. Pokrenite installer.');
    }
    // Sesija + autentikacija
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['scanner_user']) || $_SESSION['scanner_user']['role'] !== 'admin') {
        header('Location: ../login.php');
        exit;
    }
}

require __DIR__ . '/../includes/db.php';
$config = require __DIR__ . '/../includes/config.php';

$messages = [];

function column_exists(PDO $pdo, $table, $column): bool {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    return (bool)$stmt->fetch();
}

function add_column(PDO $pdo, array &$messages, $table, $column, $definition): void {
    if (!column_exists($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        $messages[] = "DODANO: $table.$column";
    } else {
        $messages[] = "OK: $table.$column";
    }
}

try {
    // findings stupci
    add_column($pdo, $messages, 'findings', 'account_name',    "VARCHAR(80) NULL");
    add_column($pdo, $messages, 'findings', 'relative_path',   "TEXT NULL");
    add_column($pdo, $messages, 'findings', 'ctime',           "DATETIME NULL");
    add_column($pdo, $messages, 'findings', 'birth_time',      "DATETIME NULL");
    add_column($pdo, $messages, 'findings', 'detected_at',     "DATETIME NULL DEFAULT CURRENT_TIMESTAMP");
    add_column($pdo, $messages, 'findings', 'source_guess',    "VARCHAR(255) NULL");
    add_column($pdo, $messages, 'findings', 'source_type',     "VARCHAR(80) NULL");
    add_column($pdo, $messages, 'findings', 'file_ext',        "VARCHAR(30) NULL");
    add_column($pdo, $messages, 'findings', 'action_status',   "VARCHAR(40) NOT NULL DEFAULT 'new'");
    add_column($pdo, $messages, 'findings', 'action_note',     "TEXT NULL");
    add_column($pdo, $messages, 'findings', 'action_at',       "DATETIME NULL");
    add_column($pdo, $messages, 'findings', 'action_by',       "VARCHAR(80) NULL");
    add_column($pdo, $messages, 'findings', 'quarantine_path', "TEXT NULL");
    add_column($pdo, $messages, 'findings', 'action_error',    "TEXT NULL");

    // Popuni prazne account_name iz owner_name
    $pdo->exec("
        UPDATE findings
        SET
            account_name = IF(account_name IS NULL OR account_name='', owner_name, account_name),
            relative_path = IF(relative_path IS NULL OR relative_path='', file_path, relative_path),
            detected_at = IF(detected_at IS NULL, created_at, detected_at)
    ");

    // scanner_actions
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS scanner_actions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            finding_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(40) NOT NULL,
            note TEXT NULL,
            created_at DATETIME NOT NULL,
            created_by VARCHAR(80) NULL,
            INDEX(finding_id), INDEX(action), INDEX(created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $messages[] = 'OK: scanner_actions';

    // scanner_users email stupac
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS scanner_users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(80) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('admin','user') NOT NULL DEFAULT 'user',
            account_name VARCHAR(80) NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            last_login DATETIME NULL,
            INDEX(role), INDEX(account_name), INDEX(active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $messages[] = 'OK: scanner_users';
    add_column($pdo, $messages, 'scanner_users', 'email', "VARCHAR(180) NULL");

    // scanner_user_accounts
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS scanner_user_accounts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            account_name VARCHAR(80) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_user_account (user_id, account_name),
            INDEX(user_id), INDEX(account_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $messages[] = 'OK: scanner_user_accounts';

    // Migracija stare account_name -> scanner_user_accounts
    $migrated = 0;
    $oldUsers = $pdo->query("SELECT id, account_name FROM scanner_users WHERE account_name IS NOT NULL AND account_name != ''")->fetchAll();
    foreach ($oldUsers as $ou) {
        $exists = $pdo->prepare("SELECT id FROM scanner_user_accounts WHERE user_id = ? AND account_name = ?");
        $exists->execute([$ou['id'], $ou['account_name']]);
        if (!$exists->fetch()) {
            $pdo->prepare("INSERT INTO scanner_user_accounts (user_id, account_name) VALUES (?, ?)")->execute([$ou['id'], $ou['account_name']]);
            $migrated++;
        }
    }
    $messages[] = $migrated > 0 ? "MIGRIRANO: $migrated korisnika -> scanner_user_accounts" : 'OK: scanner_user_accounts migracija';

    // scanner_rules
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS scanner_rules (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(200) NOT NULL,
            description TEXT NULL,
            type ENUM('filename','path','regex','regex_content','sha256','chmod','extension','filesize') NOT NULL DEFAULT 'regex',
            pattern VARCHAR(1000) NOT NULL,
            extensions VARCHAR(500) NULL,
            risk ENUM('CRITICAL','HIGH','MEDIUM','LOW','INFO') NOT NULL DEFAULT 'MEDIUM',
            active TINYINT(1) NOT NULL DEFAULT 1,
            note TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            created_by VARCHAR(80) NULL,
            INDEX(type), INDEX(risk), INDEX(active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $messages[] = 'OK: scanner_rules';

    // scanner_ignore_list
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS scanner_ignore_list (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            category ENUM('file','path','hash','user') NOT NULL,
            value VARCHAR(1000) NOT NULL,
            note TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by VARCHAR(80) NULL,
            INDEX(category), INDEX(value(100))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $messages[] = 'OK: scanner_ignore_list';

    // Kreiraj admin ako nema korisnika
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM scanner_users")->fetchColumn();
    if ($cnt === 0) {
        $hash = password_hash($config['default_admin_pass'], PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO scanner_users (username, password_hash, role, account_name, active, created_at) VALUES (?, ?, 'admin', NULL, 1, NOW())");
        $stmt->execute([$config['default_admin_user'], $hash]);
        $messages[] = 'KREIRAN ZADANI ADMIN: ' . $config['default_admin_user'];
    } else {
        $messages[] = 'OK: korisnici već postoje';
    }

    $messages[] = 'MIGRACIJA ZAVRŠENA';

} catch (Throwable $e) {
    $messages[] = 'GREŠKA: ' . $e->getMessage();
}

// CLI ispis
if (php_sapi_name() === 'cli') {
    foreach ($messages as $m) echo $m . PHP_EOL;
    exit;
}
?>
<!doctype html>
<html lang="hr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>8Core Scanner – Migracija</title>
<link rel="stylesheet" href="../assets/css/scanner.css">
</head>
<body>
<div class="util-wrap">
  <div style="display:flex;align-items:center;gap:10px;margin-bottom:20px;">
    <div style="width:34px;height:34px;background:var(--accent);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
      <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
    </div>
    <div>
      <div style="font-size:15px;font-weight:700;color:var(--text);">8Core Scanner v2.0</div>
      <div style="font-size:12px;color:var(--text-muted);">Migracija baze podataka</div>
    </div>
  </div>

  <div class="panel notice ok" style="margin-bottom:16px;">
    <?php foreach ($messages as $m): ?>
      <div class="log-line"><?= htmlspecialchars($m, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endforeach; ?>
  </div>

  <div class="util-links">
    <a href="../login.php">Prijava</a>
    <a href="../index.php">Dashboard</a>
    <a href="../admin/index.php">Admin</a>
  </div>
</div>
</body>
</html>
