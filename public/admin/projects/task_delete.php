<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../../config/db.php';
require __DIR__ . '/../../../src/auth.php';
require __DIR__ . '/../../../src/helpers.php';

require_admin($pdo);

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

$del = $pdo->prepare("DELETE FROM project_tasks WHERE id = ? AND project_id = ?");
$del->execute([$taskId, $projectId]);

redirect('/admin/projects/edit.php?id=' . $projectId);
