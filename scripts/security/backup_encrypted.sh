#!/usr/bin/env bash
set -euo pipefail

usage() {
  echo "Usage: $0 --database <db_name> [--host <host>] [--port <port>] [--user <user>] [--password-env <ENV_VAR>] [--out-dir <dir>]"
  echo "Required passphrase env: COREPANEL_BACKUP_PASSPHRASE"
  exit 1
}

DB_HOST="${COREPANEL_BACKUP_DB_HOST:-127.0.0.1}"
DB_PORT="${COREPANEL_BACKUP_DB_PORT:-3306}"
DB_NAME=""
DB_USER="${COREPANEL_BACKUP_DB_USER:-corepanel_backup}"
PASSWORD_ENV="${COREPANEL_BACKUP_DB_PASSWORD_ENV:-COREPANEL_BACKUP_DB_PASSWORD}"
OUT_DIR="${COREPANEL_BACKUP_DIR:-storage/backups}"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --database)
      DB_NAME="${2:-}"
      shift 2
      ;;
    --host)
      DB_HOST="${2:-}"
      shift 2
      ;;
    --port)
      DB_PORT="${2:-}"
      shift 2
      ;;
    --user)
      DB_USER="${2:-}"
      shift 2
      ;;
    --password-env)
      PASSWORD_ENV="${2:-}"
      shift 2
      ;;
    --out-dir)
      OUT_DIR="${2:-}"
      shift 2
      ;;
    *)
      usage
      ;;
  esac
done

if [[ -z "$DB_NAME" || -z "$DB_USER" || -z "$PASSWORD_ENV" ]]; then
  usage
fi

BACKUP_PASSPHRASE="${COREPANEL_BACKUP_PASSPHRASE:-}"
DB_PASSWORD="${!PASSWORD_ENV:-}"

if [[ -z "$BACKUP_PASSPHRASE" ]]; then
  echo "Missing COREPANEL_BACKUP_PASSPHRASE"
  exit 1
fi
if [[ -z "$DB_PASSWORD" ]]; then
  echo "Missing DB password env var: $PASSWORD_ENV"
  exit 1
fi

for bin in mysqldump gzip openssl; do
  if ! command -v "$bin" >/dev/null 2>&1; then
    echo "Missing required command: $bin"
    exit 1
  fi
done

if command -v sha256sum >/dev/null 2>&1; then
  CHECKSUM_CMD=(sha256sum)
elif command -v shasum >/dev/null 2>&1; then
  CHECKSUM_CMD=(shasum -a 256)
else
  echo "Missing checksum command (sha256sum or shasum)"
  exit 1
fi

umask 077
mkdir -p "$OUT_DIR"
chmod 700 "$OUT_DIR"

TIMESTAMP="$(date -u +%Y%m%dT%H%M%SZ)"
BASENAME="${DB_NAME}_${TIMESTAMP}.sql.gz.enc"
TARGET_FILE="${OUT_DIR%/}/${BASENAME}"
CHECKSUM_FILE="${TARGET_FILE}.sha256"
TMP_DUMP="$(mktemp "${OUT_DIR%/}/.tmp_backup_XXXXXX.sql")"
trap 'rm -f "$TMP_DUMP"' EXIT

DUMP_ARGS=(
  --single-transaction
  --quick
  --routines
  --triggers
  -h "$DB_HOST"
  -P "$DB_PORT"
  -u "$DB_USER"
  "$DB_NAME"
)
if mysqldump --help 2>/dev/null | grep -q -- '--set-gtid-purged'; then
  DUMP_ARGS=(--set-gtid-purged=OFF "${DUMP_ARGS[@]}")
fi

MYSQL_PWD="$DB_PASSWORD" mysqldump "${DUMP_ARGS[@]}" >"$TMP_DUMP"

gzip -c "$TMP_DUMP" \
  | openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt \
    -pass env:COREPANEL_BACKUP_PASSPHRASE \
    -out "$TARGET_FILE"

"${CHECKSUM_CMD[@]}" "$TARGET_FILE" >"$CHECKSUM_FILE"
chmod 600 "$TARGET_FILE" "$CHECKSUM_FILE"

echo "Encrypted backup created: $TARGET_FILE"
echo "Checksum file: $CHECKSUM_FILE"
