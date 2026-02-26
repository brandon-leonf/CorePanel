<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../../config/db.php';
require __DIR__ . '/../../../src/auth.php';
require __DIR__ . '/../../../src/helpers.php';
require __DIR__ . '/../../../src/layout.php';
require __DIR__ . '/../../../src/security.php';
require __DIR__ . '/../../../src/project_payments.php';

$me = require_any_permission($pdo, ['projects.view.any', 'projects.view.own']);
$tenantId = actor_tenant_id($me);
$userId = (int)($me['id'] ?? 0);
$canViewAnyProjects = user_has_permission($me, 'projects.view.any');

if (!ensure_project_payments_table($pdo)) {
  http_response_code(503);
  exit('Payments are not available yet.');
}
ensure_project_payment_receipts_table($pdo);
ensure_project_address_column($pdo);

$projectId = (int)($_GET['project_id'] ?? 0);
$paymentId = (int)($_GET['payment_id'] ?? 0);
$autoPrint = (string)($_GET['autoprint'] ?? '') === '1';
if ($projectId <= 0 || $paymentId <= 0) {
  http_response_code(400);
  exit('Bad request');
}

require_project_access($pdo, $me, $projectId, 'view');

$ownershipFilterSql = $canViewAnyProjects ? '' : 'AND p.user_id = ?';
$params = [$paymentId, $projectId, $tenantId];
if (!$canViewAnyProjects) {
  $params[] = $userId;
}

$stmt = $pdo->prepare(
  "SELECT
      p.id AS project_id,
      p.project_no,
      p.title AS project_title,
      p.project_address,
      client.name AS client_name,
      client.email AS client_email,
      pp.id AS payment_id,
      pp.received_at,
      pp.amount,
      pp.method,
      pp.reference,
      pp.note,
      pp.created_at,
      creator.name AS received_by_name,
      creator.email AS received_by_email,
      COALESCE(r.receipt_no, '') AS receipt_no
   FROM project_payments pp
   JOIN projects p ON p.id = pp.project_id
   JOIN users client ON client.id = p.user_id
   LEFT JOIN users creator ON creator.id = pp.created_by
   LEFT JOIN project_payment_receipts r
     ON r.payment_id = pp.id
    AND r.tenant_id = pp.tenant_id
   WHERE pp.id = ?
     AND pp.project_id = ?
     AND pp.tenant_id = ?
     AND p.tenant_id = pp.tenant_id
     AND p.deleted_at IS NULL
     AND pp.deleted_at IS NULL
     {$ownershipFilterSql}
   LIMIT 1"
);
$stmt->execute($params);
$receipt = $stmt->fetch();

if (!$receipt) {
  http_response_code(404);
  exit('Receipt not found');
}

$receipt['project_address'] = security_read_project_address($receipt['project_address'] ?? null);
$receiptNo = trim((string)($receipt['receipt_no'] ?? ''));
if ($receiptNo === '') {
  $receiptNo = 'Pending';
}

$receivedBy = trim((string)($receipt['received_by_name'] ?? ''));
if ($receivedBy === '') {
  $receivedBy = trim((string)($receipt['received_by_email'] ?? ''));
}
if ($receivedBy === '') {
  $receivedBy = 'CorePanel Admin';
}

render_header('Payment Receipt ' . $receiptNo . ' • CorePanel');
?>
<div class="container client-receipt-page">
  <div class="client-receipt-controls">
    <a href="<?= $canViewAnyProjects ? '/admin/dashboard.php' : ('/client/projects/view.php?id=' . (int)$receipt['project_id']) ?>">
      <?= $canViewAnyProjects ? '← Back to Dashboard' : '← Back to Project' ?>
    </a>
    <button type="button" data-print-window>Print Receipt</button>
  </div>

  <section class="client-receipt-sheet" aria-labelledby="client-receipt-title">
    <h1 id="client-receipt-title">Payment Receipt</h1>
    <p class="client-receipt-subtitle">CorePanel Project Payment Record</p>

    <table class="client-receipt-table" border="1" cellpadding="8" cellspacing="0">
      <tbody>
        <tr>
          <th>Receipt Number</th>
          <td><?= e($receiptNo) ?></td>
          <th>Payment Date</th>
          <td><?= e((string)$receipt['received_at']) ?></td>
        </tr>
        <tr>
          <th>Project</th>
          <td><?= e((string)$receipt['project_no']) ?> - <?= e((string)$receipt['project_title']) ?></td>
          <th>Amount Paid</th>
          <td>$<?= number_format((float)$receipt['amount'], 2) ?></td>
        </tr>
        <tr>
          <th>Payment Method</th>
          <td><?= e(project_payment_method_label((string)$receipt['method'])) ?></td>
          <th>Reference</th>
          <td><?= e((string)($receipt['reference'] ?? '')) ?></td>
        </tr>
        <tr>
          <th>Received From</th>
          <td><?= e((string)$receipt['client_name']) ?> (<?= e((string)$receipt['client_email']) ?>)</td>
          <th>Received By</th>
          <td><?= e($receivedBy) ?></td>
        </tr>
        <tr>
          <th>Project Address</th>
          <td colspan="3"><?= nl2br(e((string)($receipt['project_address'] ?? ''))) ?></td>
        </tr>
        <tr>
          <th>Additional Info</th>
          <td colspan="3"><?= nl2br(e((string)($receipt['note'] ?? ''))) ?></td>
        </tr>
      </tbody>
    </table>

    <p class="client-receipt-thankyou">Thank you</p>
  </section>
</div>

<?php if ($autoPrint): ?>
  <script>
    window.addEventListener('load', function () {
      window.print();
    });
  </script>
<?php endif; ?>
<?php render_footer(); ?>
