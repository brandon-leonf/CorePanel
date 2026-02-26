#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'EOF'
Usage: sync_offsite_backups.sh [--source-dir <dir>] [--mode <none|rclone|aws-s3|rsync>] [--dry-run]

Modes are configured via env vars:
  rclone:
    COREPANEL_BACKUP_OFFSITE_RCLONE_REMOTE=remote:bucket/path
  aws-s3:
    COREPANEL_BACKUP_OFFSITE_S3_URI=s3://bucket/path
    COREPANEL_BACKUP_OFFSITE_S3_STORAGE_CLASS=STANDARD_IA (optional)
  rsync:
    COREPANEL_BACKUP_OFFSITE_RSYNC_DEST=user@host:/path
    COREPANEL_BACKUP_OFFSITE_RSYNC_RSH='ssh -i /path/key' (optional)
EOF
  exit 1
}

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SOURCE_DIR="${COREPANEL_BACKUP_DIR:-$ROOT_DIR/storage/backups}"
MODE="${COREPANEL_BACKUP_OFFSITE_MODE:-none}"
DRY_RUN=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --source-dir)
      SOURCE_DIR="${2:-}"
      shift 2
      ;;
    --mode)
      MODE="${2:-}"
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

if [[ ! -d "$SOURCE_DIR" ]]; then
  echo "Source backup directory not found: $SOURCE_DIR"
  exit 1
fi

MODE="$(echo "$MODE" | tr '[:upper:]' '[:lower:]' | xargs)"

run_cmd() {
  local -a cmd=("$@")
  printf 'Running: %q ' "${cmd[@]}"
  printf '\n'
  "${cmd[@]}"
}

case "$MODE" in
  none|off|disabled)
    echo "Offsite sync disabled (mode: $MODE)."
    exit 0
    ;;

  rclone)
    if ! command -v rclone >/dev/null 2>&1; then
      echo "Missing required command: rclone"
      exit 1
    fi
    REMOTE="${COREPANEL_BACKUP_OFFSITE_RCLONE_REMOTE:-}"
    if [[ -z "$REMOTE" ]]; then
      echo "Missing COREPANEL_BACKUP_OFFSITE_RCLONE_REMOTE"
      exit 1
    fi

    cmd=(rclone sync "$SOURCE_DIR" "$REMOTE" --include "*.enc" --include "*.sha256" --exclude "*")
    if [[ "$DRY_RUN" -eq 1 ]]; then
      cmd+=(--dry-run)
    fi
    run_cmd "${cmd[@]}"
    ;;

  aws-s3)
    if ! command -v aws >/dev/null 2>&1; then
      echo "Missing required command: aws"
      exit 1
    fi
    S3_URI="${COREPANEL_BACKUP_OFFSITE_S3_URI:-}"
    if [[ -z "$S3_URI" ]]; then
      echo "Missing COREPANEL_BACKUP_OFFSITE_S3_URI"
      exit 1
    fi

    cmd=(aws s3 sync "$SOURCE_DIR" "$S3_URI" --exclude "*" --include "*.enc" --include "*.sha256" --delete --only-show-errors)
    STORAGE_CLASS="${COREPANEL_BACKUP_OFFSITE_S3_STORAGE_CLASS:-}"
    if [[ -n "$STORAGE_CLASS" ]]; then
      cmd+=(--storage-class "$STORAGE_CLASS")
    fi
    if [[ "$DRY_RUN" -eq 1 ]]; then
      cmd+=(--dryrun)
    fi
    run_cmd "${cmd[@]}"
    ;;

  rsync)
    if ! command -v rsync >/dev/null 2>&1; then
      echo "Missing required command: rsync"
      exit 1
    fi
    RSYNC_DEST="${COREPANEL_BACKUP_OFFSITE_RSYNC_DEST:-}"
    if [[ -z "$RSYNC_DEST" ]]; then
      echo "Missing COREPANEL_BACKUP_OFFSITE_RSYNC_DEST"
      exit 1
    fi

    cmd=(rsync -az --delete --chmod=F600,D700 --include='*.enc' --include='*.sha256' --exclude='*')
    RSYNC_RSH="${COREPANEL_BACKUP_OFFSITE_RSYNC_RSH:-}"
    if [[ -n "$RSYNC_RSH" ]]; then
      cmd+=(-e "$RSYNC_RSH")
    fi
    if [[ "$DRY_RUN" -eq 1 ]]; then
      cmd+=(--dry-run)
    fi
    cmd+=("${SOURCE_DIR%/}/" "${RSYNC_DEST%/}/")
    run_cmd "${cmd[@]}"
    ;;

  *)
    echo "Unsupported offsite mode: $MODE"
    exit 1
    ;;
esac

echo "Offsite sync completed (mode: $MODE)."
