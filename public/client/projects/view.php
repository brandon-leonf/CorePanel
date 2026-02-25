<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../../config/db.php';
require __DIR__ . '/../../../src/auth.php';
require __DIR__ . '/../../../src/helpers.php';
require __DIR__ . '/../../../src/layout.php';

require_login();
$me = current_user($pdo);
if (($me['role'] ?? 'user') === 'admin') redirect('/admin/dashboard.php');

$userId = (int)$me['id'];
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('Bad request'); }

$pstmt = $pdo->prepare("
  SELECT id, project_no, title, description, status, created_at
  FROM projects
  WHERE id = ? AND user_id = ?
  LIMIT 1
");
$pstmt->execute([$id, $userId]);
$project = $pstmt->fetch();
if (!$project) { http_response_code(404); exit('Project not found'); }

$tstmt = $pdo->prepare("SELECT * FROM project_tasks WHERE project_id = ? ORDER BY id DESC");
$tstmt->execute([$id]);
$tasks = $tstmt->fetchAll();

$total = 0.00;
foreach ($tasks as $t) $total += (float)$t['amount'];

render_header('Project ' . $project['project_no'] . ' • CorePanel');
?>
<div class="container container-wide client-project-view-page">
  <div class="client-project-view-header">
    <p><a href="/client/projects/index.php">← My Projects</a></p>
    <h1><?= e($project['project_no']) ?> — <?= e($project['title']) ?></h1>
    <p class="client-project-view-status">Status: <strong><?= e($project['status']) ?></strong></p>
  </div>

  <?php if (!empty($project['description'])): ?>
    <p class="client-project-view-description"><?= nl2br(e((string)$project['description'])) ?></p>
  <?php endif; ?>

  <section class="client-project-view-tasks-section" aria-labelledby="client-project-tasks-title">
    <h2 id="client-project-tasks-title">Tasks</h2>
    <?php if (!$tasks): ?>
      <p>No tasks added yet.</p>
    <?php else: ?>
      <div class="client-project-view-tasks-wrap">
        <table class="client-project-view-tasks-table" border="1" cellpadding="8" cellspacing="0">
          <thead>
            <tr><th>Task</th><th>Rate</th><th>Qty</th><th>Amount</th><th>Status</th></tr>
          </thead>
          <tbody>
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
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <p class="client-project-view-total"><strong>Total:</strong> $<?= number_format((float)$total, 2) ?></p>
    <?php endif; ?>
  </section>
</div>
<?php render_footer(); ?>
