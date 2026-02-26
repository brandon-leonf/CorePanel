<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../../config/db.php';
require __DIR__ . '/../../../src/auth.php';
require __DIR__ . '/../../../src/helpers.php';
require __DIR__ . '/../../../src/security.php';
require __DIR__ . '/../../../src/rate_limit.php';

$me = require_any_permission($pdo, ['projects.print.any', 'projects.print.own']);
send_security_headers(true);
ensure_project_notes_column($pdo);
ensure_project_address_column($pdo);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  exit('Bad request');
}
require_project_access($pdo, $me, $id, 'print');
$tenantId = actor_tenant_id($me);
$autoPrint = (string)($_GET['autoprint'] ?? '') === '1';

$pstmt = $pdo->prepare(
  "SELECT
     p.id,
     p.project_no,
     p.title,
     p.description,
     p.notes,
     p.project_address,
     p.status,
     p.created_at,
     u.name AS client_name,
     u.email AS client_email
   FROM projects p
   JOIN users u ON u.id = p.user_id
   WHERE p.id = ? AND p.tenant_id = ?
   LIMIT 1"
);
$pstmt->execute([$id, $tenantId]);
$project = $pstmt->fetch();
if (!$project) {
  http_response_code(404);
  exit('Project not found');
}

rl_record_download_activity($pdo, $me, 'project_export_print', 'project:' . $id);

$project['notes'] = security_read_project_notes($project['notes'] ?? null);
$project['project_address'] = security_read_project_address($project['project_address'] ?? null);

$tstmt = $pdo->prepare("
  SELECT t.task_title, t.task_description, t.rate, t.quantity, t.amount, t.status
  FROM project_tasks t
  JOIN projects p ON p.id = t.project_id
  WHERE t.project_id = ?
    AND t.tenant_id = ?
    AND p.tenant_id = ?
  ORDER BY t.id ASC
");
$tstmt->execute([$id, $tenantId, $tenantId]);
$tasks = $tstmt->fetchAll();

$total = 0.0;
foreach ($tasks as $task) {
  $total += (float)$task['amount'];
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Project <?= e((string)$project['project_no']) ?> • Print</title>
  <style>
    :root {
      color-scheme: light;
    }
    * {
      box-sizing: border-box;
    }
    body {
      margin: 0;
      padding: 24px;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      background: #f5f7fb;
      color: #111827;
    }
    .toolbar {
      display: flex;
      gap: 12px;
      margin: 0 auto 18px;
      max-width: 980px;
    }
    .toolbar a,
    .toolbar button {
      border: 1px solid #cbd5e1;
      background: #fff;
      color: #111827;
      border-radius: 8px;
      padding: 8px 14px;
      font-size: 14px;
      text-decoration: none;
      cursor: pointer;
    }
    .sheet {
      max-width: 980px;
      margin: 0 auto;
      background: #fff;
      border: 1px solid #dbe1ea;
      border-radius: 12px;
      padding: 24px;
    }
    h1 {
      margin: 0 0 14px;
      font-size: 28px;
    }
    .meta {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 10px;
      margin-bottom: 18px;
    }
    .meta-item {
      border: 1px solid #e5e7eb;
      border-radius: 10px;
      padding: 10px;
      background: #fafafa;
    }
    .meta-item strong {
      display: block;
      font-size: 12px;
      color: #4b5563;
      margin-bottom: 4px;
      text-transform: uppercase;
      letter-spacing: 0.03em;
    }
    .section-title {
      margin: 22px 0 10px;
      font-size: 18px;
    }
    .description {
      margin: 0;
      line-height: 1.5;
      white-space: normal;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 8px;
    }
    th,
    td {
      border: 1px solid #d1d5db;
      padding: 8px;
      text-align: left;
      vertical-align: top;
      font-size: 14px;
    }
    th {
      background: #f3f4f6;
    }
    .number {
      text-align: right;
      white-space: nowrap;
    }
    .total {
      margin-top: 12px;
      text-align: right;
      font-size: 16px;
    }
    @media print {
      body {
        background: #fff;
        padding: 0;
      }
      .toolbar {
        display: none;
      }
      .sheet {
        max-width: 100%;
        border: 0;
        border-radius: 0;
        padding: 0;
      }
    }
  </style>
</head>
<body<?= $autoPrint ? ' data-autoprint="1"' : '' ?>>
  <div class="toolbar">
    <a href="/admin/dashboard.php">Back to Dashboard</a>
    <button type="button" data-print-window="1">Print / Save PDF</button>
  </div>

  <main class="sheet">
    <h1><?= e((string)$project['title']) ?></h1>

    <div class="meta">
      <div class="meta-item">
        <strong>Project #</strong>
        <?= e((string)$project['project_no']) ?>
      </div>
      <div class="meta-item">
        <strong>Created</strong>
        <?= e((string)$project['created_at']) ?>
      </div>
      <div class="meta-item">
        <strong>Client</strong>
        <?= e((string)$project['client_name']) ?> (<?= e((string)$project['client_email']) ?>)
      </div>
    </div>

    <?php if (!empty($project['description'])): ?>
      <h2 class="section-title">Description</h2>
      <p class="description"><?= nl2br(e((string)$project['description'])) ?></p>
    <?php endif; ?>

    <?php if (!empty($project['project_address'])): ?>
      <h2 class="section-title">Project Address</h2>
      <p class="description"><?= nl2br(e((string)$project['project_address'])) ?></p>
    <?php endif; ?>

    <?php if (!empty($project['notes'])): ?>
      <h2 class="section-title">Notes</h2>
      <p class="description"><?= nl2br(e((string)$project['notes'])) ?></p>
    <?php endif; ?>

    <h2 class="section-title">Tasks</h2>
    <?php if (!$tasks): ?>
      <p>No tasks available for this project.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Task</th>
            <th class="number">Rate</th>
            <th class="number">Qty</th>
            <th class="number">Amount</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tasks as $task): ?>
            <tr>
              <td>
                <strong><?= e((string)$task['task_title']) ?></strong><br>
                <?= e((string)($task['task_description'] ?? '')) ?>
              </td>
              <td class="number"><?= number_format((float)$task['rate'], 2) ?></td>
              <td class="number"><?= number_format((float)$task['quantity'], 2) ?></td>
              <td class="number"><?= number_format((float)$task['amount'], 2) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p class="total"><strong>Total:</strong> $<?= number_format($total, 2) ?></p>
    <?php endif; ?>
  </main>
  <script src="/assets/js/app.js" defer></script>
</body>
</html>
