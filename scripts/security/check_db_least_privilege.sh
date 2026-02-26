#!/usr/bin/env bash
set -euo pipefail

usage() {
  echo "Usage: $0 --user <db_user> --password-env <ENV_VAR> [--host <host>] [--port <port>] [--mode runtime|migrator|backup]"
  echo "Example: COREPANEL_APP_DB_PASSWORD='secret' $0 --user corepanel_app --password-env COREPANEL_APP_DB_PASSWORD --mode runtime"
  exit 1
}

DB_HOST="127.0.0.1"
DB_PORT="3306"
DB_USER=""
PASSWORD_ENV=""
MODE="runtime"

while [[ $# -gt 0 ]]; do
  case "$1" in
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
    --mode)
      MODE="${2:-}"
      shift 2
      ;;
    *)
      usage
      ;;
  esac
done

if [[ -z "$DB_USER" || -z "$PASSWORD_ENV" ]]; then
  usage
fi

if [[ "$MODE" != "runtime" && "$MODE" != "migrator" && "$MODE" != "backup" ]]; then
  usage
fi

DB_PASSWORD="${!PASSWORD_ENV:-}"
if [[ -z "$DB_PASSWORD" ]]; then
  echo "Missing password in env var: $PASSWORD_ENV"
  exit 1
fi

if ! command -v mysql >/dev/null 2>&1; then
  echo "mysql client not found"
  exit 1
fi

GRANTS="$(MYSQL_PWD="$DB_PASSWORD" mysql \
  --batch --raw --skip-column-names \
  -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" \
  -e "SHOW GRANTS FOR CURRENT_USER();" 2>/dev/null || true)"

if [[ -z "$GRANTS" ]]; then
  echo "Unable to fetch grants for current user (connection failed or no grants returned)."
  exit 1
fi

UPPER_GRANTS="$(printf '%s\n' "$GRANTS" | tr '[:lower:]' '[:upper:]')"

FORBIDDEN_COMMON='\bGRANT ALL PRIVILEGES\b|\bWITH GRANT OPTION\b|\bSUPER\b|\bFILE\b|\bPROCESS\b|\bRELOAD\b|\bSHUTDOWN\b|\bCREATE USER\b|\bREPLICATION SLAVE\b|\bREPLICATION CLIENT\b'
if printf '%s\n' "$UPPER_GRANTS" | grep -E -n "$FORBIDDEN_COMMON" >/dev/null; then
  echo "FAIL: Found forbidden high-risk privileges:"
  printf '%s\n' "$GRANTS"
  exit 1
fi

case "$MODE" in
  runtime)
    if printf '%s\n' "$UPPER_GRANTS" | grep -E -n '\bCREATE\b|\bALTER\b|\bDROP\b|\bINDEX\b|\bTRIGGER\b|\bEVENT\b|\bLOCK TABLES\b' >/dev/null; then
      echo "FAIL: Runtime account has schema/operational privileges it should not have."
      printf '%s\n' "$GRANTS"
      exit 1
    fi
    ;;
  migrator)
    if ! printf '%s\n' "$UPPER_GRANTS" | grep -E -n '\bCREATE\b|\bALTER\b|\bDROP\b|\bINDEX\b' >/dev/null; then
      echo "WARN: Migrator account may be under-privileged for schema migrations."
    fi
    ;;
  backup)
    if printf '%s\n' "$UPPER_GRANTS" | grep -E -n '\bINSERT\b|\bUPDATE\b|\bDELETE\b|\bALTER\b|\bDROP\b|\bCREATE\b' >/dev/null; then
      echo "FAIL: Backup account should be read-only plus dump-related privileges."
      printf '%s\n' "$GRANTS"
      exit 1
    fi
    ;;
esac

echo "PASS: Grant set looks least-privilege for mode '$MODE'."
