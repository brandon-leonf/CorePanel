<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../../config/db.php';
require __DIR__ . '/../../../src/helpers.php';
require __DIR__ . '/../../../src/auth.php';
require __DIR__ . '/../../../src/layout.php';
require __DIR__ . '/../../../src/admin_audit.php';
require __DIR__ . '/../../../src/security.php';

$me = require_permission($pdo, 'users.view');
$tenantId = actor_tenant_id($me);
$canCreateUser = user_has_permission($me, 'users.create');
$canEditUser = user_has_permission($me, 'users.edit');
$canManageRoles = user_has_permission($me, 'users.role.manage');
$canDeleteUser = user_has_permission($me, 'users.delete');
$roleErrorCode = (string)($_GET['role_error'] ?? '');
$roleErrorMessage = match ($roleErrorCode) {
  'confirm_phrase' => 'Promotion blocked: type the exact confirmation phrase "MAKE ADMIN" to continue.',
  'reauth_failed' => 'Re-authentication failed. Enter your current password and valid 2FA code (if enabled) before promoting a user to admin.',
  'reauth_unavailable' => 'Re-authentication is currently unavailable. Please sign in again and retry.',
  default => '',
};
$adminReauthRequiresTotp = false;
if ($canManageRoles && ensure_user_twofa_columns($pdo)) {
  $meSecurityStmt = $pdo->prepare(
    "SELECT role, totp_secret, twofa_enabled_at
     FROM users
     WHERE id = ? AND tenant_id = ?
     LIMIT 1"
  );
  $meSecurityStmt->execute([(int)$me['id'], $tenantId]);
  $meSecurity = $meSecurityStmt->fetch();
  $adminReauthRequiresTotp = is_array($meSecurity) && admin_twofa_enabled($meSecurity);
}

$stmt = $pdo->prepare(
  "SELECT id, name, email, role, created_at
   FROM users
   WHERE tenant_id = ?
   ORDER BY CASE WHEN LOWER(role) = 'admin' THEN 0 ELSE 1 END, created_at DESC"
);
$stmt->execute([$tenantId]);
$users = $stmt->fetchAll();
$adminCount = 0;
$clientCount = 0;
foreach ($users as $userRow) {
  if (strtolower(trim((string)($userRow['role'] ?? ''))) === 'admin') {
    $adminCount++;
  } else {
    $clientCount++;
  }
}
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
  <?php if ($roleErrorMessage !== ''): ?>
    <ul><li><?= e($roleErrorMessage) ?></li></ul>
  <?php endif; ?>

  <section class="admin-users-filter-panel" aria-labelledby="client-filter-title">
    <h2 id="client-filter-title">Users</h2>
    <p>Admins are listed first. Search filters clients only.</p>
    <div class="admin-users-filter-meta">
      <span>Admins: <?= number_format((float)$adminCount, 0) ?></span>
      <span>Clients: <?= number_format((float)$clientCount, 0) ?></span>
    </div>
    <label class="admin-users-filter-label" for="client-filter-input">Search Clients</label>
    <input
      id="client-filter-input"
      type="search"
      class="admin-users-filter-input"
      placeholder="Search client name or email"
      autocomplete="off"
      data-client-filter-input
    >
  </section>

  <div class="admin-users-table-wrap">
    <table class="admin-users-table" border="1" cellpadding="8" cellspacing="0">
      <thead>
        <tr><th>Name</th><th>Email</th><th>Role</th><th>Created</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php if (!$users): ?>
          <tr>
            <td colspan="5">No users yet.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($users as $u): ?>
            <?php
              $userRole = strtolower(trim((string)($u['role'] ?? '')));
              $isAdminRow = $userRole === 'admin';
              $clientSearchText = strtolower(trim((string)$u['name'] . ' ' . (string)$u['email']));
            ?>
            <tr
              data-user-row
              data-user-role="<?= e_attr($userRole) ?>"
              data-client-row="<?= $isAdminRow ? '0' : '1' ?>"
              data-client-search="<?= e_attr($clientSearchText) ?>"
            >
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
                    <?php if ($isAdminRow): ?>
                      <form method="post" action="/admin/users/role.php" class="admin-inline-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                        <input type="hidden" name="role" value="<?= e($u['role']) ?>">
                        <button
                          class="admin-action-link admin-action-role admin-action-role-icon admin-action-role-admin-icon"
                          type="submit"
                          aria-label="Admin user (click to make user)"
                          title="Admin user"
                        >
                          <i class="bi bi-shield-lock-fill" aria-hidden="true"></i>
                        </button>
                      </form>
                    <?php else: ?>
                      <button
                        class="admin-action-link admin-action-role admin-action-role-icon admin-action-role-user-icon js-open-promote-modal"
                        type="button"
                        data-open-promote-modal="1"
                        data-target-user-id="<?= (int)$u['id'] ?>"
                        data-target-user-name="<?= e_attr((string)$u['name']) ?>"
                        data-target-user-email="<?= e_attr((string)$u['email']) ?>"
                        aria-label="User account (click to make admin)"
                        title="User account"
                      >
                        <i class="bi bi-person-fill" aria-hidden="true"></i>
                      </button>
                    <?php endif; ?>
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
          <tr class="admin-users-no-client-match" data-no-client-match hidden>
            <td colspan="5">No clients match your search.</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($canManageRoles): ?>
    <div class="admin-promote-modal" data-promote-modal hidden>
      <div class="admin-promote-modal-panel" data-promote-modal-panel role="dialog" aria-modal="true" aria-labelledby="promote-modal-title">
        <h2 id="promote-modal-title">Confirm Admin Promotion</h2>
        <p class="admin-promote-modal-target" data-promote-modal-target></p>
        <p>Type <strong>MAKE ADMIN</strong> and confirm your credentials to continue.</p>

        <form method="post" action="/admin/users/role.php" class="admin-promote-modal-form">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="id" value="" data-promote-user-id>
          <input type="hidden" name="role" value="user">

          <label>Confirmation phrase
            <input
              class="admin-role-reauth-input"
              name="confirm_phrase"
              type="text"
              autocomplete="off"
              placeholder="MAKE ADMIN"
              required
              data-promote-first-input
            >
          </label>

          <label>Your current password
            <input
              class="admin-role-reauth-input"
              name="confirm_password"
              type="password"
              autocomplete="current-password"
              placeholder="Your password"
              required
            >
          </label>

          <?php if ($adminReauthRequiresTotp): ?>
            <label>Your current 2FA code
              <input
                class="admin-role-reauth-input admin-role-reauth-input-code"
                name="confirm_totp"
                inputmode="numeric"
                autocomplete="one-time-code"
                pattern="[0-9]{6}"
                maxlength="6"
                placeholder="2FA"
                required
              >
            </label>
          <?php endif; ?>

          <div class="admin-promote-modal-actions">
            <button type="submit">Make Admin</button>
            <button type="button" class="admin-promote-modal-cancel" data-close-promote-modal>Cancel</button>
          </div>
        </form>
      </div>
    </div>
  <?php endif; ?>

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
