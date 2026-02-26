# Database Security

This document defines database-safety controls used by CorePanel.

## 1) Prepared Statements Everywhere

- App queries use `PDO::prepare(...)` + bound parameters for all user-influenced values.
- Direct SQL string concatenation for request input is not used.
- Dynamic list values (for example limits/status choices) are normalized/allowlisted before binding.

## 2) Mass-Assignment Protection

- Server handlers map input to explicit fields.
- Writes are done with fixed column lists (no `$_POST`-driven column names).
- Project/user update flows only persist approved fields:
  - `users`: `name`, `email`, `phone`, `address`, `notes`
  - `projects`: `title`, `description`, `notes`, `project_address`, `status`

## 3) Least-Privilege DB Accounts

Use separate DB accounts for runtime and migrations.

- Runtime account: CRUD only for app tables.
- Migration account: schema change privileges.
- Backup account: read-only dump privileges (`SELECT`, `SHOW VIEW`, `TRIGGER`, `EVENT`, `LOCK TABLES`).
- Never run production web traffic with `root` or superuser accounts.
- Never use `GRANT ALL PRIVILEGES` for runtime accounts.

Example SQL is provided in:
- [sql/least_privilege.sql](/Users/brandon/Desktop/Projects/COREPANEL/sql/least_privilege.sql)

Least-privilege grant audit script:
- [scripts/security/check_db_least_privilege.sh](/Users/brandon/Desktop/Projects/COREPANEL/scripts/security/check_db_least_privilege.sh)

## 4) Sensitive Field Encryption

CorePanel encrypts sensitive fields at rest when key material is configured (`COREPANEL_FIELD_KEYS` + `COREPANEL_FIELD_KEY_ACTIVE_ID`, or legacy `COREPANEL_FIELD_KEY`).

- Cipher: `AES-256-GCM` via OpenSSL.
- Stored formats:
  - `enc:<...>` for legacy 2FA secret values.
  - `enc2:<key_id>:<...>` for key-versioned 2FA secret values.
  - `encv1:<...>` for context-bound sensitive text encryption.
  - `encv2:<key_id>:<...>` for key-versioned context-bound sensitive text encryption.
- Existing plaintext values remain readable for backward compatibility.

Current encrypted field coverage:

- `users.totp_secret`
- `users.phone`
- `users.address`
- `users.notes`
- `projects.notes`
- `projects.project_address`

### Required key configuration

Preferred:

- `COREPANEL_FIELD_KEYS=<key_id>=<key_material>,<older_key_id>=<key_material>`
- `COREPANEL_FIELD_KEY_ACTIVE_ID=<key_id>`

Legacy single-key mode:

- 64-char hex key
- `base64:<...>` (decoded value should be at least 32 bytes)
- raw string (32+ chars; derived to 32 bytes internally)

If no valid key material is configured, new 2FA setup is blocked to avoid storing new plaintext secrets.
