<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../../config/db.php';
require __DIR__ . '/../../../src/auth.php';
require __DIR__ . '/../../../src/helpers.php';
require __DIR__ . '/../../../src/admin_audit.php';

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
     WHERE role = ? AND tenant_id = ?"
  );
  $countAdminsStmt->execute(['admin', $tenantId]);
  $countAdmins = (int)$countAdminsStmt->fetchColumn();
  if ($countAdmins <= 1) {
    http_response_code(400);
    exit("Cannot demote the last admin.");
  }
  $newRole = 'user';
} else {
  $newRole = 'admin';
}

$stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ? AND tenant_id = ?");
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
