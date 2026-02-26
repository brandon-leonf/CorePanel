#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'EOF'
Usage: deploy_versioned_release.sh --target <app-root|user@host:app-root> --version <release-version> [options]

Options:
  --source-dir <dir>                Source project directory (default: repo root)
  --base-url <url>                  Run smoke tests after switching current symlink
  --auth-path <path>                Authenticated route for HTTPS checks (default: /dashboard.php)
  --health-token <token>            Token for protected /health and /health/db checks
  --skip-smoke                      Skip smoke tests
  --skip-https-check                Pass-through to smoke tests
  --skip-web-exposure               Pass-through to smoke tests
  --skip-security-regression        Pass-through to smoke tests
  --skip-health-check               Pass-through to smoke tests
  --dry-run                         Print planned actions without changing target

Examples:
  ./scripts/security/deploy_versioned_release.sh \
    --target /var/www/corepanel-staging \
    --version v2026.02.26.1 \
    --base-url https://staging.example.com

  ./scripts/security/deploy_versioned_release.sh \
    --target deploy@staging-host:/var/www/corepanel \
    --version v2026.02.26.1 \
    --base-url https://staging.example.com \
    --health-token "$COREPANEL_HEALTH_TOKEN"
EOF
  exit 1
}

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SOURCE_DIR="$ROOT_DIR"
TARGET=""
VERSION=""
BASE_URL=""
AUTH_PATH="/dashboard.php"
HEALTH_TOKEN=""
SKIP_SMOKE=0
DRY_RUN=0
SMOKE_ARGS=()

while [[ $# -gt 0 ]]; do
  case "$1" in
    --target)
      TARGET="${2:-}"
      shift 2
      ;;
    --version)
      VERSION="${2:-}"
      shift 2
      ;;
    --source-dir)
      SOURCE_DIR="${2:-}"
      shift 2
      ;;
    --base-url)
      BASE_URL="${2:-}"
      shift 2
      ;;
    --auth-path)
      AUTH_PATH="${2:-}"
      shift 2
      ;;
    --health-token)
      HEALTH_TOKEN="${2:-}"
      shift 2
      ;;
    --skip-smoke)
      SKIP_SMOKE=1
      shift 1
      ;;
    --skip-https-check|--skip-web-exposure|--skip-security-regression|--skip-health-check)
      SMOKE_ARGS+=("$1")
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

if [[ -z "$TARGET" || -z "$VERSION" ]]; then
  usage
fi
if [[ ! "$VERSION" =~ ^[a-zA-Z0-9._-]{1,80}$ ]]; then
  echo "Invalid --version value. Use letters/numbers/dot/underscore/hyphen only."
  exit 1
fi
if [[ ! -d "$SOURCE_DIR" ]]; then
  echo "Source directory not found: $SOURCE_DIR"
  exit 1
fi

for bin in rsync; do
  if ! command -v "$bin" >/dev/null 2>&1; then
    echo "Missing required command: $bin"
    exit 1
  fi
done

SMOKE_SCRIPT="$ROOT_DIR/scripts/security/run_staging_smoke_tests.sh"
if [[ "$SKIP_SMOKE" -eq 0 ]] && [[ ! -x "$SMOKE_SCRIPT" ]]; then
  echo "Missing smoke test script: $SMOKE_SCRIPT"
  exit 1
fi

IS_REMOTE=0
REMOTE_HOST=""
APP_ROOT=""
if [[ "$TARGET" == *:* ]]; then
  IS_REMOTE=1
  REMOTE_HOST="${TARGET%%:*}"
  APP_ROOT="${TARGET#*:}"
  if [[ -z "$REMOTE_HOST" || -z "$APP_ROOT" ]]; then
    echo "Invalid remote target format: $TARGET"
    exit 1
  fi
  if ! command -v ssh >/dev/null 2>&1; then
    echo "Missing required command: ssh"
    exit 1
  fi
else
  APP_ROOT="$TARGET"
fi

RELEASE_REL_PATH="releases/$VERSION"

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

if [[ "$DRY_RUN" -eq 1 ]]; then
  echo "DRY RUN: planned deployment"
  if [[ "$IS_REMOTE" -eq 1 ]]; then
    echo "  remote host: $REMOTE_HOST"
  fi
  echo "  app root: $APP_ROOT"
  echo "  version: $VERSION"
  echo "  source: $SOURCE_DIR"
  echo "  release dir: $APP_ROOT/$RELEASE_REL_PATH"
  echo "  switch symlink: $APP_ROOT/current -> $RELEASE_REL_PATH"
  if [[ "$SKIP_SMOKE" -eq 0 ]]; then
    echo "  smoke checks would run against: $BASE_URL"
  fi
  exit 0
fi

if [[ "$IS_REMOTE" -eq 1 ]]; then
  echo "Preparing remote release directories: $REMOTE_HOST:$APP_ROOT"
  ssh "$REMOTE_HOST" bash -s -- "$APP_ROOT" "$VERSION" <<'EOF'
