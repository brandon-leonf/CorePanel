#!/usr/bin/env bash
set -euo pipefail

usage() {
  echo "Usage: $0 [--source-dir <uploads_dir>] [--label <name>] [--out-dir <dir>]"
  echo "Required passphrase env: COREPANEL_BACKUP_PASSPHRASE"
  exit 1
}

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SOURCE_DIR="${COREPANEL_UPLOADS_BACKUP_SOURCE_DIR:-$ROOT_DIR/storage/uploads}"
LABEL="${COREPANEL_UPLOADS_BACKUP_LABEL:-uploads}"
OUT_DIR="${COREPANEL_BACKUP_DIR:-$ROOT_DIR/storage/backups}"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --source-dir)
      SOURCE_DIR="${2:-}"
      shift 2
      ;;
    --label)
      LABEL="${2:-}"
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

if [[ -z "$SOURCE_DIR" || -z "$LABEL" || -z "$OUT_DIR" ]]; then
  usage
fi
if [[ ! -d "$SOURCE_DIR" ]]; then
  echo "Uploads source directory not found: $SOURCE_DIR"
  exit 1
fi
if [[ ! "$LABEL" =~ ^[a-zA-Z0-9._-]{1,80}$ ]]; then
  echo "Invalid label. Use letters/numbers/dot/underscore/hyphen only."
  exit 1
fi

BACKUP_PASSPHRASE="${COREPANEL_BACKUP_PASSPHRASE:-}"
if [[ -z "$BACKUP_PASSPHRASE" ]]; then
  echo "Missing COREPANEL_BACKUP_PASSPHRASE"
  exit 1
fi

for bin in tar gzip openssl; do
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
BASENAME="${LABEL}_${TIMESTAMP}.uploads.tar.gz.enc"
TARGET_FILE="${OUT_DIR%/}/${BASENAME}"
CHECKSUM_FILE="${TARGET_FILE}.sha256"

SOURCE_PARENT="$(cd "$(dirname "$SOURCE_DIR")" && pwd)"
SOURCE_BASE="$(basename "$SOURCE_DIR")"

tar -C "$SOURCE_PARENT" -cf - "$SOURCE_BASE" \
  | gzip -c \
  | openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt \
    -pass env:COREPANEL_BACKUP_PASSPHRASE \
    -out "$TARGET_FILE"

"${CHECKSUM_CMD[@]}" "$TARGET_FILE" >"$CHECKSUM_FILE"
chmod 600 "$TARGET_FILE" "$CHECKSUM_FILE"

echo "Encrypted uploads backup created: $TARGET_FILE"
echo "Checksum file: $CHECKSUM_FILE"
