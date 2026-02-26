#!/usr/bin/env bash
set -euo pipefail

if [[ $# -lt 1 ]]; then
  echo "Usage: $0 <https-base-url> [auth-path]"
  echo "Example: $0 https://panel.example.com /dashboard.php"
  exit 1
fi

BASE_URL="${1%/}"
AUTH_PATH="${2:-/dashboard.php}"

if [[ ! "$BASE_URL" =~ ^https:// ]]; then
  echo "Base URL must start with https://"
  exit 1
fi

HTTP_BASE_URL="http://${BASE_URL#https://}"
HTTPS_URL="${BASE_URL}${AUTH_PATH}"
HTTP_URL="${HTTP_BASE_URL}${AUTH_PATH}"

echo "[1/4] TLS certificate + HTTPS reachability"
curl -fsSIL "$HTTPS_URL" >/dev/null
echo "  PASS: HTTPS endpoint reachable with valid certificate"

echo "[2/4] HTTP is not serving authenticated route content directly"
HTTP_RESPONSE_HEADERS="$(curl -sSIL "$HTTP_URL" || true)"
HTTP_STATUS="$(printf '%s\n' "$HTTP_RESPONSE_HEADERS" | awk 'toupper($1) ~ /^HTTP\\// {code=$2} END {print code}')"
HTTP_LOCATION="$(printf '%s\n' "$HTTP_RESPONSE_HEADERS" | awk 'tolower($1) == "location:" {print $2; exit}' | tr -d '\r')"

if [[ "$HTTP_STATUS" =~ ^30[1278]$ ]] && [[ "$HTTP_LOCATION" =~ ^https:// ]]; then
  echo "  PASS: HTTP route redirects to HTTPS (${HTTP_STATUS})"
elif [[ "$HTTP_STATUS" == "403" ]]; then
  echo "  PASS: HTTP route rejected with 403"
else
  echo "  FAIL: HTTP route returned status ${HTTP_STATUS:-unknown} (expected HTTPS redirect or 403)"
  exit 1
fi

echo "[3/4] HSTS header check on HTTPS response"
HSTS_HEADER="$(curl -fsSIL "$HTTPS_URL" | awk -F': ' 'tolower($1) == "strict-transport-security" {print $2; exit}' | tr -d '\r')"
if [[ -n "$HSTS_HEADER" ]]; then
  echo "  PRESENT: Strict-Transport-Security: $HSTS_HEADER"
else
  echo "  NOT PRESENT: HSTS header missing (acceptable only before HSTS rollout)"
fi

echo "[4/4] Mixed-content quick scan (first HTML response)"
HTML_BODY="$(curl -fsSL "${BASE_URL}/login.php" || true)"
if printf '%s' "$HTML_BODY" | rg -n "http://[^\"'[:space:]]+" >/dev/null; then
  echo "  WARN: Found http:// references in rendered HTML. Review before enabling HSTS."
else
  echo "  PASS: No obvious http:// references in /login.php HTML"
fi

echo "Completed HTTPS/HSTS edge checks."
