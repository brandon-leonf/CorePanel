<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../config/db.php';
require __DIR__ . '/../../src/helpers.php';
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/layout.php';
require __DIR__ . '/../../src/project_payments.php';

$me = require_permission($pdo, 'client.dashboard.view');
$tenantId = actor_tenant_id($me);

if (user_has_permission($me, 'dashboard.admin.view')) {
  redirect('/admin/dashboard.php');
}

$projects = [];
$projectsLoadError = '';
$userId = (int)($me['id'] ?? 0);
$projectPaymentsEnabled = ensure_project_payments_table($pdo);
$clientActivePaidTotal = 0.0;
$clientActiveBalanceTotal = 0.0;
$clientActiveOutstandingTotal = 0.0;
$clientActiveCount = 0;

try {
  $paymentSelect = $projectPaymentsEnabled
    ? 'COALESCE(payment_totals.paid_amount, 0.00) AS paid_amount'
    : '0.00 AS paid_amount';
  $paymentJoin = $projectPaymentsEnabled
    ? "LEFT JOIN (
         SELECT project_id, tenant_id, SUM(amount) AS paid_amount
         FROM project_payments
         GROUP BY project_id, tenant_id
       ) payment_totals
         ON payment_totals.project_id = p.id
        AND payment_totals.tenant_id = p.tenant_id"
    : '';
  $stmt = $pdo->prepare("
    SELECT p.id, p.project_no, p.title, p.status, p.created_at,
           COALESCE(task_totals.total_amount, 0.00) AS project_total_amount,
           {$paymentSelect}
    FROM projects p
    LEFT JOIN (
      SELECT project_id, tenant_id, SUM(amount) AS total_amount
      FROM project_tasks
      GROUP BY project_id, tenant_id
    ) task_totals
      ON task_totals.project_id = p.id
     AND task_totals.tenant_id = p.tenant_id
    {$paymentJoin}
    WHERE p.user_id = ? AND p.tenant_id = ?
    ORDER BY p.id DESC
  ");
  $stmt->execute([$userId, $tenantId]);
  $projects = $stmt->fetchAll();

  foreach ($projects as &$projectRow) {
    $snapshot = project_payment_snapshot(
      (float)($projectRow['project_total_amount'] ?? 0.0),
      (float)($projectRow['paid_amount'] ?? 0.0)
    );
    $projectRow['_payment_snapshot'] = $snapshot;
    if ((string)($projectRow['status'] ?? '') === 'active') {
      $clientActiveCount++;
      $clientActivePaidTotal += (float)$snapshot['paid_amount'];
      $clientActiveBalanceTotal += (float)$snapshot['balance'];
      $clientActiveOutstandingTotal += max(0.0, (float)$snapshot['balance']);
    }
  }
  unset($projectRow);
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
    <div class="client-dashboard-summary-cards">
      <div class="client-dashboard-summary-card">
        <span>Active Projects</span>
        <strong><?= number_format((float)$clientActiveCount, 0) ?></strong>
      </div>
      <div class="client-dashboard-summary-card">
        <span>Received (Active)</span>
        <strong>$<?= number_format($clientActivePaidTotal, 2) ?></strong>
      </div>
      <div class="client-dashboard-summary-card">
        <span>Balance (Active)</span>
        <strong>$<?= number_format($clientActiveBalanceTotal, 2) ?></strong>
      </div>
      <div class="client-dashboard-summary-card">
        <span>Outstanding (Active)</span>
        <strong>$<?= number_format($clientActiveOutstandingTotal, 2) ?></strong>
      </div>
    </div>

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
              <th>Total</th>
              <th>Received</th>
              <th>Balance</th>
              <th>Created</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$projects): ?>
              <tr>
                <td colspan="8">No projects assigned yet.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($projects as $p): ?>
                <?php
                  $snapshot = $p['_payment_snapshot'] ?? project_payment_snapshot(
                    (float)($p['project_total_amount'] ?? 0.0),
                    (float)($p['paid_amount'] ?? 0.0)
                  );
                ?>
                <tr>
                  <td><?= e((string)$p['project_no']) ?></td>
                  <td><?= e((string)$p['title']) ?></td>
                  <td><span class="<?= e(status_class((string)$p['status'])) ?>"><?= e((string)$p['status']) ?></span></td>
                  <td>$<?= number_format((float)$snapshot['total_amount'], 2) ?></td>
                  <td>$<?= number_format((float)$snapshot['paid_amount'], 2) ?></td>
                  <td>$<?= number_format((float)$snapshot['balance'], 2) ?></td>
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
