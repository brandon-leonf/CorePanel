# Data Protection

This document describes transport and data-at-rest protection in CorePanel.

## 1) TLS Everywhere

Application-level controls:

- HTTPS redirect (required in production):
  - `COREPANEL_ENFORCE_HTTPS=1` (default behavior for non-localhost hosts)
  - Behavior: insecure HTTP requests are redirected to HTTPS (`308`).
- Authenticated routes enforce HTTPS:
  - Any route using `require_login()` will never serve content over HTTP.
  - If redirect is not possible, request is rejected with `403 HTTPS required`.
- Proxy-aware HTTPS detection:
  - `COREPANEL_TRUST_PROXY_HEADERS=1`
  - `COREPANEL_TRUSTED_PROXIES=<comma-separated IP/CIDR list>`
  - Forwarded scheme headers are ignored unless request source matches trusted proxy list.
- HSTS support:
  - `COREPANEL_HSTS_ENABLED=0` by default (enable only after HTTPS validation)
  - `COREPANEL_HSTS_MAX_AGE=31536000`
  - `COREPANEL_HSTS_INCLUDE_SUBDOMAINS=1`
  - `COREPANEL_HSTS_PRELOAD=0` (set only when preload requirements are satisfied)

Notes:

- In local development over plain `http://localhost`, keep `COREPANEL_ALLOW_INSECURE_LOCALHOST=1`.
- TLS termination should still be enforced at load balancer/reverse proxy/web server.

## 2) Encryption At Rest (Sensitive Fields)

CorePanel supports encrypted-at-rest storage for sensitive text fields when a field key is configured.

Encrypted field groups include:

- Client profile data:
  - `users.phone`
  - `users.address`
  - `users.notes`
- Project-sensitive free text:
  - `projects.notes`
  - `projects.project_address`
- Admin 2FA secret:
  - `users.totp_secret`

Behavior:

- When encryption key material is available, sensitive fields are encrypted before DB write.
- Reads transparently decrypt data for authorized display.
- Backward compatibility is maintained for previously plaintext values.

## 3) Key Management

Primary key inputs (preferred):

- `COREPANEL_FIELD_KEYS`
- `COREPANEL_FIELD_KEY_ACTIVE_ID`

Legacy fallback:

- `COREPANEL_FIELD_KEY`

Accepted formats:

- 64-char hex key
- `base64:<...>` (decoded length at least 32 bytes)
- raw string of length 32+ (internally derived)

Key protection requirements:

- Never commit keys in source control.
- Use environment variables from secure deployment configuration.
- Prefer a dedicated secrets manager (for example: cloud secret store, vault, container secrets).
- Restrict runtime process and CI access to least-privilege.
- Rotate keys with a migration plan when required.

Rotation runbook:
- [docs/KEY_ROTATION.md](/Users/brandon/Desktop/Projects/COREPANEL/docs/KEY_ROTATION.md)

Compromise response runbook:
- [docs/KEY_COMPROMISE_RESPONSE.md](/Users/brandon/Desktop/Projects/COREPANEL/docs/KEY_COMPROMISE_RESPONSE.md)

## 4) Operational Guidance

- Ensure DB columns used for encrypted text are `TEXT`-capable to avoid truncation.
- Keep backups encrypted and access-controlled.
- Test restore at least once and keep evidence logs.
- If introducing payment metadata fields, treat them as sensitive by default and apply the same encryption helpers.

Backup/restore runbook:
- [docs/BACKUP_RESTORE.md](/Users/brandon/Desktop/Projects/COREPANEL/docs/BACKUP_RESTORE.md)

## 5) Error Leakage Prevention

Runtime error handling is configured to avoid leaking stack traces or exception details in production:

- `COREPANEL_DEBUG=0` (default behavior) disables error display.
- Uncaught exceptions/fatal errors are logged server-side and return a generic message.
- DB connection failures return generic responses unless debug mode is explicitly enabled.
