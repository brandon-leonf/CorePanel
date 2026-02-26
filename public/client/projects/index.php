<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../../config/db.php';
require __DIR__ . '/../../../src/auth.php';
require __DIR__ . '/../../../src/helpers.php';
require __DIR__ . '/../../../src/layout.php';

$me = require_permission($pdo, 'client.dashboard.view');
$tenantId = actor_tenant_id($me);

// Admins should go to admin area
if (user_has_permission($me, 'dashboard.admin.view')) redirect('/admin/dashboard.php');

$userId = (int)$me['id'];

$stmt = $pdo->prepare("
  SELECT id, project_no, title, status, created_at
  FROM projects
  WHERE user_id = ?
    AND tenant_id = ?
    AND deleted_at IS NULL
  ORDER BY id DESC
");
$stmt->execute([$userId, $tenantId]);
$projects = $stmt->fetchAll();

render_header('My Projects • CorePanel');
?>
<div class="container container-wide client-projects-page admin-projects-page">
  <h1>My Projects</h1>
  <p><a href="/client/dashboard.php">← Client Dashboard</a></p>

  <section class="client-projects-list" aria-labelledby="client-projects-list-title">
    <h2 id="client-projects-list-title">Assigned Projects</h2>

    <?php if (!$projects): ?>
      <p>No projects assigned yet.</p>
    <?php else: ?>
      <div class="admin-projects-table-wrap">
        <table class="admin-projects-table" border="1" cellpadding="8" cellspacing="0">
          <thead>
            <tr>
              <th>Project #</th>
              <th>Title</th>
              <th>Status</th>
              <th>Created</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($projects as $p): ?>
              <tr>
                <td><?= e((string)$p['project_no']) ?></td>
                <td><?= e((string)$p['title']) ?></td>
                <td><span class="<?= e(status_class((string)$p['status'])) ?>"><?= e((string)$p['status']) ?></span></td>
                <td><?= e((string)$p['created_at']) ?></td>
                <td><a class="admin-project-action-link" href="/client/projects/view.php?id=<?= (int)$p['id'] ?>">View</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>
<?php render_footer(); ?>
