# Monitoring and Security Alerting

This document defines centralized security logging and alert behavior in CorePanel.

## Centralized Security Log

CorePanel writes security and monitoring events to both:

- Database table: `security_event_logs`
- Append-only file: `storage/logs/security.log` (or `COREPANEL_SECURITY_LOG_FILE`)

Primary writer: `rl_log_event(...)` in `src/rate_limit.php`.

Event sources now include:

- Rate limiting and brute-force events
- Login failures and lockouts
- Admin audit actions (mirrored as `admin_activity`)
- Export/download activity (`project_export_print`, `private_file_download`)
- Security alerts (`security_alert`)

## Tamper-Evident Protection

Each `security_event_logs` row includes hash-chain fields:

- `prev_hash`
- `event_hash`

`event_hash` is computed as an HMAC-SHA256 over event payload + previous hash, keyed by:

- `COREPANEL_LOG_CHAIN_KEY` (preferred)
- fallback: `COREPANEL_FIELD_KEY`

The file log also stores these chain fields, enabling cross-checking between DB and file stream.

## Alert Rules

Alerts are written as `event_type = security_alert` with `level = warning|critical`.

### 1) Repeated failed logins

Trigger source: rate-limit thresholds in `rl_register_attempt(...)` for action `login`.

- Warning/critical security alerts are emitted when lock thresholds are reached.

### 2) Privilege changes

Trigger source: admin role mutation actions (`promote_user`, `demote_user`).

- Emitted as `action = privilege_change`.

### 3) Export/download spikes

Trigger source:

- `/public/admin/projects/print.php` -> `project_export_print`
- `/public/file.php` -> `private_file_download`

Alert action: `export_download_spike`

- Default threshold: 25 events / 600 seconds per user/action.

### 4) Admin actions outside normal patterns

Trigger source: admin audit actions mirrored into monitoring via `admin_audit_log(...)`.

Alerts include:

- `admin_off_hours_action` (outside configured window)
- `admin_action_spike` (burst activity)
- `admin_new_ip_pattern` (new IP pattern after prior baseline)

## Adaptive CAPTCHA

Auth endpoints use adaptive CAPTCHA (simple math challenge) only after suspicious behavior:

- Login (`login`)
- Forgot password (`forgot_password`)
- Reset password (`reset_password`)

Suspicious behavior is determined from existing rate-limit state (`max_attempts` / lock state), so normal users are not challenged by default.

## Config Knobs

See `config/security.env.example`:

- `COREPANEL_LOG_CHAIN_KEY`
- `COREPANEL_SECURITY_LOG_FILE`
- `COREPANEL_DOWNLOAD_SPIKE_WINDOW_SECONDS`
- `COREPANEL_DOWNLOAD_SPIKE_THRESHOLD`
- `COREPANEL_ADMIN_HOURS_START`
- `COREPANEL_ADMIN_HOURS_END`
- `COREPANEL_ADMIN_BURST_WINDOW_SECONDS`
- `COREPANEL_ADMIN_BURST_THRESHOLD`
- `COREPANEL_ADMIN_NEW_IP_LOOKBACK_DAYS`

## Admin Visibility

`/public/admin/security.php` now includes a "Recent Security Alerts" section from `security_event_logs`.

## Health Endpoints (Protected)

CorePanel exposes two JSON health endpoints:

- `/health`
- `/health/db`

Both endpoints are protected and require either:

- Admin session (`dashboard.admin.view`/`security.manage`), or
- `COREPANEL_HEALTH_TOKEN` via:
  - `X-Health-Token` header,
  - `Authorization: Bearer <token>`, or
  - `?token=<token>` query parameter.

`/health` checks:

- DB connectivity/query (`SELECT 1`)
- Disk free space threshold
- Encryption key readiness (`COREPANEL_FIELD_KEY(S)`)
- Queue health (configurable mode)

`/health/db` checks DB only.

Status codes:

- `200` when all required checks pass
- `503` when at least one check fails
- `403` when unauthorized

Config knobs in `config/security.env.example`:

- `COREPANEL_HEALTH_TOKEN`
- `COREPANEL_HEALTH_DISK_PATH`
- `COREPANEL_HEALTH_MIN_DISK_FREE_MB`
- `COREPANEL_HEALTH_QUEUE_MODE` (`none|db|filesystem`)
- `COREPANEL_HEALTH_QUEUE_TABLE`
- `COREPANEL_HEALTH_QUEUE_DIR`
- `COREPANEL_HEALTH_QUEUE_HEARTBEAT_FILE`
- `COREPANEL_HEALTH_QUEUE_HEARTBEAT_MAX_AGE_SECONDS`
