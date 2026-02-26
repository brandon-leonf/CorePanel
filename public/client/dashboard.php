<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../config/db.php';
require __DIR__ . '/../../src/helpers.php';
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/layout.php';

$me = require_permission($pdo, 'client.dashboard.view');
$tenantId = actor_tenant_id($me);

if (user_has_permission($me, 'dashboard.admin.view')) {
  redirect('/admin/dashboard.php');
}

$projects = [];
$projectsLoadError = '';
$userId = (int)($me['id'] ?? 0);

try {
  $stmt = $pdo->prepare("
    SELECT id, project_no, title, status, created_at
    FROM projects
    WHERE user_id = ? AND tenant_id = ?
    ORDER BY id DESC
  ");
  $stmt->execute([$userId, $tenantId]);
  $projects = $stmt->fetchAll();
} catch (Throwable $e) {
  $projectsLoadError = 'Projects are not available yet.';
}

render_header('Client Dashboard • CorePanel');
?>
<div class="container container-wide client-dashboard-page">
  <h1>Client Dashboard</h1>
  <p>Welcome, <?= e($me['name']) ?> (<?= e($me['email']) ?>)</p>

  <div class="inline-links">
    <a href="/items/index.php">My Items</a>
    <span class="inline-sep">|</span>
    <a href="/client/projects/index.php">My Projects</a>
    <span class="inline-sep">|</span>
    <form method="post" action="/logout.php" class="inline-action-form">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <button type="submit" class="link-like-btn">Logout</button>
    </form>
  </div>

  <section class="client-dashboard-projects admin-projects-page" aria-labelledby="client-projects-title">
    <h2 id="client-projects-title">Assigned Projects</h2>

    <?php if ($projectsLoadError !== ''): ?>
      <p><?= e($projectsLoadError) ?></p>
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
            <?php if (!$projects): ?>
              <tr>
                <td colspan="5">No projects assigned yet.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($projects as $p): ?>
                <tr>
                  <td><?= e((string)$p['project_no']) ?></td>
                  <td><?= e((string)$p['title']) ?></td>
                  <td><span class="<?= e(status_class((string)$p['status'])) ?>"><?= e((string)$p['status']) ?></span></td>
                  <td><?= e((string)$p['created_at']) ?></td>
                  <td><a class="admin-project-action-link" href="/client/projects/view.php?id=<?= (int)$p['id'] ?>">View</a></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>
<?php render_footer(); ?>
