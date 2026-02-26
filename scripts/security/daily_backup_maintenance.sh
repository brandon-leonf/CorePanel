#!/usr/bin/env bash
set -euo pipefail

usage() {
  echo "Usage: $0 --database <db_name> [--retention-days <7-30>] [--env-file <path>] [--out-dir <dir>] [--log-file <path>] [--dry-run]"
  echo "Requires backup env vars used by backup_encrypted.sh and restore_encrypted_backup.sh."
  exit 1
}

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKUP_SCRIPT="$ROOT_DIR/scripts/security/backup_encrypted.sh"
RESTORE_SCRIPT="$ROOT_DIR/scripts/security/restore_encrypted_backup.sh"

DB_NAME=""
RETENTION_DAYS="${COREPANEL_BACKUP_RETENTION_DAYS:-14}"
ENV_FILE="${COREPANEL_BACKUP_ENV_FILE:-}"
OUT_DIR="${COREPANEL_BACKUP_DIR:-$ROOT_DIR/storage/backups}"
LOG_FILE="${COREPANEL_BACKUP_LOG_FILE:-$ROOT_DIR/storage/logs/backup_maintenance.log}"
DRY_RUN=0
RETENTION_SET=0
OUT_DIR_SET=0
LOG_FILE_SET=0

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
  log_line "DRY RUN: no backup/restore/delete actions will be performed."
fi

for bin in awk find; do
  if ! command -v "$bin" >/dev/null 2>&1; then
    log_line "ERROR: Missing required command: $bin"
    exit 1
  fi
done

BACKUP_FILE=""
if [[ "$DRY_RUN" -eq 0 ]]; then
  log_line "Starting encrypted backup for database: $DB_NAME"
  BACKUP_OUTPUT="$("$BACKUP_SCRIPT" --database "$DB_NAME" --out-dir "$OUT_DIR" 2>&1)"
  printf '%s\n' "$BACKUP_OUTPUT" | tee -a "$LOG_FILE"
  BACKUP_FILE="$(printf '%s\n' "$BACKUP_OUTPUT" | awk -F': ' '/Encrypted backup created:/ {print $2}' | tail -n 1)"
  if [[ -z "$BACKUP_FILE" || ! -f "$BACKUP_FILE" ]]; then
    log_line "ERROR: Could not locate generated backup file."
    exit 1
  fi

  log_line "Verifying backup checksum + decrypt/decompress integrity."
  "$RESTORE_SCRIPT" --file "$BACKUP_FILE" --database "$DB_NAME" --verify-only | tee -a "$LOG_FILE"
fi

log_line "Applying retention policy: keep last ${RETENTION_DAYS} days of backups for ${DB_NAME}."
if [[ "$DRY_RUN" -eq 1 ]]; then
  find "$OUT_DIR" -maxdepth 1 -type f \( \
    -name "${DB_NAME}_*.sql.gz.enc" -o \
    -name "${DB_NAME}_*.sql.gz.enc.sha256" \
  \) -mtime +"$RETENTION_DAYS" -print | sed 's/^/DRY RUN delete: /' | tee -a "$LOG_FILE"
else
  while IFS= read -r old_file; do
    [[ -z "$old_file" ]] && continue
    rm -f "$old_file"
    log_line "Deleted old backup artifact: $old_file"
  done < <(
    find "$OUT_DIR" -maxdepth 1 -type f \( \
      -name "${DB_NAME}_*.sql.gz.enc" -o \
      -name "${DB_NAME}_*.sql.gz.enc.sha256" \
    \) -mtime +"$RETENTION_DAYS" -print
  )
fi

if [[ "$DRY_RUN" -eq 0 ]]; then
  log_line "SUCCESS: Daily backup maintenance completed for $DB_NAME."
else
  log_line "SUCCESS: Daily backup maintenance dry run completed for $DB_NAME."
fi
