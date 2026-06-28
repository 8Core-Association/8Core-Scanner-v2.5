<?php
/**
 * 8Core Scanner v2.0 — config.sample.php (template za installer)
 * (c) 2026 Tomislav Galić <tomislav@8core.hr>
 * Sva prava pridržana.
 *
 * Ovaj fajl je predložak. Pravi config.php generira installer.
 * NE koristiti direktno. NE commitati config.php u repozitorij.
 */
return [
    'db_host'    => 'localhost',
    'db_name'    => 'ime_baze',
    'db_user'    => 'korisnik_baze',
    'db_pass'    => 'lozinka_baze',
    'db_charset' => 'utf8mb4',

    'default_admin_user' => 'admin',
    'default_admin_pass' => 'PromijeniOvuLozinku2026!',

    'root_engine_path' => '/root/8core_scanner',
    'scan_script'      => '/root/8core_scanner/ioc_scan.sh',
    'scan_log'         => '/root/8core_scanner/logs/ioc-scan-live.log',
    'scan_debug'       => '/root/8core_scanner/logs/ioc-debug.log',
    'quarantine_path'  => '/root/8core_scanner/quarantine',
];
