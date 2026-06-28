<?php
/**
 * 8Core Scanner v2.0 — Konfiguracija (SAMPLE)
 * (c) 2026 Tomislav Galić <tomislav@8core.hr>
 * Web: https://8core.hr
 * Kontakt: info@8core.hr | Tel: +385 099 851 0717
 * Sva prava pridržana. Ovaj softver je vlasnički i zabranjeno ga je
 * distribuirati ili mijenjati bez izričitog dopuštenja autora.
 *
 * NAPOMENA: Ova datoteka je predložak (sample).
 * Pravi config.php generira installer pri instalaciji.
 * NE koristiti ovu datoteku direktno — kopirati u config.php i podesiti vrijednosti.
 */
return [
    // ── Baza podataka ──────────────────────────────────────────────────────
    'db_host'    => 'localhost',
    'db_name'    => 'ime_baze',
    'db_user'    => 'korisnik_baze',
    'db_pass'    => 'lozinka_baze',
    'db_charset' => 'utf8mb4',

    // ── Zadani admin (kreiran pri prvoj instalaciji ako nema korisnika) ────
    // OBVEZNO promijeniti lozinku nakon prvog logina!
    'default_admin_user' => 'admin',
    'default_admin_pass' => 'PromijeniOvuLozinku2026!',

    // ── Putanje root enginea (van web roota) ──────────────────────────────
    // Vrijednosti generira installer prema unosu administratora.
    'root_engine_path' => '/root/8core_scanner',
    'scan_script'      => '/root/8core_scanner/ioc_scan.sh',
    'scan_log'         => '/root/8core_scanner/logs/ioc-scan-live.log',
    'scan_debug'       => '/root/8core_scanner/logs/ioc-debug.log',

    // QUARANTINE_BASE_PATH — NE mora biti unutar ROOT_ENGINE_PATH
    // NE smije biti unutar public_html ili bilo kojeg web-dostupnog direktorija
    'quarantine_path'  => '/home/8core_quarantine',
];
