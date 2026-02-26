<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../../config/db.php';
require __DIR__ . '/../../../src/auth.php';
require __DIR__ . '/../../../src/helpers.php';

$me = require_any_permission($pdo, ['project_tasks.delete.any', 'project_tasks.delete.own']);
$tenantId = actor_tenant_id($me);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit;
}

if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
  http_response_code(403);
  exit('Invalid CSRF token');
}

$projectId = (int)($_POST['project_id'] ?? 0);
$taskId = (int)($_POST['task_id'] ?? 0);

if ($projectId <= 0 || $taskId <= 0) {
  redirect('/admin/projects/index.php');
}
require_project_access($pdo, $me, $projectId, 'task_delete');

$del = $pdo->prepare(
  "DELETE t
   FROM project_tasks t
   JOIN projects p ON p.id = t.project_id
   WHERE t.id = ? AND t.project_id = ? AND t.tenant_id = ? AND p.tenant_id = ?"
);
$del->execute([$taskId, $projectId, $tenantId, $tenantId]);

redirect('/admin/projects/edit.php?id=' . $projectId);
