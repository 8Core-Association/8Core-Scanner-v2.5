# 8Core Scanner v2.0

**Vlasnički softver — Sva prava pridržana**
(c) 2026 Tomislav Galić / 8Core
Web: https://8core.hr | Kontakt: info@8core.hr

---

## Opis

8Core Scanner je sigurnosni scanner za shared hosting okruženja.
Skenira korisničke račune na serveru tražeći zlonamjerne fajlove
(web shellovi, obfusciran PHP, sumnjive ekstenzije i slično),
a rezultate prikazuje kroz web panel s upravljanjem nalazima.

---

## Arhitektura

Projekt je podijeljen u dva odvojena dijela:

| Dio            | Opis                                              |
|----------------|---------------------------------------------------|
| **Web panel**  | PHP aplikacija, radi pod web serverom             |
| **Root engine**| Bash skripte, radi kao root via cron              |

**Web panel i root engine NE smiju biti na istoj putanji.**
Komunikacija između njih odvija se isključivo preko baze podataka (scan queue).

---

## Struktura ZIP paketa

```
8core-scanner-v2/              ← paketna mapa (raspakirani ZIP)
├── scanner/                   ← SADRŽAJ kopirati u WEB_APP_PATH
├── 8core_scanner/             ← SADRŽAJ kopirati u ROOT_ENGINE_PATH
├── README.md
└── changelog.md
```

**Napomena:** `8core-scanner-v2/` je samo paketna mapa raspakiravanog ZIPa.
Ta mapa nije instalacijska putanja i ne smije postati dio URL-a ili serverske putanje.

---

## Instalacija — korak po korak

### Preduvjeti

- PHP >= 7.4 s ekstenzijama: `pdo`, `pdo_mysql`, `mbstring`, `json`
- MySQL / MariaDB
- Root pristup serveru
- Web server (Apache / Nginx)

---

### Korak 1 — Raspakiranje u privremenu lokaciju

ZIP uvijek raspakirati van web roota (npr. u `/root/`):

```bash
mkdir -p /root/8core-scanner-install
cd /root/8core-scanner-install
unzip 8core-scanner-v2-structure-fix2.zip
```

Nastaje:

```
/root/8core-scanner-install/
└── 8core-scanner-v2/
    ├── scanner/
    ├── 8core_scanner/
    ├── README.md
    └── changelog.md
```

> **Upozorenje:** Ako slučajno raspakiriš ZIP unutar web roota (npr.
> `/home/account/public_html/`), nastala mapa `8core-scanner-v2/` je
> privremena i treba je ukloniti nakon kopiranja. Nikad ne koristiti
> `8core-scanner-v2/` kao instalacijsku putanju.

---

### Korak 2 — Instalacija web panela

Kopirati **sadržaj** mape `scanner/` u odabrani `WEB_APP_PATH`:

```bash
# Primjer: web panel u subfolderu domene
mkdir -p /home/account/public_html/scanner
rsync -av /root/8core-scanner-install/8core-scanner-v2/scanner/ /home/account/public_html/scanner/
chown -R account:account /home/account/public_html/scanner
```

```bash
# Primjer: web panel u rootu subdomene
mkdir -p /home/account/scanner.domena.hr
rsync -av /root/8core-scanner-install/8core-scanner-v2/scanner/ /home/account/scanner.domena.hr/
chown -R account:account /home/account/scanner.domena.hr
```

**Važno:** Kopira se SADRŽAJ mape `scanner/`, ne sama mapa.

Nakon kopiranja mora nastati (primjer sa subfolder-om):

```
/home/account/public_html/scanner/index.php
/home/account/public_html/scanner/login.php
/home/account/public_html/scanner/install/
/home/account/public_html/scanner/admin/
/home/account/public_html/scanner/includes/
/home/account/public_html/scanner/assets/
```

Ne smije nastati:

```
/home/account/public_html/scanner/scanner/index.php     ← POGRESNO
/home/account/public_html/8core-scanner-v2/scanner/     ← POGRESNO
/home/account/public_html/web/scanner/index.php         ← POGRESNO
```

---

### Korak 3 — Pokretanje installera

Otvoriti u pregledniku **iz konačne instalacijske putanje**:

