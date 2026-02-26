# COREPANEL

## Local run

- Use this command for local dev server with upload limits needed for PDFs:
  - `./scripts/start_local_server.sh`
- Staging deploy + smoke:
  - `./scripts/security/deploy_staging.sh --target deploy@staging-host:/var/www/corepanel --base-url https://staging.example.com`

## Security Documentation

- Authorization rules: [docs/AUTHORIZATION.md](docs/AUTHORIZATION.md)
- Audit trail coverage: [docs/AUDIT_TRAIL.md](docs/AUDIT_TRAIL.md)
- Monitoring and alerting: [docs/MONITORING.md](docs/MONITORING.md)
- Database safety controls: [docs/DB_SECURITY.md](docs/DB_SECURITY.md)
- Data protection controls: [docs/DATA_PROTECTION.md](docs/DATA_PROTECTION.md)
- Key rotation plan: [docs/KEY_ROTATION.md](docs/KEY_ROTATION.md)
- Key compromise response: [docs/KEY_COMPROMISE_RESPONSE.md](docs/KEY_COMPROMISE_RESPONSE.md)
- Backup/restore controls: [docs/BACKUP_RESTORE.md](docs/BACKUP_RESTORE.md)
- Secrets hygiene: [docs/SECRETS_HYGIENE.md](docs/SECRETS_HYGIENE.md)
- Deployment hygiene: [docs/DEPLOYMENT_HYGIENE.md](docs/DEPLOYMENT_HYGIENE.md)
- Versioned releases + rollback: [docs/RELEASES.md](docs/RELEASES.md)
- Security regression checks: [docs/SECURITY_REGRESSION.md](docs/SECURITY_REGRESSION.md)
- Security env template: [config/security.env.example](config/security.env.example)
