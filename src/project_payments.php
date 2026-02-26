<?php
declare(strict_types=1);

function project_payment_methods(): array {
  return ['cash', 'check', 'zelle', 'credit', 'ach', 'other'];
}

function project_payment_method_label(string $method): string {
  $normalized = strtolower(trim($method));
  return match ($normalized) {
    'cash' => 'Cash',
    'check' => 'Check',
    'zelle' => 'Zelle',
    'credit' => 'Credit',
    'ach' => 'ACH',
    default => 'Other',
  };
}

function ensure_project_payments_table(PDO $pdo): bool {
  static $checked = false;
  static $available = false;

  if ($checked) {
    return $available;
  }
  $checked = true;

  try {
    $pdo->exec(
      "CREATE TABLE IF NOT EXISTS project_payments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
        project_id INT UNSIGNED NOT NULL,
        received_at DATETIME NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        method ENUM('cash','check','zelle','credit','ach','other') NOT NULL DEFAULT 'other',
        reference VARCHAR(190) NULL,
        note TEXT NULL,
        created_by INT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        deleted_at DATETIME NULL,
        INDEX idx_project_payments_project (project_id, tenant_id, received_at),
        INDEX idx_project_payments_tenant (tenant_id, received_at),
        INDEX idx_project_payments_created_by (created_by),
        INDEX idx_project_payments_deleted_at (deleted_at),
        CONSTRAINT fk_project_payments_project
          FOREIGN KEY (project_id) REFERENCES projects(id)
          ON DELETE CASCADE,
        CONSTRAINT fk_project_payments_created_by
          FOREIGN KEY (created_by) REFERENCES users(id)
          ON DELETE RESTRICT
      ) ENGINE=InnoDB"
    );

    if (!db_has_column($pdo, 'project_payments', 'tenant_id')) {
      $pdo->exec("ALTER TABLE project_payments ADD COLUMN tenant_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER id");
    }
    if (!db_has_column($pdo, 'project_payments', 'reference')) {
      $pdo->exec("ALTER TABLE project_payments ADD COLUMN reference VARCHAR(190) NULL AFTER method");
    }
    if (!db_has_column($pdo, 'project_payments', 'note')) {
      $pdo->exec("ALTER TABLE project_payments ADD COLUMN note TEXT NULL AFTER reference");
    }
    if (!db_has_column($pdo, 'project_payments', 'created_by')) {
      $pdo->exec("ALTER TABLE project_payments ADD COLUMN created_by INT UNSIGNED NOT NULL DEFAULT 1 AFTER note");
    }
    if (!db_has_column($pdo, 'project_payments', 'created_at')) {
      $pdo->exec("ALTER TABLE project_payments ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER created_by");
    }
    if (!db_has_column($pdo, 'project_payments', 'deleted_at')) {
      $pdo->exec("ALTER TABLE project_payments ADD COLUMN deleted_at DATETIME NULL AFTER created_at");
    }

    $pdo->exec(
      "UPDATE project_payments pp
       JOIN projects p ON p.id = pp.project_id
       SET pp.tenant_id = p.tenant_id
       WHERE pp.tenant_id IS NULL OR pp.tenant_id = 0"
    );

    $pdo->exec(
      "UPDATE project_payments pp
       JOIN projects p ON p.id = pp.project_id
       SET pp.created_by = COALESCE(NULLIF(pp.created_by, 0), p.created_by, p.user_id)
       WHERE pp.created_by IS NULL OR pp.created_by = 0"
    );

    try {
      $pdo->exec("ALTER TABLE project_payments ADD INDEX idx_project_payments_project (project_id, tenant_id, received_at)");
    } catch (Throwable $e) {
      // Index may already exist.
    }
    try {
      $pdo->exec("ALTER TABLE project_payments ADD INDEX idx_project_payments_tenant (tenant_id, received_at)");
    } catch (Throwable $e) {
      // Index may already exist.
    }
    try {
      $pdo->exec("ALTER TABLE project_payments ADD INDEX idx_project_payments_created_by (created_by)");
    } catch (Throwable $e) {
      // Index may already exist.
    }
    try {
      $pdo->exec("ALTER TABLE project_payments ADD INDEX idx_project_payments_deleted_at (deleted_at)");
    } catch (Throwable $e) {
      // Index may already exist.
    }

    $available = true;
    return true;
  } catch (Throwable $e) {
    $available = false;
    return false;
  }
}

