<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../../config/db.php';
require __DIR__ . '/../../../src/auth.php';
require __DIR__ . '/../../../src/helpers.php';

$me = require_any_permission($pdo, ['project_tasks.edit.any', 'project_tasks.edit.own']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit;
}

if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
  http_response_code(403);
  exit('Invalid CSRF token');
}

$projectId = (int)($_POST['project_id'] ?? 0);
if ($projectId > 0) {
  require_project_access($pdo, $me, $projectId, 'task_edit');
  redirect('/admin/projects/edit.php?id=' . $projectId);
}

redirect('/admin/projects/index.php');
