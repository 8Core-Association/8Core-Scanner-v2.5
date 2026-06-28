#!/bin/bash
# ==========================================================
# 8Core IOC Scanner v3.2
# Copyright (c) 2026 8Core
# Author: Tomislav Galić / 8Core
# Web: https://8core.hr
# Output: MariaDB + live tail log
# ==========================================================

BASE="/home"
TARGET_TYPE="all"
TARGET_VALUE="/home"

# Konfiguracija se učitava iz scanner-db.conf koji se nalazi u
# istom direktoriju kao i ova skripta.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONFIG="${SCRIPT_DIR}/scanner-db.conf"

for arg in "$@"; do
  case "$arg" in
    --all)
      BASE="/home"
      TARGET_TYPE="all"
      TARGET_VALUE="/home"
      ;;
    --account=*)
      ACCOUNT="${arg#*=}"
      BASE="/home/$ACCOUNT"
      TARGET_TYPE="account"
      TARGET_VALUE="$ACCOUNT"
      ;;
    --path=*)
      BASE="${arg#*=}"
      TARGET_TYPE="custom_path"
      TARGET_VALUE="$BASE"
      ;;
    --config=*)
      CONFIG="${arg#*=}"
      ;;
    *)
      echo "Nepoznati argument: $arg"
      echo "Upotreba: $0 --all | --account=korisnik | --path=/home/korisnik/putanja [--config=/putanja/do/scanner-db.conf]"
      exit 1
      ;;
  esac
done

[ -f "$CONFIG" ] || { echo "GREŠKA: Nedostaje config: $CONFIG"; exit 1; }
source "$CONFIG"

DB_HOST="${DB_HOST//$'\r'/}"
DB_NAME="${DB_NAME//$'\r'/}"
DB_USER="${DB_USER//$'\r'/}"
DB_PASS="${DB_PASS//$'\r'/}"
DB_CHARSET="${DB_CHARSET//$'\r'/}"

# Putanje iz konfiguracije ili default
LOG_PATH="${LOG_PATH:-${SCRIPT_DIR}/logs}"
RUN_LOG="${LOG_PATH}/ioc-scan-live.log"

# Karantena se isključuje iz skeniranja
QUARANTINE_BASE_PATH="${QUARANTINE_BASE_PATH:-${QUARANTINE_PATH:-}}"

mkdir -p "$LOG_PATH"
: > "$RUN_LOG"

log() {
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$RUN_LOG"
}

die() {
  log "GREŠKA: $*"
  exit 1
}

[ -d "$BASE" ] || die "Putanja skeniranja ne postoji: $BASE"