function project_payment_parse_received_at(string $raw, array &$errors): ?string {
  $raw = trim($raw);
  if ($raw === '') {
    $errors[] = 'Received date/time is required.';
    return null;
  }

  $candidates = [$raw];
  if (str_contains($raw, ' ')) {
    $candidates[] = str_replace(' ', 'T', $raw);
  }

  foreach ($candidates as $candidate) {
    foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d H:i:s'] as $format) {
      $dt = DateTimeImmutable::createFromFormat($format, $candidate);
      $errorsInfo = DateTimeImmutable::getLastErrors();
      $warningCount = is_array($errorsInfo) ? (int)($errorsInfo['warning_count'] ?? 0) : 0;
      $errorCount = is_array($errorsInfo) ? (int)($errorsInfo['error_count'] ?? 0) : 0;
      if (
        $dt instanceof DateTimeImmutable &&
        $warningCount === 0 &&
        $errorCount === 0
      ) {
        return $dt->format('Y-m-d H:i:s');
      }
    }
  }

  $errors[] = 'Invalid received date/time format.';
  return null;
}

function project_payment_snapshot(float $totalAmount, float $paidAmount): array {
  $total = round($totalAmount, 2);
  $paid = round($paidAmount, 2);
  $balance = round($total - $paid, 2);

  $statusKey = 'unpaid';
  $statusLabel = 'Unpaid';
  $progress = 0.0;

  if ($total <= 0.0) {
    if ($paid > 0.0) {
      $statusKey = 'paid';
      $statusLabel = 'Paid';
      $progress = 100.0;
    }
  } elseif ($paid <= 0.0) {
    $statusKey = 'unpaid';
    $statusLabel = 'Unpaid';
    $progress = 0.0;
  } elseif ($paid < $total) {
    $statusKey = 'partial';
    $statusLabel = 'Partially Paid';
    $progress = ($paid / $total) * 100.0;
  } else {
    $statusKey = 'paid';
    $statusLabel = 'Paid';
    $progress = 100.0;
  }

  $progress = max(0.0, min(100.0, round($progress, 1)));

  return [
    'total_amount' => $total,
    'paid_amount' => $paid,
    'balance' => $balance,
    'status_key' => $statusKey,
    'status_label' => $statusLabel,
    'progress_percent' => $progress,
  ];
}

function project_payment_status_class(string $statusKey): string {
  return match (strtolower(trim($statusKey))) {
    'paid' => 'status-text status-text-success',
    'partial' => 'status-text payment-status-partial',
    default => 'status-text payment-status-unpaid',
  };
}

