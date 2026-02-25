<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../config/db.php';
require __DIR__ . '/../../src/helpers.php';
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/layout.php';

require_admin($pdo);
$me = current_user($pdo);
$projects = [];
$projectsLoadError = '';

try {
  $stmt = $pdo->query("
    SELECT p.id, p.project_no, p.title, p.status, p.created_at,
           u.name AS client_name, u.email AS client_email
    FROM projects p
    JOIN users u ON u.id = p.user_id
    WHERE p.status = 'active'
    ORDER BY p.id DESC
  ");
  $projects = $stmt->fetchAll();
} catch (Throwable $e) {
  $projectsLoadError = 'Projects are not available yet.';
}

render_header('Admin Dashboard • CorePanel');
?>
<div class="container container-wide admin-dashboard-page">
  <h1>Admin Dashboard</h1>
  <p>Welcome, <?= e($me['name']) ?> (<?= e($me['email']) ?>)</p>

  <div class="inline-links">
    <a href="/admin/users/index.php">Manage Users</a>
    <span class="inline-sep">|</span>
    <a href="/items/index.php">My Items</a>
    <span class="inline-sep">|</span>
    <form method="post" action="/logout.php" class="inline-action-form">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <button type="submit" class="link-like-btn">Logout</button>
    </form>
  </div>

  <section class="admin-authz-panel" aria-labelledby="authz-rules-title">
    <h2 id="authz-rules-title">Authorization Rules</h2>
    <p><strong>Clients:</strong> Can only view and manage their own data.</p>
    <p><strong>Admins:</strong> Can access admin endpoints and view all users/data.</p>
    <p><strong>Enforced Server-Side:</strong> require_login(), require_admin(), and user-scoped queries.</p>
  </section>

  <section class="admin-dashboard-projects admin-projects-page" aria-labelledby="dashboard-projects-title">
    <h2 id="dashboard-projects-title">Projects</h2>
    <p>
      <a href="/admin/projects/index.php">View Full Projects</a>
      <span class="inline-sep">|</span>
      <a href="/admin/projects/create.php">+ New Project</a>
    </p>

    <?php if ($projectsLoadError !== ''): ?>
      <p><?= e($projectsLoadError) ?></p>
    <?php else: ?>
      <div class="admin-projects-table-wrap">
        <table class="admin-projects-table" border="1" cellpadding="8" cellspacing="0">
          <thead>
            <tr>
              <th>Project #</th>
              <th>Title</th>
              <th>Client</th>
              <th>Status</th>
              <th>Created</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$projects): ?>
              <tr>
                <td colspan="6">No projects yet.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($projects as $p): ?>
                <tr>
                  <td><?= e((string)$p['project_no']) ?></td>
                  <td><?= e((string)$p['title']) ?></td>
                  <td><?= e((string)$p['client_name']) ?> (<?= e((string)$p['client_email']) ?>)</td>
                  <td><?= e((string)$p['status']) ?></td>
                  <td><?= e((string)$p['created_at']) ?></td>
                  <td class="admin-project-actions-cell">
                    <div class="admin-project-actions">
                      <a class="admin-project-action-link" href="/admin/projects/edit.php?id=<?= (int)$p['id'] ?>">Edit</a>
                      <a class="admin-project-action-link" href="/admin/projects/print.php?id=<?= (int)$p['id'] ?>&autoprint=1" target="_blank" rel="noopener">Print PDF</a>
                    </div>
                  </td>
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
