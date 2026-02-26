<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../../config/db.php';
require __DIR__ . '/../../../src/auth.php';
require __DIR__ . '/../../../src/helpers.php';
require __DIR__ . '/../../../src/admin_audit.php';
require __DIR__ . '/../../../src/security.php';
require __DIR__ . '/../../../src/totp.php';

function redirect_role_error(string $code): never {
  redirect('/admin/users/index.php?role_error=' . rawurlencode($code));
}

$me = require_permission($pdo, 'users.role.manage');
$tenantId = actor_tenant_id($me);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit;
}

if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
  http_response_code(403);
  exit('Invalid CSRF token');
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) redirect('/admin/users/index.php');

if ($me && (int)$me['id'] === $id) {
  http_response_code(400);
  exit("You can't change your own role.");
}

/** Always read the current role from DB (never trust POST) */
$targetUser = require_user_in_tenant($pdo, $me, $id);
$currentRoleDb = (string)($targetUser['role'] ?? 'user');

if ($currentRoleDb === 'admin') {
  $countAdminsStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM users
     WHERE role = ?
       AND tenant_id = ?
       AND deleted_at IS NULL"
  );
  $countAdminsStmt->execute(['admin', $tenantId]);
  $countAdmins = (int)$countAdminsStmt->fetchColumn();
  if ($countAdmins <= 1) {
    http_response_code(400);
    exit("Cannot demote the last admin.");
  }
  $newRole = 'user';
} else {
  $actorStmt = $pdo->prepare(
    "SELECT id, role, password_hash, totp_secret, twofa_enabled_at
     FROM users
     WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL
     LIMIT 1"
  );
  $actorStmt->execute([(int)$me['id'], $tenantId]);
  $actor = $actorStmt->fetch();
  if (!$actor || (string)($actor['role'] ?? 'user') !== 'admin') {
    redirect_role_error('reauth_unavailable');
  }

  $confirmPassword = (string)($_POST['confirm_password'] ?? '');
  $confirmPhrase = strtoupper(trim((string)($_POST['confirm_phrase'] ?? '')));
  if ($confirmPhrase !== 'MAKE ADMIN') {
    admin_audit_log(
      $pdo,
      (int)$me['id'],
      'promote_user_reauth_failed',
      $id,
      'Failed confirmation phrase before admin promotion',
      $tenantId
    );
    redirect_role_error('confirm_phrase');
  }

  if ($confirmPassword === '' || !password_verify($confirmPassword, (string)$actor['password_hash'])) {
    admin_audit_log(
      $pdo,
      (int)$me['id'],
      'promote_user_reauth_failed',
      $id,
      'Failed password re-authentication before admin promotion',
      $tenantId
    );
    redirect_role_error('reauth_failed');
  }

  $requiresTwofa = ensure_user_twofa_columns($pdo) && admin_twofa_enabled($actor);
  if ($requiresTwofa) {
    $totpSecret = totp_secret_resolve((string)($actor['totp_secret'] ?? ''));
    $confirmTotp = preg_replace('/\D+/', '', (string)($_POST['confirm_totp'] ?? '')) ?? '';
    if ($totpSecret === null || $confirmTotp === '' || !totp_verify_code($totpSecret, $confirmTotp, 1, 30, 6)) {
      admin_audit_log(
        $pdo,
        (int)$me['id'],
        'promote_user_reauth_failed',
        $id,
        'Failed 2FA re-authentication before admin promotion',
        $tenantId
      );
      redirect_role_error('reauth_failed');
    }
  }

  $newRole = 'admin';
}

$stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL");
$stmt->execute([$newRole, $id, $tenantId]);
sync_user_legacy_role_binding($pdo, $id, $newRole);

if ($me) {
  $action = $newRole === 'admin' ? 'promote_user' : 'demote_user';
  admin_audit_log(
    $pdo,
    (int)$me['id'],
    $action,
    $id,
    "Changed role to {$newRole}",
    $tenantId
  );
}

redirect('/admin/users/index.php');
