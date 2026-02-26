<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../../config/db.php';
require __DIR__ . '/../../../src/auth.php';
require __DIR__ . '/../../../src/helpers.php';
require __DIR__ . '/../../../src/layout.php';
require __DIR__ . '/../../../src/security.php';
require __DIR__ . '/../../../src/upload.php';
require __DIR__ . '/../../../src/project_payments.php';

$me = require_permission($pdo, 'projects.view.own');
if (user_has_permission($me, 'dashboard.admin.view')) redirect('/admin/dashboard.php');
ensure_project_notes_column($pdo);
ensure_project_address_column($pdo);

$userId = (int)$me['id'];
$tenantId = actor_tenant_id($me);
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('Bad request'); }
require_project_access($pdo, $me, $id, 'view');

$pstmt = $pdo->prepare(
  "SELECT id, project_no, title, description, notes, project_address, status, created_at
   FROM projects
   WHERE id = ? AND user_id = ? AND tenant_id = ?
   LIMIT 1"
);
$pstmt->execute([$id, $userId, $tenantId]);
$project = $pstmt->fetch();
if (!$project) { http_response_code(404); exit('Project not found'); }
$project['notes'] = security_read_project_notes($project['notes'] ?? null);
$project['project_address'] = security_read_project_address($project['project_address'] ?? null);
$errors = [];
$canUploadDocuments = true;
$projectPaymentsAvailable = ensure_project_payments_table($pdo);
$projectPaymentReceiptsEnabled = ensure_project_payment_receipts_table($pdo);
$projectPdfMaxBytes = upload_max_pdf_bytes();
$projectServerUploadLimitBytes = upload_effective_server_limit_bytes();
if ($projectServerUploadLimitBytes > 0) {
  $projectPdfMaxBytes = min($projectPdfMaxBytes, $projectServerUploadLimitBytes);
}
$projectPdfMaxLabel = upload_human_bytes($projectPdfMaxBytes);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_project_documents') {
  if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token');
  }
  if (!$canUploadDocuments) {
    http_response_code(403);
    exit('Forbidden');
  }

  $files = $_FILES['project_documents'] ?? null;
  if (!is_array($files) || !isset($files['error']) || !is_array($files['error'])) {
    $errors[] = 'Select at least one PDF to upload.';
  } else {
    $hasDocument = false;

    try {
      $insDoc = $pdo->prepare("
        INSERT INTO project_images (project_id, tenant_id, image_path)
        VALUES (?, ?, ?)
      ");

      foreach (array_keys($files['error']) as $idx) {
        $fileError = (int)($files['error'][$idx] ?? UPLOAD_ERR_NO_FILE);
        if ($fileError === UPLOAD_ERR_NO_FILE) {
          continue;
        }

        $hasDocument = true;
        $file = [
          'name' => $files['name'][$idx] ?? '',
          'type' => $files['type'][$idx] ?? '',
          'tmp_name' => $files['tmp_name'][$idx] ?? '',
          'error' => $fileError,
          'size' => $files['size'][$idx] ?? 0,
        ];
        [$docPath, $uploadErr] = upload_project_pdf($file);
        if ($uploadErr !== null) {
          $errors[] = 'PDF upload failed: ' . $uploadErr;
          continue;
        }

        $insDoc->execute([$id, $tenantId, $docPath]);
      }
    } catch (Throwable $e) {
      $errors[] = 'Project files are not available yet.';
    }

    if (!$hasDocument) {
      $errors[] = 'Select at least one PDF to upload.';
    }
    if (!$errors) {
      redirect('/client/projects/view.php?id=' . $id);
    }
  }
}

$tstmt = $pdo->prepare(
  "SELECT t.*
   FROM project_tasks t
   JOIN projects p ON p.id = t.project_id
   WHERE t.project_id = ?
     AND t.tenant_id = ?
     AND p.user_id = ?
     AND p.tenant_id = ?
   ORDER BY t.id DESC"
);
$tstmt->execute([$id, $tenantId, $userId, $tenantId]);
$tasks = $tstmt->fetchAll();
$projectPayments = [];
$projectImages = [];
$projectDocuments = [];
$projectImagesAvailable = true;

