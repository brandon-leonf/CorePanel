<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../../config/db.php';
require __DIR__ . '/../../../src/auth.php';
require __DIR__ . '/../../../src/helpers.php';
require __DIR__ . '/../../../src/layout.php';
require __DIR__ . '/../../../src/validation.php';
require __DIR__ . '/../../../src/security.php';
require __DIR__ . '/../../../src/admin_audit.php';

/**
 * Restrict header values to a single line to prevent header injection.
 */
function mail_header_clean(string $value): string {
  return trim(str_replace(["\r", "\n"], ' ', $value));
}

function project_email_domain_from_address(string $email): string {
  $email = strtolower(trim($email));
  if (preg_match('/\A[a-z0-9._%+\-]+@([a-z0-9.\-]+\.[a-z]{2,})\z/i', $email, $matches) === 1) {
    return strtolower((string)$matches[1]);
  }

  $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
  $host = preg_replace('/:\d+\z/', '', $host ?? '');
  if (is_string($host) && preg_match('/\A[a-z0-9.\-]+\.[a-z]{2,}\z/i', $host) === 1) {
    return $host;
  }

  return 'corepanel.local';
}

function project_email_html_to_text(string $html): string {
  $text = preg_replace('#<(br|/p|/div|/li|/h[1-6])\b[^>]*>#i', "\n", $html) ?? $html;
  $text = strip_tags($text);
  $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
  $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
  return trim($text);
}

function send_project_email_message(
  string $to,
  string $subject,
  string $htmlBody,
  string $fromEmail,
  string $fromName,
  ?string $replyTo,
  ?string &$error
): bool {
  $error = null;
  $cleanTo = mail_header_clean($to);
  $cleanSubject = mail_header_clean($subject);
  $cleanFromEmail = mail_header_clean($fromEmail);
  $cleanFromName = mail_header_clean($fromName);
  $cleanReplyTo = $replyTo !== null ? mail_header_clean($replyTo) : null;

  $boundary = 'corepanel-alt-' . bin2hex(random_bytes(12));
  $textBody = project_email_html_to_text($htmlBody);
  if ($textBody === '') {
    $textBody = 'Project update attached in HTML format.';
  }

  $domain = project_email_domain_from_address($fromEmail);
  $messageId = sprintf('<%s@%s>', bin2hex(random_bytes(12)), $domain);

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

  $additionalParams = '';
  $envelopeFrom = strtolower(trim((string)($_ENV['COREPANEL_MAIL_ENVELOPE_FROM'] ?? getenv('COREPANEL_MAIL_ENVELOPE_FROM') ?: $fromEmail)));
  if ($envelopeFrom !== '' && filter_var($envelopeFrom, FILTER_VALIDATE_EMAIL)) {
    $additionalParams = '-f' . $envelopeFrom;
  }

  $headersString = implode("\r\n", $headers);
  $sent = $additionalParams !== ''
    ? @mail($cleanTo, $cleanSubject, $body, $headersString, $additionalParams)
    : @mail($cleanTo, $cleanSubject, $body, $headersString);

  if (!$sent) {
    $lastError = error_get_last();
    $error = is_array($lastError) ? (string)($lastError['message'] ?? '') : '';
    if ($error === '') {
      $error = 'Mail transport rejected the message.';
    }
  }

  return $sent;
}