case "$BASE" in
  /home|/home/*) ;;
  *) die "Nesigurna putanja blokirana: $BASE" ;;
esac

mysql_run() {
  mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" --default-character-set="${DB_CHARSET:-utf8mb4}" -N -B -e "$1"
}

sql_escape() {
  printf "%s" "$1" | sed "s/'/''/g"
}

guess_source() {
  local file="$1"

  case "$file" in
    *"/wp-content/uploads/"*)             echo "wordpress_upload|web_upload" ;;
    *"/wp-content/plugins/"*)             echo "wordpress_plugin|plugin" ;;
    *"/wp-content/themes/"*)              echo "wordpress_theme|theme" ;;
    *"/administrator/components/"*)       echo "joomla_admin_component|component" ;;
    *"/components/"*)                     echo "joomla_component|component" ;;
    *"/media/com_sppagebuilder/"*)        echo "sppagebuilder|builder" ;;
    *"/tmp/"*)                            echo "tmp_runtime_or_upload|tmp" ;;
    *"/cache/"*)                          echo "cache_runtime|cache" ;;
    *"/.well-known/"*)                    echo "well_known|system" ;;
    *)                                    echo "unknown|unknown" ;;
  esac
}

log "8Core IOC Scanner v3.2 pokrenut"
log "Base: $BASE"
log "Target tip: $TARGET_TYPE"
log "Target vrijednost: $TARGET_VALUE"
log "Baza: $DB_NAME@$DB_HOST"
log "Config: $CONFIG"

mysql_run "SELECT 1;" >/dev/null || die "Konekcija na bazu neuspješna"

mysql_run "
CREATE TABLE IF NOT EXISTS scans (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  base_path VARCHAR(500) NOT NULL,
  target_type VARCHAR(30) NULL,
  target_value VARCHAR(255) NULL,
  files_found INT UNSIGNED DEFAULT 0,
  status VARCHAR(30) DEFAULT 'RUNNING',
  INDEX(status),
  INDEX(started_at),
  INDEX(target_type),
  INDEX(target_value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX(scan_id),
  INDEX(risk),
  INDEX(rule_name),
  INDEX(account_name),
  INDEX(owner_name),
  INDEX(file_ext),
  INDEX(detected_at),
  INDEX(action_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
" || die "Kreiranje tablica neuspješno"

mysql_run "
ALTER TABLE scans
  ADD COLUMN IF NOT EXISTS target_type VARCHAR(30) NULL,
  ADD COLUMN IF NOT EXISTS target_value VARCHAR(255) NULL;
" >/dev/null 2>&1

mysql_run "
ALTER TABLE findings
  ADD COLUMN IF NOT EXISTS account_name VARCHAR(80) NULL,
  ADD COLUMN IF NOT EXISTS relative_path TEXT NULL,
  ADD COLUMN IF NOT EXISTS ctime DATETIME NULL,
  ADD COLUMN IF NOT EXISTS birth_time DATETIME NULL,
  ADD COLUMN IF NOT EXISTS detected_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  ADD COLUMN IF NOT EXISTS source_guess VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS source_type VARCHAR(80) NULL,
  ADD COLUMN IF NOT EXISTS file_ext VARCHAR(30) NULL,
  ADD COLUMN IF NOT EXISTS action_status VARCHAR(40) NOT NULL DEFAULT 'new',
  ADD COLUMN IF NOT EXISTS action_note TEXT NULL,
  ADD COLUMN IF NOT EXISTS action_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS action_by VARCHAR(80) NULL;
" >/dev/null 2>&1

SCAN_ID=$(mysql_run "
INSERT INTO scans (started_at, base_path, target_type, target_value)
VALUES (NOW(), '$(sql_escape "$BASE")', '$(sql_escape "$TARGET_TYPE")', '$(sql_escape "$TARGET_VALUE")');
SELECT LAST_INSERT_ID();
")

[ -n "$SCAN_ID" ] || die "Ne mogu kreirati scan zapis"

log "Scan ID: $SCAN_ID"

insert_finding() {
  local rule="$1"
  local risk="$2"
  local file="$3"

  [ -f "$file" ] || return

  local mtime ctime birth owner group size fname perms sha account rel ext source source_guess source_type

  mtime=$(stat -c '%y' "$file" 2>/dev/null | cut -d'.' -f1)
  ctime=$(stat -c '%z' "$file" 2>/dev/null | cut -d'.' -f1)
  birth=$(stat -c '%w' "$file" 2>/dev/null | cut -d'.' -f1)

  [ "$birth" = "-" ] && birth=""

  owner=$(stat -c '%U' "$file" 2>/dev/null)
  group=$(stat -c '%G' "$file" 2>/dev/null)
  size=$(stat -c '%s' "$file" 2>/dev/null)
  perms=$(stat -c '%a' "$file" 2>/dev/null)
  fname=$(basename "$file")

  account=$(echo "$file" | awk -F/ '{print $3}')
  rel="${file#/home/$account/}"

  ext="${fname##*.}"
  [ "$ext" = "$fname" ] && ext=""

  source=$(guess_source "$file")
  source_guess="${source%%|*}"
  source_type="${source##*|}"

  if [ "$risk" = "HIGH" ] || [ "$risk" = "CRITICAL" ]; then
    sha=$(sha256sum "$file" 2>/dev/null | awk '{print $1}')
  else
    sha=""
  fi

  mysql_run "
  INSERT INTO findings
  (
    scan_id, rule_name, risk,
    account_name, owner_name, group_name, perms,
    file_size, file_name, file_ext, file_path, relative_path,
    mtime, ctime, birth_time, detected_at,
    source_guess, source_type, sha256
  )
  VALUES (
    $SCAN_ID,
    '$(sql_escape "$rule")',
    '$(sql_escape "$risk")',
    '$(sql_escape "$account")',
    '$(sql_escape "$owner")',
    '$(sql_escape "$group")',
    '$(sql_escape "$perms")',
    ${size:-0},
    '$(sql_escape "$fname")',
    '$(sql_escape "$ext")',
    '$(sql_escape "$file")',
    '$(sql_escape "$rel")',
    NULLIF('$(sql_escape "$mtime")',''),
    NULLIF('$(sql_escape "$ctime")',''),
    NULLIF('$(sql_escape "$birth")',''),
    NOW(),
    '$(sql_escape "$source_guess")',
    '$(sql_escape "$source_type")',
    '$(sql_escape "$sha")'
  );
  " >/dev/null

  log "NAĐENO [$risk] $rule :: $file"
}

scan_pattern() {
  local title="$1"
  local risk="$2"
  shift 2

  log "Skeniranje: $title [$risk]"

  # Isključi QUARANTINE_BASE_PATH iz skeniranja ako je definiran i unutar BASE
  if [ -n "$QUARANTINE_BASE_PATH" ] && [[ "$QUARANTINE_BASE_PATH" == "$BASE"* ]]; then
    find "$BASE" -path "$QUARANTINE_BASE_PATH" -prune -o "$@" -print 2>/dev/null | while IFS= read -r file; do
      insert_finding "$title" "$risk" "$file"
    done
  else
    find "$BASE" "$@" 2>/dev/null | while IFS= read -r file; do
      insert_finding "$title" "$risk" "$file"
    done
  fi
}

scan_pattern "filefuns.php" "CRITICAL" \
  -type f -name "filefuns.php"

scan_pattern ".sys-* datoteke" "HIGH" \
  -type f -name ".sys-*"

scan_pattern "adman marker txt" "HIGH" \
  -type f -name "adman.*.txt"

scan_pattern "mixed-case PHP ekstenzije" "MEDIUM" \
  -type f \( -name "*.PHP" -o -name "*.Php" -o -name "*.pHp" -o -name "*.PHp" -o -name "*.phP" -o -name "*.pHP" \)

scan_pattern "tmp izvršni web fajlovi" "HIGH" \
  -type f \( -path "*/tmp/*.php" -o -path "*/tmp/*.php5" -o -path "*/tmp/*.phtml" -o -path "*/tmp/*.phar" \)

scan_pattern "sumnjivi random index.php direktoriji" "HIGH" \
  -type f -name "index.php" -size +10k \
  -regextype posix-extended \
  -regex '.*/([0-9a-f]{5,6}|[0-9]{5,6})/index\.php$'

