# Backup and Restore Controls

## Requirements

- Backups must be encrypted at rest.
- Backups must run automatically at least once per day.
- Backup retention must be between 7 and 30 days.
- Backup integrity must be verified (checksum + decrypt/decompress check).
- Backup files must be access-controlled (`0700` directory, `0600` files).
- Restore capability must be tested at least once before production launch.

## Encrypted backup workflow

Scripts:
- [scripts/security/backup_encrypted.sh](/Users/brandon/Desktop/Projects/COREPANEL/scripts/security/backup_encrypted.sh)
- [scripts/security/backup_uploads_encrypted.sh](/Users/brandon/Desktop/Projects/COREPANEL/scripts/security/backup_uploads_encrypted.sh)
- [scripts/security/restore_encrypted_backup.sh](/Users/brandon/Desktop/Projects/COREPANEL/scripts/security/restore_encrypted_backup.sh)
- [scripts/security/run_backup_restore_drill.sh](/Users/brandon/Desktop/Projects/COREPANEL/scripts/security/run_backup_restore_drill.sh)
- [scripts/security/daily_backup_maintenance.sh](/Users/brandon/Desktop/Projects/COREPANEL/scripts/security/daily_backup_maintenance.sh)
- [scripts/security/sync_offsite_backups.sh](/Users/brandon/Desktop/Projects/COREPANEL/scripts/security/sync_offsite_backups.sh)

Required secrets (env):
- `COREPANEL_BACKUP_PASSPHRASE`
- DB password env var selected via script options (default: `COREPANEL_BACKUP_DB_PASSWORD` for backup, `COREPANEL_RESTORE_DB_PASSWORD` for restore)

Optional DB endpoint env:
- `COREPANEL_BACKUP_DB_HOST`, `COREPANEL_BACKUP_DB_PORT`
- `COREPANEL_RESTORE_DB_HOST`, `COREPANEL_RESTORE_DB_PORT`

Backup maintenance env:
- `COREPANEL_BACKUP_RETENTION_DAYS` (default `14`, allowed `7-30`)
- `COREPANEL_BACKUP_LOG_FILE` (default `storage/logs/backup_maintenance.log`)
- `COREPANEL_BACKUP_ENV_FILE` (optional env file path for cron jobs)
- `COREPANEL_UPLOADS_BACKUP_SOURCE_DIR` (default `storage/uploads`)
- `COREPANEL_UPLOADS_BACKUP_LABEL` (default `uploads`)

Offsite sync env:
- `COREPANEL_BACKUP_OFFSITE_MODE` (`none`, `rclone`, `aws-s3`, `rsync`)
- `COREPANEL_BACKUP_OFFSITE_RCLONE_REMOTE`
- `COREPANEL_BACKUP_OFFSITE_S3_URI`
- `COREPANEL_BACKUP_OFFSITE_S3_STORAGE_CLASS` (optional)
- `COREPANEL_BACKUP_OFFSITE_RSYNC_DEST`
- `COREPANEL_BACKUP_OFFSITE_RSYNC_RSH` (optional)

## Quick commands

Create encrypted backup:

```bash
COREPANEL_BACKUP_PASSPHRASE='...' \
COREPANEL_BACKUP_DB_PASSWORD='...' \
./scripts/security/backup_encrypted.sh --database corepanel
```

Create encrypted uploads backup:

```bash
COREPANEL_BACKUP_PASSPHRASE='...' \
./scripts/security/backup_uploads_encrypted.sh \
  --source-dir /path/to/COREPANEL/storage/uploads \
  --label uploads
```

Verify backup can be decrypted/decompressed:

```bash
COREPANEL_BACKUP_PASSPHRASE='...' \
./scripts/security/restore_encrypted_backup.sh \
  --file storage/backups/<backup.sql.gz.enc> \
  --verify-only
```

Note:
- `restore_encrypted_backup.sh` verifies the backup `.sha256` checksum before verify/restore (unless `--skip-checksum` is explicitly used).

Restore into a test database:

```bash
COREPANEL_BACKUP_PASSPHRASE='...' \
COREPANEL_RESTORE_DB_PASSWORD='...' \
./scripts/security/restore_encrypted_backup.sh \
  --file storage/backups/<backup.sql.gz.enc> \
  --database corepanel_restore_test
```

Run a full drill and append evidence log:

```bash
COREPANEL_BACKUP_PASSPHRASE='...' \
COREPANEL_BACKUP_DB_PASSWORD='...' \
COREPANEL_RESTORE_DB_PASSWORD='...' \
./scripts/security/run_backup_restore_drill.sh \
  --database corepanel \
  --restore-db corepanel_restore_test
```

## Daily automation + retention

Run daily encrypted DB + uploads backup, checksum/decrypt verification, retention pruning, and optional offsite sync:

```bash
COREPANEL_BACKUP_PASSPHRASE='...' \
COREPANEL_BACKUP_DB_PASSWORD='...' \
./scripts/security/daily_backup_maintenance.sh \
  --database corepanel \
  --retention-days 14
```

Example cron (UTC 02:15 daily) with offsite mode from env file:

```cron
15 2 * * * cd /path/to/COREPANEL && /bin/bash ./scripts/security/daily_backup_maintenance.sh --database corepanel --retention-days 14 --env-file /path/to/COREPANEL/config/security.env >> /path/to/COREPANEL/storage/logs/cron_backup.log 2>&1
```

Example offsite-only dry run:

```bash
COREPANEL_BACKUP_OFFSITE_MODE=aws-s3 \
COREPANEL_BACKUP_OFFSITE_S3_URI=s3://my-secure-bucket/corepanel-backups \
./scripts/security/sync_offsite_backups.sh --source-dir storage/backups --dry-run
```

Retention policy:
- Keep between 7 and 30 days.
- Default is 14 days.
- Older DB artifacts (`*.sql.gz.enc`, `.sha256`) and uploads artifacts (`*.uploads.tar.gz.enc`, `.sha256`) are automatically removed by the maintenance script.

## Evidence log

Append drill outputs to:
- `docs/backup_restore_test.log`

Current status:
- Initial drill executed on **2026-02-26 (UTC)** and recorded in `docs/backup_restore_test.log`.

Required cadence:
- Run full restore drill at least monthly and after major infra/database changes.

Minimum fields to track:
- Date/time (UTC)
- Operator
- Backup filename
- Verify-only result
- Full restore result
- Follow-up issues
