#!/usr/bin/env bash
set -euo pipefail

usage() {
  echo "Usage: $0 --file <backup.sql.gz.enc> --database <target_db> [--host <host>] [--port <port>] [--user <user>] [--password-env <ENV_VAR>] [--verify-only] [--skip-checksum]"
  echo "Required passphrase env: COREPANEL_BACKUP_PASSPHRASE"
  exit 1
}

BACKUP_FILE=""
TARGET_DB=""
DB_HOST="${COREPANEL_RESTORE_DB_HOST:-127.0.0.1}"
DB_PORT="${COREPANEL_RESTORE_DB_PORT:-3306}"
DB_USER="${COREPANEL_RESTORE_DB_USER:-corepanel_migrator}"
PASSWORD_ENV="${COREPANEL_RESTORE_DB_PASSWORD_ENV:-COREPANEL_RESTORE_DB_PASSWORD}"
VERIFY_ONLY=0
SKIP_CHECKSUM=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --file)
      BACKUP_FILE="${2:-}"
      shift 2
      ;;
    --database)
      TARGET_DB="${2:-}"
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
    --verify-only)
      VERIFY_ONLY=1
      shift 1
      ;;
    --skip-checksum)
      SKIP_CHECKSUM=1
      shift 1
      ;;
    *)
      usage
      ;;
  esac
done

if [[ -z "$BACKUP_FILE" || ! -f "$BACKUP_FILE" ]]; then
  usage
fi
if [[ -z "$TARGET_DB" && "$VERIFY_ONLY" -eq 0 ]]; then
  usage
fi

BACKUP_PASSPHRASE="${COREPANEL_BACKUP_PASSPHRASE:-}"
DB_PASSWORD="${!PASSWORD_ENV:-}"

if [[ -z "$BACKUP_PASSPHRASE" ]]; then
  echo "Missing COREPANEL_BACKUP_PASSPHRASE"
  exit 1
fi
if [[ -z "$DB_PASSWORD" && "$VERIFY_ONLY" -eq 0 ]]; then
  echo "Missing DB password env var: $PASSWORD_ENV"
  exit 1
fi

for bin in openssl gzip; do
  if ! command -v "$bin" >/dev/null 2>&1; then
    echo "Missing required command: $bin"
    exit 1
  fi
done

sha256_file() {
  local target_file="$1"
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$target_file" | awk '{print $1}'
    return 0
  fi
  if command -v shasum >/dev/null 2>&1; then
    shasum -a 256 "$target_file" | awk '{print $1}'
    return 0
  fi
  return 1
}

verify_backup_checksum() {
  local backup_file="$1"
  local checksum_file="${backup_file}.sha256"

  if [[ "$SKIP_CHECKSUM" -eq 1 ]]; then
    echo "Checksum verification skipped (--skip-checksum)."
    return 0
  fi

  if [[ ! -f "$checksum_file" ]]; then
    echo "Missing checksum file: $checksum_file"
    exit 1
  fi

  local expected actual
  expected="$(awk '{print $1}' "$checksum_file" | head -n 1 | tr -d '[:space:]')"
  if [[ -z "$expected" || ${#expected} -ne 64 ]]; then
    echo "Invalid checksum file format: $checksum_file"
    exit 1
  fi

  if ! actual="$(sha256_file "$backup_file")"; then
    echo "Missing checksum command (sha256sum or shasum)"
    exit 1
  fi

  if [[ "$actual" != "$expected" ]]; then
    echo "Checksum mismatch for backup file: $backup_file"
    exit 1
  fi

  echo "PASS: Backup checksum verified: $backup_file"
}

verify_backup_checksum "$BACKUP_FILE"

if [[ "$VERIFY_ONLY" -eq 1 ]]; then
  openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 \
    -pass env:COREPANEL_BACKUP_PASSPHRASE \
    -in "$BACKUP_FILE" \
    | gzip -dc >/dev/null
  echo "PASS: Backup decrypt + decompression verified: $BACKUP_FILE"
  exit 0
fi

if ! command -v mysql >/dev/null 2>&1; then
  echo "Missing required command: mysql"
  exit 1
fi

openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 \
  -pass env:COREPANEL_BACKUP_PASSPHRASE \
  -in "$BACKUP_FILE" \
  | gzip -dc \
  | sed '/SET @@GLOBAL.GTID_PURGED/d' \
  | MYSQL_PWD="$DB_PASSWORD" mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "$TARGET_DB"

echo "Restore completed into database: $TARGET_DB"
