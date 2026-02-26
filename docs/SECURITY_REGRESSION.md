# Security Regression Checks

Run the minimum security regression suite:

```bash
./scripts/security/run_security_regression.sh http://localhost:8000
```

What it verifies:

- CSRF: state-changing POST fails when `csrf_token` is missing.
- RBAC: standard user cannot access admin-only endpoint.
- Rate limiting: repeated failed login attempts trigger lock message.
- IDOR: cross-tenant project/file access tampering is denied.
- Upload hardening: non-image payload with image extension/MIME is rejected.

Notes:

- The runner creates temporary fixtures and cleans them up automatically.
- It uses an isolated test client IP via `X-Forwarded-For` to avoid interference from existing rate-limit state.
