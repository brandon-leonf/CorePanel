#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_root"

chmod +x .githooks/pre-commit
chmod +x scripts/security/check_staged_secrets.sh
chmod +x scripts/security/scan_git_history_secrets.sh

git config core.hooksPath .githooks

echo "Git hooks installed. core.hooksPath=.githooks"
