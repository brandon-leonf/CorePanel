#!/usr/bin/env bash
set -euo pipefail

usage() {
  echo "Usage: $0 --database <db_name> [--retention-days <7-30>] [--env-file <path>] [--out-dir <dir>] [--log-file <path>] [--uploads-source-dir <dir>] [--uploads-label <label>] [--skip-uploads] [--skip-offsite] [--dry-run]"
  echo "Requires backup env vars used by backup_encrypted.sh, backup_uploads_encrypted.sh, and restore_encrypted_backup.sh."
  exit 1
}

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKUP_DB_SCRIPT="$ROOT_DIR/scripts/security/backup_encrypted.sh"
RESTORE_DB_SCRIPT="$ROOT_DIR/scripts/security/restore_encrypted_backup.sh"
BACKUP_UPLOADS_SCRIPT="$ROOT_DIR/scripts/security/backup_uploads_encrypted.sh"
SYNC_OFFSITE_SCRIPT="$ROOT_DIR/scripts/security/sync_offsite_backups.sh"

DB_NAME=""
RETENTION_DAYS="${COREPANEL_BACKUP_RETENTION_DAYS:-14}"
ENV_FILE="${COREPANEL_BACKUP_ENV_FILE:-}"
OUT_DIR="${COREPANEL_BACKUP_DIR:-$ROOT_DIR/storage/backups}"
LOG_FILE="${COREPANEL_BACKUP_LOG_FILE:-$ROOT_DIR/storage/logs/backup_maintenance.log}"
UPLOADS_SOURCE_DIR="${COREPANEL_UPLOADS_BACKUP_SOURCE_DIR:-$ROOT_DIR/storage/uploads}"
UPLOADS_LABEL="${COREPANEL_UPLOADS_BACKUP_LABEL:-uploads}"
DRY_RUN=0
SKIP_UPLOADS=0
SKIP_OFFSITE=0
RETENTION_SET=0
OUT_DIR_SET=0
LOG_FILE_SET=0
UPLOADS_SOURCE_SET=0
UPLOADS_LABEL_SET=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --database)
      DB_NAME="${2:-}"
      shift 2
      ;;
    --retention-days)
      RETENTION_DAYS="${2:-}"
      RETENTION_SET=1
      shift 2
      ;;
    --env-file)
      ENV_FILE="${2:-}"
      shift 2
      ;;
    --out-dir)
      OUT_DIR="${2:-}"
      OUT_DIR_SET=1
      shift 2
      ;;
    --log-file)
      LOG_FILE="${2:-}"
      LOG_FILE_SET=1
      shift 2
      ;;
    --uploads-source-dir)
      UPLOADS_SOURCE_DIR="${2:-}"
      UPLOADS_SOURCE_SET=1
      shift 2
      ;;
    --uploads-label)
      UPLOADS_LABEL="${2:-}"
      UPLOADS_LABEL_SET=1
      shift 2
      ;;
    --skip-uploads)
      SKIP_UPLOADS=1
      shift 1
      ;;
    --skip-offsite)
      SKIP_OFFSITE=1
      shift 1
      ;;
    --dry-run)
      DRY_RUN=1
      shift 1
      ;;
    *)
      usage
      ;;
  esac
done

if [[ -n "$ENV_FILE" ]]; then
  if [[ ! -f "$ENV_FILE" ]]; then
    echo "Environment file not found: $ENV_FILE"
    exit 1
  fi
  set -a
  # shellcheck disable=SC1090
  source "$ENV_FILE"
  set +a
fi

if [[ "$RETENTION_SET" -eq 0 ]]; then
  RETENTION_DAYS="${COREPANEL_BACKUP_RETENTION_DAYS:-14}"
fi
if [[ "$OUT_DIR_SET" -eq 0 ]]; then
  OUT_DIR="${COREPANEL_BACKUP_DIR:-$ROOT_DIR/storage/backups}"
