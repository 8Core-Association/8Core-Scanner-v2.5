<?php
/**
 * 8Core Scanner v2.0 — Konekcija na bazu
 * (c) 2026 Tomislav Galić <tomislav@8core.hr>
 * Sva prava pridržana.
 */
$_configPath = __DIR__ . '/config.php';

if (!file_exists($_configPath)) {
    $installUrl = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/install/';
    header('Location: ' . $installUrl);
    exit;
}

$config = require $_configPath;

$dsn = 'mysql:host=' . $config['db_host'] . ';dbname=' . $config['db_name'] . ';charset=' . $config['db_charset'];

$pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
