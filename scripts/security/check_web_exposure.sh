#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${1:-http://localhost:8000}"

for bin in curl; do
  if ! command -v "$bin" >/dev/null 2>&1; then
    echo "Missing required command: $bin"
    exit 1
  fi
done

check_blocked_path() {
  local path="$1"
  local label="$2"
  local body_file
  body_file="$(mktemp)"
  local code
  code="$(curl -sS -o "$body_file" -w '%{http_code}' "$BASE_URL$path")"
  local body_head
  body_head="$(head -n 3 "$body_file" | tr '\n' ' ')"
  rm -f "$body_file"

  if [[ "$code" =~ ^2 ]]; then
    echo "FAIL: $label is reachable ($path => HTTP $code)."
    if [[ -n "$body_head" ]]; then
      echo "  Response preview: $body_head"
    fi
    return 1
  fi

  echo "PASS: $label blocked ($path => HTTP $code)."
  return 0
}

FAILURES=0

check_blocked_path "/.env" ".env file" || FAILURES=$((FAILURES + 1))
check_blocked_path "/.env.local" ".env.* file" || FAILURES=$((FAILURES + 1))
check_blocked_path "/config/security.env" "security env file in /config" || FAILURES=$((FAILURES + 1))
check_blocked_path "/config/db.local.php" "local DB config in /config" || FAILURES=$((FAILURES + 1))
check_blocked_path "/uploads/" "direct /uploads directory access" || FAILURES=$((FAILURES + 1))
check_blocked_path "/uploads/.gitkeep" "direct /uploads file access" || FAILURES=$((FAILURES + 1))
check_blocked_path "/phpinfo.php" "phpinfo dev endpoint" || FAILURES=$((FAILURES + 1))
check_blocked_path "/debug.php" "debug endpoint" || FAILURES=$((FAILURES + 1))
check_blocked_path "/test.php" "test endpoint" || FAILURES=$((FAILURES + 1))
check_blocked_path "/csp_report.php" "CSP report/dev endpoint (should be disabled in production)" || FAILURES=$((FAILURES + 1))
check_blocked_path "/__security_probe_nonexistent__" "unknown route fallback" || FAILURES=$((FAILURES + 1))

if (( FAILURES > 0 )); then
  echo "FAIL: Web exposure checks failed ($FAILURES issue(s))."
  exit 1
fi

echo "PASS: Web exposure checks passed."