function compose_default_project_email_html(array $project): string {
  $projectNo = htmlspecialchars((string)($project['project_no'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $title = htmlspecialchars((string)($project['title'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $clientName = htmlspecialchars((string)($project['client_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $status = htmlspecialchars((string)($project['status'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $projectAddress = trim((string)(security_read_project_address($project['project_address'] ?? null) ?? ''));
  $projectAddressHtml = $projectAddress === ''
    ? ''
    : "<p style=\"margin:0 0 10px;\"><strong>Project address:</strong><br>" . nl2br(htmlspecialchars($projectAddress, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . "</p>";
  $description = trim((string)($project['description'] ?? ''));
  $descriptionHtml = $description === ''
    ? '<p style="margin:0 0 12px;">No additional project summary is available right now.</p>'
    : '<p style="margin:0 0 12px;">' . nl2br(htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>';

  return
    "<!doctype html>\n" .
    "<html>\n" .
    "<head>\n" .
    "  <meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\">\n" .
    "  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n" .
    "  <title>Project Update</title>\n" .
    "</head>\n" .
    "<body style=\"margin:0;padding:0;background:#f3f5f9;font-family:Arial,Helvetica,sans-serif;color:#1d2838;\">\n" .
    "  <table role=\"presentation\" width=\"100%\" cellspacing=\"0\" cellpadding=\"0\">\n" .
    "    <tr>\n" .
    "      <td align=\"center\" style=\"padding:24px 12px;\">\n" .
    "        <table role=\"presentation\" width=\"620\" cellspacing=\"0\" cellpadding=\"0\" style=\"max-width:620px;width:100%;background:#ffffff;border:1px solid #e3e8f1;\">\n" .
    "          <tr>\n" .
    "            <td style=\"padding:18px 22px;background:#1f4e99;color:#ffffff;\">\n" .
    "              <h1 style=\"margin:0;font-size:21px;line-height:1.3;\">Project Update</h1>\n" .
    "              <p style=\"margin:6px 0 0;font-size:14px;line-height:1.4;\">{$projectNo} | {$title}</p>\n" .
    "            </td>\n" .
    "          </tr>\n" .
    "          <tr>\n" .
    "            <td style=\"padding:20px 22px;\">\n" .
    "              <p style=\"margin:0 0 12px;\">Hello {$clientName},</p>\n" .
    "              <p style=\"margin:0 0 12px;\">This is your latest project update.</p>\n" .
    "              <p style=\"margin:0 0 10px;\"><strong>Current status:</strong> {$status}</p>\n" .
    "              {$projectAddressHtml}\n" .
    "              {$descriptionHtml}\n" .
    "              <p style=\"margin:16px 0 0;\">If you have questions, reply to this email and we will follow up.</p>\n" .
    "            </td>\n" .
    "          </tr>\n" .
    "          <tr>\n" .
    "            <td style=\"padding:12px 22px;background:#f7f9fc;color:#5a6a82;font-size:12px;line-height:1.5;\">\n" .
    "              You are receiving this update because you are the client for project {$projectNo}.\n" .
    "            </td>\n" .
    "          </tr>\n" .
    "        </table>\n" .
    "      </td>\n" .
    "    </tr>\n" .
    "  </table>\n" .
    "</body>\n" .
    "</html>";
}

$me = require_any_permission($pdo, ['projects.view.any', 'projects.view.own']);
$tenantId = actor_tenant_id($me);

$projectId = (int)($_GET['id'] ?? ($_POST['project_id'] ?? 0));
if ($projectId <= 0) {
  http_response_code(400);
  exit('Bad request');
}
require_project_access($pdo, $me, $projectId, 'view');

$hasProjectAddress = db_has_column($pdo, 'projects', 'project_address');
$hasUserPhone = db_has_column($pdo, 'users', 'phone');
$projectAddressSelect = $hasProjectAddress ? 'p.project_address' : 'NULL AS project_address';
$userPhoneSelect = $hasUserPhone ? 'u.phone AS client_phone' : 'NULL AS client_phone';

$stmt = $pdo->prepare(
  "SELECT
      p.id,
      p.project_no,
      p.title,
      p.description,
      p.status,
      p.user_id,
      {$projectAddressSelect},
      u.id AS client_id,
      u.name AS client_name,
      u.email AS client_email,
      {$userPhoneSelect}
   FROM projects p
   JOIN users u ON u.id = p.user_id
   WHERE p.id = ? AND p.tenant_id = ?
   LIMIT 1"
);
$stmt->execute([$projectId, $tenantId]);
$project = $stmt->fetch();
if (!$project) {
  http_response_code(404);
  exit('Project not found');
}

$clientEmail = strtolower(trim((string)($project['client_email'] ?? '')));
if ($clientEmail === '' || filter_var($clientEmail, FILTER_VALIDATE_EMAIL) === false) {
  http_response_code(400);
  exit('Client email is not configured for this project.');
}

$clientPhone = security_read_user_phone($project['client_phone'] ?? null);
$projectAddress = security_read_project_address($project['project_address'] ?? null);
$errors = [];
$sentMessage = null;

$subject = normalize_single_line((string)($_POST['subject'] ?? ''));
if ($subject === '') {
  $subject = 'Project update: ' . (string)$project['project_no'] . ' — ' . (string)$project['title'];
}
$htmlBody = (string)($_POST['html_body'] ?? '');
if (trim($htmlBody) === '') {
  $htmlBody = compose_default_project_email_html($project);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token');
  }

  $subject = normalize_single_line((string)($_POST['subject'] ?? ''));
  $htmlBody = (string)($_POST['html_body'] ?? '');

  validate_required_text($subject, 'Subject', 190, $errors);
  if (trim($htmlBody) === '') {
    $errors[] = 'Email HTML body is required.';
  }
  if (strlen($htmlBody) > 200000) {
    $errors[] = 'Email HTML body is too large.';
  }

  if (!$errors) {
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

    $mailError = null;
    $replyToValue = ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) ? $replyTo : null;
    $sent = send_project_email_message(
      $clientEmail,
      $subject,
      $htmlBody,
      $mailFrom,
      $fromName,
      $replyToValue,
      $mailError
    );

    if ($sent) {
      admin_audit_log(
        $pdo,
        (int)$me['id'],
        'send_project_email',
        (int)$project['client_id'],
        'Sent project email for ' . (string)$project['project_no'] . ' to ' . $clientEmail,
        $tenantId
      );
      $sentMessage = 'Email sent successfully to ' . $clientEmail . '.';
    } else {
      $errors[] = 'Email could not be sent from this server right now.';
      if ($mailError !== null && $mailError !== '') {
        error_log('[MAIL ERROR] ' . $mailError);
      }
    }
  }
}

render_header('Compose Project Email • CorePanel');
?>
<div class="container container-wide admin-project-email-page">
  <h1>Compose Project Email</h1>
  <p><a href="/admin/dashboard.php">← Back to Admin Dashboard</a></p>

  <section class="admin-project-email-meta">
    <p><strong>Project:</strong> <?= e((string)$project['project_no']) ?> — <?= e((string)$project['title']) ?></p>
    <p><strong>Client:</strong> <?= e((string)$project['client_name']) ?> (<?= e($clientEmail) ?>)</p>
    <?php if (trim((string)$clientPhone) !== ''): ?>
      <p><strong>Phone:</strong> <?= e((string)$clientPhone) ?></p>
    <?php endif; ?>
    <?php if (trim((string)$projectAddress) !== ''): ?>
      <p><strong>Project Address:</strong> <?= nl2br(e((string)$projectAddress)) ?></p>
    <?php endif; ?>
  </section>

  <?php if ($sentMessage !== null): ?>
    <p class="admin-project-email-success status-text-success"><?= e($sentMessage) ?></p>
  <?php endif; ?>

  <?php if ($errors): ?>
    <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>

  <form method="post" class="admin-project-email-form">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="project_id" value="<?= (int)$projectId ?>">

    <label>To
      <input type="email" value="<?= e($clientEmail) ?>" readonly>
    </label>

    <label>Subject
      <input name="subject" value="<?= e($subject) ?>" required>
    </label>

    <label>HTML Email Body
      <textarea name="html_body" rows="20" spellcheck="false" required><?= e($htmlBody) ?></textarea>
    </label>

    <button type="submit">Send Email</button>
  </form>
</div>
<?php render_footer(); ?>
