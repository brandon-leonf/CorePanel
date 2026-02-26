<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../../config/db.php';
require __DIR__ . '/../../../src/auth.php';
require __DIR__ . '/../../../src/helpers.php';
require __DIR__ . '/../../../src/layout.php';
require __DIR__ . '/../../../src/project_payments.php';

$me = require_any_permission($pdo, ['projects.view.any', 'projects.view.own']);
$tenantId = actor_tenant_id($me);
$canViewAny = user_has_permission($me, 'projects.view.any');
$canCreateProject = user_has_permission($me, 'projects.create');
$actorId = (int)$me['id'];
$projectPaymentsEnabled = ensure_project_payments_table($pdo);

$sql = "
  SELECT p.id, p.user_id, p.project_no, p.title, p.status, p.created_at,
         u.name AS client_name, u.email AS client_email,
         COALESCE(task_totals.total_amount, 0.00) AS project_total_amount,
         " . ($projectPaymentsEnabled ? 'COALESCE(payment_totals.paid_amount, 0.00)' : '0.00') . " AS paid_amount
  FROM projects p
  JOIN users u ON u.id = p.user_id
  LEFT JOIN (
    SELECT project_id, tenant_id, SUM(amount) AS total_amount
    FROM project_tasks
    GROUP BY project_id, tenant_id
  ) task_totals
    ON task_totals.project_id = p.id
   AND task_totals.tenant_id = p.tenant_id
  " . (
    $projectPaymentsEnabled
      ? "LEFT JOIN (
           SELECT project_id, tenant_id, SUM(amount) AS paid_amount
           FROM project_payments
           GROUP BY project_id, tenant_id
         ) payment_totals
           ON payment_totals.project_id = p.id
          AND payment_totals.tenant_id = p.tenant_id"
      : ""
  ) . "
  WHERE p.tenant_id = ?
";
$params = [$tenantId];

if (!$canViewAny) {
  $sql .= " AND p.user_id = ? ";
  $params[] = (int)$me['id'];
}

$sql .= " ORDER BY p.id DESC ";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$projects = $stmt->fetchAll();

render_header('Projects • Admin • CorePanel');
?>
<div class="container container-wide admin-projects-page">
  <h1>Projects</h1>
  <p>
    <a href="/admin/dashboard.php">← Admin Dashboard</a>
    <?php if ($canCreateProject): ?>
      | <a href="/admin/projects/create.php">+ New Project</a>
    <?php endif; ?>
  </p>

  <div class="admin-projects-table-wrap">
    <table class="admin-projects-table" border="1" cellpadding="8" cellspacing="0">
      <thead>
        <tr>
          <th>Project #</th>
          <th>Title</th>
          <th>Client</th>
          <th>Status</th>
          <th>Total</th>
          <th>Paid</th>
          <th>Balance</th>
          <th>Payment</th>
          <th>Created</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$projects): ?>
          <tr>
            <td colspan="10">No projects yet.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($projects as $p): ?>
            <?php
              $paymentSnapshot = project_payment_snapshot(
                (float)($p['project_total_amount'] ?? 0.0),
                (float)($p['paid_amount'] ?? 0.0)
              );
              $paymentProgressPercent = number_format((float)$paymentSnapshot['progress_percent'], 1, '.', '');
            ?>
            <tr>
              <td><?= e($p['project_no']) ?></td>
              <td><?= e($p['title']) ?></td>
              <td><?= e($p['client_name']) ?> (<?= e($p['client_email']) ?>)</td>
              <td><span class="<?= e(status_class((string)$p['status'])) ?>"><?= e((string)$p['status']) ?></span></td>
              <td>$<?= number_format((float)$paymentSnapshot['total_amount'], 2) ?></td>
              <td>$<?= number_format((float)$paymentSnapshot['paid_amount'], 2) ?></td>
              <td>$<?= number_format((float)$paymentSnapshot['balance'], 2) ?></td>
              <td>
                <span class="<?= e(project_payment_status_class((string)$paymentSnapshot['status_key'])) ?>">
                  <?= e((string)$paymentSnapshot['status_label']) ?>
                </span>
                <div class="payment-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= e($paymentProgressPercent) ?>">
                  <span class="payment-progress-fill" style="width: <?= e($paymentProgressPercent) ?>%;"></span>
                </div>
                <small class="payment-progress-caption"><?= e($paymentProgressPercent) ?>%</small>
              </td>
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
</div>
<?php render_footer(); ?>
