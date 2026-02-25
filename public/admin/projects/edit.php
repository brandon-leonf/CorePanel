<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../../config/db.php';
require __DIR__ . '/../../../src/auth.php';
require __DIR__ . '/../../../src/helpers.php';
require __DIR__ . '/../../../src/layout.php';

require_admin($pdo);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('Bad request'); }

$pstmt = $pdo->prepare("
  SELECT p.*, u.name AS client_name, u.email AS client_email
  FROM projects p
  JOIN users u ON u.id = p.user_id
  WHERE p.id = ?
  LIMIT 1
");
$pstmt->execute([$id]);
$project = $pstmt->fetch();
if (!$project) { http_response_code(404); exit('Project not found'); }

$errors = [];
$title = (string)$project['title'];
$description = (string)($project['description'] ?? '');
$status = (string)$project['status'];

/** Update project meta */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_project') {
  if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token');
  }

  $title = trim((string)($_POST['title'] ?? ''));
  $description = trim((string)($_POST['description'] ?? ''));
  $status = (string)($_POST['status'] ?? 'active');

  if ($title === '') $errors[] = 'Title is required.';
  if (!in_array($status, ['draft','active','paused','completed'], true)) $errors[] = 'Invalid status.';

  if (!$errors) {
    $up = $pdo->prepare("UPDATE projects SET title=?, description=?, status=? WHERE id=?");
    $up->execute([$title, $description === '' ? null : $description, $status, $id]);
    redirect('/admin/projects/edit.php?id=' . $id);
  }
}

/** Add task */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_task') {
  if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token');
  }

  $taskTitle = trim((string)($_POST['task_title'] ?? ''));
  $taskDesc  = trim((string)($_POST['task_description'] ?? ''));
  $rate = (float)($_POST['rate'] ?? 0);
  $qty  = (float)($_POST['quantity'] ?? 1);

  if ($taskTitle === '') $errors[] = 'Task title is required.';
  if ($rate < 0) $errors[] = 'Rate must be >= 0.';
  if ($qty < 0) $errors[] = 'Quantity must be >= 0.';

  $amount = round($rate * $qty, 2);

  if (!$errors) {
    $ins = $pdo->prepare("
      INSERT INTO project_tasks (project_id, task_title, task_description, rate, quantity, amount)
      VALUES (?, ?, ?, ?, ?, ?)
    ");
    $ins->execute([
      $id,
      $taskTitle,
      $taskDesc === '' ? null : $taskDesc,
      $rate,
      $qty,
      $amount
    ]);
    redirect('/admin/projects/edit.php?id=' . $id);
  }
}

$tstmt = $pdo->prepare("SELECT * FROM project_tasks WHERE project_id = ? ORDER BY id DESC");
$tstmt->execute([$id]);
$tasks = $tstmt->fetchAll();

$total = 0.00;
foreach ($tasks as $t) $total += (float)$t['amount'];

render_header('Edit Project • Admin • CorePanel');
?>
<div class="container container-wide admin-project-edit-page">
  <div class="admin-project-edit-header">
    <p><a href="/admin/projects/index.php">← Projects</a></p>
    <h1><?= e($project['project_no']) ?> — <?= e($project['title']) ?></h1>
    <p class="admin-project-edit-client">Client: <?= e($project['client_name']) ?> (<?= e($project['client_email']) ?>)</p>
  </div>

  <?php if ($errors): ?>
    <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>

  <div class="admin-project-edit-grid">
    <section class="admin-project-edit-panel" aria-labelledby="project-details-title">
      <h2 id="project-details-title">Project Details</h2>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="update_project">

        <label>Title<br>
          <input name="title" value="<?= e($title) ?>" required>
        </label>

        <label>Description<br>
          <textarea name="description" rows="4"><?= e($description) ?></textarea>
        </label>

        <label>Status<br>
          <select name="status">
            <?php foreach (['draft','active','paused','completed'] as $s): ?>
              <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e($s) ?></option>
            <?php endforeach; ?>
          </select>
        </label>

        <button type="submit">Save Project</button>
      </form>
    </section>

    <section class="admin-project-edit-panel" aria-labelledby="add-task-title">
      <h2 id="add-task-title">Add Task</h2>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="add_task">

        <label>Task Title<br>
          <input name="task_title" required>
        </label>

        <label>Task Description<br>
          <textarea name="task_description" rows="3"></textarea>
        </label>

        <div class="admin-project-edit-task-row">
          <label>Rate<br>
            <input name="rate" type="number" step="0.01" value="0.00" required>
          </label>

          <label>Quantity<br>
            <input name="quantity" type="number" step="0.01" value="1.00" required>
          </label>
        </div>

        <button type="submit">Add Task</button>
      </form>
    </section>
  </div>

  <section class="admin-project-edit-tasks-section" aria-labelledby="tasks-title">
    <h2 id="tasks-title">Tasks</h2>
    <div class="admin-project-edit-tasks-wrap">
      <table class="admin-project-edit-tasks-table" border="1" cellpadding="8" cellspacing="0">
        <thead>
          <tr>
            <th>Title</th>
            <th>Rate</th>
            <th>Qty</th>
            <th>Amount</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$tasks): ?>
            <tr>
              <td colspan="6">No tasks yet.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($tasks as $t): ?>
              <tr>
                <td>
                  <strong><?= e($t['task_title']) ?></strong><br>
                  <small><?= e((string)($t['task_description'] ?? '')) ?></small>
                </td>
                <td><?= number_format((float)$t['rate'], 2) ?></td>
                <td><?= number_format((float)$t['quantity'], 2) ?></td>
                <td><?= number_format((float)$t['amount'], 2) ?></td>
                <td><?= e($t['status']) ?></td>
                <td class="admin-project-edit-task-actions-cell">
                  <div class="admin-project-edit-task-actions">
                    <a class="admin-project-edit-task-action-link" href="/admin/projects/task_edit.php?project_id=<?= (int)$id ?>&task_id=<?= (int)$t['id'] ?>">Edit</a>
                    <form method="post" action="/admin/projects/task_delete.php" class="admin-project-edit-task-delete-form">
                      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="project_id" value="<?= (int)$id ?>">
                      <input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>">
                      <button
                        type="submit"
                        class="admin-project-edit-task-delete-btn"
                        onclick="return confirm('Delete this task?')"
                      >
                        Delete
                      </button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <p class="admin-project-edit-total"><strong>Total:</strong> $<?= number_format((float)$total, 2) ?></p>
  </section>
</div>
<?php render_footer(); ?>
