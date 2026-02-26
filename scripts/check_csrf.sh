#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"

mapfile -t PHP_FILES < <(rg --files "$ROOT/public" "$ROOT/src" | rg '\.php$')

missing_form_token=()
missing_post_verify=()

for f in "${PHP_FILES[@]}"; do
  if rg -qi '<form[^>]*method="post"' "$f" || rg -qi "<form[^>]*method='post'" "$f"; then
    if ! rg -q 'csrf_token\(' "$f"; then
      missing_form_token+=("$f")
    fi
  fi

  if rg -q "REQUEST_METHOD.*POST" "$f"; then
    if ! rg -q 'csrf_verify\(' "$f"; then
      missing_post_verify+=("$f")
    fi
  fi
done

if ((${#missing_form_token[@]} > 0)); then
  echo "CSRF check failed: POST forms missing csrf_token() in:" >&2
  printf '  %s\n' "${missing_form_token[@]}" >&2
fi

if ((${#missing_post_verify[@]} > 0)); then
  echo "CSRF check failed: POST handlers missing csrf_verify() in:" >&2
  printf '  %s\n' "${missing_post_verify[@]}" >&2
fi

if ((${#missing_form_token[@]} > 0 || ${#missing_post_verify[@]} > 0)); then
  exit 1
fi

echo "CSRF check passed: all POST forms and POST handlers are protected."
