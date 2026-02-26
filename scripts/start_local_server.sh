#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="${COREPANEL_PHP_BIN:-php}"
PORT="${COREPANEL_PORT:-8000}"
UPLOAD_MAX_FILESIZE="${COREPANEL_UPLOAD_MAX_FILESIZE:-20M}"
POST_MAX_SIZE="${COREPANEL_POST_MAX_SIZE:-25M}"
MAX_FILE_UPLOADS="${COREPANEL_MAX_FILE_UPLOADS:-20}"

cd "$ROOT_DIR"
exec "$PHP_BIN" \
  -d "upload_max_filesize=${UPLOAD_MAX_FILESIZE}" \
  -d "post_max_size=${POST_MAX_SIZE}" \
  -d "max_file_uploads=${MAX_FILE_UPLOADS}" \
  -S "localhost:${PORT}" \
  -t public
