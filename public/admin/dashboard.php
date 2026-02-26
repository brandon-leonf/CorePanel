<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../config/db.php';
require __DIR__ . '/../../src/helpers.php';
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/layout.php';
require __DIR__ . '/../../src/security.php';
require __DIR__ . '/../../src/project_payments.php';

$me = require_permission($pdo, 'dashboard.admin.view');
$tenantId = actor_tenant_id($me);
$actorId = (int)$me['id'];
$canCreateProject = user_has_permission($me, 'projects.create');
$canViewUsers = user_has_permission($me, 'users.view');
$canManageSecurity = user_has_permission($me, 'security.manage');
$canViewProjectsAny = user_has_permission($me, 'projects.view.any');
$canViewProjectsOwn = user_has_permission($me, 'projects.view.own');
$projects = [];
$projectsLoadError = '';
$userHasPhoneColumn = db_has_column($pdo, 'users', 'phone');
$projectPaymentsEnabled = ensure_project_payments_table($pdo);
$projectDueDateEnabled = ensure_project_due_date_column($pdo);
$arTotalOutstanding = 0.0;
$arOverdueOutstanding = 0.0;
$arTopCustomers = [];
$activeProjectsPaidTotal = 0.0;
$activeProjectsBalanceTotal = 0.0;
$activeProjectsOutstandingTotal = 0.0;
$activeProjectsLongestAgeSeconds = 0;
$activeProjectsAvgAgeSeconds = 0;
$dashboardStatuses = ['active', 'draft', 'paused'];

/**
 * Build a safe tel: URI from a free-form phone value.
 */
function project_phone_call_href(?string $phone): ?string {
  $raw = trim((string)$phone);
  if ($raw === '') {
    return null;
  }

  $digits = preg_replace('/\D+/', '', $raw) ?? '';
  if ($digits === '') {
    return null;
  }

  if (str_starts_with($raw, '+')) {
    return 'tel:+' . $digits;
  }

  if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
    return 'tel:+' . $digits;
  }

  return 'tel:+1' . $digits;
}

/**
 * Format an elapsed duration in a compact day/hour/minute label.
 */
function project_active_duration_label(int $seconds): string {
  $seconds = max(0, $seconds);
  if ($seconds < 3600) {
    $minutes = max(1, (int)floor($seconds / 60));
    return $minutes . 'm';
  }

  $days = (int)floor($seconds / 86400);
  $hours = (int)floor(($seconds % 86400) / 3600);
  if ($days > 0) {
    return $days . 'd ' . $hours . 'h';
  }

  $minutes = (int)floor(($seconds % 3600) / 60);
  return ((int)floor($seconds / 3600)) . 'h ' . $minutes . 'm';
}