```
# Ako je web panel u subfolderu:
https://domena.hr/scanner/install/

# Ako je web panel u rootu subdomene:
https://scanner.domena.hr/install/
```

Ne otvarati installer iz privremene paketne putanje:

```
https://domena.hr/8core-scanner-v2/scanner/install/     ← POGRESNO
```

Installer automatski detektira `WEB_APP_PATH` iz vlastite lokacije na serveru.

Installer će:
1. Provjeriti PHP okruženje (verzija, ekstenzije, dozvole)
2. Zatražiti DB podatke i putanje (`WEB_APP_PATH`, `WEB_APP_URL`, `ENGINE_SOURCE_PATH`, `ROOT_ENGINE_PATH`, `QUARANTINE_BASE_PATH`, `LOG_PATH`)
3. Kreirati sve potrebne tablice u bazi
4. Generirati `includes/config.php`
5. Zaključati se (`install/install.lock`)
6. Generirati **root install skriptu** (prikazuje se u textarea za kopiranje)

---

---

### Korak 4 — Instalacija root enginea (putem root install skripte)

Web installer **ne može pisati u root direktorije niti traži root lozinku**.
Umjesto toga, na kraju instalacije prikazuje generiranu bash skriptu u textarea.

**Root lozinka se ne unosi kroz browser.**

Tok:

1. Na kraju instalacije (korak 3 installera) kliknuti **"Kopiraj script"**
2. Zalijepiti u root terminal ili sačuvati kao fajl
3. Pokrenuti kao root:

```bash
chmod +x /root/install_8core_scanner.sh
bash /root/install_8core_scanner.sh
```

Što root install script radi:
- Provjerava postoji li `ENGINE_SOURCE_PATH/ioc_scan.sh`
- Kreira `ROOT_ENGINE_PATH`, `LOG_PATH`, `QUARANTINE_BASE_PATH`
- Kopira engine fajlove (`cp -a`)
- Kreira `scanner-db.conf` s DB podacima (shell-safe escaping)
- Postavlja vlasništvo `root:root` i permisije (`700`, `600`)
- Označava `ioc_scan.sh` i `scanner_worker.sh` kao executable
- Ispisuje cron primjer

**ENGINE_SOURCE_PATH** unositi u installer — to je putanja do raspakirane mape `8core_scanner/` iz ZIP paketa:

```
/root/8core-scanner-install/8core-scanner-v2/8core_scanner
```

**ROOT_ENGINE_PATH** je gdje će engine biti instaliran (default: `/root/8core_scanner`).
Administrator bira stvarnu putanju — nije hardkodirano.

**QUARANTINE_BASE_PATH** je odvojena putanja za karantenu — **ne mora biti unutar ROOT_ENGINE_PATH**.
Default prijedlog: `/home/8core_quarantine`
Alternativa: `/root/8core_scanner/quarantine` ili bilo koja druga putanja van web roota.

Pravila za QUARANTINE_BASE_PATH:
- Mora biti apsolutna putanja
- Ne smije biti unutar `public_html` ili bilo kojeg web-dostupnog direktorija
- Vlasnik: `root:root`, permisije: `700`
- `ioc_scan.sh` automatski isključuje `QUARANTINE_BASE_PATH` iz skeniranja (prune) ako se nalazi unutar `BASE` scan putanje (npr. ako je scan `--all` i karantena je unutar `/home`)

```bash
mkdir -p /root/8core_scanner
rsync -av /root/8core-scanner-install/8core-scanner-v2/8core_scanner/ /root/8core_scanner/
chown -R root:root /root/8core_scanner
chmod +x /root/8core_scanner/ioc_scan.sh /root/8core_scanner/scanner_worker.sh
# Karantena je odvojena od root engine putanje:
mkdir -p /home/8core_quarantine
chown root:root /home/8core_quarantine
chmod 700 /home/8core_quarantine
```

**Napomena:** Ako si koristio root install script iz installera, ovaj korak je već obavljen automatski.

Nakon kopiranja mora nastati:

