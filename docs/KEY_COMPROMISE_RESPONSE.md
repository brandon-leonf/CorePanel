# Encryption Key Compromise Response Plan

This playbook is for suspected or confirmed compromise of field encryption keys.

## 1) Immediate containment (first hour)

1. Declare incident and assign incident commander.
2. Freeze deployments and privileged config changes.
3. Rotate `COREPANEL_FIELD_KEY_ACTIVE_ID` to a newly generated key id.
4. Keep compromised key id only as temporary decrypt fallback while recovery runs.
5. Restrict access to secrets manager and rotate operator credentials used for key access.

## 2) Scope assessment

- Identify affected key id(s), environments, and time window.
- Review logs for:
  - unusual admin actions
  - suspicious export/download spikes
  - anomalous auth activity
- Determine whether DB snapshots/backups may also be exposed.

## 3) Recovery actions

1. Generate replacement key id and update `COREPANEL_FIELD_KEYS`.
2. Re-encrypt all sensitive fields under the new active key id.
3. Rotate related secrets if applicable:
   - backup passphrase
   - log chain key
   - TOTP secrets (for high-risk admin accounts)
4. Run security regression checks and backup/restore drill.

## 4) Key retirement

- Remove compromised key id from `COREPANEL_FIELD_KEYS` only after:
  - re-encryption completion
  - application validation
  - restore drill confirmation
- Confirm old compromised key material is deleted from runtime and secret manager history/versions where policy allows.

## 5) Communication and audit

- Record timeline, impacted data classes, and remediation evidence.
- Notify stakeholders according to internal/legal requirements.
- Add follow-up tasks (hardening gaps, monitoring improvements, runbook updates).

## 6) Exit criteria

- New key id fully active and validated.
- Compromised key removed from runtime keyring.
- No decrypt failures or incident indicators in monitoring.
- Incident postmortem completed with preventive actions assigned.
