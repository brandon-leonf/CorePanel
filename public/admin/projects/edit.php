<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../../config/db.php';
require __DIR__ . '/../../../src/auth.php';
require __DIR__ . '/../../../src/helpers.php';
require __DIR__ . '/../../../src/layout.php';
require __DIR__ . '/../../../src/upload.php';
require __DIR__ . '/../../../src/validation.php';
require __DIR__ . '/../../../src/security.php';
require __DIR__ . '/../../../src/project_payments.php';
require __DIR__ . '/../../../src/admin_audit.php';

$me = require_any_permission($pdo, ['projects.edit.any', 'projects.edit.own']);
$tenantId = actor_tenant_id($me);
$projectNotesEnabled = ensure_project_notes_column($pdo);
$projectAddressEnabled = ensure_project_address_column($pdo);
$projectDueDateEnabled = ensure_project_due_date_column($pdo);
security_prepare_sensitive_storage($pdo);
$projectStatuses = project_statuses($pdo);
$projectPaymentsEnabled = ensure_project_payments_table($pdo);
$projectPaymentReceiptsEnabled = ensure_project_payment_receipts_table($pdo);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('Bad request'); }
$projectScope = require_project_access($pdo, $me, $id, 'edit');
$ownsProject = (int)$projectScope['user_id'] === (int)$me['id'];
$canManageTasks = user_has_permission($me, 'project_tasks.edit.any') || ($ownsProject && user_has_permission($me, 'project_tasks.edit.own'));
$canDeleteTasks = user_has_permission($me, 'project_tasks.delete.any') || ($ownsProject && user_has_permission($me, 'project_tasks.delete.own'));
$canManageImages = user_has_permission($me, 'project_images.manage.any') || ($ownsProject && user_has_permission($me, 'project_images.manage.own'));
$canCreatePayments = user_has_permission($me, 'payments.create');
$canManagePayments = $canCreatePayments;

$pstmt = $pdo->prepare("
  SELECT p.*, u.name AS client_name, u.email AS client_email
  FROM projects p
  JOIN users u ON u.id = p.user_id
  WHERE p.id = ?
    AND p.tenant_id = ?
    AND p.deleted_at IS NULL
    AND u.deleted_at IS NULL
  LIMIT 1
");
$pstmt->execute([$id, $tenantId]);
$project = $pstmt->fetch();
if (!$project) { http_response_code(404); exit('Project not found'); }

$errors = [];
$title = (string)$project['title'];
$description = (string)($project['description'] ?? '');
$notes = (string)(security_read_project_notes($project['notes'] ?? null) ?? '');
$projectAddress = (string)(security_read_project_address($project['project_address'] ?? null) ?? '');
$dueDateInput = (string)($project['due_date'] ?? '');
$status = (string)$project['status'];
$projectPdfMaxBytes = upload_max_pdf_bytes();
$projectServerUploadLimitBytes = upload_effective_server_limit_bytes();
if ($projectServerUploadLimitBytes > 0) {
  $projectPdfMaxBytes = min($projectPdfMaxBytes, $projectServerUploadLimitBytes);
}
$projectPdfMaxLabel = upload_human_bytes($projectPdfMaxBytes);
$projectImages = [];
$projectDocuments = [];
$deletedProjectImages = [];
$deletedProjectDocuments = [];
$projectImagesEnabled = true;
$projectImagesError = '';
$projectPayments = [];
$deletedProjectPayments = [];
$projectPaymentsError = '';
$paymentReceivedAtInput = (new DateTimeImmutable())->format('Y-m-d\TH:i');
$paymentAmountInput = '';
$paymentMethodInput = 'cash';
$paymentReferenceInput = '';
$paymentNoteInput = '';
$sendReceiptEmailInput = isset($_POST['send_receipt_email']);
$flashInfo = [];
if (isset($_GET['receipt']) && trim((string)$_GET['receipt']) !== '') {
  $flashInfo[] = 'Receipt generated: ' . trim((string)$_GET['receipt']);
}
if (isset($_GET['receipt_email'])) {
  $receiptEmailStatus = trim((string)$_GET['receipt_email']);
  if ($receiptEmailStatus === 'sent') {
    $flashInfo[] = 'Receipt email sent successfully.';
  } elseif ($receiptEmailStatus === 'failed') {
    $flashInfo[] = 'Receipt generated, but email could not be sent.';
  }
}

try {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS project_images (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      project_id INT UNSIGNED NOT NULL,
      tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
      image_path VARCHAR(255) NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      deleted_at DATETIME NULL,
      INDEX idx_project_images_project_id (project_id),
      INDEX idx_project_images_tenant_id (tenant_id),
      INDEX idx_project_images_deleted_at (deleted_at),
      CONSTRAINT fk_project_images_project
        FOREIGN KEY (project_id) REFERENCES projects(id)
        ON DELETE CASCADE
    ) ENGINE=InnoDB
  ");
  if (!db_has_column($pdo, 'project_images', 'deleted_at')) {
    $pdo->exec("ALTER TABLE project_images ADD COLUMN deleted_at DATETIME NULL AFTER created_at");
  }
  try {
    $pdo->exec("ALTER TABLE project_images ADD INDEX idx_project_images_deleted_at (deleted_at)");
  } catch (Throwable $e) {
    // Index may already exist.
  }
} catch (Throwable $e) {
  $projectImagesEnabled = false;
  $projectImagesError = 'Project images are not available yet.';
}

/** Update project meta */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_project') {
  if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token');
  }

  $title = normalize_single_line((string)($_POST['title'] ?? ''));
  $description = normalize_multiline((string)($_POST['description'] ?? ''));
  $notes = normalize_multiline((string)($_POST['notes'] ?? ''));
  $projectAddress = normalize_multiline((string)($_POST['project_address'] ?? ''));
  $dueDateInput = trim((string)($_POST['due_date'] ?? ''));
  $status = (string)($_POST['status'] ?? 'active');

  validate_required_text($title, 'Title', 190, $errors);
  validate_optional_text($description, 'Description', 10000, $errors);
  if ($projectNotesEnabled) {
    validate_optional_text($notes, 'Notes', 5000, $errors);
  }
  if ($projectAddressEnabled) {
    validate_optional_text($projectAddress, 'Project address', 2000, $errors);
  }
  $dueDateValue = null;
  if ($projectDueDateEnabled && $dueDateInput !== '') {
    $dueDate = DateTimeImmutable::createFromFormat('Y-m-d', $dueDateInput);
    $dueErrors = DateTimeImmutable::getLastErrors();
    $dueWarnings = is_array($dueErrors) ? (int)($dueErrors['warning_count'] ?? 0) : 0;
    $dueErrorCount = is_array($dueErrors) ? (int)($dueErrors['error_count'] ?? 0) : 0;
    if (
      !($dueDate instanceof DateTimeImmutable) ||
      $dueWarnings > 0 ||
      $dueErrorCount > 0
    ) {
      $errors[] = 'Due date must be a valid date.';
    } else {
      $dueDateValue = $dueDate->format('Y-m-d');
    }
  }
  if (!in_array($status, $projectStatuses, true)) $errors[] = 'Invalid status.';

  if (!$errors) {
    $notesStored = security_store_project_notes($notes === '' ? null : $notes);
    $projectAddressStored = security_store_project_address($projectAddress === '' ? null : $projectAddress);
    $setClauses = ['title = ?', 'description = ?'];
    $params = [$title, $description === '' ? null : $description];
    if ($projectNotesEnabled) {
      $setClauses[] = 'notes = ?';
      $params[] = $notesStored;
    }
    if ($projectAddressEnabled) {
      $setClauses[] = 'project_address = ?';
      $params[] = $projectAddressStored;
    }
    if ($projectDueDateEnabled) {
      $setClauses[] = 'due_date = ?';
      $params[] = $dueDateValue;
    }
    $setClauses[] = 'status = ?';
    $params[] = $status;
    $params[] = $id;
    $params[] = $tenantId;

    $up = $pdo->prepare(
      'UPDATE projects SET ' . implode(', ', $setClauses) . ' WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL'
    );
    $up->execute($params);
    redirect('/admin/projects/edit.php?id=' . $id);
  }
}

