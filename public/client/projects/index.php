<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../../config/db.php';
require __DIR__ . '/../../../src/auth.php';
require __DIR__ . '/../../../src/helpers.php';
require __DIR__ . '/../../../src/layout.php';

require_login();
$me = current_user($pdo);

// Admins should go to admin area
if (($me['role'] ?? 'user') === 'admin') redirect('/admin/dashboard.php');

$userId = (int)$me['id'];

$stmt = $pdo->prepare("
  SELECT id, project_no, title, status, created_at
  FROM projects
  WHERE user_id = ?
  ORDER BY id DESC
");
$stmt->execute([$userId]);
$projects = $stmt->fetchAll();

render_header('My Projects • CorePanel');
?>
<div class="container">
  <h1>My Projects</h1>
  <p><a href="/client/dashboard.php">← Client Dashboard</a></p>

  <?php if (!$projects): ?>
    <p>No projects assigned yet.</p>
  <?php else: ?>
    <table border="1" cellpadding="8" cellspacing="0" style="width:100%">
      <thead>
        <tr><th>Project #</th><th>Title</th><th>Status</th><th>Created</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($projects as $p): ?>
          <tr>
            <td><?= e($p['project_no']) ?></td>
            <td><?= e($p['title']) ?></td>
            <td><?= e($p['status']) ?></td>
            <td><?= e((string)$p['created_at']) ?></td>
            <td><a href="/client/projects/view.php?id=<?= (int)$p['id'] ?>">View</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php render_footer(); ?>