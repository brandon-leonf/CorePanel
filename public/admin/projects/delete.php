<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../../config/db.php';
require __DIR__ . '/../../../src/auth.php';
require __DIR__ . '/../../../src/helpers.php';
require __DIR__ . '/../../../src/security.php';
require __DIR__ . '/../../../src/upload.php';
require __DIR__ . '/../../../src/admin_audit.php';

$me = require_any_permission($pdo, ['projects.edit.any', 'projects.edit.own']);
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

$projectScope = require_project_access($pdo, $me, $projectId, 'edit');
$projectUserId = (int)($projectScope['user_id'] ?? 0);

$projectStmt = $pdo->prepare(
  "SELECT id, user_id, project_no, title
   FROM projects
   WHERE id = ? AND tenant_id = ?
   LIMIT 1"
);
$projectStmt->execute([$projectId, $tenantId]);
$project = $projectStmt->fetch();
if (!$project) {
  http_response_code(404);
  exit('Project not found');
}

$projectMediaPaths = [];
if (security_table_exists($pdo, 'project_images')) {
  $pathsStmt = $pdo->prepare(
    "SELECT image_path
     FROM project_images
     WHERE project_id = ?
       AND tenant_id = ?
       AND image_path IS NOT NULL
       AND image_path <> ''"
  );
  $pathsStmt->execute([$projectId, $tenantId]);
  $projectMediaPaths = array_values(
    array_unique(
      array_map(
        static fn(mixed $value): string => (string)$value,
        $pathsStmt->fetchAll(PDO::FETCH_COLUMN) ?: []
      )
    )
  );
}

$pdo->beginTransaction();
try {
  if (security_table_exists($pdo, 'project_payment_receipts') && security_table_exists($pdo, 'project_payments')) {
    $deleteReceipts = $pdo->prepare(
      "DELETE r
       FROM project_payment_receipts r
       JOIN project_payments p ON p.id = r.payment_id
       WHERE p.project_id = ?
         AND p.tenant_id = ?"
    );
    $deleteReceipts->execute([$projectId, $tenantId]);
  }

  if (security_table_exists($pdo, 'project_payments')) {
    $deletePayments = $pdo->prepare(
      "DELETE FROM project_payments
       WHERE project_id = ?
         AND tenant_id = ?"
    );
    $deletePayments->execute([$projectId, $tenantId]);
  }

  if (security_table_exists($pdo, 'project_tasks')) {
    $deleteTasks = $pdo->prepare(
      "DELETE FROM project_tasks
       WHERE project_id = ?
         AND tenant_id = ?"
    );
    $deleteTasks->execute([$projectId, $tenantId]);
  }

  if (security_table_exists($pdo, 'project_images')) {
    $deleteImages = $pdo->prepare(
      "DELETE FROM project_images
       WHERE project_id = ?
         AND tenant_id = ?"
    );
    $deleteImages->execute([$projectId, $tenantId]);
  }

  $deleteProject = $pdo->prepare(
    "DELETE FROM projects
     WHERE id = ?
       AND tenant_id = ?"
  );
  $deleteProject->execute([$projectId, $tenantId]);

  if ($me) {
    $projectNo = trim((string)($project['project_no'] ?? ''));
    $projectTitle = trim((string)($project['title'] ?? ''));
    $summary = 'Deleted project';
    if ($projectNo !== '' || $projectTitle !== '') {
      $summary .= ' ' . trim($projectNo . ' ' . $projectTitle);
    }
    admin_audit_log(
      $pdo,
      (int)$me['id'],
      'delete_project',
      $projectUserId > 0 ? $projectUserId : null,
      $summary,
      $tenantId
    );
  }

  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  throw $e;
}

foreach ($projectMediaPaths as $reference) {
  upload_delete_reference_if_unreferenced($pdo, $reference);
}

redirect($returnTo);
