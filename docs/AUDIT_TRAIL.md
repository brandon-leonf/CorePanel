# Audit Trail Coverage

This document records audit-trail behavior for state-changing actions in CorePanel.

## Purpose

- Define where action history is stored.
- Document which endpoints/actions are currently logged.
- Make coverage gaps explicit so new work can be tracked and reviewed.

## Primary Audit Store

- Table: `admin_user_audit_logs`
- Writer: `admin_audit_log(...)` in `src/admin_audit.php`
- Reader: `admin_audit_recent(...)` in `src/admin_audit.php`
- UI: `public/admin/users/index.php` ("Audit Trail" section)

Stored fields:

- `actor_user_id`
- `target_user_id` (nullable)
- `action`
- `summary`
- `ip_address`
- `created_at`

## Event Catalog

Currently emitted `action` values:

- `create_user`
- `delete_user`
- `promote_user`
- `demote_user`

## State-Changing Endpoint Matrix

### Authentication and Account Recovery

- `POST /login.php`
  - State change: create authenticated session.
  - Logged in `admin_user_audit_logs`: No.
- `POST /logout.php`
  - State change: destroy authenticated session.
  - Logged in `admin_user_audit_logs`: No.
- `POST /forgot_password.php` (implemented via `src/forgot_password.php`)
  - State change: create password reset record.
  - Logged in `admin_user_audit_logs`: No.
- `POST /reset_password.php`
  - State change: update user password, mark reset token used.
  - Logged in `admin_user_audit_logs`: No.

### Client Item Actions

- `POST /items/create.php`
  - State change: create item.
  - Logged in `admin_user_audit_logs`: No.
- `POST /items/edit.php`
  - State change: update item.
  - Logged in `admin_user_audit_logs`: No.
- `POST /items/delete.php`
  - State change: delete item.
  - Logged in `admin_user_audit_logs`: No.

### Admin User Actions

- `POST /admin/users/create.php`
  - State change: create user (role `user`).
  - Logged in `admin_user_audit_logs`: Yes (`create_user`).
- `POST /admin/users/role.php`
  - State change: promote/demote user role.
  - Logged in `admin_user_audit_logs`: Yes (`promote_user` / `demote_user`).
- `POST /admin/users/delete.php`
  - State change: delete user (+ dependent records).
  - Logged in `admin_user_audit_logs`: Yes (`delete_user`).
- `POST /admin/users/edit.php` with `action=update_user`
  - State change: update user profile fields.
  - Logged in `admin_user_audit_logs`: No.
- `POST /admin/users/edit.php` with `action=add_project`
  - State change: create project for user.
  - Logged in `admin_user_audit_logs`: No.

### Admin Project Actions

- `POST /admin/projects/create.php`
  - State change: create project.
  - Logged in `admin_user_audit_logs`: No.
- `POST /admin/projects/edit.php` with `action=update_project`
  - State change: update project metadata.
  - Logged in `admin_user_audit_logs`: No.
- `POST /admin/projects/edit.php` with `action=add_task`
  - State change: add project task.
  - Logged in `admin_user_audit_logs`: No.
- `POST /admin/projects/task_edit.php`
  - State change: update project task.
  - Logged in `admin_user_audit_logs`: No.
- `POST /admin/projects/task_delete.php`
  - State change: delete project task.
  - Logged in `admin_user_audit_logs`: No.
- `POST /admin/projects/edit.php` with `action=add_project_images`
  - State change: add project images.
  - Logged in `admin_user_audit_logs`: No.
- `POST /admin/projects/edit.php` with `action=delete_project_image`
  - State change: delete project image.
  - Logged in `admin_user_audit_logs`: No.

## Notes on Scope

- The current audit table is admin-focused (`admin_user_audit_logs`).
- Client self-service actions are not currently recorded in this table.
- This document is the required source of truth for current coverage.

## Recommended Expansion Plan

- Add admin audit events for project and task mutations.
- Add audit event for admin user profile edits.
- Consider a separate broader table (for client actions) if full system activity tracing is required.
