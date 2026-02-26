<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../../config/db.php';
require __DIR__ . '/../../../src/helpers.php';
require __DIR__ . '/../../../src/auth.php';
require __DIR__ . '/../../../src/layout.php';
require __DIR__ . '/../../../src/admin_audit.php';

$me = require_permission($pdo, 'users.view');
$tenantId = actor_tenant_id($me);
$canCreateUser = user_has_permission($me, 'users.create');
$canEditUser = user_has_permission($me, 'users.edit');
$canManageRoles = user_has_permission($me, 'users.role.manage');
$canDeleteUser = user_has_permission($me, 'users.delete');

$stmt = $pdo->prepare(
  "SELECT id, name, email, role, created_at
   FROM users
   WHERE tenant_id = ?
   ORDER BY created_at DESC"
);
$stmt->execute([$tenantId]);
$users = $stmt->fetchAll();
$auditRows = admin_audit_recent($pdo, 30, $tenantId);

render_header('Manage Users • CorePanel');
?>
<div class="container container-wide admin-users-page">
  <h1>Manage Users</h1>
  <p>
    <a href="/admin/dashboard.php">← Admin Dashboard</a>
    <?php if ($canCreateUser): ?>
      | <a href="/admin/users/create.php">+ New Client</a>
    <?php endif; ?>
  </p>

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
              <div class="admin-user-actions-inline" role="group" aria-label="User actions">
                <?php if ($canEditUser): ?>
                  <a class="admin-action-link admin-action-edit" href="/admin/users/edit.php?id=<?= (int)$u['id'] ?>">Edit</a>
                <?php endif; ?>

                <?php if ($canEditUser && ($canManageRoles || $canDeleteUser)): ?>
                  <span class="admin-action-sep">|</span>
                <?php endif; ?>

                <?php if ($canManageRoles): ?>
                  <form method="post" action="/admin/users/role.php" class="admin-inline-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                    <input type="hidden" name="role" value="<?= e($u['role']) ?>">
                    <button
                      class="admin-action-link admin-action-role<?= $u['role'] === 'admin' ? '' : ' admin-action-role-promote' ?>"
                      type="submit"
                    >
                      <i class="bi <?= $u['role'] === 'admin' ? 'bi-person' : 'bi-shield-lock' ?>" aria-hidden="true"></i>
                      <span>Make <?= $u['role'] === 'admin' ? 'User' : 'Admin' ?></span>
                    </button>
                  </form>
                <?php endif; ?>

                <?php if ($canManageRoles && $canDeleteUser): ?>
                  <span class="admin-action-sep">|</span>
                <?php endif; ?>

                <?php if ($canDeleteUser): ?>
                  <form method="post" action="/admin/users/delete.php" class="admin-inline-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                    <button
                      class="admin-action-link admin-action-delete"
                      type="submit"
                      data-confirm="Delete this user? This will also delete their items."
                      aria-label="Delete user"
                    >
                      <i class="bi bi-trash3" aria-hidden="true"></i>
                    </button>
                  </form>
                <?php endif; ?>
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