try {
  $phoneSelect = $userHasPhoneColumn ? 'u.phone AS client_phone' : 'NULL AS client_phone';
  $dueDateSelect = $projectDueDateEnabled ? 'p.due_date' : 'NULL AS due_date';
  $paymentSelect = $projectPaymentsEnabled
    ? 'COALESCE(payment_totals.paid_amount, 0.00) AS paid_amount'
    : '0.00 AS paid_amount';
  $paymentJoin = $projectPaymentsEnabled
    ? "LEFT JOIN (
           SELECT project_id, tenant_id, SUM(amount) AS paid_amount
           FROM project_payments
           WHERE deleted_at IS NULL
           GROUP BY project_id, tenant_id
         ) payment_totals
           ON payment_totals.project_id = p.id
          AND payment_totals.tenant_id = p.tenant_id"
    : '';
  $statusPlaceholders = implode(',', array_fill(0, count($dashboardStatuses), '?'));
  $stmt = $pdo->prepare("
    SELECT p.id, p.project_no, p.title, p.status, p.created_at,
           p.user_id,
           u.name AS client_name, u.email AS client_email,
           {$phoneSelect},
           {$dueDateSelect},
           COALESCE(task_totals.total_amount, 0.00) AS project_total_amount,
           {$paymentSelect}
    FROM projects p
    JOIN users u ON u.id = p.user_id AND u.deleted_at IS NULL
    LEFT JOIN (
      SELECT project_id, tenant_id, SUM(amount) AS total_amount
      FROM project_tasks
      GROUP BY project_id, tenant_id
    ) task_totals
      ON task_totals.project_id = p.id
     AND task_totals.tenant_id = p.tenant_id
    {$paymentJoin}
    WHERE p.status IN ({$statusPlaceholders})
      AND p.tenant_id = ?
      AND p.deleted_at IS NULL
    ORDER BY p.id DESC
  ");
  $stmt->execute(array_merge($dashboardStatuses, [$tenantId]));
  $projects = $stmt->fetchAll();

  $today = new DateTimeImmutable('today');
  $now = new DateTimeImmutable();
  $customerOutstanding = [];
  $activeProjectsCount = 0;
  $activeProjectsAgeSecondsTotal = 0;
  foreach ($projects as &$projectRow) {
    $isActiveProject = strtolower(trim((string)($projectRow['status'] ?? ''))) === 'active';
    $snapshot = project_payment_snapshot(
      (float)($projectRow['project_total_amount'] ?? 0.0),
      (float)($projectRow['paid_amount'] ?? 0.0)
    );
    $projectRow['_payment_snapshot'] = $snapshot;
    $projectOutstanding = max(0.0, (float)$snapshot['balance']);
    $projectRow['_project_outstanding'] = $projectOutstanding;

    if ($isActiveProject) {
      $activeProjectsCount++;
      $activeProjectsPaidTotal += (float)$snapshot['paid_amount'];
      $activeProjectsBalanceTotal += (float)$snapshot['balance'];
      $activeProjectsOutstandingTotal += $projectOutstanding;

      $createdAtRaw = trim((string)($projectRow['created_at'] ?? ''));
      $activeAgeSeconds = 0;
      if ($createdAtRaw !== '') {
        try {
          $createdAt = new DateTimeImmutable($createdAtRaw);
          $activeAgeSeconds = max(0, $now->getTimestamp() - $createdAt->getTimestamp());
        } catch (Throwable $e) {
          $activeAgeSeconds = 0;
        }
      }
      $projectRow['_active_for_seconds'] = $activeAgeSeconds;
      $projectRow['_active_for_label'] = project_active_duration_label($activeAgeSeconds);
      $activeProjectsAgeSecondsTotal += $activeAgeSeconds;
      if ($activeAgeSeconds > $activeProjectsLongestAgeSeconds) {
        $activeProjectsLongestAgeSeconds = $activeAgeSeconds;
      }

      if ($projectOutstanding > 0.0) {
        $arTotalOutstanding += $projectOutstanding;

        $dueDateRaw = trim((string)($projectRow['due_date'] ?? ''));
        if ($dueDateRaw !== '') {
          try {
            $dueDate = new DateTimeImmutable($dueDateRaw . ' 00:00:00');
            if ($dueDate < $today) {
              $arOverdueOutstanding += $projectOutstanding;
            }
          } catch (Throwable $e) {
            // Ignore invalid historical due date rows.
          }
        }

        $customerKey = (string)($projectRow['user_id'] ?? '');
        if (!isset($customerOutstanding[$customerKey])) {
          $customerOutstanding[$customerKey] = [
            'user_id' => (int)($projectRow['user_id'] ?? 0),
            'client_name' => (string)($projectRow['client_name'] ?? ''),
            'client_email' => (string)($projectRow['client_email'] ?? ''),
            'outstanding' => 0.0,
            'projects' => 0,
          ];
        }
        $customerOutstanding[$customerKey]['outstanding'] += $projectOutstanding;
        $customerOutstanding[$customerKey]['projects']++;
      }
    } else {
      $projectRow['_active_for_seconds'] = 0;
      $projectRow['_active_for_label'] = '-';
    }
  }
  unset($projectRow);

  $arTopCustomers = array_values($customerOutstanding);
  usort(
    $arTopCustomers,
    static function (array $a, array $b): int {
      return (float)$b['outstanding'] <=> (float)$a['outstanding'];
    }
  );
  if (count($arTopCustomers) > 10) {
    $arTopCustomers = array_slice($arTopCustomers, 0, 10);
  }
  if ($activeProjectsCount > 0) {
    $activeProjectsAvgAgeSeconds = (int)floor($activeProjectsAgeSecondsTotal / $activeProjectsCount);
  }
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

  <section class="admin-ar-panel" aria-labelledby="ar-title">
    <h2 id="ar-title">A/R Dashboard</h2>
    <div class="admin-ar-stats">
      <div class="admin-ar-stat">
        <span>Total Outstanding</span>
        <strong>$<?= number_format($arTotalOutstanding, 2) ?></strong>
      </div>
      <div class="admin-ar-stat">
        <span>Overdue Outstanding</span>
        <strong>$<?= number_format($arOverdueOutstanding, 2) ?></strong>
      </div>
      <div class="admin-ar-stat">
        <span>Top Customers</span>
        <strong><?= number_format((float)count($arTopCustomers), 0) ?></strong>
      </div>
    </div>

    <?php if (!$arTopCustomers): ?>
      <p class="admin-ar-empty">No outstanding balances on active projects.</p>
    <?php else: ?>
      <div class="admin-ar-table-wrap">
        <table class="admin-ar-table" border="1" cellpadding="8" cellspacing="0">
          <thead>
            <tr>
              <th>Customer</th>
              <th>Projects</th>
              <th>Outstanding</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($arTopCustomers as $row): ?>
              <tr>
                <td><?= e((string)$row['client_name']) ?> (<?= e((string)$row['client_email']) ?>)</td>
                <td><?= number_format((float)$row['projects'], 0) ?></td>
                <td>$<?= number_format((float)$row['outstanding'], 2) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
    <?php if (!$projectDueDateEnabled): ?>
      <p class="admin-ar-empty">Overdue uses project due dates. Due date column is not available.</p>
    <?php endif; ?>
  </section>

  <section class="admin-active-metrics-panel" aria-labelledby="active-metrics-title">
    <h2 id="active-metrics-title">Active Projects Snapshot</h2>
    <div class="admin-active-metrics-grid">
      <div class="admin-active-metric">
        <span>Current Paid</span>
        <strong>$<?= number_format($activeProjectsPaidTotal, 2) ?></strong>
      </div>
      <div class="admin-active-metric">
        <span>Total Balance</span>
        <strong>$<?= number_format($activeProjectsBalanceTotal, 2) ?></strong>
      </div>
      <div class="admin-active-metric">
        <span>Outstanding</span>
        <strong>$<?= number_format($activeProjectsOutstandingTotal, 2) ?></strong>
      </div>
      <div class="admin-active-metric">
        <span>Longest Active</span>
        <strong><?= e(project_active_duration_label($activeProjectsLongestAgeSeconds)) ?></strong>
      </div>
      <div class="admin-active-metric">
        <span>Average Active Time</span>
        <strong><?= e(project_active_duration_label($activeProjectsAvgAgeSeconds)) ?></strong>
      </div>
      <div class="admin-active-metric">
        <span>Active Projects</span>
        <strong><?= number_format((float)$activeProjectsCount, 0) ?></strong>
      </div>
    </div>
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
              <th>Total</th>
              <th>Paid</th>
              <th>Balance</th>
              <th>Payment</th>
              <th>Created</th>
              <th>Active For</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$projects): ?>
              <tr>
                <td colspan="11">No projects yet.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($projects as $p): ?>
                <?php
                  $clientPhone = security_read_user_phone($p['client_phone'] ?? null);
                  $callHref = project_phone_call_href($clientPhone);
                  $canAccessProject = $canViewProjectsAny || ($canViewProjectsOwn && (int)$p['user_id'] === $actorId);
                  $canEditProject = user_has_permission($me, 'projects.edit.any') || (user_has_permission($me, 'projects.edit.own') && (int)$p['user_id'] === $actorId);
                  $canDeleteProject = user_has_permission($me, 'projects.delete.any') || (user_has_permission($me, 'projects.delete.own') && (int)$p['user_id'] === $actorId);
                  $paymentSnapshot = $p['_payment_snapshot'] ?? project_payment_snapshot(
                    (float)($p['project_total_amount'] ?? 0.0),
                    (float)($p['paid_amount'] ?? 0.0)
                  );
                  $paymentProgressPercent = number_format((float)$paymentSnapshot['progress_percent'], 1, '.', '');
                ?>
                <tr>
                  <td><?= e((string)$p['project_no']) ?></td>
                  <td><?= e((string)$p['title']) ?></td>
                  <td><?= e((string)$p['client_name']) ?> (<?= e((string)$p['client_email']) ?>)</td>
                  <td><span class="<?= e(status_class((string)$p['status'])) ?>"><?= e((string)$p['status']) ?></span></td>
                  <td>$<?= number_format((float)$paymentSnapshot['total_amount'], 2) ?></td>
                  <td>$<?= number_format((float)$paymentSnapshot['paid_amount'], 2) ?></td>
                  <td>$<?= number_format((float)$paymentSnapshot['balance'], 2) ?></td>
                  <td>
                    <span class="<?= e(project_payment_status_class((string)$paymentSnapshot['status_key'])) ?>">
                      <?= e((string)$paymentSnapshot['status_label']) ?>
                    </span>
                    <div class="payment-progress payment-progress-<?= e((string)$paymentSnapshot['status_key']) ?>" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= e($paymentProgressPercent) ?>">
                      <span class="payment-progress-fill" style="width: <?= e($paymentProgressPercent) ?>%;"></span>
                    </div>
                    <small class="payment-progress-caption"><?= e($paymentProgressPercent) ?>%</small>
                  </td>
                  <td><?= e((string)$p['created_at']) ?></td>
                  <td><?= e((string)($p['_active_for_label'] ?? '-')) ?></td>
                  <td class="admin-project-actions-cell">
                    <div class="admin-project-actions">
                      <?php if ($canEditProject): ?>
                        <a class="admin-project-action-link" href="/admin/projects/edit.php?id=<?= (int)$p['id'] ?>">Edit</a>
                      <?php endif; ?>
                      <?php if (user_has_permission($me, 'projects.print.any') || (user_has_permission($me, 'projects.print.own') && (int)$p['user_id'] === $actorId)): ?>
                        <a class="admin-project-action-link" href="/admin/projects/print.php?id=<?= (int)$p['id'] ?>&autoprint=1" target="_blank" rel="noopener">Print PDF</a>
                      <?php endif; ?>
                      <?php if ($canAccessProject): ?>
                        <a class="admin-project-action-link" href="/admin/projects/email_compose.php?id=<?= (int)$p['id'] ?>" target="_blank" rel="noopener">Email</a>
                      <?php endif; ?>
                      <?php if ($callHref !== null): ?>
                        <a class="admin-project-action-link" href="<?= e_url_attr($callHref) ?>">Call</a>
                      <?php endif; ?>
                      <?php if ($canDeleteProject): ?>
                        <form method="post" action="/admin/projects/delete.php" class="admin-project-inline-form">
                          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                          <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                          <input type="hidden" name="return_to" value="/admin/dashboard.php">
                          <button
                            class="admin-project-action-link admin-project-action-button admin-project-action-delete"
                            type="submit"
                            data-confirm="Delete this project? You can restore it later."
                            aria-label="Delete project"
                            title="Delete project"
                          >
                            <i class="bi bi-trash3" aria-hidden="true"></i>
                          </button>
                        </form>
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
