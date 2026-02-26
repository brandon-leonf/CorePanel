#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'EOF'
Usage: deploy_staging.sh --target <rsync-destination> [options]

Options:
  --source-dir <dir>                Source project directory (default: repo root)
  --base-url <url>                  Run smoke tests against this staging URL after deploy
  --auth-path <path>                Authenticated route path for HTTPS checks (default: /dashboard.php)
  --health-token <token>            Token for protected /health smoke checks
  --skip-smoke                      Skip smoke tests after deploy
  --skip-https-check                Pass-through to smoke tests
  --skip-web-exposure               Pass-through to smoke tests
  --skip-security-regression        Pass-through to smoke tests
  --skip-health-check               Pass-through to smoke tests
  --dry-run                         Dry run rsync and smoke flow

Target format examples:
  local:  /var/www/corepanel-staging
  remote: deploy@staging-host:/var/www/corepanel
EOF
  exit 1
}

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SOURCE_DIR="$ROOT_DIR"
TARGET=""
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

if [[ -z "$TARGET" ]]; then
  usage
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

is_remote=0
remote_host=""
remote_path=""
if [[ "$TARGET" == *:* ]]; then
  is_remote=1
  remote_host="${TARGET%%:*}"
  remote_path="${TARGET#*:}"
  if [[ -z "$remote_host" || -z "$remote_path" ]]; then
    echo "Invalid remote target format: $TARGET"
    exit 1
  fi
  if ! command -v ssh >/dev/null 2>&1; then
    echo "Missing required command: ssh (needed for remote target)"
    exit 1
  fi
fi

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
  RSYNC_ARGS+=(--dry-run)
fi

if [[ "$is_remote" -eq 1 ]]; then
  echo "Preparing remote staging directory: ${remote_host}:${remote_path}"
  if [[ "$DRY_RUN" -eq 0 ]]; then
    ssh "$remote_host" "mkdir -p '$remote_path'"
  else
    echo "DRY RUN: ssh $remote_host mkdir -p '$remote_path'"
  fi
  DEPLOY_DEST="$TARGET/"
else
  echo "Preparing local staging directory: $TARGET"
  mkdir -p "$TARGET"
  DEPLOY_DEST="${TARGET%/}/"
fi

echo "Deploying source to staging target via rsync"
rsync "${RSYNC_ARGS[@]}" "$SOURCE_DIR/" "$DEPLOY_DEST"

echo "Deployment sync completed."

if [[ "$SKIP_SMOKE" -eq 1 ]]; then
  echo "Smoke tests skipped (--skip-smoke)."
  exit 0
fi

if [[ -z "$BASE_URL" ]]; then
  echo "--base-url is required unless --skip-smoke is set."
  exit 1
fi

echo "Running staging smoke tests against $BASE_URL"
SMOKE_CMD=("$SMOKE_SCRIPT" "$BASE_URL" --auth-path "$AUTH_PATH")
if [[ -n "$HEALTH_TOKEN" ]]; then
  SMOKE_CMD+=(--health-token "$HEALTH_TOKEN")
fi
if [[ "$DRY_RUN" -eq 1 ]]; then
  SMOKE_CMD+=(--skip-security-regression)
fi
SMOKE_CMD+=("${SMOKE_ARGS[@]}")

"${SMOKE_CMD[@]}"

echo "Staging deploy + smoke tests completed successfully."
