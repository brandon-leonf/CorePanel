#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'EOF'
Usage: rollback_versioned_release.sh --target <app-root|user@host:app-root> [options]

Options:
  --to-version <version>            Roll back to this release version (defaults to previous symlink)
  --base-url <url>                  Run smoke tests after rollback
  --auth-path <path>                Authenticated route for HTTPS checks (default: /dashboard.php)
  --health-token <token>            Token for protected /health and /health/db checks
  --skip-smoke                      Skip smoke tests
  --skip-https-check                Pass-through to smoke tests
  --skip-web-exposure               Pass-through to smoke tests
  --skip-security-regression        Pass-through to smoke tests
  --skip-health-check               Pass-through to smoke tests
  --dry-run                         Print planned rollback without changing target

Examples:
  ./scripts/security/rollback_versioned_release.sh --target /var/www/corepanel-staging
  ./scripts/security/rollback_versioned_release.sh --target deploy@staging-host:/var/www/corepanel --to-version v2026.02.25.3
EOF
  exit 1
}

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TARGET=""
TO_VERSION=""
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
    --to-version)
      TO_VERSION="${2:-}"
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
if [[ -n "$TO_VERSION" && ! "$TO_VERSION" =~ ^[a-zA-Z0-9._-]{1,80}$ ]]; then
  echo "Invalid --to-version value. Use letters/numbers/dot/underscore/hyphen only."
  exit 1
fi

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

CURRENT_REL=""
TARGET_REL=""

if [[ "$IS_REMOTE" -eq 1 ]]; then
  mapfile -t ROLLBACK_INFO < <(ssh "$REMOTE_HOST" bash -s -- "$APP_ROOT" "$TO_VERSION" <<'EOF'
set -euo pipefail
APP_ROOT="$1"
TO_VERSION="$2"
CURRENT_LINK="$APP_ROOT/current"
PREVIOUS_LINK="$APP_ROOT/previous"

if [[ ! -L "$CURRENT_LINK" ]]; then
  echo "ERROR: Missing current symlink at $CURRENT_LINK" >&2
  exit 1
fi
CURRENT_REL="$(readlink "$CURRENT_LINK")"

TARGET_REL=""
if [[ -n "$TO_VERSION" ]]; then
  CANDIDATE="releases/$TO_VERSION"
  if [[ ! -d "$APP_ROOT/$CANDIDATE" ]]; then
    echo "ERROR: Requested release not found: $APP_ROOT/$CANDIDATE" >&2
    exit 1
  fi
  TARGET_REL="$CANDIDATE"
elif [[ -L "$PREVIOUS_LINK" ]]; then
  TARGET_REL="$(readlink "$PREVIOUS_LINK")"
  if [[ -z "$TARGET_REL" || ! -d "$APP_ROOT/$TARGET_REL" ]]; then
    echo "ERROR: previous symlink does not point to a valid release" >&2
    exit 1
  fi
else
  echo "ERROR: No --to-version supplied and previous symlink not available" >&2
  exit 1
fi

echo "$CURRENT_REL"
echo "$TARGET_REL"
EOF
)

  if [[ ${#ROLLBACK_INFO[@]} -lt 2 ]]; then
    echo "Unable to resolve rollback target on remote host."
    exit 1
  fi
  CURRENT_REL="${ROLLBACK_INFO[0]}"
  TARGET_REL="${ROLLBACK_INFO[1]}"
else
  CURRENT_LINK="$APP_ROOT/current"
  PREVIOUS_LINK="$APP_ROOT/previous"

  if [[ ! -L "$CURRENT_LINK" ]]; then
    echo "Missing current symlink at $CURRENT_LINK"
    exit 1
  fi
  CURRENT_REL="$(readlink "$CURRENT_LINK")"

  if [[ -n "$TO_VERSION" ]]; then
    TARGET_REL="releases/$TO_VERSION"
    if [[ ! -d "$APP_ROOT/$TARGET_REL" ]]; then
      echo "Requested release not found: $APP_ROOT/$TARGET_REL"
      exit 1
    fi
  elif [[ -L "$PREVIOUS_LINK" ]]; then
    TARGET_REL="$(readlink "$PREVIOUS_LINK")"
    if [[ -z "$TARGET_REL" || ! -d "$APP_ROOT/$TARGET_REL" ]]; then
      echo "previous symlink does not point to a valid release"
      exit 1
    fi
  else
    echo "No --to-version supplied and previous symlink not available"
    exit 1
  fi
fi

if [[ "$CURRENT_REL" == "$TARGET_REL" ]]; then
  echo "Current release already points to rollback target ($TARGET_REL)."
  exit 0
fi

if [[ "$DRY_RUN" -eq 1 ]]; then
  echo "DRY RUN: rollback plan"
  echo "  app root: $APP_ROOT"
  echo "  current:  $CURRENT_REL"
  echo "  target:   $TARGET_REL"
  echo "  current symlink would switch to: $TARGET_REL"
  exit 0
fi

if [[ "$IS_REMOTE" -eq 1 ]]; then
  ssh "$REMOTE_HOST" bash -s -- "$APP_ROOT" "$CURRENT_REL" "$TARGET_REL" <<'EOF'
set -euo pipefail
APP_ROOT="$1"
CURRENT_REL="$2"
TARGET_REL="$3"
CURRENT_LINK="$APP_ROOT/current"
PREVIOUS_LINK="$APP_ROOT/previous"

if [[ ! -d "$APP_ROOT/$TARGET_REL" ]]; then
  echo "Rollback target missing: $APP_ROOT/$TARGET_REL"
  exit 1
fi

ln -sfn "$CURRENT_REL" "$PREVIOUS_LINK"
ln -sfn "$TARGET_REL" "$CURRENT_LINK"
EOF
else
  CURRENT_LINK="$APP_ROOT/current"
  PREVIOUS_LINK="$APP_ROOT/previous"

  if [[ ! -d "$APP_ROOT/$TARGET_REL" ]]; then
    echo "Rollback target missing: $APP_ROOT/$TARGET_REL"
    exit 1
  fi

  ln -sfn "$CURRENT_REL" "$PREVIOUS_LINK"
  ln -sfn "$TARGET_REL" "$CURRENT_LINK"
fi

echo "Rollback completed: $CURRENT_REL -> $TARGET_REL"

if [[ "$SKIP_SMOKE" -eq 1 ]]; then
  echo "Smoke tests skipped (--skip-smoke)."
  exit 0
fi

if [[ -z "$BASE_URL" ]]; then
  echo "--base-url is required unless --skip-smoke is set."
  exit 1
fi

echo "Running smoke tests after rollback"
SMOKE_CMD=("$SMOKE_SCRIPT" "$BASE_URL" --auth-path "$AUTH_PATH")
if [[ -n "$HEALTH_TOKEN" ]]; then
  SMOKE_CMD+=(--health-token "$HEALTH_TOKEN")
fi
SMOKE_CMD+=("${SMOKE_ARGS[@]}")
"${SMOKE_CMD[@]}"

echo "Rollback + smoke checks completed successfully."
