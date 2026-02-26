# Authorization Rules

This document defines the server-side authorization model used by CorePanel.

## RBAC Model

CorePanel uses role-based access control (RBAC) with permissions:

- Tables:
  - `roles`
  - `permissions`
  - `role_permissions`
  - `user_roles`
- Legacy `users.role` remains for backward compatibility and is synchronized to RBAC:
  - `admin` legacy role maps to RBAC role `admin`
  - `user` legacy role maps to RBAC role `client`

## Permission Enforcement Primitives

Authorization helpers in `src/auth.php`:

- `require_login()`
- `current_user(PDO $pdo)`
  - Returns `roles`, `permissions`, and `tenant_id` with user identity.
- `require_permission(PDO $pdo, string $permission)`
- `require_any_permission(PDO $pdo, array $permissions)`
- `user_has_permission(array $actor, string $permission)`

## Object-Level Authorization

Object-level access checks are enforced for project resources:

- `require_project_access(PDO $pdo, array $actor, int $projectId, string $action)`
  - Validates the project exists.
  - Validates tenant boundary (`project.tenant_id === actor.tenant_id`).
  - Enforces action permissions with `any` vs `own` semantics.

Used for:

- Project view/edit/print
- Project task edit/delete
- Client project detail view

## Tenant Isolation

CorePanel is tenant-aware:

- Tenant table: `tenants`
- Tenant scope columns:
  - `users.tenant_id`
  - `projects.tenant_id`
  - `items.tenant_id`
  - `project_tasks.tenant_id`
  - `project_images.tenant_id`
- Admin/user/project queries are tenant-scoped.
- User-targeted admin actions validate target user tenant.
- File-serving route (`/file.php`) enforces tenant-scoped ownership checks.

Isolation verification:
- `scripts/security/test_tenant_isolation.sh` runs URL/body tampering checks across two tenants and validates file download isolation.

## Route Access Pattern

- Public pages:
  - `/login.php`
  - `/forgot_password.php`
  - `/reset_password.php`
  - `/register.php` (registration disabled; informational page only)
- Role/permission routing:
  - `/dashboard.php` routes by permission (`dashboard.admin.view` vs client dashboard permission).
- Admin area:
  - `/admin/*` endpoints enforce specific permissions, not only `admin/user`.
- Client area:
  - `/client/*` endpoints enforce client/project own-data permissions.

## State-Changing Requests

All state-changing POST handlers require CSRF validation:

- Hidden input: `csrf_token()`
- Server check: `csrf_verify(...)`

## Account Provisioning

- Self-registration is disabled on `/register.php`.
- New client users are created only through admin user-management flows.
