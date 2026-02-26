<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../config/db.php';
require __DIR__ . '/../../src/helpers.php';
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/layout.php';

$me = require_permission($pdo, 'dashboard.admin.view');
$tenantId = actor_tenant_id($me);
$actorId = (int)$me['id'];
$canCreateProject = user_has_permission($me, 'projects.create');
$canViewUsers = user_has_permission($me, 'users.view');
$canManageSecurity = user_has_permission($me, 'security.manage');
$projects = [];
$projectsLoadError = '';

try {
  $stmt = $pdo->prepare("
    SELECT p.id, p.project_no, p.title, p.status, p.created_at,
           p.user_id,
           u.name AS client_name, u.email AS client_email
    FROM projects p
    JOIN users u ON u.id = p.user_id
    WHERE p.status = ? AND p.tenant_id = ?
    ORDER BY p.id DESC
  ");
  $stmt->execute(['active', $tenantId]);
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
    <?php if ($canViewUsers): ?>
      <a href="/admin/users/index.php">Manage Users</a>
      <span class="inline-sep">|</span>
    <?php endif; ?>
    <?php if ($canManageSecurity): ?>
      <a href="/admin/security.php">Admin Security</a>
      <span class="inline-sep">|</span>
    <?php endif; ?>
    <a href="/items/index.php">My Items</a>
    <span class="inline-sep">|</span>
    <form method="post" action="/logout.php" class="inline-action-form">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <button type="submit" class="link-like-btn">Logout</button>
    </form>
  </div>

  <section class="admin-authz-panel" aria-labelledby="authz-rules-title">
    <h2 id="authz-rules-title">Authorization Rules</h2>
    <p><strong>RBAC:</strong> Access is permission-based (not just admin/user).</p>
    <p><strong>Object Checks:</strong> Project/task actions validate access to the specific record.</p>
    <p><strong>Tenant Boundaries:</strong> Admin screens and project data are isolated per tenant.</p>
  </section>

  <section class="admin-dashboard-projects admin-projects-page" aria-labelledby="dashboard-projects-title">
    <h2 id="dashboard-projects-title">Projects</h2>
    <p>
      <a href="/admin/projects/index.php">View Full Projects</a>
      <?php if ($canCreateProject): ?>
        <span class="inline-sep">|</span>
        <a href="/admin/projects/create.php">+ New Project</a>
      <?php endif; ?>
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
                  <td><span class="<?= e(status_class((string)$p['status'])) ?>"><?= e((string)$p['status']) ?></span></td>
                  <td><?= e((string)$p['created_at']) ?></td>
                  <td class="admin-project-actions-cell">
                    <div class="admin-project-actions">
                      <?php if (user_has_permission($me, 'projects.edit.any') || (user_has_permission($me, 'projects.edit.own') && (int)$p['user_id'] === $actorId)): ?>
                        <a class="admin-project-action-link" href="/admin/projects/edit.php?id=<?= (int)$p['id'] ?>">Edit</a>
                      <?php endif; ?>
                      <?php if (user_has_permission($me, 'projects.print.any') || (user_has_permission($me, 'projects.print.own') && (int)$p['user_id'] === $actorId)): ?>
                        <a class="admin-project-action-link" href="/admin/projects/print.php?id=<?= (int)$p['id'] ?>&autoprint=1" target="_blank" rel="noopener">Print PDF</a>
                      <?php endif; ?>
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