fi
if [[ "$LOG_FILE_SET" -eq 0 ]]; then
  LOG_FILE="${COREPANEL_BACKUP_LOG_FILE:-$ROOT_DIR/storage/logs/backup_maintenance.log}"
fi
if [[ "$UPLOADS_SOURCE_SET" -eq 0 ]]; then
  UPLOADS_SOURCE_DIR="${COREPANEL_UPLOADS_BACKUP_SOURCE_DIR:-$ROOT_DIR/storage/uploads}"
fi
if [[ "$UPLOADS_LABEL_SET" -eq 0 ]]; then
  UPLOADS_LABEL="${COREPANEL_UPLOADS_BACKUP_LABEL:-uploads}"
fi

if [[ -z "$DB_NAME" ]]; then
  usage
fi

if ! [[ "$RETENTION_DAYS" =~ ^[0-9]+$ ]]; then
  echo "Retention days must be an integer between 7 and 30."
  exit 1
fi
if (( RETENTION_DAYS < 7 || RETENTION_DAYS > 30 )); then
  echo "Retention days must be between 7 and 30."
  exit 1
fi

if [[ "$SKIP_UPLOADS" -eq 0 && ! -d "$UPLOADS_SOURCE_DIR" ]]; then
  echo "Uploads source directory not found: $UPLOADS_SOURCE_DIR"
  exit 1
fi
if ! [[ "$UPLOADS_LABEL" =~ ^[a-zA-Z0-9._-]{1,80}$ ]]; then
  echo "Uploads label is invalid. Use letters/numbers/dot/underscore/hyphen only."
  exit 1
fi

for script_file in "$BACKUP_DB_SCRIPT" "$RESTORE_DB_SCRIPT" "$BACKUP_UPLOADS_SCRIPT" "$SYNC_OFFSITE_SCRIPT"; do
  if [[ ! -x "$script_file" ]]; then
    echo "Required script is missing or not executable: $script_file"
    exit 1
  fi
done

mkdir -p "$OUT_DIR" "$(dirname "$LOG_FILE")"
chmod 700 "$OUT_DIR"

log_line() {
  local message="$1"
  local ts
  ts="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  printf '[%s] %s\n' "$ts" "$message" | tee -a "$LOG_FILE"
}

LOCK_DIR="${OUT_DIR%/}/.backup_maintenance.lock"
if ! mkdir "$LOCK_DIR" 2>/dev/null; then
  log_line "ERROR: Backup maintenance already running."
  exit 1
fi
trap 'rm -rf "$LOCK_DIR"' EXIT

if [[ "$DRY_RUN" -eq 1 ]]; then
  log_line "DRY RUN: no backup, restore, retention delete, or offsite sync writes will be performed."
fi

for bin in awk find tar gzip openssl; do
  if ! command -v "$bin" >/dev/null 2>&1; then
    log_line "ERROR: Missing required command: $bin"
    exit 1
  fi
done

DB_BACKUP_FILE=""
UPLOADS_BACKUP_FILE=""