scan_pattern "cache.php sumnjive lokacije" "MEDIUM" \
  -type f -name "cache.php"

log "Skeniranje: poznati command shell indikatori [HIGH]"

if [ -n "$QUARANTINE_BASE_PATH" ] && [[ "$QUARANTINE_BASE_PATH" == "$BASE"* ]]; then
  find "$BASE" -path "$QUARANTINE_BASE_PATH" -prune -o \
    -type f \( -name "*.php" -o -name "*.PHP" -o -name "*.Php" -o -name "*.pHp" -o -name "*.phtml" -o -name "*.php5" -o -name "*.phar" \) \
    -print 2>/dev/null | \
    xargs -r grep -IlE "shell_exec|passthru|popen|proc_open|base64_decode|gzinflate|str_rot13|@eval|eval\(" 2>/dev/null | \
    while IFS= read -r file; do
      insert_finding "poznati command shell indikatori" "HIGH" "$file"
    done
else
  find "$BASE" \
    -type f \( -name "*.php" -o -name "*.PHP" -o -name "*.Php" -o -name "*.pHp" -o -name "*.phtml" -o -name "*.php5" -o -name "*.phar" \) \
    -exec grep -IlE "shell_exec|passthru|popen|proc_open|base64_decode|gzinflate|str_rot13|@eval|eval\(" {} \; \
    2>/dev/null | while IFS= read -r file; do
      insert_finding "poznati command shell indikatori" "HIGH" "$file"
    done
fi

COUNT=$(mysql_run "SELECT COUNT(*) FROM findings WHERE scan_id=$SCAN_ID;")

mysql_run "
UPDATE scans
SET finished_at = NOW(),
    files_found = $COUNT,
    status = 'FINISHED'
WHERE id = $SCAN_ID;
" >/dev/null

log "8Core IOC Scanner završen"
log "Scan ID: $SCAN_ID"
log "Nalazi: $COUNT"
log "Log: tail -f $RUN_LOG"

echo "GOTOVO scan_id=$SCAN_ID nalazi=$COUNT"