/** Add task */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_task') {
  if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token');
  }
  if (!$canManageTasks) {
    http_response_code(403);
    exit('Forbidden');
  }

  $taskTitle = normalize_single_line((string)($_POST['task_title'] ?? ''));
  $taskDesc  = normalize_multiline((string)($_POST['task_description'] ?? ''));
  $rate = validate_decimal_input($_POST['rate'] ?? '', 'Rate', 0.0, 99999999.99, $errors);
  $qty  = validate_decimal_input($_POST['quantity'] ?? '', 'Quantity', 0.0, 99999999.99, $errors);

  validate_required_text($taskTitle, 'Task title', 190, $errors);
  validate_optional_text($taskDesc, 'Task description', 5000, $errors);

  $amount = ($rate !== null && $qty !== null) ? round($rate * $qty, 2) : 0.0;

  if (!$errors) {
    $ins = $pdo->prepare("
      INSERT INTO project_tasks (project_id, tenant_id, task_title, task_description, rate, quantity, amount)
      VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $ins->execute([
      $id,
      $tenantId,
      $taskTitle,
      $taskDesc === '' ? null : $taskDesc,
      $rate,
      $qty,
      $amount
    ]);
    redirect('/admin/projects/edit.php?id=' . $id);
  }
}

/** Add project payment */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_project_payment') {
  if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token');
  }
  if (!$canCreatePayments) {
    http_response_code(403);
    exit('Forbidden');
  }
  if ((int)($project['tenant_id'] ?? 0) !== $tenantId) {
    http_response_code(403);
    exit('Forbidden');
  }

  if (!$projectPaymentsEnabled) {
    $errors[] = 'Payments are not available yet.';
  } else {
    $sendReceiptEmailInput = isset($_POST['send_receipt_email']);
    $paymentReceivedAtInput = trim((string)($_POST['payment_received_at'] ?? ''));
    $paymentAmountInput = trim((string)($_POST['payment_amount'] ?? ''));
    $paymentMethodInput = strtolower(trim((string)($_POST['payment_method'] ?? 'other')));
    $paymentReferenceInput = normalize_single_line((string)($_POST['payment_reference'] ?? ''));
    $paymentNoteInput = normalize_multiline((string)($_POST['payment_note'] ?? ''));

    $paymentReceivedAt = project_payment_parse_received_at($paymentReceivedAtInput, $errors);
    $paymentAmount = validate_decimal_input($paymentAmountInput, 'Payment amount', 0.01, 99999999.99, $errors);
    if ($paymentReceivedAt !== null) {
      try {
        $receivedAtDate = new DateTimeImmutable($paymentReceivedAt);
        $nowWithTolerance = (new DateTimeImmutable())->modify('+5 minutes');
        if ($receivedAtDate > $nowWithTolerance) {
          $errors[] = 'Date received cannot be in the future.';
        }
      } catch (Throwable $e) {
        $errors[] = 'Invalid date received.';
      }
    }
    if (!in_array($paymentMethodInput, project_payment_methods(), true)) {
      $errors[] = 'Invalid payment method.';
    }
    validate_optional_text($paymentReferenceInput, 'Payment reference', 190, $errors);
    validate_optional_text($paymentNoteInput, 'Payment note', 5000, $errors);

    if (!$errors && $paymentReceivedAt !== null && $paymentAmount !== null) {
      $insPayment = $pdo->prepare(
        "INSERT INTO project_payments
          (tenant_id, project_id, received_at, amount, method, reference, note, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
      );
      $insPayment->execute([
        (int)$project['tenant_id'],
        $id,
        $paymentReceivedAt,
        $paymentAmount,
        $paymentMethodInput,
        $paymentReferenceInput === '' ? null : $paymentReferenceInput,
        $paymentNoteInput === '' ? null : $paymentNoteInput,
        (int)$me['id'],
      ]);
      $newPaymentId = (int)$pdo->lastInsertId();

      $clientEmail = strtolower(trim((string)($project['client_email'] ?? '')));
      if ($clientEmail === '' || filter_var($clientEmail, FILTER_VALIDATE_EMAIL) === false) {
        $clientEmail = '';
      }

      $receipt = project_payment_create_receipt(
        $pdo,
        (int)$project['tenant_id'],
        $id,
        $newPaymentId,
        (int)$me['id'],
        $clientEmail === '' ? null : $clientEmail
      );
      $receiptNo = is_array($receipt) ? (string)($receipt['receipt_no'] ?? '') : '';
      $receiptEmailResult = '';

      if ($sendReceiptEmailInput && $clientEmail !== '' && is_array($receipt)) {
        $mailFrom = strtolower(trim((string)($_ENV['COREPANEL_MAIL_FROM'] ?? getenv('COREPANEL_MAIL_FROM') ?: '')));
        if ($mailFrom === '' || filter_var($mailFrom, FILTER_VALIDATE_EMAIL) === false) {
          $fallbackFrom = strtolower(trim((string)($me['email'] ?? '')));
          $mailFrom = filter_var($fallbackFrom, FILTER_VALIDATE_EMAIL) ? $fallbackFrom : 'noreply@corepanel.local';
        }

        $fromName = trim((string)($_ENV['COREPANEL_MAIL_FROM_NAME'] ?? getenv('COREPANEL_MAIL_FROM_NAME') ?: 'CorePanel'));
        if ($fromName === '') {
          $fromName = 'CorePanel';
        }
        $replyTo = strtolower(trim((string)($me['email'] ?? '')));
        $replyToValue = filter_var($replyTo, FILTER_VALIDATE_EMAIL) ? $replyTo : null;

        $receiptSubject = 'Payment receipt ' . ($receiptNo !== '' ? $receiptNo : ('for ' . (string)$project['project_no']));
        $receiptHtml = project_payment_compose_receipt_html(
          $project,
          [
            'amount' => $paymentAmount,
            'method' => $paymentMethodInput,
            'received_at' => $paymentReceivedAt,
            'reference' => $paymentReferenceInput,
            'note' => $paymentNoteInput,
          ],
          $receipt
        );

        $mailError = null;
        $sentReceiptMail = project_payment_send_receipt_email(
          $clientEmail,
          $receiptSubject,
          $receiptHtml,
          $mailFrom,
          $fromName,
          $replyToValue,
          $mailError
        );
        if ($sentReceiptMail) {
          project_payment_update_receipt_email_status($pdo, (int)$receipt['id'], 'sent');
          $receiptEmailResult = 'sent';
          admin_audit_log(
            $pdo,
            (int)$me['id'],
            'project_payment.receipt_email_sent',
            (int)$project['user_id'],
            'Sent receipt ' . (string)$receipt['receipt_no'] . ' to ' . $clientEmail,
            $tenantId
          );
        } else {
          project_payment_update_receipt_email_status($pdo, (int)$receipt['id'], 'failed', $mailError);
          $receiptEmailResult = 'failed';
          admin_audit_log(
            $pdo,
            (int)$me['id'],
            'project_payment.receipt_email_failed',
            (int)$project['user_id'],
            'Failed sending receipt ' . (string)$receipt['receipt_no'] . ' to ' . $clientEmail,
            $tenantId
          );
        }
      } elseif ($sendReceiptEmailInput && $clientEmail === '' && is_array($receipt)) {
        project_payment_update_receipt_email_status($pdo, (int)$receipt['id'], 'failed', 'Client email is not configured.');
        $receiptEmailResult = 'failed';
        admin_audit_log(
          $pdo,
          (int)$me['id'],
          'project_payment.receipt_email_failed',
          (int)$project['user_id'],
          'Failed sending receipt ' . (string)$receipt['receipt_no'] . ': client email missing.',
          $tenantId
        );
      } elseif (is_array($receipt)) {
        project_payment_update_receipt_email_status($pdo, (int)$receipt['id'], 'not_requested');
      }

      admin_audit_log(
        $pdo,
        (int)$me['id'],
        'project_payment.add',
        (int)$project['user_id'],
        sprintf(
          'Added payment of $%s (%s) for project %s',
          number_format((float)$paymentAmount, 2, '.', ''),
          $paymentMethodInput,
          (string)$project['project_no']
        ),
        $tenantId
      );
      if (is_array($receipt) && $receiptNo !== '') {
        admin_audit_log(
          $pdo,
          (int)$me['id'],
          'project_payment.receipt',
          (int)$project['user_id'],
          'Generated receipt ' . $receiptNo . ' for project ' . (string)$project['project_no'],
          $tenantId
        );
      }

      $redirectUrl = '/admin/projects/edit.php?id=' . $id;
      if ($receiptNo !== '') {
        $redirectUrl .= '&receipt=' . rawurlencode($receiptNo);
      }
      if ($receiptEmailResult !== '') {
        $redirectUrl .= '&receipt_email=' . rawurlencode($receiptEmailResult);
      }
      redirect($redirectUrl);
    }
  }
}

