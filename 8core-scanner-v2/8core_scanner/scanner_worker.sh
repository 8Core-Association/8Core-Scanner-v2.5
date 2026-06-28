#!/bin/bash
# ==========================================================
# 8Core Scanner Worker v1.3
# Copyright (c) 2026 8Core
# Author: Tomislav Galić / 8Core
# Web: https://8core.hr
# Svrha: Izvršava scan queue + delete/quarantine akcije
# ==========================================================

# Konfiguracija se učitava iz scanner-db.conf koji se nalazi u
# istom direktoriju kao i ova skripta.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONFIG="${SCRIPT_DIR}/scanner-db.conf"
SCANNER="${SCRIPT_DIR}/ioc_scan.sh"
LOCK="/var/run/8core-scanner-worker.lock"

# Putanje iz konfiguracije ili default
LOG_PATH="${LOG_PATH:-${SCRIPT_DIR}/logs}"
LOG="${LOG_PATH}/scanner-worker.log"
QUARANTINE_BASE="${QUARANTINE_BASE_PATH:-${QUARANTINE_PATH:-${SCRIPT_DIR}/quarantine}}"

log() {
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG"
}

[ -f "$CONFIG" ] || { log "GREŠKA: nedostaje $CONFIG"; exit 1; }
[ -x "$SCANNER" ] || { log "GREŠKA: scanner nije izvršiv: $SCANNER"; exit 1; }

source "$CONFIG"

# Primijeni putanje iz config ako su postavljene
LOG_PATH="${LOG_PATH:-${SCRIPT_DIR}/logs}"
LOG="${LOG_PATH}/scanner-worker.log"
QUARANTINE_BASE="${QUARANTINE_BASE_PATH:-${QUARANTINE_PATH:-${SCRIPT_DIR}/quarantine}}"

DB_HOST="${DB_HOST//$'\r'/}"
DB_NAME="${DB_NAME//$'\r'/}"
DB_USER="${DB_USER//$'\r'/}"
DB_PASS="${DB_PASS//$'\r'/}"
DB_CHARSET="${DB_CHARSET//$'\r'/}"

mkdir -p "$LOG_PATH"

mysql_run() {
  mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
    --default-character-set="${DB_CHARSET:-utf8mb4}" -N -B -e "$1"
}

sql_escape() {
  printf "%s" "$1" | sed "s/'/''/g"
}

