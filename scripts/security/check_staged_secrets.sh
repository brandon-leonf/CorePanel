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
tmp_files="$(mktemp)"
trap 'rm -f "$tmp_added" "$tmp_filtered" "$tmp_files"' EXIT

git diff --cached --name-only --diff-filter=ACMR -- . > "$tmp_files"

file_violations=0
while IFS= read -r staged_file; do
  [[ -z "$staged_file" ]] && continue
  case "$staged_file" in
    .env|.env.*)
      case "$staged_file" in
        .env.example|.env.sample|.env.template)
          ;;
        *)
          if [[ $file_violations -eq 0 ]]; then
            echo "ERROR: Forbidden secret file detected in staged changes." >&2
          fi
          file_violations=1
          echo "  - $staged_file" >&2
          ;;
      esac
      ;;
    config/security.env|config/db.local.php)
      if [[ $file_violations -eq 0 ]]; then
        echo "ERROR: Forbidden secret file detected in staged changes." >&2
      fi
      file_violations=1
      echo "  - $staged_file" >&2
      ;;
    *.pem|*.key|*.p12|*.pfx|*.jks|*.keystore)
      if [[ $file_violations -eq 0 ]]; then
        echo "ERROR: Potential key material file detected in staged changes." >&2
      fi
      file_violations=1
      echo "  - $staged_file" >&2
      ;;
  esac
done < "$tmp_files"

if [[ $file_violations -ne 0 ]]; then
  echo "" >&2
  echo "Do not commit local secret files or encryption keys to git." >&2
  exit 1
fi

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

is_placeholder_value() {
  local value_lc="$1"
  [[ "$value_lc" == "changeme" ]] && return 0
  [[ "$value_lc" == "change_me" ]] && return 0
  [[ "$value_lc" == "replace_me" ]] && return 0
  [[ "$value_lc" == "example" ]] && return 0
  [[ "$value_lc" == "example_value" ]] && return 0
  [[ "$value_lc" == "placeholder" ]] && return 0
  [[ "$value_lc" == "your_value_here" ]] && return 0
  [[ "$value_lc" == "set_me" ]] && return 0
  [[ "$value_lc" == "todo" ]] && return 0
  return 1
}

while IFS= read -r entry; do
  [[ -z "$entry" ]] && continue
  file_part="${entry%%:*}"
  line_part="${entry#*:}"

  if [[ "$line_part" =~ ^[[:space:]]*([A-Za-z_][A-Za-z0-9_]*)[[:space:]]*=[[:space:]]*(.+)$ ]]; then
    var_name="${BASH_REMATCH[1]}"
    raw_value="${BASH_REMATCH[2]}"

    case "$var_name" in
      COREPANEL_FIELD_KEY|COREPANEL_ENCRYPTION_KEY|COREPANEL_DB_PASSWORD|DB_PASSWORD|MYSQL_PASSWORD|MYSQL_ROOT_PASSWORD|MARIADB_ROOT_PASSWORD|SMTP_PASSWORD|SMTP_PASS|MAIL_PASSWORD|RECAPTCHA_SECRET|CAPTCHA_SECRET|HCAPTCHA_SECRET)
        clean_value="${raw_value%%#*}"
        clean_value="${clean_value%\"}"
        clean_value="${clean_value#\"}"
        clean_value="${clean_value%\'}"
        clean_value="${clean_value#\'}"
        clean_value="$(printf '%s' "$clean_value" | sed -E 's/^[[:space:]]+//; s/[[:space:]]+$//')"
        if [[ -n "$clean_value" ]]; then
          value_lc="$(printf '%s' "$clean_value" | tr '[:upper:]' '[:lower:]')"
          if ! is_placeholder_value "$value_lc"; then
            if [[ $violations -eq 0 ]]; then
              echo "ERROR: Potential secret detected in staged changes." >&2
              echo "Review and remove secrets before commit." >&2
              echo "" >&2
            fi
            violations=1
            echo "$file_part:$line_part" >&2
            echo "" >&2
          fi
        fi
        ;;
    esac
  fi
done < "$tmp_filtered"

if [[ $violations -ne 0 ]]; then
  echo "If this is intentional test data, add 'codex: allow-secret' on that line." >&2
  exit 1
fi

exit 0
