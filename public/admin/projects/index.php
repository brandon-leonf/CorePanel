<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../../config/db.php';
require __DIR__ . '/../../../src/auth.php';
require __DIR__ . '/../../../src/helpers.php';
require __DIR__ . '/../../../src/layout.php';

require_admin($pdo);

$stmt = $pdo->query("
  SELECT p.id, p.project_no, p.title, p.status, p.created_at,
         u.name AS client_name, u.email AS client_email
  FROM projects p
  JOIN users u ON u.id = p.user_id
  ORDER BY p.id DESC
");
$projects = $stmt->fetchAll();

render_header('Projects • Admin • CorePanel');
?>
<div class="container container-wide admin-projects-page">
  <h1>Projects</h1>
  <p>
    <a href="/admin/dashboard.php">← Admin Dashboard</a> |
    <a href="/admin/projects/create.php">+ New Project</a>
  </p>

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
              <td><?= e($p['project_no']) ?></td>
              <td><?= e($p['title']) ?></td>
              <td><?= e($p['client_name']) ?> (<?= e($p['client_email']) ?>)</td>
              <td><?= e($p['status']) ?></td>
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
</div>
<?php render_footer(); ?>
