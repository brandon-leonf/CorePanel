# Secrets Hygiene

This document defines how CorePanel prevents secrets from being committed and how to respond if secrets are found in git history.

## Commit-Time Secret Blocking

- Hook path: `.githooks/pre-commit`
- Scanner: `scripts/security/check_staged_secrets.sh`

The hook scans staged additions for common secret signatures:

- Private key blocks
- Cloud/API tokens (AWS/GitHub/Google/Slack patterns)
- Bearer tokens
- ENV-style key/token/password assignments

Enable hooks once per clone:

```bash
./scripts/install_git_hooks.sh
```

## Local Secret File Policy

Never commit real runtime secrets. Use ignored local files:

- `config/db.local.php`
- `config/security.env`
- `.env` / `.env.*`

Commit only templates/examples:

- `config/db.local.example.php`
- `config/security.env.example`

## History Scanning

Run periodic history scans:

```bash
./scripts/security/scan_git_history_secrets.sh
```

## If A Secret Is Found In History

1. Rotate/revoke the exposed key immediately.
2. Rewrite git history (`git filter-repo` or BFG) to remove the secret.
3. Force-push cleaned history and coordinate team re-clones/rebases.
4. Re-run the history scan script until clean.
