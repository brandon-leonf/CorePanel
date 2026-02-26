<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../../config/db.php';
require __DIR__ . '/../../../src/auth.php';
require __DIR__ . '/../../../src/helpers.php';
require __DIR__ . '/../../../src/security.php';
require __DIR__ . '/../../../src/admin_audit.php';

$me = require_permission($pdo, 'users.delete');
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
if ($id <= 0) {
  redirect('/admin/users/index.php');
}

$targetStmt = $pdo->prepare(
  "SELECT id, email, role
   FROM users
   WHERE id = ?
     AND tenant_id = ?
     AND role = 'user'
     AND deleted_at IS NOT NULL
   LIMIT 1"
);
$targetStmt->execute([$id, $tenantId]);
$targetUser = $targetStmt->fetch();
if (!$targetUser) {
  http_response_code(404);
  exit('User not found');
}

$pdo->beginTransaction();
try {
  $restoreUser = $pdo->prepare(
    "UPDATE users
     SET deleted_at = NULL
     WHERE id = ?
       AND tenant_id = ?
       AND deleted_at IS NOT NULL"
  );
  $restoreUser->execute([$id, $tenantId]);

  if (security_table_exists($pdo, 'projects')) {
    $restoreProjects = $pdo->prepare(
      "UPDATE projects
       SET deleted_at = NULL
       WHERE user_id = ?
         AND tenant_id = ?
         AND deleted_at IS NOT NULL"
    );
    $restoreProjects->execute([$id, $tenantId]);
  }

  if (security_table_exists($pdo, 'project_payments') && security_table_exists($pdo, 'projects')) {
    $restorePayments = $pdo->prepare(
      "UPDATE project_payments pp
       JOIN projects p ON p.id = pp.project_id
       SET pp.deleted_at = NULL
       WHERE p.user_id = ?
         AND p.tenant_id = ?
         AND pp.tenant_id = ?
         AND pp.deleted_at IS NOT NULL"
    );
    $restorePayments->execute([$id, $tenantId, $tenantId]);
  }

  if (security_table_exists($pdo, 'project_images') && security_table_exists($pdo, 'projects')) {
    $restoreFiles = $pdo->prepare(
      "UPDATE project_images pi
       JOIN projects p ON p.id = pi.project_id
       SET pi.deleted_at = NULL
       WHERE p.user_id = ?
         AND p.tenant_id = ?
         AND pi.tenant_id = ?
         AND pi.deleted_at IS NOT NULL"
    );
    $restoreFiles->execute([$id, $tenantId, $tenantId]);
  }

  admin_audit_log(
    $pdo,
    (int)$me['id'],
    'restore_user',
    $id,
    "Restored user {$targetUser['email']} ({$targetUser['role']})",
    $tenantId
  );

  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  throw $e;
}

redirect('/admin/users/index.php');
