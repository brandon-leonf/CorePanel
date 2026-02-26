#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'EOF'
Usage: run_staging_smoke_tests.sh <base-url> [options]

Options:
  --auth-path <path>                Authenticated route path for HTTPS checks (default: /dashboard.php)
  --health-token <token>            Token for protected /health and /health/db checks
  --skip-https-check                Skip HTTPS/HSTS edge check
  --skip-web-exposure               Skip web exposure check
  --skip-security-regression        Skip security regression suite
  --skip-health-check               Skip protected health endpoint checks
EOF
  exit 1
}

if [[ $# -lt 1 ]]; then
  usage
fi

BASE_URL="${1%/}"
shift

AUTH_PATH="/dashboard.php"
HEALTH_TOKEN=""
SKIP_HTTPS_CHECK=0
SKIP_WEB_EXPOSURE=0
SKIP_SECURITY_REGRESSION=0
SKIP_HEALTH_CHECK=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --auth-path)
      AUTH_PATH="${2:-}"
      shift 2
      ;;
    --health-token)
      HEALTH_TOKEN="${2:-}"
      shift 2
      ;;
    --skip-https-check)
      SKIP_HTTPS_CHECK=1
      shift 1
      ;;
    --skip-web-exposure)
      SKIP_WEB_EXPOSURE=1
      shift 1
      ;;
    --skip-security-regression)
      SKIP_SECURITY_REGRESSION=1
      shift 1
      ;;
    --skip-health-check)
      SKIP_HEALTH_CHECK=1
      shift 1
      ;;
    *)
      usage
      ;;
  esac
done

if [[ -z "$BASE_URL" ]]; then
  usage
fi

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CHECK_HTTPS_SCRIPT="$ROOT_DIR/scripts/security/check_https_edge.sh"
CHECK_WEB_EXPOSURE_SCRIPT="$ROOT_DIR/scripts/security/check_web_exposure.sh"
SECURITY_REGRESSION_SCRIPT="$ROOT_DIR/scripts/security/run_security_regression.sh"

for bin in curl rg; do
  if ! command -v "$bin" >/dev/null 2>&1; then
    echo "Missing required command: $bin"
    exit 1
  fi
done

for script_file in "$CHECK_HTTPS_SCRIPT" "$CHECK_WEB_EXPOSURE_SCRIPT" "$SECURITY_REGRESSION_SCRIPT"; do
  if [[ ! -x "$script_file" ]]; then
    echo "Required script is missing or not executable: $script_file"
    exit 1
  fi
done

step=1

echo "[${step}] Baseline reachability checks"
for path in /login.php /forgot_password.php; do
  status="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE_URL$path")"
  if [[ ! "$status" =~ ^2|3 ]]; then
    echo "FAIL: $BASE_URL$path returned HTTP $status"
    exit 1
  fi
  echo "  PASS: $path reachable (HTTP $status)"
done
step=$((step + 1))

if [[ "$SKIP_HTTPS_CHECK" -eq 0 ]]; then
  if [[ "$BASE_URL" =~ ^https:// ]]; then
    echo "[${step}] HTTPS/HSTS edge check"
    "$CHECK_HTTPS_SCRIPT" "$BASE_URL" "$AUTH_PATH"
  else
    echo "[${step}] HTTPS/HSTS edge check skipped (base URL is not https://)"
  fi
else
  echo "[${step}] HTTPS/HSTS edge check skipped (--skip-https-check)"
fi
step=$((step + 1))

if [[ "$SKIP_WEB_EXPOSURE" -eq 0 ]]; then
  echo "[${step}] Web exposure check"
  "$CHECK_WEB_EXPOSURE_SCRIPT" "$BASE_URL"
else
  echo "[${step}] Web exposure check skipped (--skip-web-exposure)"
fi
step=$((step + 1))

if [[ "$SKIP_HEALTH_CHECK" -eq 0 ]]; then
  if [[ -n "$HEALTH_TOKEN" ]]; then
    echo "[${step}] Protected health endpoint checks"

    health_body_file="$(mktemp)"
    health_status="$(curl -sS -o "$health_body_file" -w '%{http_code}' -H "X-Health-Token: $HEALTH_TOKEN" "$BASE_URL/health")"
    if [[ "$health_status" != "200" ]] || ! rg -n '"status"\s*:\s*"ok"' "$health_body_file" >/dev/null; then
      echo "FAIL: /health check failed (HTTP $health_status)."
      echo "Body preview: $(head -c 300 "$health_body_file")"
      rm -f "$health_body_file"
      exit 1
    fi
    rm -f "$health_body_file"

    health_db_body_file="$(mktemp)"
    health_db_status="$(curl -sS -o "$health_db_body_file" -w '%{http_code}' -H "X-Health-Token: $HEALTH_TOKEN" "$BASE_URL/health/db")"
    if [[ "$health_db_status" != "200" ]] || ! rg -n '"status"\s*:\s*"ok"' "$health_db_body_file" >/dev/null; then
      echo "FAIL: /health/db check failed (HTTP $health_db_status)."
      echo "Body preview: $(head -c 300 "$health_db_body_file")"
      rm -f "$health_db_body_file"
      exit 1
    fi
    rm -f "$health_db_body_file"

    echo "  PASS: /health and /health/db returned status ok"
  else
    echo "[${step}] Protected health endpoint checks skipped (no --health-token provided)"
  fi
else
  echo "[${step}] Protected health endpoint checks skipped (--skip-health-check)"
fi
step=$((step + 1))

if [[ "$SKIP_SECURITY_REGRESSION" -eq 0 ]]; then
  echo "[${step}] Security regression suite"
  "$SECURITY_REGRESSION_SCRIPT" "$BASE_URL"
else
  echo "[${step}] Security regression suite skipped (--skip-security-regression)"
fi

echo "Staging smoke tests completed successfully."
