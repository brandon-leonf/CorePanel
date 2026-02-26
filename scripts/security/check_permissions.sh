#!/usr/bin/env bash
set -euo pipefail

MODE="check"
if [[ "${1:-}" == "--fix" ]]; then
  MODE="fix"
  shift 1
fi

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
FAILURES=0

perm_of() {
  local target="$1"
  if stat -f '%Lp' "$target" >/dev/null 2>&1; then
    stat -f '%Lp' "$target"
    return 0
  fi

  if stat -c '%a' "$target" >/dev/null 2>&1; then
    stat -c '%a' "$target"
    return 0
  fi

  return 1
}

ensure_mode() {
  local path="$1"
  local expected="$2"
  local required_type="$3"
  local optional="$4"

  if [[ ! -e "$path" ]]; then
    if [[ "$optional" == "1" ]]; then
      echo "SKIP: $path (missing optional path)"
      return 0
    fi
    echo "FAIL: Missing required path: $path"
    FAILURES=$((FAILURES + 1))
    return 1
  fi

  if [[ "$required_type" == "dir" && ! -d "$path" ]]; then
    echo "FAIL: Expected directory: $path"
    FAILURES=$((FAILURES + 1))
    return 1
  fi
  if [[ "$required_type" == "file" && ! -f "$path" ]]; then
    echo "FAIL: Expected file: $path"
    FAILURES=$((FAILURES + 1))
    return 1
  fi

  local actual
  if ! actual="$(perm_of "$path")"; then
    echo "FAIL: Could not read permissions: $path"
    FAILURES=$((FAILURES + 1))
    return 1
  fi

  if [[ "$actual" == "$expected" ]]; then
    echo "PASS: $path permissions are $actual"
    return 0
  fi

  if [[ "$MODE" == "fix" ]]; then
    chmod "$expected" "$path"
    local new_actual
    new_actual="$(perm_of "$path" || true)"
    if [[ "$new_actual" == "$expected" ]]; then
      echo "FIXED: $path permissions $actual -> $new_actual"
      return 0
    fi
    echo "FAIL: Could not set permissions on $path (current $new_actual, expected $expected)"
    FAILURES=$((FAILURES + 1))
    return 1
  fi

  echo "FAIL: $path permissions are $actual (expected $expected)"
  FAILURES=$((FAILURES + 1))
  return 1
}

ensure_mode "$ROOT_DIR/storage" "700" "dir" "0" || true
ensure_mode "$ROOT_DIR/storage/backups" "700" "dir" "0" || true
ensure_mode "$ROOT_DIR/storage/logs" "700" "dir" "0" || true
ensure_mode "$ROOT_DIR/storage/uploads" "700" "dir" "0" || true
ensure_mode "$ROOT_DIR/storage/uploads/images" "700" "dir" "0" || true
ensure_mode "$ROOT_DIR/config" "750" "dir" "0" || true
ensure_mode "$ROOT_DIR/config/db.local.php" "600" "file" "1" || true
ensure_mode "$ROOT_DIR/config/security.env" "600" "file" "1" || true
ensure_mode "$ROOT_DIR/.env" "600" "file" "1" || true

if (( FAILURES > 0 )); then
  echo "FAIL: Permission check found $FAILURES issue(s)."
  exit 1
fi

if [[ "$MODE" == "fix" ]]; then
  echo "PASS: Permission check completed with fixes."
else
  echo "PASS: Permission check passed."
fi