if [[ "$DRY_RUN" -eq 0 ]]; then
  log_line "Starting encrypted DB backup for database: $DB_NAME"
  DB_BACKUP_OUTPUT="$($BACKUP_DB_SCRIPT --database "$DB_NAME" --out-dir "$OUT_DIR" 2>&1)"
  printf '%s\n' "$DB_BACKUP_OUTPUT" | tee -a "$LOG_FILE"
  DB_BACKUP_FILE="$(printf '%s\n' "$DB_BACKUP_OUTPUT" | awk -F': ' '/Encrypted backup created:/ {print $2}' | tail -n 1)"
  if [[ -z "$DB_BACKUP_FILE" || ! -f "$DB_BACKUP_FILE" ]]; then
    log_line "ERROR: Could not locate generated DB backup file."
    exit 1
  fi

  log_line "Verifying DB backup checksum + decrypt/decompress integrity."
  "$RESTORE_DB_SCRIPT" --file "$DB_BACKUP_FILE" --database "$DB_NAME" --verify-only | tee -a "$LOG_FILE"

  if [[ "$SKIP_UPLOADS" -eq 0 ]]; then
    log_line "Starting encrypted uploads backup from: $UPLOADS_SOURCE_DIR"
    UPLOADS_BACKUP_OUTPUT="$($BACKUP_UPLOADS_SCRIPT --source-dir "$UPLOADS_SOURCE_DIR" --label "$UPLOADS_LABEL" --out-dir "$OUT_DIR" 2>&1)"
    printf '%s\n' "$UPLOADS_BACKUP_OUTPUT" | tee -a "$LOG_FILE"
    UPLOADS_BACKUP_FILE="$(printf '%s\n' "$UPLOADS_BACKUP_OUTPUT" | awk -F': ' '/Encrypted uploads backup created:/ {print $2}' | tail -n 1)"
    if [[ -z "$UPLOADS_BACKUP_FILE" || ! -f "$UPLOADS_BACKUP_FILE" ]]; then
      log_line "ERROR: Could not locate generated uploads backup file."
      exit 1
    fi

    log_line "Verifying uploads backup decrypt/decompress/archive integrity."
    if ! openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 \
      -pass env:COREPANEL_BACKUP_PASSPHRASE \
      -in "$UPLOADS_BACKUP_FILE" \
      | gzip -dc \
      | tar -tf - >/dev/null; then
      log_line "ERROR: Uploads backup integrity verification failed."
      exit 1
    fi
    log_line "PASS: Uploads backup integrity verified: $UPLOADS_BACKUP_FILE"
  else
    log_line "Uploads backup skipped (--skip-uploads)."
  fi
else
  log_line "DRY RUN: Skipping DB and uploads backup creation."
fi

log_line "Applying retention policy: keep last ${RETENTION_DAYS} days of DB and uploads backups."
if [[ "$DRY_RUN" -eq 1 ]]; then
  find "$OUT_DIR" -maxdepth 1 -type f \( \
    -name "${DB_NAME}_*.sql.gz.enc" -o \
    -name "${DB_NAME}_*.sql.gz.enc.sha256" -o \
    -name "${UPLOADS_LABEL}_*.uploads.tar.gz.enc" -o \
    -name "${UPLOADS_LABEL}_*.uploads.tar.gz.enc.sha256" \
  \) -mtime +"$RETENTION_DAYS" -print | sed 's/^/DRY RUN delete: /' | tee -a "$LOG_FILE"
else
  while IFS= read -r old_file; do
    [[ -z "$old_file" ]] && continue
    rm -f "$old_file"
    log_line "Deleted old backup artifact: $old_file"
  done < <(
    find "$OUT_DIR" -maxdepth 1 -type f \( \
      -name "${DB_NAME}_*.sql.gz.enc" -o \
      -name "${DB_NAME}_*.sql.gz.enc.sha256" -o \
      -name "${UPLOADS_LABEL}_*.uploads.tar.gz.enc" -o \
      -name "${UPLOADS_LABEL}_*.uploads.tar.gz.enc.sha256" \
    \) -mtime +"$RETENTION_DAYS" -print
  )
fi

if [[ "$SKIP_OFFSITE" -eq 0 ]]; then
  log_line "Starting offsite sync for encrypted backup artifacts."
  if [[ "$DRY_RUN" -eq 1 ]]; then
    "$SYNC_OFFSITE_SCRIPT" --source-dir "$OUT_DIR" --dry-run 2>&1 | tee -a "$LOG_FILE"
  else
    "$SYNC_OFFSITE_SCRIPT" --source-dir "$OUT_DIR" 2>&1 | tee -a "$LOG_FILE"
  fi
else
  log_line "Offsite sync skipped (--skip-offsite)."
fi

if [[ "$DRY_RUN" -eq 0 ]]; then
  log_line "SUCCESS: Daily backup maintenance completed for $DB_NAME (DB + uploads + offsite sync)."
else
  log_line "SUCCESS: Daily backup maintenance dry run completed for $DB_NAME (DB + uploads + offsite sync)."
fi
