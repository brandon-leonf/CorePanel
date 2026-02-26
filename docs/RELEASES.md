# Versioned Releases and Rollback

CorePanel supports versioned release directories with atomic symlink switching:

- `releases/<version>/...`
- `current -> releases/<version>`
- `previous -> releases/<old-version>`

This enables fast rollback by updating symlinks instead of re-syncing full source.

## Scripts

- [scripts/security/package_release.sh](../scripts/security/package_release.sh)
- [scripts/security/deploy_versioned_release.sh](../scripts/security/deploy_versioned_release.sh)
- [scripts/security/rollback_versioned_release.sh](../scripts/security/rollback_versioned_release.sh)

## 1) Build a versioned artifact (optional)

```bash
./scripts/security/package_release.sh --version v2026.02.26.1
```

Output:

- `storage/releases/artifacts/v2026.02.26.1.tar.gz`
- `storage/releases/artifacts/v2026.02.26.1.tar.gz.sha256`

## 2) Deploy a versioned release

By default this deploys from the local source tree (`--source-dir`), then switches the `current` symlink atomically.

Local target:

```bash
./scripts/security/deploy_versioned_release.sh \
  --target /var/www/corepanel-staging \
  --version v2026.02.26.1 \
  --base-url https://staging.example.com
```

Remote target:

```bash
./scripts/security/deploy_versioned_release.sh \
  --target deploy@staging-host:/var/www/corepanel \
  --version v2026.02.26.1 \
  --base-url https://staging.example.com \
  --health-token "$COREPANEL_HEALTH_TOKEN"
```

What it does:

- syncs source into `releases/<version>`
- links `releases/<version>/storage -> ../../shared/storage`
- updates `previous` from old `current`
- points `current` to the new release
- optionally runs smoke tests

## 3) Roll back quickly

Roll back to `previous`:

```bash
./scripts/security/rollback_versioned_release.sh \
  --target deploy@staging-host:/var/www/corepanel \
  --base-url https://staging.example.com \
  --health-token "$COREPANEL_HEALTH_TOKEN"
```

Roll back to a specific version:

```bash
./scripts/security/rollback_versioned_release.sh \
  --target deploy@staging-host:/var/www/corepanel \
  --to-version v2026.02.25.3 \
  --base-url https://staging.example.com
```

## Notes

- Use the same smoke suite as staging deployment for post-deploy and post-rollback validation.
- Keep `shared/storage` outside release directories to avoid data loss between versions.
- Web server document root should point to `current/public`.