/** Soft delete project payment */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_project_payment') {
  if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token');
  }
  if (!$canManagePayments) {
    http_response_code(403);
    exit('Forbidden');
  }
  if (!$projectPaymentsEnabled) {
    $errors[] = 'Payments are not available yet.';
  } else {
    $paymentId = (int)($_POST['payment_id'] ?? 0);
    if ($paymentId <= 0) {
      $errors[] = 'Invalid payment.';
    } else {
      $paymentStmt = $pdo->prepare(
        "SELECT id, amount, method
         FROM project_payments
         WHERE id = ?
           AND project_id = ?
           AND tenant_id = ?
           AND deleted_at IS NULL
         LIMIT 1"
      );
      $paymentStmt->execute([$paymentId, $id, $tenantId]);
      $payment = $paymentStmt->fetch();

      if (!$payment) {
        $errors[] = 'Payment not found.';
      } else {
        $softDeletePayment = $pdo->prepare(
          "UPDATE project_payments
           SET deleted_at = NOW()
           WHERE id = ?
             AND project_id = ?
             AND tenant_id = ?
             AND deleted_at IS NULL"
        );
        $softDeletePayment->execute([$paymentId, $id, $tenantId]);
        admin_audit_log(
          $pdo,
          (int)$me['id'],
          'project_payment.delete',
          (int)$project['user_id'],
          sprintf(
            'Soft deleted payment #%d of $%s (%s) for project %s',
            $paymentId,
            number_format((float)$payment['amount'], 2, '.', ''),
            (string)$payment['method'],
            (string)$project['project_no']
          ),
          $tenantId
        );
        redirect('/admin/projects/edit.php?id=' . $id);
      }
    }
  }
}

