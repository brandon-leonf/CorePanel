# Encryption Key Versioning and Rotation

This runbook defines how CorePanel manages `COREPANEL_FIELD_KEY` material with key versioning.

## 1) Key versioning model

- Active keyring env: `COREPANEL_FIELD_KEYS`
- Active key selector: `COREPANEL_FIELD_KEY_ACTIVE_ID`
- Entry format: `key_id=key_material` (comma/semicolon/newline separated)
- `key_id` format: letters, numbers, `.`, `_`, `-` (max 40 chars)
- Supported key material formats:
  - 64-char hex
  - `base64:<...>` (decoded length >= 32 bytes)
  - raw 32+ char string

Example:

```env
COREPANEL_FIELD_KEYS=k2026q1=base64:...,k2025q3=base64:...
COREPANEL_FIELD_KEY_ACTIVE_ID=k2026q1
```

Behavior:
- New writes use the active key id.
- Existing encrypted values remain readable while old key ids stay in `COREPANEL_FIELD_KEYS`.
- Legacy single-key env (`COREPANEL_FIELD_KEY`) is still supported for backward compatibility.

## 2) Rotation triggers

- Scheduled rotation: every 12 months.
- Emergency rotation: immediately on suspected compromise/leak.
- Personnel/infra trigger: secret store migration or privileged operator change.

## 3) Planned rotation process

1. Create new key version in secret manager (for example `k2026q2`).
2. Add it to `COREPANEL_FIELD_KEYS`; keep previous key id(s) present.
3. Set `COREPANEL_FIELD_KEY_ACTIVE_ID` to the new key id.
4. Deploy config to all app instances.
5. Re-encrypt sensitive data by running a maintenance update pass (read + write) for:
   - `users.phone`, `users.address`, `users.notes`, `users.totp_secret`
   - `projects.notes`, `projects.project_address`
6. Validate app behavior (login, admin 2FA, profile/project reads and updates).
7. Run backup + restore drill and confirm success.
8. Remove old key id(s) from keyring only after re-encryption and verification are complete.

## 4) Post-rotation validation checklist

- No decrypt failures in logs for encrypted fields.
- Admin 2FA still works.
- Sensitive reads/writes work on both admin and client paths.
- Backup/restore drill evidence captured in:
  - `docs/backup_restore_test.log`
- Change ticket includes operator, date, old/new key ids, and validation evidence.

## 5) Rollback

- If decrypt failures occur after activation:
  1. Restore previous `COREPANEL_FIELD_KEY_ACTIVE_ID`.
  2. Keep both key ids in `COREPANEL_FIELD_KEYS`.
  3. Re-run validation checks.
  4. Investigate scope before retrying rotation.

## 6) Compromise response

Use the incident procedure in:
- [docs/KEY_COMPROMISE_RESPONSE.md](/Users/brandon/Desktop/Projects/COREPANEL/docs/KEY_COMPROMISE_RESPONSE.md)