```
/root/8core_scanner/ioc_scan.sh
/root/8core_scanner/scanner_worker.sh
/root/8core_scanner/scanner-db.conf.sample
/root/8core_scanner/logs/
# Karantena se kreira odvojeno (QUARANTINE_BASE_PATH):
/home/8core_quarantine/
```

Ne smije nastati:

```
/root/8core_scanner/8core_scanner/scanner_worker.sh     ← POGRESNO
/root/root/8core_scanner/scanner_worker.sh              ← POGRESNO
```

> **Sigurnost:** Root engine mora biti **van web roota**. Nikad ne
> instalirati `8core_scanner/` unutar `/home/*/public_html/` ili bilo
> koje web-dostupne putanje.

---

### Korak 5 — Postavljanje cron joba

```bash
# Kao root (crontab -e):
* * * * * /root/8core_scanner/scanner_worker.sh >> /root/8core_scanner/logs/scanner_worker_cron.log 2>&1
```

Zamijeniti `/root/8core_scanner/` sa stvarnom `ROOT_ENGINE_PATH` ako je drugačija.

---

### Korak 6 — Sigurnost mape install/

Nakon provjere rada sustava obrisati ili preimenovati mapu `install/`:

```bash
# Brisanje (preporučeno):
rm -rf /home/account/public_html/scanner/install/

# Ili preimenovanje:
mv /home/account/public_html/scanner/install/ \
   /home/account/public_html/scanner/install_disabled/
```

Mapa je zaštićena putem `install.lock` (blocked 403), ali brisanje eliminira površinu napada.
Za buduće nadogradnje sheme baze koristiti `install/migrate.php` (zahtijeva admin prijavu).

### Korak 7 — Čišćenje

Nakon uspješne instalacije ukloniti privremenu paketnu mapu:

```bash
rm -rf /root/8core-scanner-install
```

---

## Konfiguracija

### Web konfiguracija (`includes/config.php`)

Generira installer. Ključne vrijednosti:

| Ključ              | Opis                                      |
|--------------------|-------------------------------------------|
| `db_host`          | MySQL host                                |
| `db_name`          | Naziv baze                                |
| `db_user`          | Korisnik baze                             |
| `db_pass`          | Lozinka baze                              |
| `root_engine_path` | Putanja root enginea                      |
| `scan_script`      | Putanja do `ioc_scan.sh`                  |
| `scan_log`         | Putanja do live log fajla                 |
| `quarantine_path`  | Putanja karantene (QUARANTINE_BASE_PATH)  |

### Root konfiguracija (`scanner-db.conf`)

Generira installer. Ključne vrijednosti:

| Varijabla          | Opis                                      |
|--------------------|-------------------------------------------|
| `DB_HOST`          | MySQL host                                |
| `DB_NAME`          | Naziv baze                                |
| `DB_USER`          | Korisnik baze                             |
| `DB_PASS`          | Lozinka baze                              |
| `ROOT_ENGINE_PATH`      | Putanja root enginea                      |
| `QUARANTINE_BASE_PATH`  | Putanja karantene (odvojena od engine)    |
| `LOG_PATH`              | Putanja log direktorija                   |

---

## Sigurnost

- `includes/config.php` — NE commitati, NE javno dostupan
- `scanner-db.conf` — `chmod 600`, vlasnik root, **van web roota**
- `install/install.lock` — nastaje nakon instalacije, blokira reinstalaciju (403)
- `install/` mapa — preporučuje se brisanje ili preimenovanje nakon instalacije
- Root engine mora biti van web roota bez iznimke
- Web panel ne može direktno izvršavati root naredbe
- Root lozinka se ne unosi kroz browser niti PHP formu
- `8core-scanner-v2/` paketna mapa ukloniti nakon instalacije

---

## Tok rada

```
Web panel                     Baza podataka           Root engine
─────────────────────         ─────────────────        ─────────────────────
Korisnik zatraži scan  ──►  scanner_scan_requests  ◄──  scanner_worker.sh
Pregled nalaza         ◄──  findings               ──►  ioc_scan.sh
Akcija (quarantine)    ──►  findings.action_status  ◄──  scanner_worker.sh
```

---

## Verzija

Trenutna verzija: **2.0.0** (2026-06-28)

Vidi `changelog.md` za povijest izmjena.