/** Restore project payment */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore_project_payment') {
  if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token');
  }
  if (!$canManagePayments) {
    http_response_code(403);
    exit('Forbidden');
  }
  if (!$projectPaymentsEnabled) {
    $errors[] = 'Payments are not available yet.';
  } else {
    $paymentId = (int)($_POST['payment_id'] ?? 0);
    if ($paymentId <= 0) {
      $errors[] = 'Invalid payment.';
    } else {
      $paymentStmt = $pdo->prepare(
        "SELECT id, amount, method
         FROM project_payments
         WHERE id = ?
           AND project_id = ?
           AND tenant_id = ?
           AND deleted_at IS NOT NULL
         LIMIT 1"
      );
      $paymentStmt->execute([$paymentId, $id, $tenantId]);
      $payment = $paymentStmt->fetch();

      if (!$payment) {
        $errors[] = 'Payment not found.';
      } else {
        $restorePayment = $pdo->prepare(
          "UPDATE project_payments
           SET deleted_at = NULL
           WHERE id = ?
             AND project_id = ?
             AND tenant_id = ?
             AND deleted_at IS NOT NULL"
        );
        $restorePayment->execute([$paymentId, $id, $tenantId]);
        admin_audit_log(
          $pdo,
          (int)$me['id'],
          'project_payment.restore',
          (int)$project['user_id'],
          sprintf(
            'Restored payment #%d of $%s (%s) for project %s',
            $paymentId,
            number_format((float)$payment['amount'], 2, '.', ''),
            (string)$payment['method'],
            (string)$project['project_no']
          ),
          $tenantId
        );
        redirect('/admin/projects/edit.php?id=' . $id);
      }
    }
  }
}

/** Add project images */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_project_images') {
  if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token');
  }
  if (!$canManageImages) {
    http_response_code(403);
    exit('Forbidden');
  }

  if (!$projectImagesEnabled) {
    $errors[] = 'Project images are not available yet.';
  } else {
    $files = $_FILES['project_images'] ?? null;
    if (!is_array($files) || !isset($files['error']) || !is_array($files['error'])) {
      $errors[] = 'Select at least one image to upload.';
    } else {
      $hasImage = false;
      $insImg = $pdo->prepare("
        INSERT INTO project_images (project_id, tenant_id, image_path)
        VALUES (?, ?, ?)
      ");

      foreach (array_keys($files['error']) as $idx) {
        $fileError = (int)($files['error'][$idx] ?? UPLOAD_ERR_NO_FILE);
        if ($fileError === UPLOAD_ERR_NO_FILE) {
          continue;
        }

        $hasImage = true;
        $file = [
          'name' => $files['name'][$idx] ?? '',
          'type' => $files['type'][$idx] ?? '',
          'tmp_name' => $files['tmp_name'][$idx] ?? '',
          'error' => $fileError,
          'size' => $files['size'][$idx] ?? 0,
        ];
        [$imagePath, $uploadErr] = upload_item_image($file);
        if ($uploadErr !== null) {
          $errors[] = 'Image upload failed: ' . $uploadErr;
          continue;
        }

        $insImg->execute([$id, $tenantId, $imagePath]);
      }

      if (!$hasImage) {
        $errors[] = 'Select at least one image to upload.';
      }
      if (!$errors) {
        redirect('/admin/projects/edit.php?id=' . $id);
      }
    }
  }
}

/** Add project PDFs */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_project_documents') {
  if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token');
  }
  if (!$canManageImages) {
    http_response_code(403);
    exit('Forbidden');
  }

  if (!$projectImagesEnabled) {
    $errors[] = 'Project files are not available yet.';
  } else {
    $files = $_FILES['project_documents'] ?? null;
    if (!is_array($files) || !isset($files['error']) || !is_array($files['error'])) {
      $errors[] = 'Select at least one PDF to upload.';
    } else {
      $hasDocument = false;
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

      if (!$hasDocument) {
        $errors[] = 'Select at least one PDF to upload.';
      }
      if (!$errors) {
        redirect('/admin/projects/edit.php?id=' . $id);
      }
    }
  }
}

/** Soft delete project file */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_project_image') {
  if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token');
  }
  if (!$canManageImages) {
    http_response_code(403);
    exit('Forbidden');
  }

  if (!$projectImagesEnabled) {
    $errors[] = 'Project images are not available yet.';
  } else {
    $imageId = (int)($_POST['image_id'] ?? 0);
    if ($imageId <= 0) {
      $errors[] = 'Invalid image.';
    } else {
      $imgStmt = $pdo->prepare("
        SELECT i.image_path
        FROM project_images i
        JOIN projects p ON p.id = i.project_id
        WHERE i.id = ?
          AND i.project_id = ?
          AND i.tenant_id = ?
          AND p.tenant_id = ?
          AND p.deleted_at IS NULL
          AND i.deleted_at IS NULL
        LIMIT 1
      ");
      $imgStmt->execute([$imageId, $id, $tenantId, $tenantId]);
      $img = $imgStmt->fetch();

      if (!$img) {
        $errors[] = 'Image not found.';
      } else {
        $delImg = $pdo->prepare("
          UPDATE project_images i
          JOIN projects p ON p.id = i.project_id
          SET i.deleted_at = NOW()
          WHERE i.id = ?
            AND i.project_id = ?
            AND i.tenant_id = ?
            AND p.tenant_id = ?
            AND p.deleted_at IS NULL
            AND i.deleted_at IS NULL
        ");
        $delImg->execute([$imageId, $id, $tenantId, $tenantId]);
        admin_audit_log(
          $pdo,
          (int)$me['id'],
          'project_file.delete',
          (int)$project['user_id'],
          'Soft deleted project file #' . $imageId . ' for project ' . (string)$project['project_no'],
          $tenantId
        );

        redirect('/admin/projects/edit.php?id=' . $id);
      }
    }
  }
}

/** Restore project file */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore_project_image') {
  if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token');
  }
  if (!$canManageImages) {
    http_response_code(403);
    exit('Forbidden');
  }

  if (!$projectImagesEnabled) {
    $errors[] = 'Project files are not available yet.';
  } else {
    $imageId = (int)($_POST['image_id'] ?? 0);
    if ($imageId <= 0) {
      $errors[] = 'Invalid file.';
    } else {
      $restoreImg = $pdo->prepare("
        UPDATE project_images i
        JOIN projects p ON p.id = i.project_id
        SET i.deleted_at = NULL
        WHERE i.id = ?
          AND i.project_id = ?
          AND i.tenant_id = ?
          AND p.tenant_id = ?
          AND p.deleted_at IS NULL
          AND i.deleted_at IS NOT NULL
      ");
      $restoreImg->execute([$imageId, $id, $tenantId, $tenantId]);
      if ($restoreImg->rowCount() > 0) {
        admin_audit_log(
          $pdo,
          (int)$me['id'],
          'project_file.restore',
          (int)$project['user_id'],
          'Restored project file #' . $imageId . ' for project ' . (string)$project['project_no'],
          $tenantId
        );
      }
      redirect('/admin/projects/edit.php?id=' . $id);
    }
  }
}

