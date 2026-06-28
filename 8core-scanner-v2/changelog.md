# Changelog — 8Core Scanner

Sve značajne izmjene dokumentirane su u ovoj datoteci.
Format se temelji na [Keep a Changelog](https://keepachangelog.com/hr/1.0.0/).
Verzioniranje slijedi [Semantic Versioning](https://semver.org/lang/hr/).

---

## [2.0.1] — 2026-06-28

### Added

- **`scanner/admin/update.php`** — Admin Update stranica:
  - Upload ZIP paketa, dry-run prikaz, apply web update
  - Preskače `includes/config.php` i `install/install.lock` (nikad se ne prepisuju)
  - Sekcija B: DB migracije — prikaz pending/primjenjeno, gumb za primjenu
  - Sekcija C: Root engine update skripta (textarea za kopiranje, ne izvršava web)
  - Backup root enginea u `ROOT_ENGINE_PATH_backup_YYYYMMDD_HHMMSS` prije kopiranja
  - ZIP validacija: provjera ekstenzije, path traversal zaštita, strukturna provjera
- **`scanner/admin/about.php`** — O scanneru:
  - Verzije (paket, instalirana), okruženje (PHP, hostname, web path, install.lock)
  - Konfigurirane putanje (root_engine_path, quarantine_path, scan_log)
  - Cron hint s ispravnim putanjama
  - Health Check: config.php, install.lock, DB konekcija, sve potrebne tablice, putanje
- **`scanner/admin/changelog.php`** — Prikaz changelog.md iz root paketa
  - Minimalni Markdown → HTML renderer (headings, liste, code, bold)
  - Fallback poruka ako fajl nije pronađen
- **`scanner/admin/sidebar.php`** — Dodane stavke: Update, Changelog, O scanneru
- **`scanner/VERSION`** — verzija paketa (`2.0.1`)
- **`scanner/install/migrations/20260628_001_add_migrations_and_settings.sql`**:
  - `scanner_migrations` tablica (tracking primjenjenih migracija)
  - `scanner_settings` tablica (installed_version, installed_at, last_updated_at)
- **`scanner/install/migrations/20260628_002_add_rule_key.sql`**:
  - `rule_key` kolona u `scanner_rules` (hash za deduplikaciju pri importu)
  - `imported_from` kolona
  - Popunjava `rule_key` za postojeće retke

### Changed

- Update proces čuva sve korisničke podatke (users, findings, rules, ignore list, config, quarantine, logs, cron)
- Web update preskače zaštićene fajlove, ne briše ništa
- Root engine update kreira backup prije kopiranja

### Security

- Update ne prepisuje `scanner-db.conf`, `config.php`, `install/install.lock`
- Update ne traži root lozinku kroz web formu
- Root update skripta se samo generira — web je ne izvršava
- ZIP upload: provjera ekstenzije, path traversal filter, strukturna validacija
- Temp direktorij se briše nakon primjene

---

## [2.0.0-fix5] — 2026-06-28

### Usklađivanje QUARANTINE_BASE_PATH i isključivanje iz scanova (fix5)

#### Izmijenjeno

- **`8core_scanner/scanner_worker.sh`** — `QUARANTINE_BASE` sada koristi redoslijed prioriteta:
  1. `QUARANTINE_BASE_PATH` (nova varijabla iz installera)
  2. `QUARANTINE_PATH` (stara varijabla, backward compat)
  3. fallback: `${SCRIPT_DIR}/quarantine`
  Promjena na oba mjesta (prije i poslije `source "$CONFIG"`).

- **`8core_scanner/ioc_scan.sh`** — dodano isključivanje karantene iz scan operacija:
  - `QUARANTINE_BASE_PATH` se čita iz configa s fallbackom na `QUARANTINE_PATH`
  - Ako je `QUARANTINE_BASE_PATH` definiran i nalazi se unutar `BASE` scan putanje,
    `find` koristi `-path "$QUARANTINE_BASE_PATH" -prune` da preskoči karantenu
  - Primijenjeno na sve `scan_pattern` pozive i direktni `find` blok za shell indikatore

- **`scanner/install/index.php`** — poboljšan hint za polje `QUARANTINE_BASE_PATH`:
  eksplicitna napomena da ne smije biti unutar `public_html`, preporučene permisije i vlasnik

- **`README.md`** — ažurirana napomena uz `QUARANTINE_BASE_PATH`:
  opisano automatsko isključivanje iz skeniranja via `find -prune`

#### Sigurnost

- Karantena se više ne skenira tiho kao sumnjivi fajlovi (`ioc_scan.sh` je svjestan vlastite karantene)
- `scanner_worker.sh` koristi ispravnu putanju karantene prema konfiguraciji — ne vraća se na `/root/8core_scanner/quarantine` ako je definiran `QUARANTINE_BASE_PATH`

---

## [2.0.0-fix4] — 2026-06-28

### Konfigurabilna karantena (QUARANTINE_BASE_PATH) (fix4)

#### Izmijenjeno

- **`install/index.php`** — polje `QUARANTINE_PATH` preimenovano u `QUARANTINE_BASE_PATH`:
  - Default prijedlog promijenjen iz `/root/8core_scanner/quarantine` u `/home/8core_quarantine`
  - Hint u formi: upozorenje da ne smije biti unutar public_html
  - Bash varijabla u root install skripti preimenovana u `QUARANTINE_BASE_PATH`
  - Root install script: dodan odvojeni `chown root:root` i `chmod 700` za `QUARANTINE_BASE_PATH`
  - Checklist na koraku 3: dodan red za provjeru permisija karantene
  - Scanner-db.conf u root install skripti: varijabla preimenovana u `QUARANTINE_BASE_PATH`
- **`8core_scanner/scanner-db.conf.sample`** — varijabla `QUARANTINE_PATH` → `QUARANTINE_BASE_PATH`, default `/home/8core_quarantine`
- **`scanner/install/templates/scanner-db.sample.conf`** — varijabla `QUARANTINE_PATH` → `QUARANTINE_BASE_PATH`, default `/home/8core_quarantine`
- **`scanner/includes/config.sample.php`** — komentar uz `quarantine_path` navodi da ne smije biti u public_html, default `/home/8core_quarantine`
- **`README.md`** — dodana sekcija za `QUARANTINE_BASE_PATH`:
  - Pravila (van public_html, apsolutna putanja, root:root, 700)
  - Default prijedlog `/home/8core_quarantine` s alternativama
  - Napomena da scanner mora isključiti tu putanju iz scanova

#### Nije izmijenjeno

- Ključ `quarantine_path` u `config.php` i aplikacijskom kodu ostaje isti (interno)
- Sav scan/PHP/bash logika
- Struktura ZIP paketa

---

## [2.0.0-fix3] — 2026-06-28

### Dodavanje root engine install koraka u installer (fix3)

#### Dodano

- **`install/index.php`** — novi završni korak "Root engine instalacija" (korak 3):
  - Polje `ENGINE_SOURCE_PATH` — putanja do raspakirane mape `8core_scanner/` iz ZIP paketa
  - Validacija `ENGINE_SOURCE_PATH`: upozorenje ako `ioc_scan.sh` ili `scanner_worker.sh` nisu pronađeni (ne blokira instalaciju web dijela)
  - **Generiranje root install skripte** — prikazuje se u `<textarea>` za kopiranje, ne sprema se javno na disk
  - Gumb "Kopiraj script" (Clipboard API + fallback)
  - Provjera izvorne mape u skripti (`set -e`, bail na missing files)
  - Automatsko kreiranje `ROOT_ENGINE_PATH`, `LOG_PATH`, `QUARANTINE_PATH`
  - Kopiranje engine fajlova (`cp -a`)
  - Generiranje `scanner-db.conf` putem bash heredoc s nepromjenjivim delimiterom (`_8CORE_CONF_END_`)
  - Postavljanje vlasništva `root:root` i permisija (`700`/`600`/`+x`)
  - Sintaktička provjera skripti u završnoj fazi (`bash -n`)
  - Prikaz cron primjera s stvarnim putanjama
  - Checklist koraka za provjeru nakon instalacije
  - Sigurnosna preporuka za brisanje/preimenovanje mape `install/` s primjerom naredbi
- **`install/index.php`** — poboljšana poruka za zaključani installer (`install.lock`):
  - Prikazuje sigurnosnu preporuku za brisanje mape `install/`
- **`sh_quote()`** — nova PHP funkcija za shell-safe single-quote escaping bash vrijednosti
- **`generateRootInstallScript()`** — generira kompletnu bash skriptu s DB podacima i putanjama

#### Izmijenjeno

- **`install/index.php`** — uklonjen `file_put_contents` za `generated-scanner-db.conf`
  (DB podaci više nisu zapisani u web-dostupan fajl — sadržaj je samo u textarea skripte)
- **`README.md`** — ažuriran korak instalacije root enginea:
  - Opis root install skripte i toka (root lozinka se ne unosi kroz browser)
  - Sekcija za sigurnost mape `install/` (korak 6)
  - Sigurnosna sekcija proširena: install.lock, preporuka brisanja install/, nema root lozinke kroz PHP

#### Sigurnost

- Root lozinka se ne traži, ne prima, ne sprema niti prosljeđuje kroz web formu
- DB lozinka u root install skripti koristi shell-safe single-quote escaping
- Root install script se prikazuje u textarea i ne sprema se na disk kao javno dostupan fajl
- `install.lock` blokira ponovni pristup installeru (HTTP 403)
- Preporuka za brisanje mape `install/` nakon instalacije dokumentirana i prikazana u UI

---

## [2.0.0-fix2] — 2026-06-28

### Ispravak instalacijske logike i dokumentacije putanja (fix2)

#### Izmijenjeno

- **`README.md`** — potpuni ispravak instalacijske dokumentacije:
  - Jasna razlika između ZIP paketne mape (`8core-scanner-v2/`) i stvarnih instalacijskih putanja
  - Uputa da se ZIP uvijek raspakira u privremenu lokaciju van web roota (npr. `/root/`)
  - Primjeri kopiranja s `rsync` koji kopiraju **sadržaj** mapa, ne same mape
  - Dva konkretna primjera: web panel u subfolderu domene i u rootu subdomene
  - Primjeri ispravnih i neispravnih rezultata kopiranja (što smije, što ne smije nastati)
  - Upozorenje da `8core-scanner-v2/` nije instalacijska putanja i da se uklanja nakon instalacije
  - Upozorenje da se installer otvara iz **konačne instalacijske putanje**, ne iz privremene paketne mape
  - Napomena da root engine mora biti van web roota bez iznimke
  - Korak za čišćenje privremene paketne mape nakon instalacije
- **Instalacijska logika** — potvrđeno da `installer/index.php` ispravno detektira `WEB_APP_PATH`
  iz stvarne lokacije skripte (`realpath(__DIR__ . '/../')`) neovisno o paketnoj strukturi

#### Nije izmijenjeno

- Sav PHP kod web panela i bash engine kod
- Struktura ZIP paketa (ostaje kao u fix1)
- Verzija ostaje **2.0.0** (ovo je korekcija dokumentacije)

---

## [2.0.0-fix1] — 2026-06-28

### Korekcija strukture paketa (fix1)

#### Izmijenjeno

- **Struktura ZIP paketa** — uklonjene `web/` i `root/` omotne mape koje su mogle dovesti do
  pogrešnih instalacijskih putanja (npr. `/public_html/web/scanner/` ili `/root/root/8core_scanner/`)
- **`web/scanner/`** preimenovano u **`scanner/`** — sadržaj se kopira direktno u `WEB_APP_PATH`
- **`root/8core_scanner/`** preimenovano u **`8core_scanner/`** — sadržaj se kopira direktno u `ROOT_ENGINE_PATH`
- **`scanner/assets/img/`** — dodana mapa za buduće slike (logo, ikone, UI elementi); za sada prazna s `.gitkeep`
- **`README.md`** — ažurirana struktura, napomena o razlici između ZIP mapa i instalacijskih putanja,
  proširene instalacijske upute s primjerima ispravnih i neispravnih putanja

#### Nije izmijenjeno

- Sva poslovna logika web panela i bash enginea
- Sve relativne putanje u PHP kodu (već ispravne, ne ovise o ZIP strukturi)
- Verzija ostaje **2.0.0** (ovo je korekcija pakiranja, ne novi release)

---

## [2.0.0] — 2026-06-28

### Reorganizacija arhitekture (V2.0 paket)

Ovo izdanje ne donosi nove funkcionalnosti — radi se o potpunoj
reorganizaciji strukture projekta radi čišće osnove za daljnji razvoj.

#### Dodano

- **Struktura paketa `8core-scanner-v2/`** s jasnom podjelom na `scanner/` i `8core_scanner/`
- **Installer** (`scanner/install/index.php`) — korak-po-korak instalacija s:
  - Provjerom PHP okruženja (verzija, ekstenzije, dozvole)
  - Unosom DB podataka i testom konekcije
  - Podesivim putanjama: `WEB_APP_PATH`, `WEB_APP_URL`, `ROOT_ENGINE_PATH`, `QUARANTINE_PATH`, `LOG_PATH`
  - Automatskim generiranjem `includes/config.php`
  - Automatskim generiranjem `install/generated-scanner-db.conf` za root engine
  - Pokretanjem migracije sheme baze
  - Zaključavanjem installera (`install.lock`) nakon uspješne instalacije
- **`install/checks.php`** — provjere okruženja i logika migracije odvojena u zasebni modul
- **`install/migrate.php`** — premješteno iz korijena web aplikacije u `install/` mapu; zaštićeno autentikacijom
- **`install/schema.sql`** — referentna SQL shema svih tablica
- **`install/install.lock.example`** — primjer lock fajla
- **`install/templates/config.sample.php`** — predložak PHP konfiguracije
- **`install/templates/scanner-db.sample.conf`** — predložak bash DB konfiguracije
- **`includes/config.sample.php`** — predložak koji zamjenjuje stvarni `config.php` u paketu
- **`8core_scanner/scanner-db.conf.sample`** — predložak koji zamjenjuje stvarni `scanner-db.conf`
- **`8core_scanner/logs/.gitkeep`** — prazni placeholder za log direktorij
- **`8core_scanner/quarantine/.gitkeep`** — prazni placeholder za karantena direktorij
- **`8core_scanner/bin/.gitkeep`** — rezerviran za buduće binarne skripte
- **`8core_scanner/lib/.gitkeep`** — rezerviran za buduće biblioteke
- **`8core_scanner/modules/.gitkeep`** — rezerviran za buduće module
- **`8core_scanner/rules/.gitkeep`** — rezerviran za buduća pravila u fajlovima
- **`8core_scanner/migrations/.gitkeep`** — rezerviran za buduće DB migracije
- **`README.md`** — dokumentacija strukture, instalacije i konfiguracije (na hrvatskom)
- **`changelog.md`** — ovaj fajl

#### Izmijenjeno

- **`includes/db.php`** — dodana automatska preusmjeravanja na installer ako `config.php` ne postoji
- **`includes/auth.php`** — prilagođene `require_login()` / `require_admin()` putanje za rad na proizvoljnoj dubini
- **`ioc_scan.sh`** (v3.1 → v3.2) — putanje (`CONFIG`, `RUN_LOG`) sada se detektiraju relativno prema lokaciji skripte uz podršku za `--config=` argument; log se sprema u `LOG_PATH` iz konfiguracije
- **`scanner_worker.sh`** (v1.2 → v1.3) — putanje (`CONFIG`, `SCANNER`, `LOG`, `QUARANTINE_BASE`) sada se detektiraju relativno prema lokaciji skripte; podrška za `LOG_PATH` i `QUARANTINE_PATH` iz konfiguracije; prosljeđuje `--config=` argument scanneru
- **`index.php`** — ažuriran link za migrate na `install/migrate.php`; verzija u sidebaru: `v2.0`
- **`login.php`** — ažurirana verzija u naslovu: `v2.0`
- **`scan.php`** — ažurirana verzija u sidebaru: `v2.0`
- **`admin/sidebar.php`** — ažurirana verzija: `Admin Panel v2.0`; popravljen `mb_strtoupper` za avatar
- **`admin/index.php`** — tekst `Active/Inactive` preveden na `Aktivan/Neaktivan`
- **`admin/users.php`** — tekst `Activate/Deactivate` preveden na `Aktiviraj/Deaktiviraj`; tekst `Set pass` preveden na `Postavi`
- Sve `include`/`require` putanje prilagođene novoj dubini direktorija

#### Uklonjeno

- **Stvarni `config.php`** iz paketa (sadrži lozinke — ne smije biti u repozitoriju)
- **Stvarni `scanner-db.conf`** iz paketa (sadrži lozinke — ne smije biti u repozitoriju)
- **Svi log fajlovi** (`*.log`, `ioc-debug.log`, `ioc-scan-live.log`, `scanner-worker.log`, `scanner_worker_cron.log`) — sadrže stvarne putanje, korisnike i nalaze
- **`debug.php`** iz korijena web aplikacije — osjetljiv dijagnostički alat koji ne smije biti javno dostupan bez zaštite

#### Sigurnost

- Installer se zaključava nakon uspješne instalacije (`install.lock`)
- `config.php` se NE isporučuje u paketu — generira ga installer
- `scanner-db.conf` se NE isporučuje u paketu — generira ga installer
- Root engine je van web roota
- Web panel ne može direktno izvršavati root naredbe

---

## [1.5.0] — 2026-05-xx *(rekonstruirano iz koda)*

### Poboljšanja web panela

#### Dodano

- **Pravila i definicije** (`admin/rules.php`) — CRUD sučelje za IOC pravila s tipovima: filename, path, regex, regex_content, SHA256, chmod, extension, filesize
- **Ignore lista** (`admin/ignore.php`) — kategorizirani popis ignoriranih fajlova, putanja, hasheva i korisnika
- **Bulk akcije** na nalazima — odabir više nalaza i primjena akcije odjednom
- **CSV uvoz/izvoz** pravila
- **scanner_ignore_list** tablica u bazi
- **scanner_rules** tablica u bazi
- **scanner_scan_requests** — queue sustav za zahtjeve skeniranja
- Podrška za više accounta po korisniku (`scanner_user_accounts` tablica)
- Prikaz `source_guess` i `source_type` u tablici nalaza
- Email polje za korisnike

#### Izmijenjeno

- Scan triggering premješten na queue logiku (scan.php → scanner_scan_requests → worker)
- Prošireni detalji nalaza: SHA-256, ctime, birth_time, quarantine_path
- Admin sidebar reorganiziran s grupiranjem po sekcijama

---

## [1.0.0] — 2026-04-xx *(rekonstruirano iz koda)*

### Inicijalno izdanje

#### Dodano

- Osnovna struktura web panela (login, dashboard, admin)
- PHP autentikacija s bcrypt lozinkama i session managementom
- PDO konekcija na MySQL/MariaDB
- Prikaz nalaza s filtiranjem po riziku, accountu, statusu i pretraživanjem
- Akcije na nalazima: checked, ignore, quarantine_requested, delete_requested
- Admin panel: upravljanje korisnicima s role-based pristupom
- Bash IOC scanner (`ioc_scan.sh`) koji radi kao root
- Bash worker (`scanner_worker.sh`) za procesiranje akcija
- Stat kartice: CRITICAL, HIGH, MEDIUM, ignored, quarantine req., delete req.
- `scanner_users`, `findings`, `scans`, `scanner_actions` tablice u bazi

---

*Ovaj changelog automatski se ažurira uz svako novo izdanje.*