safe_home_path() {
  case "$1" in
    /home/*) return 0 ;;
    *)       return 1 ;;
  esac
}

prepare_runtime() {
  mkdir -p "$QUARANTINE_BASE"
  chmod 700 "$QUARANTINE_BASE"
}

process_file_actions() {
  local rows id status file account qdir qpath basefile ts final_status

  rows=$(mysql_run "
    SELECT id, action_status, file_path, IFNULL(account_name,'unknown')
    FROM findings
    WHERE action_status IN ('delete_requested','quarantine_requested')
    ORDER BY id ASC
    LIMIT 20;
  ")

  [ -z "$rows" ] && return 0

  while IFS=$'\t' read -r id status file account; do
    [ -z "$id" ] && continue

    log "Action zahtjev ID=$id STATUS=$status FILE=$file"

    if ! safe_home_path "$file"; then
      final_status="${status%_requested}_failed"
      mysql_run "
        UPDATE findings
        SET action_status='$final_status',
            action_error='Nesigurna putanja blokirana',
            action_at=NOW()
        WHERE id=$id;
      "
      log "Akcija neuspješna ID=$id nesigurna putanja"
      continue
    fi

    if [ ! -f "$file" ]; then
      final_status="${status%_requested}_failed"
      mysql_run "
        UPDATE findings
        SET action_status='$final_status',
            action_error='Fajl nije pronađen',
            action_at=NOW()
        WHERE id=$id;
      "
      log "Akcija neuspješna ID=$id fajl nije pronađen"
      continue
    fi

    if [ "$status" = "delete_requested" ]; then
      if rm -f -- "$file"; then
        mysql_run "
          UPDATE findings
          SET action_status='deleted',
              action_error=NULL,
              action_at=NOW()
          WHERE id=$id;
        "
        log "Obrisano ID=$id FILE=$file"
      else
        mysql_run "
          UPDATE findings
          SET action_status='delete_failed',
              action_error='rm neuspješan',
              action_at=NOW()
          WHERE id=$id;
        "
        log "Brisanje neuspješno ID=$id FILE=$file"
      fi
    fi

    if [ "$status" = "quarantine_requested" ]; then
      ts=$(date '+%Y%m%d-%H%M%S')
      basefile=$(basename "$file")
      qdir="$QUARANTINE_BASE/$account"
      qpath="$qdir/${id}_${ts}_$basefile"

      mkdir -p "$qdir"
      chmod 700 "$qdir"

      if mv -- "$file" "$qpath"; then
        chmod 600 "$qpath"
        mysql_run "
          UPDATE findings
          SET action_status='quarantined',
              quarantine_path='$(sql_escape "$qpath")',
              action_error=NULL,
              action_at=NOW()
          WHERE id=$id;
        "
        log "Karantenizirano ID=$id NA=$qpath"
      else
        mysql_run "
          UPDATE findings
          SET action_status='quarantine_failed',
              action_error='mv neuspješan',
              action_at=NOW()
          WHERE id=$id;
        "
        log "Karantena neuspješna ID=$id FILE=$file"
      fi
    fi

  done <<< "$rows"
}

process_scan_queue() {
  local RUNNING REQ REQ_ID TARGET_TYPE TARGET_VALUE RET

  RUNNING=$(mysql_run "SELECT COUNT(*) FROM scans WHERE status='RUNNING';")
  if [ "$RUNNING" != "0" ]; then
    log "Scanner već radi. Čekanje."
    return 0
  fi

  REQ=$(mysql_run "
    SELECT id, target_type, target_value
    FROM scanner_scan_requests
    WHERE status='PENDING'
    ORDER BY id ASC
    LIMIT 1;
  ")

  [ -z "$REQ" ] && return 0

  REQ_ID=$(echo "$REQ" | awk '{print $1}')
  TARGET_TYPE=$(echo "$REQ" | awk '{print $2}')
  TARGET_VALUE=$(echo "$REQ" | cut -f3-)

  log "Obrada scan zahtjeva ID=$REQ_ID TIP=$TARGET_TYPE TARGET=$TARGET_VALUE"

  mysql_run "
    UPDATE scanner_scan_requests
    SET status='RUNNING', started_at=NOW()
    WHERE id=$REQ_ID;
  "

  if [ "$TARGET_TYPE" = "all" ]; then
    "$SCANNER" --all --config="$CONFIG"
    RET=$?
  elif [ "$TARGET_TYPE" = "account" ]; then
    "$SCANNER" --account="$TARGET_VALUE" --config="$CONFIG"
    RET=$?
  elif [ "$TARGET_TYPE" = "custom_path" ]; then
    "$SCANNER" --path="$TARGET_VALUE" --config="$CONFIG"
    RET=$?
  else
    RET=99
  fi

  if [ "$RET" = "0" ]; then
    mysql_run "
      UPDATE scanner_scan_requests
      SET status='FINISHED', finished_at=NOW(), note='OK'
      WHERE id=$REQ_ID;
    "
    log "Scan zahtjev završen ID=$REQ_ID"
  else
    mysql_run "
      UPDATE scanner_scan_requests
      SET status='FAILED', finished_at=NOW(), note='Scanner vratio kod $RET'
      WHERE id=$REQ_ID;
    "
    log "Scan zahtjev neuspješan ID=$REQ_ID RET=$RET"
  fi
}

(
  flock -n 9 || exit 0
  log "Worker pokrenut"

  prepare_runtime
  process_file_actions
  process_scan_queue

) 9>"$LOCK"