function ensure_project_payment_receipts_table(PDO $pdo): bool {
  static $checked = false;
  static $available = false;

  if ($checked) {
    return $available;
  }
  $checked = true;

  try {
    $pdo->exec(
      "CREATE TABLE IF NOT EXISTS project_payment_receipts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
        project_id INT UNSIGNED NOT NULL,
        payment_id INT UNSIGNED NOT NULL,
        receipt_no VARCHAR(40) NOT NULL,
        recipient_email VARCHAR(190) NULL,
        email_status ENUM('not_requested','sent','failed') NOT NULL DEFAULT 'not_requested',
        emailed_at DATETIME NULL,
        email_error VARCHAR(255) NULL,
        created_by INT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_project_payment_receipt_payment (payment_id),
        UNIQUE KEY uniq_project_payment_receipt_no (receipt_no),
        INDEX idx_project_payment_receipts_project (project_id, tenant_id, created_at),
        INDEX idx_project_payment_receipts_tenant (tenant_id, created_at),
        CONSTRAINT fk_project_payment_receipts_project
          FOREIGN KEY (project_id) REFERENCES projects(id)
          ON DELETE CASCADE,
        CONSTRAINT fk_project_payment_receipts_payment
          FOREIGN KEY (payment_id) REFERENCES project_payments(id)
          ON DELETE CASCADE,
        CONSTRAINT fk_project_payment_receipts_created_by
          FOREIGN KEY (created_by) REFERENCES users(id)
          ON DELETE RESTRICT
      ) ENGINE=InnoDB"
    );

    if (!db_has_column($pdo, 'project_payment_receipts', 'tenant_id')) {
      $pdo->exec("ALTER TABLE project_payment_receipts ADD COLUMN tenant_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER id");
    }
    if (!db_has_column($pdo, 'project_payment_receipts', 'recipient_email')) {
      $pdo->exec("ALTER TABLE project_payment_receipts ADD COLUMN recipient_email VARCHAR(190) NULL AFTER receipt_no");
    }
    if (!db_has_column($pdo, 'project_payment_receipts', 'email_status')) {
      $pdo->exec("ALTER TABLE project_payment_receipts ADD COLUMN email_status ENUM('not_requested','sent','failed') NOT NULL DEFAULT 'not_requested' AFTER recipient_email");
    }
    if (!db_has_column($pdo, 'project_payment_receipts', 'emailed_at')) {
      $pdo->exec("ALTER TABLE project_payment_receipts ADD COLUMN emailed_at DATETIME NULL AFTER email_status");
    }
    if (!db_has_column($pdo, 'project_payment_receipts', 'email_error')) {
      $pdo->exec("ALTER TABLE project_payment_receipts ADD COLUMN email_error VARCHAR(255) NULL AFTER emailed_at");
    }
    if (!db_has_column($pdo, 'project_payment_receipts', 'created_by')) {
      $pdo->exec("ALTER TABLE project_payment_receipts ADD COLUMN created_by INT UNSIGNED NOT NULL DEFAULT 1 AFTER email_error");
    }
    if (!db_has_column($pdo, 'project_payment_receipts', 'created_at')) {
      $pdo->exec("ALTER TABLE project_payment_receipts ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER created_by");
    }

    try {
      $pdo->exec("ALTER TABLE project_payment_receipts ADD UNIQUE KEY uniq_project_payment_receipt_payment (payment_id)");
    } catch (Throwable $e) {
      // Index may already exist.
    }
    try {
      $pdo->exec("ALTER TABLE project_payment_receipts ADD UNIQUE KEY uniq_project_payment_receipt_no (receipt_no)");
    } catch (Throwable $e) {
      // Index may already exist.
    }
    try {
      $pdo->exec("ALTER TABLE project_payment_receipts ADD INDEX idx_project_payment_receipts_project (project_id, tenant_id, created_at)");
    } catch (Throwable $e) {
      // Index may already exist.
    }
    try {
      $pdo->exec("ALTER TABLE project_payment_receipts ADD INDEX idx_project_payment_receipts_tenant (tenant_id, created_at)");
    } catch (Throwable $e) {
      // Index may already exist.
    }

    $available = true;
    return true;
  } catch (Throwable $e) {
    $available = false;
    return false;
  }
}

