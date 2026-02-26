<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../../config/db.php';
require __DIR__ . '/../../../src/auth.php';
require __DIR__ . '/../../../src/helpers.php';
require __DIR__ . '/../../../src/security.php';
require __DIR__ . '/../../../src/admin_audit.php';

$me = require_any_permission($pdo, ['projects.delete.any', 'projects.delete.own']);
$tenantId = actor_tenant_id($me);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit;
}

if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
  http_response_code(403);
  exit('Invalid CSRF token');
}

$projectId = (int)($_POST['id'] ?? 0);
if ($projectId <= 0) {
  redirect('/admin/projects/index.php');
}

$returnToRaw = trim((string)($_POST['return_to'] ?? ''));
$returnTo = '/admin/projects/index.php';
if ($returnToRaw !== '' && str_starts_with($returnToRaw, '/admin/') && !str_contains($returnToRaw, "\n") && !str_contains($returnToRaw, "\r")) {
  $returnTo = $returnToRaw;
}

$projectStmt = $pdo->prepare(
  "SELECT p.id, p.user_id, p.project_no, p.title, u.deleted_at AS client_deleted_at
   FROM projects p
   LEFT JOIN users u ON u.id = p.user_id
   WHERE p.id = ?
     AND p.tenant_id = ?
     AND p.deleted_at IS NOT NULL
   LIMIT 1"
);
$projectStmt->execute([$projectId, $tenantId]);
$project = $projectStmt->fetch();
if (!$project) {
  http_response_code(404);
  exit('Project not found');
}
if (trim((string)($project['client_deleted_at'] ?? '')) !== '') {
  http_response_code(400);
  exit('Restore the client account before restoring this project.');
}

$canRestoreAny = user_has_permission($me, 'projects.delete.any');
$canRestoreOwn = user_has_permission($me, 'projects.delete.own') && (int)$project['user_id'] === (int)$me['id'];
if (!$canRestoreAny && !$canRestoreOwn) {
  http_response_code(403);
  exit('Forbidden');
}

$pdo->beginTransaction();
try {
  if (security_table_exists($pdo, 'project_payments')) {
    $restorePayments = $pdo->prepare(
      "UPDATE project_payments
       SET deleted_at = NULL
       WHERE project_id = ?
         AND tenant_id = ?
         AND deleted_at IS NOT NULL"
    );
    $restorePayments->execute([$projectId, $tenantId]);
  }

  if (security_table_exists($pdo, 'project_images')) {
    $restoreFiles = $pdo->prepare(
      "UPDATE project_images
       SET deleted_at = NULL
       WHERE project_id = ?
         AND tenant_id = ?
         AND deleted_at IS NOT NULL"
    );
    $restoreFiles->execute([$projectId, $tenantId]);
  }

  $restoreProject = $pdo->prepare(
    "UPDATE projects
     SET deleted_at = NULL
     WHERE id = ?
       AND tenant_id = ?
       AND deleted_at IS NOT NULL"
  );
  $restoreProject->execute([$projectId, $tenantId]);

  $projectNo = trim((string)($project['project_no'] ?? ''));
  $projectTitle = trim((string)($project['title'] ?? ''));
  $summary = 'Restored project';
  if ($projectNo !== '' || $projectTitle !== '') {
    $summary .= ' ' . trim($projectNo . ' ' . $projectTitle);
  }
  admin_audit_log(
    $pdo,
    (int)$me['id'],
    'restore_project',
    (int)$project['user_id'],
    $summary,
    $tenantId
  );

  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  throw $e;
}

redirect($returnTo);
