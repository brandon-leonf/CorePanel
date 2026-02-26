#!/usr/bin/env bash
set -euo pipefail

usage() {
  echo "Usage: $0 --database <source_db> [--restore-db <target_db>] [--report-file <path>]"
  echo "Requires backup/restore env vars used by backup_encrypted.sh and restore_encrypted_backup.sh."
  exit 1
}

SOURCE_DB=""
RESTORE_DB=""
REPORT_FILE="docs/backup_restore_test.log"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --database)
      SOURCE_DB="${2:-}"
      shift 2
      ;;
    --restore-db)
      RESTORE_DB="${2:-}"
      shift 2
      ;;
    --report-file)
      REPORT_FILE="${2:-}"
      shift 2
      ;;
    *)
      usage
      ;;
  esac
done

if [[ -z "$SOURCE_DB" ]]; then
  usage
fi

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKUP_SCRIPT="$ROOT_DIR/scripts/security/backup_encrypted.sh"
RESTORE_SCRIPT="$ROOT_DIR/scripts/security/restore_encrypted_backup.sh"

TMP_LOG="$(mktemp)"
trap 'rm -f "$TMP_LOG"' EXIT

echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] Starting backup/restore drill for source DB: $SOURCE_DB" | tee -a "$TMP_LOG"

BACKUP_OUTPUT="$("$BACKUP_SCRIPT" --database "$SOURCE_DB" 2>&1 | tee -a "$TMP_LOG")"
BACKUP_FILE="$(printf '%s\n' "$BACKUP_OUTPUT" | awk -F': ' '/Encrypted backup created:/ {print $2}' | tail -n 1)"

if [[ -z "$BACKUP_FILE" || ! -f "$BACKUP_FILE" ]]; then
  echo "Drill failed: could not locate generated backup file." | tee -a "$TMP_LOG"
  exit 1
fi

"$RESTORE_SCRIPT" --file "$BACKUP_FILE" --database "$SOURCE_DB" --verify-only | tee -a "$TMP_LOG"

if [[ -n "$RESTORE_DB" ]]; then
  "$RESTORE_SCRIPT" --file "$BACKUP_FILE" --database "$RESTORE_DB" | tee -a "$TMP_LOG"
  echo "Full restore test executed against DB: $RESTORE_DB" | tee -a "$TMP_LOG"
else
  echo "Full restore step skipped (no --restore-db provided)." | tee -a "$TMP_LOG"
fi

mkdir -p "$(dirname "$REPORT_FILE")"
{
  echo "----"
  cat "$TMP_LOG"
  echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] Drill completed successfully."
} >>"$REPORT_FILE"

echo "Drill report appended to: $REPORT_FILE"