set -euo pipefail
APP_ROOT="$1"
VERSION="$2"
mkdir -p "$APP_ROOT/releases" "$APP_ROOT/shared/storage/uploads/images" "$APP_ROOT/shared/storage/backups" "$APP_ROOT/shared/storage/logs"
if [[ -e "$APP_ROOT/releases/$VERSION" ]]; then
  echo "Release already exists: $APP_ROOT/releases/$VERSION"
  exit 1
fi
mkdir -p "$APP_ROOT/releases/$VERSION"
EOF

  echo "Syncing release contents to remote target"
  rsync "${RSYNC_ARGS[@]}" "$SOURCE_DIR/" "$REMOTE_HOST:$APP_ROOT/$RELEASE_REL_PATH/"

  if [[ -f "$SOURCE_DIR/storage/.htaccess" ]]; then
    rsync -az --ignore-existing "$SOURCE_DIR/storage/.htaccess" "$REMOTE_HOST:$APP_ROOT/shared/storage/.htaccess"
  fi

  echo "Finalizing remote symlinks (current/previous)"
  ssh "$REMOTE_HOST" bash -s -- "$APP_ROOT" "$VERSION" <<'EOF'
set -euo pipefail
APP_ROOT="$1"
VERSION="$2"
RELEASE_DIR="$APP_ROOT/releases/$VERSION"
CURRENT_LINK="$APP_ROOT/current"
PREVIOUS_LINK="$APP_ROOT/previous"

if [[ ! -d "$RELEASE_DIR" ]]; then
  echo "Release directory missing: $RELEASE_DIR"
  exit 1
fi

rm -rf "$RELEASE_DIR/storage"
ln -sfn ../../shared/storage "$RELEASE_DIR/storage"

printf '%s\n' "$VERSION" > "$RELEASE_DIR/RELEASE_VERSION"
printf '%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$RELEASE_DIR/RELEASE_DEPLOYED_AT_UTC"

if [[ -L "$CURRENT_LINK" ]]; then
  OLD_CURRENT="$(readlink "$CURRENT_LINK")"
  if [[ -n "$OLD_CURRENT" ]]; then
    ln -sfn "$OLD_CURRENT" "$PREVIOUS_LINK"
  fi
fi
ln -sfn "releases/$VERSION" "$CURRENT_LINK"
EOF
else
  echo "Preparing local release directories: $APP_ROOT"
  mkdir -p "$APP_ROOT/releases" "$APP_ROOT/shared/storage/uploads/images" "$APP_ROOT/shared/storage/backups" "$APP_ROOT/shared/storage/logs"
  if [[ -e "$APP_ROOT/$RELEASE_REL_PATH" ]]; then
    echo "Release already exists: $APP_ROOT/$RELEASE_REL_PATH"
    exit 1
  fi
  mkdir -p "$APP_ROOT/$RELEASE_REL_PATH"

  echo "Syncing release contents to local target"
  rsync "${RSYNC_ARGS[@]}" "$SOURCE_DIR/" "$APP_ROOT/$RELEASE_REL_PATH/"

  if [[ -f "$SOURCE_DIR/storage/.htaccess" ]] && [[ ! -f "$APP_ROOT/shared/storage/.htaccess" ]]; then
    cp "$SOURCE_DIR/storage/.htaccess" "$APP_ROOT/shared/storage/.htaccess"
    chmod 600 "$APP_ROOT/shared/storage/.htaccess"
  fi

  RELEASE_DIR="$APP_ROOT/$RELEASE_REL_PATH"
  CURRENT_LINK="$APP_ROOT/current"
  PREVIOUS_LINK="$APP_ROOT/previous"

  rm -rf "$RELEASE_DIR/storage"
  ln -sfn ../../shared/storage "$RELEASE_DIR/storage"

  printf '%s\n' "$VERSION" > "$RELEASE_DIR/RELEASE_VERSION"
  printf '%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$RELEASE_DIR/RELEASE_DEPLOYED_AT_UTC"

  if [[ -L "$CURRENT_LINK" ]]; then
    OLD_CURRENT="$(readlink "$CURRENT_LINK")"
    if [[ -n "$OLD_CURRENT" ]]; then
      ln -sfn "$OLD_CURRENT" "$PREVIOUS_LINK"
    fi
  fi
  ln -sfn "$RELEASE_REL_PATH" "$CURRENT_LINK"
fi

echo "Versioned release deployed: $VERSION"

if [[ "$SKIP_SMOKE" -eq 1 ]]; then
  echo "Smoke tests skipped (--skip-smoke)."
  exit 0
fi

if [[ -z "$BASE_URL" ]]; then
  echo "--base-url is required unless --skip-smoke is set."
  exit 1
fi

echo "Running smoke tests after release switch"
SMOKE_CMD=("$SMOKE_SCRIPT" "$BASE_URL" --auth-path "$AUTH_PATH")
if [[ -n "$HEALTH_TOKEN" ]]; then
  SMOKE_CMD+=(--health-token "$HEALTH_TOKEN")
fi
SMOKE_CMD+=("${SMOKE_ARGS[@]}")
"${SMOKE_CMD[@]}"

echo "Release + smoke checks completed successfully."