$tstmt = $pdo->prepare(
  "SELECT t.*
   FROM project_tasks t
   JOIN projects p ON p.id = t.project_id
   WHERE t.project_id = ?
     AND t.tenant_id = ?
     AND p.tenant_id = ?
     AND p.deleted_at IS NULL
   ORDER BY t.id DESC"
);
$tstmt->execute([$id, $tenantId, $tenantId]);
$tasks = $tstmt->fetchAll();

if ($projectPaymentsEnabled) {
  try {
    $receiptSelect = $projectPaymentReceiptsEnabled
      ? 'r.receipt_no, r.email_status, r.emailed_at'
      : "NULL AS receipt_no, 'not_requested' AS email_status, NULL AS emailed_at";
    $receiptJoin = $projectPaymentReceiptsEnabled
      ? 'LEFT JOIN project_payment_receipts r ON r.payment_id = pp.id'
      : '';
    $paymentListStmt = $pdo->prepare(
      "SELECT pp.id, pp.received_at, pp.amount, pp.method, pp.reference, pp.note, pp.created_at,
              u.name AS created_by_name, u.email AS created_by_email,
              {$receiptSelect}
       FROM project_payments pp
       JOIN projects p ON p.id = pp.project_id
       LEFT JOIN users u ON u.id = pp.created_by
       {$receiptJoin}
       WHERE pp.project_id = ?
         AND pp.tenant_id = ?
         AND p.tenant_id = ?
         AND p.deleted_at IS NULL
         AND pp.deleted_at IS NULL
       ORDER BY pp.received_at DESC, pp.id DESC"
    );
    $paymentListStmt->execute([$id, $tenantId, $tenantId]);
    $projectPayments = $paymentListStmt->fetchAll() ?: [];

    $deletedPaymentListStmt = $pdo->prepare(
      "SELECT pp.id, pp.received_at, pp.amount, pp.method, pp.reference, pp.note, pp.created_at, pp.deleted_at,
              u.name AS created_by_name, u.email AS created_by_email,
              {$receiptSelect}
       FROM project_payments pp
       JOIN projects p ON p.id = pp.project_id
       LEFT JOIN users u ON u.id = pp.created_by
       {$receiptJoin}
       WHERE pp.project_id = ?
         AND pp.tenant_id = ?
         AND p.tenant_id = ?
         AND p.deleted_at IS NULL
         AND pp.deleted_at IS NOT NULL
       ORDER BY pp.deleted_at DESC, pp.id DESC"
    );
    $deletedPaymentListStmt->execute([$id, $tenantId, $tenantId]);
    $deletedProjectPayments = $deletedPaymentListStmt->fetchAll() ?: [];
  } catch (Throwable $e) {
    $projectPaymentsError = 'Payments are not available yet.';
  }
}