try {
  $imgStmt = $pdo->prepare("
    SELECT i.id, i.image_path, i.created_at
    FROM project_images i
    JOIN projects p ON p.id = i.project_id
    WHERE i.project_id = ?
      AND i.tenant_id = ?
      AND p.user_id = ?
      AND p.tenant_id = ?
    ORDER BY i.id DESC
  ");
  $imgStmt->execute([$id, $tenantId, $userId, $tenantId]);
  $projectMedia = $imgStmt->fetchAll();
  foreach ($projectMedia as $media) {
    $reference = (string)($media['image_path'] ?? '');
    if (upload_reference_is_pdf($reference)) {
      $projectDocuments[] = $media;
    } elseif (upload_reference_is_image($reference)) {
      $projectImages[] = $media;
    }
  }
} catch (Throwable $e) {
  $projectImagesAvailable = false;
}

$total = 0.00;
foreach ($tasks as $t) $total += (float)$t['amount'];
$paidAmount = 0.00;

if ($projectPaymentsAvailable) {
  try {
    $receiptSelect = $projectPaymentReceiptsEnabled
      ? 'r.receipt_no'
      : 'NULL AS receipt_no';
    $receiptJoin = $projectPaymentReceiptsEnabled
      ? 'LEFT JOIN project_payment_receipts r ON r.payment_id = pp.id'
      : '';
    $paymentStmt = $pdo->prepare(
      "SELECT pp.id, pp.received_at, pp.amount, pp.method, pp.reference, pp.note, {$receiptSelect}
       FROM project_payments pp
       JOIN projects p ON p.id = pp.project_id
       {$receiptJoin}
       WHERE pp.project_id = ?
         AND pp.tenant_id = ?
         AND p.user_id = ?
         AND p.tenant_id = ?
       ORDER BY pp.received_at DESC, pp.id DESC"
    );
    $paymentStmt->execute([$id, $tenantId, $userId, $tenantId]);
    $projectPayments = $paymentStmt->fetchAll() ?: [];
  } catch (Throwable $e) {
    $projectPaymentsAvailable = false;
  }
}

foreach ($projectPayments as $paymentRow) {
  $paidAmount += (float)($paymentRow['amount'] ?? 0.0);
}
$paymentSnapshot = project_payment_snapshot($total, $paidAmount);
$paymentProgressPercent = number_format((float)$paymentSnapshot['progress_percent'], 1, '.', '');

render_header('Project ' . $project['project_no'] . ' • CorePanel');
?>
<div class="container container-wide client-project-view-page">
  <div class="client-project-view-header">
    <p><a href="/client/projects/index.php">← My Projects</a></p>
    <h1><?= e($project['project_no']) ?> — <?= e($project['title']) ?></h1>
    <p class="client-project-view-status">Status: <strong class="<?= e(status_class((string)$project['status'])) ?>"><?= e((string)$project['status']) ?></strong></p>
  </div>

  <?php if ($errors): ?>
    <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>

  <?php if (!empty($project['description'])): ?>
    <p class="client-project-view-description"><?= nl2br(e((string)$project['description'])) ?></p>
  <?php endif; ?>

  <?php if (!empty($project['project_address'])): ?>
    <p class="client-project-view-description"><strong>Project Address:</strong><br><?= nl2br(e((string)$project['project_address'])) ?></p>
  <?php endif; ?>

  <section class="client-project-view-tasks-section" aria-labelledby="client-project-tasks-title">
    <h2 id="client-project-tasks-title">Tasks</h2>
    <?php if (!$tasks): ?>
      <p>No tasks added yet.</p>
    <?php else: ?>
      <div class="client-project-view-tasks-wrap">
        <table class="client-project-view-tasks-table" border="1" cellpadding="8" cellspacing="0">
          <thead>
            <tr><th>Task</th><th>Rate</th><th>Qty</th><th>Amount</th><th>Status</th></tr>
          </thead>
          <tbody>
            <?php foreach ($tasks as $t): ?>
              <tr>
                <td>
                  <strong><?= e($t['task_title']) ?></strong><br>
                  <small><?= e((string)($t['task_description'] ?? '')) ?></small>
                </td>
                <td><?= number_format((float)$t['rate'], 2) ?></td>
                <td><?= number_format((float)$t['quantity'], 2) ?></td>
                <td><?= number_format((float)$t['amount'], 2) ?></td>
                <td><span class="<?= e(status_class((string)$t['status'])) ?>"><?= e((string)$t['status']) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <p class="client-project-view-total"><strong>Total:</strong> $<?= number_format((float)$total, 2) ?></p>
    <?php endif; ?>
  </section>

  <section class="client-project-view-payments-section" aria-labelledby="client-project-payments-title">
    <h2 id="client-project-payments-title">Payments & Receipts</h2>
    <?php if (!$projectPaymentsAvailable): ?>
      <p class="client-project-view-images-note">Payments are not available yet.</p>
    <?php else: ?>
      <div class="client-project-view-payments-summary">
        <div class="client-project-view-payments-stat">
          <span>Received</span>
          <strong>$<?= number_format((float)$paymentSnapshot['paid_amount'], 2) ?></strong>
        </div>
        <div class="client-project-view-payments-stat">
          <span>Balance</span>
          <strong>$<?= number_format((float)$paymentSnapshot['balance'], 2) ?></strong>
        </div>
        <div class="client-project-view-payments-stat">
          <span>Status</span>
          <strong class="<?= e(project_payment_status_class((string)$paymentSnapshot['status_key'])) ?>">
            <?= e((string)$paymentSnapshot['status_label']) ?>
          </strong>
        </div>
      </div>
      <div class="client-project-view-payments-progress-row">
        <div class="payment-progress payment-progress-<?= e((string)$paymentSnapshot['status_key']) ?>" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= e($paymentProgressPercent) ?>">
          <span class="payment-progress-fill" style="width: <?= e($paymentProgressPercent) ?>%;"></span>
        </div>
        <small class="payment-progress-caption"><?= e($paymentProgressPercent) ?>%</small>
      </div>

      <div class="client-project-view-payments-wrap">
        <table class="client-project-view-payments-table" border="1" cellpadding="8" cellspacing="0">
          <thead>
            <tr>
              <th>Date</th>
              <th>Amount</th>
              <th>Method</th>
              <th>Reference</th>
              <th>Note</th>
              <th>Receipt #</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$projectPayments): ?>
              <tr>
                <td colspan="7">No payments recorded yet.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($projectPayments as $payment): ?>
                <?php
                  $receiptNo = trim((string)($payment['receipt_no'] ?? ''));
                  $receiptUrl = '/client/projects/receipt.php?project_id=' . (int)$id . '&payment_id=' . (int)$payment['id'];
                  $receiptPrintUrl = $receiptUrl . '&autoprint=1';
                ?>
                <tr>
                  <td><?= e((string)$payment['received_at']) ?></td>
                  <td>$<?= number_format((float)$payment['amount'], 2) ?></td>
                  <td><?= e(project_payment_method_label((string)$payment['method'])) ?></td>
                  <td><?= e((string)($payment['reference'] ?? '')) ?></td>
                  <td><?= e((string)($payment['note'] ?? '')) ?></td>
                  <td><?= $receiptNo !== '' ? e($receiptNo) : 'Pending' ?></td>
                  <td class="client-project-view-payment-actions">
                    <a href="<?= e_url_attr($receiptUrl) ?>" target="_blank" rel="noopener">View</a>
                    <a href="<?= e_url_attr($receiptPrintUrl) ?>" target="_blank" rel="noopener">Print</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <?php if (!empty($project['notes'])): ?>
    <section class="client-project-view-notes-section" aria-labelledby="client-project-notes-title">
      <h2 id="client-project-notes-title">Notes</h2>
      <p class="client-project-view-notes"><?= nl2br(e((string)$project['notes'])) ?></p>
    </section>
  <?php endif; ?>

  <?php if ($projectImagesAvailable): ?>
    <section class="client-project-view-images-section" aria-labelledby="client-project-images-title">
      <h2 id="client-project-images-title">Images</h2>
      <?php if (!$projectImages): ?>
        <p class="client-project-view-images-note">No images uploaded for this project yet.</p>
      <?php else: ?>
        <div class="client-project-view-images-grid">
          <?php foreach ($projectImages as $img): ?>
            <?php $viewUrl = media_file_url((string)$img['image_path']) ?? '#'; ?>
            <?php $downloadUrl = media_file_url((string)$img['image_path'], true) ?? '#'; ?>
            <div class="client-project-view-image-card">
              <a href="<?= e_url_attr($viewUrl) ?>" target="_blank" rel="noopener">
                <img src="<?= e_url_attr($viewUrl, 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==') ?>" alt="Project image">
              </a>
              <div class="client-project-view-image-actions">
                <a href="<?= e_url_attr($viewUrl) ?>" target="_blank" rel="noopener">View</a>
                <a href="<?= e_url_attr($downloadUrl) ?>">Download</a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="client-project-view-images-section" aria-labelledby="client-project-docs-title">
      <h2 id="client-project-docs-title">Project PDFs</h2>
      <?php if ($canUploadDocuments): ?>
        <form method="post" enctype="multipart/form-data" class="client-project-view-upload-form">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="add_project_documents">
          <label>Upload PDF Files<br>
            <input type="file" name="project_documents[]" accept=".pdf,application/pdf" multiple>
          </label>
          <p class="client-project-view-images-note">Max PDF size: <?= e($projectPdfMaxLabel) ?></p>
          <button type="submit">Upload PDFs</button>
        </form>
      <?php endif; ?>
      <?php if (!$projectDocuments): ?>
        <p class="client-project-view-images-note">No PDF files uploaded for this project yet.</p>
      <?php else: ?>
        <div class="client-project-view-documents-list">
          <?php foreach ($projectDocuments as $doc): ?>
            <?php $docViewUrl = media_file_url((string)$doc['image_path']) ?? '#'; ?>
            <?php $docDownloadUrl = media_file_url((string)$doc['image_path'], true) ?? '#'; ?>
            <?php $docName = upload_reference_filename((string)$doc['image_path']) ?? ('Document #' . (int)$doc['id']); ?>
            <div class="client-project-view-document-item">
              <strong><?= e($docName) ?></strong>
              <div class="client-project-view-document-actions">
                <a href="<?= e_url_attr($docViewUrl) ?>" target="_blank" rel="noopener">View</a>
                <a href="<?= e_url_attr($docDownloadUrl) ?>">Download</a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
