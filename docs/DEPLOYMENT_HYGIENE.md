# Deployment Hygiene Checklist

## 1) PHP runtime

- Production default is non-debug (`COREPANEL_DEBUG` disabled).
- Runtime bootstrap enforces:
  - `display_errors=0`
  - `display_startup_errors=0`
  - `log_errors=1`
- Uncaught exceptions and fatal errors are logged server-side and return a generic 500 response.

## 2) User-facing error leakage

- DB connection failures return generic messages in non-debug mode.
- Forgot-password reset token links are shown only in debug mode.
- No stack traces or SQL error details should be shown to end users in production.

## 3) Secret and env file exposure

- Sensitive runtime files are git-ignored (`.env*`, `config/security.env`, `config/*.local.php`).
- HTTP deny rules are present for:
  - `public/.htaccess` (dotfiles, `.env*`, backup/log/sql artifacts, debug/test files)
  - `config/.htaccess` (deny all)
  - `storage/.htaccess` (deny all)
- Deploy with web root set to `public/` only.

## 4) Debug/test endpoint exposure

- CSP report endpoint (`/csp_report.php`) is disabled by default unless explicitly enabled:
  - `COREPANEL_CSP_REPORT_ENABLED=1`
- CSP `report-uri` is only emitted when CSP report endpoint is enabled.
- Keep `phpinfo`, test, and debug routes absent from production.

## 5) HTTPS and HSTS rollout

- TLS certificate must be valid for production hostnames at the edge/proxy.
- Authenticated routes are HTTPS-only:
  - any route behind `require_login()` redirects HTTP to HTTPS or rejects with `403`.
- If using reverse proxy headers:
  - enable `COREPANEL_TRUST_PROXY_HEADERS=1`
  - set `COREPANEL_TRUSTED_PROXIES` to exact proxy IP/CIDR values.
- HSTS should be enabled only after HTTPS is validated end-to-end:
  - start with `COREPANEL_HSTS_ENABLED=0`
  - after confirming no mixed content and no subdomain breakage, set `COREPANEL_HSTS_ENABLED=1`.
- Quick validation script:
  - `./scripts/security/check_https_edge.sh https://your-domain.example /dashboard.php`

## 6) Database and secret operations

- DB runtime user must be least-privilege (no `GRANT ALL`, no admin-level grants).
- Use separate accounts for runtime, migrations, and backups.
- Validate grants with:
  - `./scripts/security/check_db_least_privilege.sh --user corepanel_app --password-env COREPANEL_APP_DB_PASSWORD --mode runtime`
- Deployment gate:
  - if least-privilege check fails, do not deploy until runtime DB credentials are switched to a non-privileged account.
- Keep an explicit encryption key rotation policy:
  - [docs/KEY_ROTATION.md](/Users/brandon/Desktop/Projects/COREPANEL/docs/KEY_ROTATION.md)
- Keep key versioning and compromise response plans documented:
  - [docs/KEY_COMPROMISE_RESPONSE.md](/Users/brandon/Desktop/Projects/COREPANEL/docs/KEY_COMPROMISE_RESPONSE.md)
- Keep encrypted backups and verify restore capability:
  - [docs/BACKUP_RESTORE.md](/Users/brandon/Desktop/Projects/COREPANEL/docs/BACKUP_RESTORE.md)
- Enforce backup operations gate before go-live:
  - daily encrypted backup automation is active (`daily_backup_maintenance.sh` + cron)
  - retention set to 7-30 days
  - checksum and decrypt/decompress integrity verification enabled
  - at least one restore drill completed and logged

## 7) Multi-tenant isolation verification

- Run tenant isolation test before production deploy:
  - `./scripts/security/test_tenant_isolation.sh https://your-domain.example`
- Test coverage includes:
  - URL ID tampering on project view/edit routes.
  - Body ID tampering on project task delete endpoint.
  - Cross-tenant file download key tampering on `/file.php`.

## 8) Security regression smoke suite

- Run the combined minimum regression suite:
  - `./scripts/security/run_security_regression.sh https://your-domain.example`
- Includes checks for:
  - CSRF failure on missing token
  - RBAC denial for forbidden admin action
  - Rate-limit lock behavior
  - IDOR protection for project/file access
  - Upload MIME/magic-byte rejection

## 9) Web exposure + filesystem permission gate

- Verify sensitive paths are not directly web-accessible:
  - `./scripts/security/check_web_exposure.sh https://your-domain.example`
- This check includes:
  - direct `/uploads` access blocked
  - `.env` and config secrets not web-readable
  - debug/dev endpoints not reachable (`phpinfo`, `debug`, `test`, `csp_report`)
  - unknown route probe not returning `2xx`
- Verify strict filesystem permissions:
  - `./scripts/security/check_permissions.sh`
  - optional auto-remediation: `./scripts/security/check_permissions.sh --fix`