if ($projectImagesEnabled) {
  try {
    $imgListStmt = $pdo->prepare("
      SELECT i.id, i.image_path, i.created_at
      FROM project_images i
      JOIN projects p ON p.id = i.project_id
      WHERE i.project_id = ?
        AND i.tenant_id = ?
        AND p.tenant_id = ?
        AND p.deleted_at IS NULL
        AND i.deleted_at IS NULL
      ORDER BY i.id DESC
    ");
    $imgListStmt->execute([$id, $tenantId, $tenantId]);
    $projectMedia = $imgListStmt->fetchAll();
    foreach ($projectMedia as $media) {
      $reference = (string)($media['image_path'] ?? '');
      if (upload_reference_is_pdf($reference)) {
        $projectDocuments[] = $media;
      } elseif (upload_reference_is_image($reference)) {
        $projectImages[] = $media;
      }
    }

    $deletedImgListStmt = $pdo->prepare("
      SELECT i.id, i.image_path, i.created_at, i.deleted_at
      FROM project_images i
      JOIN projects p ON p.id = i.project_id
      WHERE i.project_id = ?
        AND i.tenant_id = ?
        AND p.tenant_id = ?
        AND p.deleted_at IS NULL
        AND i.deleted_at IS NOT NULL
      ORDER BY i.deleted_at DESC, i.id DESC
    ");
    $deletedImgListStmt->execute([$id, $tenantId, $tenantId]);
    $deletedProjectMedia = $deletedImgListStmt->fetchAll() ?: [];
    foreach ($deletedProjectMedia as $media) {
      $reference = (string)($media['image_path'] ?? '');
      if (upload_reference_is_pdf($reference)) {
        $deletedProjectDocuments[] = $media;
      } elseif (upload_reference_is_image($reference)) {
        $deletedProjectImages[] = $media;
      }
    }
  } catch (Throwable $e) {
    $projectImagesError = 'Project images are not available yet.';
  }
}

$total = 0.00;
foreach ($tasks as $t) $total += (float)$t['amount'];
$paidAmount = 0.00;
foreach ($projectPayments as $payment) {
  $paidAmount += (float)($payment['amount'] ?? 0.0);
}
$paymentSnapshot = project_payment_snapshot($total, $paidAmount);
$paymentProgressPercent = number_format((float)$paymentSnapshot['progress_percent'], 1, '.', '');
$overpaidAmount = max(0.0, 0.0 - (float)$paymentSnapshot['balance']);

render_header('Edit Project • Admin • CorePanel');
?>
<div class="container container-wide admin-project-edit-page">
  <div class="admin-project-edit-header">
    <p><a href="/admin/projects/index.php">← Projects</a></p>
    <h1><?= e($project['project_no']) ?> — <?= e($project['title']) ?></h1>
    <p class="admin-project-edit-client">Client: <?= e($project['client_name']) ?> (<?= e($project['client_email']) ?>)</p>
  </div>

  <?php if ($errors): ?>
    <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>
  <?php if ($flashInfo): ?>
    <div class="admin-project-edit-flash-wrap">
      <?php foreach ($flashInfo as $info): ?>
        <p class="admin-project-edit-flash status-text-success"><?= e($info) ?></p>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="admin-project-edit-grid">
    <section class="admin-project-edit-panel" aria-labelledby="project-details-title">
      <h2 id="project-details-title">Project Details</h2>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="update_project">

        <label>Title<br>
          <input name="title" value="<?= e($title) ?>" required>
        </label>

        <label>Description<br>
          <textarea name="description" rows="4"><?= e($description) ?></textarea>
        </label>

        <?php if ($projectNotesEnabled): ?>
          <label>Notes<br>
            <textarea name="notes" rows="4"><?= e($notes) ?></textarea>
          </label>
        <?php endif; ?>

        <?php if ($projectAddressEnabled): ?>
          <label>Project Address<br>
            <textarea name="project_address" rows="3"><?= e($projectAddress) ?></textarea>
          </label>
        <?php endif; ?>

        <?php if ($projectDueDateEnabled): ?>
          <label>Due Date<br>
            <input type="date" name="due_date" value="<?= e($dueDateInput) ?>">
          </label>
        <?php endif; ?>

        <label>Status<br>
          <select name="status">
            <?php foreach ($projectStatuses as $s): ?>
              <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e($s) ?></option>
            <?php endforeach; ?>
          </select>
        </label>

        <button type="submit">Save Project</button>
      </form>
    </section>

    <?php if ($canManageTasks): ?>
      <section class="admin-project-edit-panel" aria-labelledby="add-task-title">
        <h2 id="add-task-title">Add Task</h2>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="add_task">

          <label>Task Title<br>
            <input name="task_title" required>
          </label>

          <label>Task Description<br>
            <textarea name="task_description" rows="3"></textarea>
          </label>

          <div class="admin-project-edit-task-row">
            <label>Rate<br>
              <input name="rate" type="number" step="0.01" value="0.00" required>
            </label>

            <label>Quantity<br>
              <input name="quantity" type="number" step="0.01" value="1.00" required>
            </label>
          </div>

          <button type="submit">Add Task</button>
        </form>
      </section>
    <?php endif; ?>
  </div>

  <?php if ($canManageImages): ?>
    <section class="admin-project-edit-images-section" aria-labelledby="project-images-title">
      <h2 id="project-images-title">Project Images</h2>

    <?php if ($projectImagesError !== ''): ?>
      <p class="admin-project-edit-images-note"><?= e($projectImagesError) ?></p>
    <?php else: ?>
      <div class="admin-project-edit-upload-grid">
        <form method="post" enctype="multipart/form-data" class="admin-project-edit-images-upload-form">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="add_project_images">

          <label>Upload Images<br>
            <input type="file" name="project_images[]" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" multiple>
          </label>

          <button type="submit">Upload Images</button>
        </form>

        <form method="post" enctype="multipart/form-data" class="admin-project-edit-images-upload-form">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="add_project_documents">

          <label>Upload PDF Files<br>
            <input type="file" name="project_documents[]" accept=".pdf,application/pdf" multiple>
          </label>
          <p class="admin-project-edit-images-note">Max PDF size: <?= e($projectPdfMaxLabel) ?></p>

          <button type="submit">Upload PDFs</button>
        </form>
      </div>

      <?php if (!$projectImages): ?>
        <p class="admin-project-edit-images-note">No images uploaded yet.</p>
      <?php else: ?>
        <div class="admin-project-edit-images-grid">
          <?php foreach ($projectImages as $img): ?>
            <figure class="admin-project-edit-image-card">
              <?php $viewUrl = media_file_url((string)$img['image_path']) ?? '#'; ?>
              <?php $downloadUrl = media_file_url((string)$img['image_path'], true) ?? '#'; ?>
              <a href="<?= e_url_attr($viewUrl) ?>" target="_blank" rel="noopener">
                <img src="<?= e_url_attr($viewUrl, 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==') ?>" alt="Project image">
              </a>
              <div class="admin-project-edit-media-actions">
                <a class="admin-project-edit-media-link" href="<?= e_url_attr($viewUrl) ?>" target="_blank" rel="noopener">Open</a>
                <a class="admin-project-edit-media-link" href="<?= e_url_attr($downloadUrl) ?>">Download</a>
              </div>
              <form method="post" class="admin-project-edit-image-delete-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete_project_image">
                <input type="hidden" name="image_id" value="<?= (int)$img['id'] ?>">
                <button
                  type="submit"
                  class="admin-project-edit-image-delete-btn"
                  data-confirm="Delete this image? You can restore it later."
                >
                  Delete Image
                </button>
              </form>
            </figure>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <h3 class="admin-project-edit-documents-title">Project PDFs</h3>
      <?php if (!$projectDocuments): ?>
        <p class="admin-project-edit-images-note">No PDF files uploaded yet.</p>
      <?php else: ?>
        <div class="admin-project-edit-documents-list">
          <?php foreach ($projectDocuments as $doc): ?>
            <?php $docViewUrl = media_file_url((string)$doc['image_path']) ?? '#'; ?>
            <?php $docDownloadUrl = media_file_url((string)$doc['image_path'], true) ?? '#'; ?>
            <?php $docName = upload_reference_filename((string)$doc['image_path']) ?? ('Document #' . (int)$doc['id']); ?>
            <div class="admin-project-edit-document-item">
              <div class="admin-project-edit-document-meta">
                <strong><?= e($docName) ?></strong>
                <small><?= e((string)$doc['created_at']) ?></small>
              </div>
              <div class="admin-project-edit-document-actions">
                <a class="admin-project-edit-media-link" href="<?= e_url_attr($docViewUrl) ?>" target="_blank" rel="noopener">View</a>
                <a class="admin-project-edit-media-link" href="<?= e_url_attr($docDownloadUrl) ?>">Download</a>
                <form method="post" class="admin-project-edit-image-delete-form">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="delete_project_image">
                  <input type="hidden" name="image_id" value="<?= (int)$doc['id'] ?>">
                  <button
                    type="submit"
                    class="admin-project-edit-document-delete-btn"
                    data-confirm="Delete this file? You can restore it later."
                  >
                    Delete
                  </button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($deletedProjectImages || $deletedProjectDocuments): ?>
        <h3 class="admin-project-edit-documents-title">Deleted Files</h3>
      <?php endif; ?>

      <?php if ($deletedProjectImages): ?>
        <p class="admin-project-edit-images-note">Deleted Images</p>
        <div class="admin-project-edit-documents-list">
          <?php foreach ($deletedProjectImages as $deletedImg): ?>
            <?php $deletedImgName = upload_reference_filename((string)$deletedImg['image_path']) ?? ('Image #' . (int)$deletedImg['id']); ?>
            <div class="admin-project-edit-document-item">
              <div class="admin-project-edit-document-meta">
                <strong><?= e($deletedImgName) ?></strong>
                <small>Deleted: <?= e((string)($deletedImg['deleted_at'] ?? '')) ?></small>
              </div>
              <div class="admin-project-edit-document-actions">
                <form method="post" class="admin-project-edit-image-delete-form">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="restore_project_image">
                  <input type="hidden" name="image_id" value="<?= (int)$deletedImg['id'] ?>">
                  <button
                    type="submit"
                    class="admin-project-edit-media-link"
                  >
                    Restore
                  </button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($deletedProjectDocuments): ?>
        <p class="admin-project-edit-images-note">Deleted PDFs</p>
        <div class="admin-project-edit-documents-list">
          <?php foreach ($deletedProjectDocuments as $deletedDoc): ?>
            <?php $deletedDocName = upload_reference_filename((string)$deletedDoc['image_path']) ?? ('Document #' . (int)$deletedDoc['id']); ?>
            <div class="admin-project-edit-document-item">
              <div class="admin-project-edit-document-meta">
                <strong><?= e($deletedDocName) ?></strong>
                <small>Deleted: <?= e((string)($deletedDoc['deleted_at'] ?? '')) ?></small>
              </div>
              <div class="admin-project-edit-document-actions">
                <form method="post" class="admin-project-edit-image-delete-form">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="restore_project_image">
                  <input type="hidden" name="image_id" value="<?= (int)$deletedDoc['id'] ?>">
                  <button
                    type="submit"
                    class="admin-project-edit-media-link"
                  >
                    Restore
                  </button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
    </section>
  <?php endif; ?>

  <section class="admin-project-edit-payments-section" aria-labelledby="payments-title">
    <h2 id="payments-title">Payments</h2>
    <?php if (!$projectPaymentsEnabled): ?>
      <p class="admin-project-edit-images-note">Payments are not available yet.</p>
    <?php elseif ($projectPaymentsError !== ''): ?>
      <p class="admin-project-edit-images-note"><?= e($projectPaymentsError) ?></p>
    <?php else: ?>
      <div class="admin-project-edit-payments-summary">
        <div class="admin-project-edit-payments-stat">
          <span>Total</span>
          <strong>$<?= number_format((float)$paymentSnapshot['total_amount'], 2) ?></strong>
        </div>
        <div class="admin-project-edit-payments-stat">
          <span>Paid</span>
          <strong>$<?= number_format((float)$paymentSnapshot['paid_amount'], 2) ?></strong>
        </div>
        <div class="admin-project-edit-payments-stat">
          <span>Balance</span>
          <strong>$<?= number_format((float)$paymentSnapshot['balance'], 2) ?></strong>
        </div>
        <div class="admin-project-edit-payments-stat">
          <span>Status</span>
          <strong class="<?= e(project_payment_status_class((string)$paymentSnapshot['status_key'])) ?>">
            <?= e((string)$paymentSnapshot['status_label']) ?>
          </strong>
        </div>
      </div>

      <div class="admin-project-edit-payments-progress-row">
        <div class="payment-progress payment-progress-<?= e((string)$paymentSnapshot['status_key']) ?>" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= e($paymentProgressPercent) ?>">
          <span class="payment-progress-fill" style="width: <?= e($paymentProgressPercent) ?>%;"></span>
        </div>
        <small class="payment-progress-caption"><?= e($paymentProgressPercent) ?>%</small>
        <?php if ($overpaidAmount > 0.0): ?>
          <small class="payment-overpaid-caption">Overpaid by $<?= number_format($overpaidAmount, 2) ?></small>
        <?php endif; ?>
      </div>

      <?php if ($canCreatePayments): ?>
        <?php $paymentReceivedAtValue = str_replace(' ', 'T', substr($paymentReceivedAtInput, 0, 16)); ?>
        <form method="post" class="admin-project-edit-payment-form">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="add_project_payment">

          <div class="admin-project-edit-payment-form-grid">
            <label>Amount<br>
              <input type="number" name="payment_amount" min="0.01" step="0.01" value="<?= e($paymentAmountInput) ?>" required>
            </label>

            <label>Date Received<br>
              <input type="datetime-local" name="payment_received_at" value="<?= e($paymentReceivedAtValue) ?>" required>
            </label>

            <label>Payment Method<br>
              <select name="payment_method" required>
                <?php foreach (project_payment_methods() as $methodKey): ?>
                  <option value="<?= e($methodKey) ?>" <?= $paymentMethodInput === $methodKey ? 'selected' : '' ?>>
                    <?= e(project_payment_method_label($methodKey)) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>

          <label>Reference # (optional)<br>
            <input name="payment_reference" value="<?= e($paymentReferenceInput) ?>" maxlength="190" placeholder="Check #, transaction id, memo">
          </label>

          <label>Note<br>
            <textarea name="payment_note" rows="3" maxlength="5000"><?= e($paymentNoteInput) ?></textarea>
          </label>

          <label class="admin-project-edit-payment-checkbox">
            <input type="checkbox" name="send_receipt_email" value="1" <?= $sendReceiptEmailInput ? 'checked' : '' ?>>
            Email receipt to client after saving payment
          </label>

          <button type="submit">Add Payment</button>
        </form>
      <?php else: ?>
        <p class="admin-project-edit-images-note">You do not have permission to add payments.</p>
      <?php endif; ?>

      <div class="admin-project-edit-payments-table-wrap">
        <table class="admin-project-edit-payments-table" border="1" cellpadding="8" cellspacing="0">
          <thead>
            <tr>
              <th>Date</th>
              <th>Amount</th>
              <th>Method</th>
              <th>Reference</th>
              <th>Note</th>
              <th>Receipt</th>
              <th>Added By</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$projectPayments): ?>
              <tr>
                <td colspan="8">No payments recorded yet.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($projectPayments as $payment): ?>
                <?php
                  $createdByLabel = trim((string)($payment['created_by_name'] ?? ''));
                  if ($createdByLabel === '') {
                    $createdByLabel = trim((string)($payment['created_by_email'] ?? ''));
                  }
                  if ($createdByLabel === '') {
                    $createdByLabel = 'Unknown';
                  }
                ?>
                <tr>
                  <td><?= e((string)$payment['received_at']) ?></td>
                  <td>$<?= number_format((float)$payment['amount'], 2) ?></td>
                  <td><?= e(project_payment_method_label((string)$payment['method'])) ?></td>
                  <td><?= e((string)($payment['reference'] ?? '')) ?></td>
                  <td><?= e((string)($payment['note'] ?? '')) ?></td>
                  <td>
                    <?php if (trim((string)($payment['receipt_no'] ?? '')) === ''): ?>
                      <span class="status-text">-</span>
                    <?php else: ?>
                      <strong><?= e((string)$payment['receipt_no']) ?></strong>
                      <?php if ((string)($payment['email_status'] ?? '') === 'sent'): ?>
                        <br><small class="status-text-success">Emailed</small>
                      <?php elseif ((string)($payment['email_status'] ?? '') === 'failed'): ?>
                        <br><small class="status-text-danger">Email failed</small>
                      <?php else: ?>
                        <br><small class="status-text">Not emailed</small>
                      <?php endif; ?>
                    <?php endif; ?>
                  </td>
                  <td><?= e($createdByLabel) ?></td>
                  <td>
                    <?php if ($canManagePayments): ?>
                      <form method="post" class="admin-project-edit-image-delete-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete_project_payment">
                        <input type="hidden" name="payment_id" value="<?= (int)$payment['id'] ?>">
                        <button
                          type="submit"
                          class="admin-project-edit-document-delete-btn"
                          data-confirm="Delete this payment? You can restore it later."
                        >
                          Delete
                        </button>
                      </form>
                    <?php else: ?>
                      <span class="status-text">-</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="admin-project-edit-payments-table-wrap">
        <h3 class="admin-project-edit-documents-title">Deleted Payments</h3>
        <table class="admin-project-edit-payments-table" border="1" cellpadding="8" cellspacing="0">
          <thead>
            <tr>
              <th>Date</th>
              <th>Amount</th>
              <th>Method</th>
              <th>Reference</th>
              <th>Deleted At</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$deletedProjectPayments): ?>
              <tr>
                <td colspan="6">No deleted payments.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($deletedProjectPayments as $payment): ?>
                <tr>
                  <td><?= e((string)$payment['received_at']) ?></td>
                  <td>$<?= number_format((float)$payment['amount'], 2) ?></td>
                  <td><?= e(project_payment_method_label((string)$payment['method'])) ?></td>
                  <td><?= e((string)($payment['reference'] ?? '')) ?></td>
                  <td><?= e((string)($payment['deleted_at'] ?? '')) ?></td>
                  <td>
                    <?php if ($canManagePayments): ?>
                      <form method="post" class="admin-project-edit-image-delete-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="restore_project_payment">
                        <input type="hidden" name="payment_id" value="<?= (int)$payment['id'] ?>">
                        <button
                          type="submit"
                          class="admin-project-edit-media-link"
                        >
                          Restore
                        </button>
                      </form>
                    <?php else: ?>
                      <span class="status-text">-</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="admin-project-edit-tasks-section" aria-labelledby="tasks-title">
    <h2 id="tasks-title">Tasks</h2>
    <div class="admin-project-edit-tasks-wrap">
      <table class="admin-project-edit-tasks-table" border="1" cellpadding="8" cellspacing="0">
        <thead>
          <tr>
            <th>Title</th>
            <th>Rate</th>
            <th>Qty</th>
            <th>Amount</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$tasks): ?>
            <tr>
              <td colspan="6">No tasks yet.</td>
            </tr>
          <?php else: ?>
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
                <td class="admin-project-edit-task-actions-cell">
                  <div class="admin-project-edit-task-actions">
                    <?php if ($canManageTasks): ?>
                      <a class="admin-project-edit-task-action-link" href="/admin/projects/task_edit.php?project_id=<?= (int)$id ?>&task_id=<?= (int)$t['id'] ?>">Edit</a>
                    <?php endif; ?>
                    <?php if ($canDeleteTasks): ?>
                      <form method="post" action="/admin/projects/task_delete.php" class="admin-project-edit-task-delete-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="project_id" value="<?= (int)$id ?>">
                        <input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>">
                        <button
                          type="submit"
                          class="admin-project-edit-task-delete-btn"
                          data-confirm="Delete this task?"
                        >
                          Delete
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
    <p class="admin-project-edit-total"><strong>Total:</strong> $<?= number_format((float)$total, 2) ?></p>
  </section>
</div>
<?php render_footer(); ?>
