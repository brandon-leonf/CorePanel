<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../../config/db.php';
require __DIR__ . '/../../../src/helpers.php';
require __DIR__ . '/../../../src/auth.php';
require __DIR__ . '/../../../src/layout.php';
require __DIR__ . '/../../../src/admin_audit.php';

require_admin($pdo);

$stmt = $pdo->query("SELECT id, name, email, role, created_at FROM users ORDER BY created_at DESC");
$users = $stmt->fetchAll();
$auditRows = admin_audit_recent($pdo, 30);

render_header('Manage Users • CorePanel');
?>
<div class="container container-wide admin-users-page">
  <h1>Manage Users</h1>
  <p><a href="/admin/dashboard.php">← Admin Dashboard</a> | <a href="/admin/users/create.php">+ New Client</a></p>

  <div class="admin-users-table-wrap">
    <table class="admin-users-table" border="1" cellpadding="8" cellspacing="0">
      <thead>
        <tr><th>Name</th><th>Email</th><th>Role</th><th>Created</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): ?>
          <tr>
            <td><?= e($u['name']) ?></td>
            <td><?= e($u['email']) ?></td>
            <td><?= e($u['role']) ?></td>
            <td><?= e((string)$u['created_at']) ?></td>
            <td class="admin-user-actions-cell">
              <div class="admin-user-actions-split" role="group" aria-label="User actions">
                <form method="post" action="/admin/users/role.php" class="admin-user-action-form">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <input type="hidden" name="role" value="<?= e($u['role']) ?>">
                  <button
                    class="admin-user-action-btn admin-user-action-role<?= $u['role'] === 'admin' ? '' : ' admin-user-action-role-promote' ?>"
                    type="submit"
                  >
                    <i class="bi <?= $u['role'] === 'admin' ? 'bi-person' : 'bi-shield-lock' ?>" aria-hidden="true"></i>
                    <span>Make <?= $u['role'] === 'admin' ? 'User' : 'Admin' ?></span>
                  </button>
                </form>

                <form method="post" action="/admin/users/delete.php" class="admin-user-action-form">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <button
                    class="admin-user-action-btn admin-user-action-delete"
                    type="submit"
                    onclick="return confirm('Delete this user? This will also delete their items.')"
                  >
                    <i class="bi bi-trash3" aria-hidden="true"></i>
                    <span>Delete</span>
                  </button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <section class="admin-audit-section" aria-labelledby="admin-audit-title">
    <h2 id="admin-audit-title">Audit Trail</h2>
    <p>Records who changed user records and when.</p>

    <div class="admin-audit-table-wrap">
      <table class="admin-audit-table" border="1" cellpadding="8" cellspacing="0">
        <thead>
          <tr>
            <th>When</th>
            <th>Actor</th>
            <th>Action</th>
            <th>Target</th>
            <th>Details</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$auditRows): ?>
            <tr>
              <td colspan="5">No audit records yet.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($auditRows as $row): ?>
              <tr>
                <td><?= e((string)$row['created_at']) ?></td>
                <td>
                  <?= e((string)($row['actor_name'] ?? 'Unknown')) ?>
                  <?php if (!empty($row['actor_email'])): ?>
                    <br><small><?= e((string)$row['actor_email']) ?></small>
                  <?php endif; ?>
                </td>
                <td><?= e((string)$row['action']) ?></td>
                <td>
                  <?php if (!empty($row['target_name']) || !empty($row['target_email'])): ?>
                    <?= e((string)($row['target_name'] ?? 'Unknown')) ?>
                    <?php if (!empty($row['target_email'])): ?>
                      <br><small><?= e((string)$row['target_email']) ?></small>
                    <?php endif; ?>
                  <?php else: ?>
                    <span>Deleted user</span>
                  <?php endif; ?>
                </td>
                <td><?= e((string)($row['summary'] ?? '')) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>
<?php render_footer(); ?>
