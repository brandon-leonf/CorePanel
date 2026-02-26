#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'EOF'
Usage: package_release.sh [--version <release-version>] [--source-dir <dir>] [--out-dir <dir>]

Creates a versioned release tarball and checksum using project-safe exclusions.

Examples:
  ./scripts/security/package_release.sh --version v2026.02.26.1
  ./scripts/security/package_release.sh --out-dir /tmp/releases
EOF
  exit 1
}

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SOURCE_DIR="$ROOT_DIR"
OUT_DIR="$ROOT_DIR/storage/releases/artifacts"
VERSION=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --version)
      VERSION="${2:-}"
      shift 2
      ;;
    --source-dir)
      SOURCE_DIR="${2:-}"
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

if [[ ! -d "$SOURCE_DIR" ]]; then
  echo "Source directory not found: $SOURCE_DIR"
  exit 1
fi

if [[ -z "$VERSION" ]]; then
  ts="$(date -u +%Y%m%dT%H%M%SZ)"
  git_sha="nogit"
  if command -v git >/dev/null 2>&1 && git -C "$SOURCE_DIR" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    git_sha="$(git -C "$SOURCE_DIR" rev-parse --short HEAD 2>/dev/null || echo nogit)"
  fi
  VERSION="v${ts}-${git_sha}"
fi

if [[ ! "$VERSION" =~ ^[a-zA-Z0-9._-]{1,80}$ ]]; then
  echo "Invalid version format. Use letters/numbers/dot/underscore/hyphen only."
  exit 1
fi

for bin in rsync tar gzip; do
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

ARTIFACT="$OUT_DIR/${VERSION}.tar.gz"
CHECKSUM_FILE="$ARTIFACT.sha256"
if [[ -e "$ARTIFACT" || -e "$CHECKSUM_FILE" ]]; then
  echo "Artifact already exists for version: $VERSION"
  exit 1
fi

TMP_DIR="$(mktemp -d "${OUT_DIR%/}/.tmp_release_${VERSION}_XXXXXX")"
trap 'rm -rf "$TMP_DIR"' EXIT
STAGE_DIR="$TMP_DIR/$VERSION"
mkdir -p "$STAGE_DIR"

RSYNC_ARGS=(
  -az
  --delete
  --exclude=.git/
  --exclude=.github/
  --exclude=.DS_Store
  --exclude=config/db.local.php
  --exclude=config/security.env
  --exclude=.env
  --exclude=storage/backups/
  --exclude=storage/logs/
  --exclude=storage/uploads/
)

rsync "${RSYNC_ARGS[@]}" "$SOURCE_DIR/" "$STAGE_DIR/"

GIT_COMMIT=""
if command -v git >/dev/null 2>&1 && git -C "$SOURCE_DIR" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  GIT_COMMIT="$(git -C "$SOURCE_DIR" rev-parse HEAD 2>/dev/null || true)"
fi

printf '%s\n' "$VERSION" > "$STAGE_DIR/RELEASE_VERSION"
printf '%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$STAGE_DIR/RELEASE_PACKAGED_AT_UTC"
if [[ -n "$GIT_COMMIT" ]]; then
  printf '%s\n' "$GIT_COMMIT" > "$STAGE_DIR/RELEASE_GIT_COMMIT"
fi

(
  cd "$TMP_DIR"
  tar -cf - "$VERSION" | gzip -c > "$ARTIFACT"
)

"${CHECKSUM_CMD[@]}" "$ARTIFACT" > "$CHECKSUM_FILE"
chmod 600 "$ARTIFACT" "$CHECKSUM_FILE"

echo "Release version: $VERSION"
echo "Release artifact created: $ARTIFACT"
echo "Checksum file: $CHECKSUM_FILE"
