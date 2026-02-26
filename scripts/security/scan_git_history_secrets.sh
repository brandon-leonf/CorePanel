#!/usr/bin/env bash
set -euo pipefail

if ! git rev-parse --git-dir >/dev/null 2>&1; then
  echo "Run this script inside a git repository." >&2
  exit 2
fi

revisions="$(git rev-list --all)"
if [[ -z "$revisions" ]]; then
  echo "No git history found."
  exit 0
fi

if ! command -v rg >/dev/null 2>&1 && ! command -v grep >/dev/null 2>&1; then
  echo "No scanner available (missing 'rg' and 'grep')." >&2
  exit 2
fi

tmp_matches="$(mktemp)"
trap 'rm -f "$tmp_matches"' EXIT

scan_history() {
  local pattern="$1"
  git grep -nI -E -e "$pattern" $revisions -- . || true
}

{
  scan_history "-----BEGIN [A-Z ]*PRIVATE KEY-----"
  scan_history "AKIA[0-9A-Z]{16}"
  scan_history "ASIA[0-9A-Z]{16}"
  scan_history "gh[pousr]_[A-Za-z0-9_]{20,}"
  scan_history "xox[baprs]-[A-Za-z0-9-]{10,}"
  scan_history "AIza[0-9A-Za-z_-]{35}"
} > "$tmp_matches"

# Scan likely ENV-style assignments with key-like names.
git grep -nI -E \
  -e "^[[:space:]]*[A-Z0-9_]*(KEY|SECRET|TOKEN|PASSWORD|PASS)[A-Z0-9_]*[[:space:]]*=[[:space:]]*['\"]?[A-Za-z0-9+/_=-]{16,}['\"]?[[:space:]]*$" \
  $revisions -- . >> "$tmp_matches" || true

filtered="$(cat "$tmp_matches" \
  | grep -v "config/security.env.example" \
  | grep -v "config/db.local.example.php" \
  | grep -v "codex: allow-secret" \
  || true)"

if [[ -n "$filtered" ]]; then
  echo "Potential secrets found in git history:"
  echo "$filtered"
  echo
  echo "Action required:"
  echo "1) Rotate exposed credentials immediately."
  echo "2) Rewrite history (git filter-repo/BFG) and force-push."
  exit 1
fi

echo "No obvious secrets detected in git history."
exit 0
