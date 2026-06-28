<?php
/**
 * 8Core Scanner v2.0 — Installer: Provjere okruženja
 * (c) 2026 Tomislav Galić <tomislav@8core.hr>
 * Sva prava pridržana.
 */
if (!defined('SCANNER_INSTALL')) {
    http_response_code(403);
    exit('Forbidden');
}

function getChecks(): array {
    $checks = [];

    // PHP verzija
    $phpOk = version_compare(PHP_VERSION, '7.4.0', '>=');
    $checks[] = [
        'label' => 'PHP verzija: ' . PHP_VERSION,
        'ok'    => $phpOk,
        'note'  => $phpOk ? '' : 'Potrebna PHP >= 7.4',
    ];

    // PDO MySQL
    $pdoOk = extension_loaded('pdo') && extension_loaded('pdo_mysql');
    $checks[] = [
        'label' => 'PDO MySQL ekstenzija',
        'ok'    => $pdoOk,
        'note'  => $pdoOk ? '' : 'Instalirati php-pdo i php-mysqlnd',
    ];

    // mbstring
    $mbOk = extension_loaded('mbstring');
    $checks[] = [
        'label' => 'mbstring ekstenzija',
        'ok'    => $mbOk,
        'note'  => $mbOk ? '' : 'Instalirati php-mbstring',
    ];

    // json
    $jsonOk = extension_loaded('json');
    $checks[] = [
        'label' => 'JSON ekstenzija',
        'ok'    => $jsonOk,
        'note'  => $jsonOk ? '' : 'Instalirati php-json',
    ];

    // Pisanje u includes/
    $includesDir  = realpath(__DIR__ . '/../includes');
    $includesWrite = $includesDir && is_writable($includesDir);
    $checks[] = [
        'label' => 'Pisanje u includes/ direktorij',
        'ok'    => $includesWrite,
        'note'  => $includesWrite ? $includesDir : 'chmod 755 ili chown na web korisnika',
    ];

    // Pisanje u install/
    $installWrite = is_writable(__DIR__);
    $checks[] = [
        'label' => 'Pisanje u install/ direktorij',
        'ok'    => $installWrite,
        'note'  => $installWrite ? __DIR__ : 'chmod 755 ili chown na web korisnika',
    ];

    // config.php još ne postoji (čisti install)
    $configExists = file_exists(__DIR__ . '/../includes/config.php');
    $checks[] = [
        'label' => 'config.php ' . ($configExists ? 'već postoji (reinstalacija)' : 'ne postoji (čista instalacija)'),
        'ok'    => true,
        'warn'  => false,
        'note'  => $configExists ? 'Prepisati postojeću konfiguraciju' : '',
    ];

    return $checks;
}

function runMigrations(PDO $pdo, string $adminUser, string $adminPass): array {
    $messages = [];

    function _col(PDO $p, $t, $c) {
        $s = $p->prepare("SHOW COLUMNS FROM `$t` LIKE ?");
        $s->execute([$c]);
        return (bool)$s->fetch();
    }
    function _addcol(PDO $p, array &$m, $t, $c, $def) {
        if (!_col($p, $t, $c)) {
            $p->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def");
            $m[] = "DODANO: $t.$c";
        } else {
            $m[] = "OK: $t.$c";
        }
    }

    // scans
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS scans (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            started_at DATETIME NOT NULL,
            finished_at DATETIME NULL,
            base_path VARCHAR(500) NOT NULL,
            target_type VARCHAR(30) NULL,
            target_value VARCHAR(255) NULL,
            files_found INT UNSIGNED DEFAULT 0,
            status VARCHAR(30) DEFAULT 'RUNNING',
            INDEX(status), INDEX(started_at), INDEX(target_type), INDEX(target_value)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $messages[] = 'OK: scans';

    // findings
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS findings (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            scan_id BIGINT UNSIGNED NOT NULL,
            rule_name VARCHAR(150) NOT NULL,
            risk VARCHAR(20) NOT NULL,
            account_name VARCHAR(80) NULL,
            owner_name VARCHAR(80) NULL,
            group_name VARCHAR(80) NULL,
            perms VARCHAR(20) NULL,
            file_size BIGINT UNSIGNED NULL,
            file_name VARCHAR(255) NULL,
            file_ext VARCHAR(30) NULL,
            file_path TEXT NOT NULL,
            relative_path TEXT NULL,
            mtime DATETIME NULL,
            ctime DATETIME NULL,
            birth_time DATETIME NULL,
            detected_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            source_guess VARCHAR(255) NULL,
            source_type VARCHAR(80) NULL,
            sha256 CHAR(64) NULL,
            action_status VARCHAR(40) NOT NULL DEFAULT 'new',
            action_note TEXT NULL,
            action_at DATETIME NULL,
            action_by VARCHAR(80) NULL,
            quarantine_path TEXT NULL,
            action_error TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX(scan_id), INDEX(risk), INDEX(rule_name), INDEX(account_name),
            INDEX(owner_name), INDEX(file_ext), INDEX(detected_at), INDEX(action_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $messages[] = 'OK: findings';

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

    // scanner_users
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS scanner_users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(80) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('admin','user') NOT NULL DEFAULT 'user',
            account_name VARCHAR(80) NULL,
            email VARCHAR(180) NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            last_login DATETIME NULL,
            INDEX(role), INDEX(account_name), INDEX(active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $messages[] = 'OK: scanner_users';

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

    // scanner_scan_requests
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
            INDEX(status), INDEX(requested_by), INDEX(target_type), INDEX(target_value), INDEX(requested_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $messages[] = 'OK: scanner_scan_requests';

    // Kreiraj admin korisnika ako nema korisnika
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM scanner_users")->fetchColumn();
    if ($cnt === 0) {
        $hash = password_hash($adminPass, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("
            INSERT INTO scanner_users (username, password_hash, role, account_name, active, created_at)
            VALUES (?, ?, 'admin', NULL, 1, NOW())
        ");
        $stmt->execute([$adminUser, $hash]);
        $messages[] = "KREIRAN ADMIN: $adminUser";
    } else {
        $messages[] = 'OK: korisnici već postoje, admin nije kreiran';
    }

    $messages[] = 'MIGRACIJA ZAVRŠENA';
    return $messages;
}
