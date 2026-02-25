<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../../config/db.php';
require __DIR__ . '/../../../src/auth.php';
require __DIR__ . '/../../../src/helpers.php';
require __DIR__ . '/../../../src/layout.php';

require_admin($pdo);

$projectId = (int)($_GET['project_id'] ?? $_POST['project_id'] ?? 0);
$taskId = (int)($_GET['task_id'] ?? $_POST['task_id'] ?? 0);
if ($projectId <= 0 || $taskId <= 0) {
  http_response_code(400);
  exit('Bad request');
}

$stmt = $pdo->prepare("
  SELECT t.*, p.project_no, p.title AS project_title
  FROM project_tasks t
  JOIN projects p ON p.id = t.project_id
  WHERE t.id = ? AND t.project_id = ?
  LIMIT 1
");
$stmt->execute([$taskId, $projectId]);
$task = $stmt->fetch();
if (!$task) {
  http_response_code(404);
  exit('Task not found');
}

$errors = [];
$taskTitle = (string)$task['task_title'];
$taskDescription = (string)($task['task_description'] ?? '');
$rate = (float)$task['rate'];
$quantity = (float)$task['quantity'];
$status = (string)$task['status'];
$validStatuses = ['todo', 'in_progress', 'done'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token');
  }

  $taskTitle = trim((string)($_POST['task_title'] ?? ''));
  $taskDescription = trim((string)($_POST['task_description'] ?? ''));
  $rate = (float)($_POST['rate'] ?? 0);
  $quantity = (float)($_POST['quantity'] ?? 1);
  $status = (string)($_POST['status'] ?? 'todo');

  if ($taskTitle === '') {
    $errors[] = 'Task title is required.';
  }
  if ($rate < 0) {
    $errors[] = 'Rate must be >= 0.';
  }
  if ($quantity < 0) {
    $errors[] = 'Quantity must be >= 0.';
  }
  if (!in_array($status, $validStatuses, true)) {
    $errors[] = 'Invalid task status.';
  }

  if (!$errors) {
    $amount = round($rate * $quantity, 2);
    $up = $pdo->prepare("
      UPDATE project_tasks
      SET task_title = ?, task_description = ?, rate = ?, quantity = ?, amount = ?, status = ?
      WHERE id = ? AND project_id = ?
    ");
    $up->execute([
      $taskTitle,
      $taskDescription === '' ? null : $taskDescription,
      $rate,
      $quantity,
      $amount,
      $status,
      $taskId,
      $projectId
    ]);

    redirect('/admin/projects/edit.php?id=' . $projectId);
  }
}

render_header('Edit Task • Admin • CorePanel');
?>
<div class="container admin-project-task-edit-page">
  <h1>Edit Task</h1>
  <p>
    <a href="/admin/projects/edit.php?id=<?= (int)$projectId ?>">← Back to Project</a>
  </p>
  <p>
    Project: <strong><?= e((string)$task['project_no']) ?> — <?= e((string)$task['project_title']) ?></strong>
  </p>

  <?php if ($errors): ?>
    <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="project_id" value="<?= (int)$projectId ?>">
    <input type="hidden" name="task_id" value="<?= (int)$taskId ?>">

    <label>Task Title<br>
      <input name="task_title" value="<?= e($taskTitle) ?>" required>
    </label>

    <label>Task Description<br>
      <textarea name="task_description" rows="4"><?= e($taskDescription) ?></textarea>
    </label>

    <label>Rate<br>
      <input name="rate" type="number" step="0.01" value="<?= e(number_format($rate, 2, '.', '')) ?>" required>
    </label>

    <label>Quantity<br>
      <input name="quantity" type="number" step="0.01" value="<?= e(number_format($quantity, 2, '.', '')) ?>" required>
    </label>

    <label>Status<br>
      <select name="status">
        <?php foreach ($validStatuses as $taskStatus): ?>
          <option value="<?= e($taskStatus) ?>" <?= $status === $taskStatus ? 'selected' : '' ?>>
            <?= e($taskStatus) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <button type="submit">Save Task</button>
  </form>
</div>
<?php render_footer(); ?>
