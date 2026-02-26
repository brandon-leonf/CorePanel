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
- encryption key files (`*.pem`, `*.key`, `*.p12`, `*.pfx`, `*.jks`, `*.keystore`)

Explicitly never store in GitHub:

- `.env` and encryption keys
- DB passwords
- SMTP passwords
- CAPTCHA secrets

Commit only templates/examples:

- `config/db.local.example.php`
- `config/security.env.example`

Commit hook now blocks staged changes that include:

- Forbidden files: `.env`, `.env.*` (except `.env.example`/`.env.sample`/`.env.template`), `config/security.env`, `config/db.local.php`
- Key-material filename patterns above
- Non-placeholder assignments for:
  - `COREPANEL_FIELD_KEY`, `COREPANEL_ENCRYPTION_KEY`
  - `COREPANEL_DB_PASSWORD`, `DB_PASSWORD`, `MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD`, `MARIADB_ROOT_PASSWORD`
  - `SMTP_PASSWORD`, `SMTP_PASS`, `MAIL_PASSWORD`
  - `RECAPTCHA_SECRET`, `CAPTCHA_SECRET`, `HCAPTCHA_SECRET`

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