function project_payment_generate_receipt_no(): string {
  return 'RCT-' . gmdate('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(4)));
}

function project_payment_create_receipt(
  PDO $pdo,
  int $tenantId,
  int $projectId,
  int $paymentId,
  int $createdBy,
  ?string $recipientEmail
): ?array {
  if (!ensure_project_payment_receipts_table($pdo)) {
    return null;
  }

  $recipient = null;
  if (is_string($recipientEmail)) {
    $recipient = strtolower(trim($recipientEmail));
    if ($recipient === '' || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
      $recipient = null;
    }
  }

  $insert = $pdo->prepare(
    "INSERT INTO project_payment_receipts
      (tenant_id, project_id, payment_id, receipt_no, recipient_email, email_status, created_by)
     VALUES (?, ?, ?, ?, ?, 'not_requested', ?)"
  );

  for ($attempt = 0; $attempt < 5; $attempt++) {
    $receiptNo = project_payment_generate_receipt_no();
    try {
      $insert->execute([$tenantId, $projectId, $paymentId, $receiptNo, $recipient, $createdBy]);
      return [
        'id' => (int)$pdo->lastInsertId(),
        'receipt_no' => $receiptNo,
        'recipient_email' => $recipient,
        'email_status' => 'not_requested',
      ];
    } catch (Throwable $e) {
      $sqlState = (string)($e instanceof PDOException ? ($e->errorInfo[0] ?? '') : '');
      if ($sqlState === '23000') {
        continue;
      }
      return null;
    }
  }

  return null;
}

function project_payment_update_receipt_email_status(
  PDO $pdo,
  int $receiptId,
  string $status,
  ?string $errorMessage = null
): void {
  if ($receiptId <= 0 || !ensure_project_payment_receipts_table($pdo)) {
    return;
  }

  $normalized = strtolower(trim($status));
  if (!in_array($normalized, ['not_requested', 'sent', 'failed'], true)) {
    $normalized = 'failed';
  }

  $error = trim((string)$errorMessage);
  if ($error !== '') {
    $error = mb_substr($error, 0, 255);
  } else {
    $error = '';
  }

  $stmt = $pdo->prepare(
    "UPDATE project_payment_receipts
     SET email_status = ?,
         emailed_at = CASE WHEN ? = 'sent' OR ? = 'failed' THEN NOW() ELSE NULL END,
         email_error = ?
     WHERE id = ?"
  );
  $stmt->execute([
    $normalized,
    $normalized,
    $normalized,
    $error === '' ? null : $error,
    $receiptId,
  ]);
}

function project_payment_mail_header_clean(string $value): string {
  return trim(str_replace(["\r", "\n"], ' ', $value));
}

function project_payment_html_to_text(string $html): string {
  $text = preg_replace('#<(br|/p|/div|/li|/h[1-6])\b[^>]*>#i', "\n", $html) ?? $html;
  $text = strip_tags($text);
  $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
  $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
  return trim($text);
}

function project_payment_compose_receipt_html(array $project, array $payment, array $receipt): string {
  $receiptNo = htmlspecialchars((string)($receipt['receipt_no'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $projectNo = htmlspecialchars((string)($project['project_no'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $projectTitle = htmlspecialchars((string)($project['title'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $clientName = htmlspecialchars((string)($project['client_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $amount = number_format((float)($payment['amount'] ?? 0.0), 2);
  $method = htmlspecialchars(project_payment_method_label((string)($payment['method'] ?? 'other')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $receivedAt = htmlspecialchars((string)($payment['received_at'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $referenceRaw = trim((string)($payment['reference'] ?? ''));
  $noteRaw = trim((string)($payment['note'] ?? ''));
  $reference = $referenceRaw === '' ? 'N/A' : htmlspecialchars($referenceRaw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $note = $noteRaw === '' ? 'N/A' : nl2br(htmlspecialchars($noteRaw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

  return
    "<!doctype html>\n" .
    "<html><head><meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\"><title>Payment Receipt</title></head>\n" .
    "<body style=\"margin:0;padding:0;background:#f3f5f9;font-family:Arial,Helvetica,sans-serif;color:#1d2838;\">\n" .
    "<table role=\"presentation\" width=\"100%\" cellspacing=\"0\" cellpadding=\"0\"><tr><td align=\"center\" style=\"padding:24px 12px;\">\n" .
    "<table role=\"presentation\" width=\"620\" cellspacing=\"0\" cellpadding=\"0\" style=\"max-width:620px;width:100%;background:#ffffff;border:1px solid #e3e8f1;\">\n" .
    "<tr><td style=\"padding:18px 22px;background:#1f4e99;color:#ffffff;\"><h1 style=\"margin:0;font-size:21px;line-height:1.3;\">Payment Receipt</h1><p style=\"margin:6px 0 0;font-size:14px;line-height:1.4;\">Receipt {$receiptNo}</p></td></tr>\n" .
    "<tr><td style=\"padding:20px 22px;\">\n" .
    "<p style=\"margin:0 0 12px;\">Hello {$clientName},</p>\n" .
    "<p style=\"margin:0 0 12px;\">We received your payment. Thank you.</p>\n" .
    "<p style=\"margin:0 0 8px;\"><strong>Project:</strong> {$projectNo} | {$projectTitle}</p>\n" .
    "<p style=\"margin:0 0 8px;\"><strong>Amount:</strong> \${$amount}</p>\n" .
    "<p style=\"margin:0 0 8px;\"><strong>Date received:</strong> {$receivedAt}</p>\n" .
    "<p style=\"margin:0 0 8px;\"><strong>Method:</strong> {$method}</p>\n" .
    "<p style=\"margin:0 0 8px;\"><strong>Reference #:</strong> {$reference}</p>\n" .
    "<p style=\"margin:0 0 8px;\"><strong>Note:</strong><br>{$note}</p>\n" .
    "</td></tr>\n" .
    "<tr><td style=\"padding:12px 22px;background:#f7f9fc;color:#5a6a82;font-size:12px;line-height:1.5;\">This is an automated receipt from CorePanel.</td></tr>\n" .
    "</table></td></tr></table></body></html>";
}

function project_payment_send_receipt_email(
  string $to,
  string $subject,
  string $htmlBody,
  string $fromEmail,
  string $fromName,
  ?string $replyTo,
  ?string &$error
): bool {
  $error = null;
  $cleanTo = project_payment_mail_header_clean($to);
  $cleanSubject = project_payment_mail_header_clean($subject);
  $cleanFromEmail = project_payment_mail_header_clean($fromEmail);
  $cleanFromName = project_payment_mail_header_clean($fromName);
  $cleanReplyTo = $replyTo !== null ? project_payment_mail_header_clean($replyTo) : null;

  $boundary = 'corepanel-payment-' . bin2hex(random_bytes(12));
  $textBody = project_payment_html_to_text($htmlBody);
  if ($textBody === '') {
    $textBody = 'Payment receipt attached in HTML format.';
  }

  $messageId = sprintf('<%s@%s>', bin2hex(random_bytes(12)), 'corepanel.local');
  $headers = [
    'Date: ' . date(DATE_RFC2822),
    'Message-ID: ' . $messageId,
    'MIME-Version: 1.0',
    'From: ' . $cleanFromName . ' <' . $cleanFromEmail . '>',
    'To: ' . $cleanTo,
    'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    'X-Mailer: CorePanel',
  ];
  if ($cleanReplyTo !== null && $cleanReplyTo !== '' && filter_var($cleanReplyTo, FILTER_VALIDATE_EMAIL)) {
    $headers[] = 'Reply-To: ' . $cleanReplyTo;
  }

  $body = '';
  $body .= '--' . $boundary . "\r\n";
  $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
  $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
  $body .= $textBody . "\r\n\r\n";
  $body .= '--' . $boundary . "\r\n";
  $body .= "Content-Type: text/html; charset=UTF-8\r\n";
  $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
  $body .= $htmlBody . "\r\n\r\n";
  $body .= '--' . $boundary . "--\r\n";

  $headersString = implode("\r\n", $headers);
  $sent = @mail($cleanTo, $cleanSubject, $body, $headersString);

  if (!$sent) {
    $lastError = error_get_last();
    $error = is_array($lastError) ? (string)($lastError['message'] ?? '') : '';
    if ($error === '') {
      $error = 'Mail transport rejected the receipt.';
    }
  }

  return $sent;
}
