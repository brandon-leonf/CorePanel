#!/usr/bin/env bash
set -euo pipefail

if ! git rev-parse --git-dir >/dev/null 2>&1; then
  exit 0
fi

if git diff --cached --quiet --exit-code; then
  exit 0
fi

if ! command -v rg >/dev/null 2>&1 && ! command -v grep >/dev/null 2>&1; then
  echo "Secret scan skipped: neither 'rg' nor 'grep' is available." >&2
  exit 0
fi

tmp_added="$(mktemp)"
tmp_filtered="$(mktemp)"
trap 'rm -f "$tmp_added" "$tmp_filtered"' EXIT

git diff --cached --no-color -U0 -- . \
  | awk '
      /^\+\+\+ b\// { file = substr($0, 7); next }
      /^\+\+\+ / { next }
      /^\+/ {
        if (file == "") next
        line = substr($0, 2)
        if (line ~ /^[[:space:]]*$/) next
        print file ":" line
      }
    ' > "$tmp_added"

if [[ ! -s "$tmp_added" ]]; then
  exit 0
fi

awk '
  {
    line = $0
    if (line ~ /codex:[[:space:]]*allow-secret/) next
    if (line ~ /:[[:space:]]*#/) next
    print line
  }
' "$tmp_added" > "$tmp_filtered"

if [[ ! -s "$tmp_filtered" ]]; then
  exit 0
fi

scan_pattern() {
  local pattern="$1"
  if command -v rg >/dev/null 2>&1; then
    rg -n --pcre2 -e "$pattern" "$tmp_filtered" || true
  else
    grep -nE "$pattern" "$tmp_filtered" || true
  fi
}

declare -a patterns=(
  "-----BEGIN [A-Z ]*PRIVATE KEY-----"
  "AKIA[0-9A-Z]{16}"
  "ASIA[0-9A-Z]{16}"
  "gh[pousr]_[A-Za-z0-9_]{20,}"
  "AIza[0-9A-Za-z_-]{35}"
  "xox[baprs]-[A-Za-z0-9-]{10,}"
  "(?i)authorization:[[:space:]]*bearer[[:space:]]+[A-Za-z0-9._-]{20,}"
  ":[[:space:]]*[A-Z0-9_]*(KEY|SECRET|TOKEN|PASSWORD|PASS)[A-Z0-9_]*[[:space:]]*=[[:space:]]*['\"]?[A-Za-z0-9+/_=-]{16,}['\"]?[[:space:]]*$"
)

violations=0
for pattern in "${patterns[@]}"; do
  matches="$(scan_pattern "$pattern")"
  if [[ -n "$matches" ]]; then
    if [[ $violations -eq 0 ]]; then
      echo "ERROR: Potential secret detected in staged changes." >&2
      echo "Review and remove secrets before commit." >&2
      echo "" >&2
    fi
    violations=1
    echo "$matches" >&2
    echo "" >&2
  fi
done

if [[ $violations -ne 0 ]]; then
  echo "If this is intentional test data, add 'codex: allow-secret' on that line." >&2
  exit 1
fi

exit 0
