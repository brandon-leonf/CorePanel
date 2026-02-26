<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../config/db.php';
require __DIR__ . '/../../src/helpers.php';
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/layout.php';
require __DIR__ . '/../../src/security.php';
require __DIR__ . '/../../src/totp.php';
require __DIR__ . '/../../src/admin_audit.php';
require_once __DIR__ . '/../../src/rate_limit.php';

$me = require_permission($pdo, 'security.manage');
start_session();
$tenantId = actor_tenant_id($me);

$errors = [];
$messages = [];
$code = '';

if (!$me || (int)($me['id'] ?? 0) <= 0) {
  http_response_code(403);
  exit('Forbidden');
}

if (!ensure_user_twofa_columns($pdo)) {
  $errors[] = 'Unable to load two-factor settings right now.';
}

if ($errors) {
  render_header('Admin Security • CorePanel');
  ?>
  <div class="container">
    <h1>Admin Security</h1>
    <p><a href="/admin/dashboard.php">← Back to Dashboard</a></p>
    <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
  </div>
  <?php
  render_footer();
  exit;
}

$stmt = $pdo->prepare(
  "SELECT id, name, email, role, totp_secret, twofa_enabled_at
   FROM users
   WHERE id = ?
   LIMIT 1"
);
$stmt->execute([(int)$me['id']]);
$admin = $stmt->fetch();

if (!$admin || ($admin['role'] ?? 'user') !== 'admin') {
  http_response_code(403);
  exit('Forbidden');
}

$sensitiveEncryptionReady = security_sensitive_encryption_ready();
$enrollSecret = (string)($_SESSION['twofa_enroll_secret'] ?? '');
$twofaEnabled = admin_twofa_enabled($admin);
if ($twofaEnabled && $enrollSecret !== '') {
  unset($_SESSION['twofa_enroll_secret']);
  $enrollSecret = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token');
  }

  $action = (string)($_POST['action'] ?? '');

  if ($action === 'begin_setup') {
    if ($twofaEnabled) {
      $messages[] = 'Two-factor authentication is already enabled.';
    } elseif (!$sensitiveEncryptionReady) {
      $errors[] = '2FA setup requires COREPANEL_FIELD_KEY to encrypt secrets at rest.';
    } else {
      $enrollSecret = totp_generate_secret(20);
      $_SESSION['twofa_enroll_secret'] = $enrollSecret;
      $messages[] = 'Scan the key with your authenticator app and confirm with a 6-digit code.';
    }
  } elseif ($action === 'confirm_setup') {
    $code = preg_replace('/\D+/', '', (string)($_POST['code'] ?? '')) ?? '';
    if (!$sensitiveEncryptionReady) {
      $errors[] = '2FA setup requires COREPANEL_FIELD_KEY to encrypt secrets at rest.';
    } elseif ($enrollSecret === '') {
      $errors[] = 'Start setup before confirming a code.';
    } elseif (!totp_verify_code($enrollSecret, $code, 1, 30, 6)) {
      $errors[] = 'Invalid authentication code.';
    } else {
      $storedSecret = totp_secret_store_value($enrollSecret);
      if ($storedSecret === null) {
        $errors[] = 'Could not secure the 2FA secret. Try again.';
      }
    }

    if (!$errors) {
      $up = $pdo->prepare(
        "UPDATE users
         SET totp_secret = ?, twofa_enabled_at = NOW()
         WHERE id = ? AND role = 'admin'"
      );
      $up->execute([$storedSecret, (int)$admin['id']]);
      unset($_SESSION['twofa_enroll_secret']);
      $enrollSecret = '';
      $messages[] = 'Two-factor authentication is now enabled.';

      admin_audit_log(
        $pdo,
        (int)$admin['id'],
        'enable_admin_2fa',
        (int)$admin['id'],
        'Enabled TOTP two-factor authentication for admin account',
        $tenantId
      );

      $stmt->execute([(int)$admin['id']]);
      $admin = $stmt->fetch();
      $twofaEnabled = admin_twofa_enabled($admin ?: []);
    }
  } elseif ($action === 'disable_2fa') {
    $up = $pdo->prepare(
      "UPDATE users
       SET totp_secret = NULL, twofa_enabled_at = NULL
       WHERE id = ? AND role = 'admin'"
    );
    $up->execute([(int)$admin['id']]);
    unset($_SESSION['twofa_enroll_secret']);
    $enrollSecret = '';
    $messages[] = 'Two-factor authentication has been disabled.';

    admin_audit_log(
      $pdo,
      (int)$admin['id'],
      'disable_admin_2fa',
      (int)$admin['id'],
      'Disabled TOTP two-factor authentication for admin account',
      $tenantId
    );

    $stmt->execute([(int)$admin['id']]);
    $admin = $stmt->fetch();
    $twofaEnabled = admin_twofa_enabled($admin ?: []);
  }
}

$issuer = 'CorePanel';
$accountLabel = (string)($admin['email'] ?? 'admin');
$otpauthUri = $enrollSecret !== '' ? totp_build_uri($issuer, $accountLabel, $enrollSecret) : '';

rl_ensure_tables($pdo);
$alertsStmt = $pdo->prepare(
  "SELECT id, level, action, subject, details, ip_address, created_at
   FROM security_event_logs
   WHERE event_type = 'security_alert'
     AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0)
   ORDER BY id DESC
   LIMIT 25"
);
$alertsStmt->execute([$tenantId]);
$recentAlerts = $alertsStmt->fetchAll() ?: [];

render_header('Admin Security • CorePanel');
?>
<div class="container container-wide admin-security-page">
  <h1>Admin Security</h1>
  <p><a href="/admin/dashboard.php">← Back to Dashboard</a></p>

  <?php if ($errors): ?>
    <div class="admin-security-alert admin-security-alert-error">
      <?php foreach ($errors as $err): ?>
        <p><?= e($err) ?></p>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($messages): ?>
    <div class="admin-security-alert admin-security-alert-success">
      <?php foreach ($messages as $message): ?>
        <p><?= e($message) ?></p>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <section class="admin-security-panel" aria-labelledby="admin-security-2fa-title">
    <h2 id="admin-security-2fa-title">Two-Factor Authentication (Optional)</h2>
    <?php if (!$sensitiveEncryptionReady): ?>
      <p class="admin-security-note">
        <strong>Configuration required:</strong> set <code>COREPANEL_FIELD_KEY</code> (or keyring vars) to enable encrypted 2FA storage.
      </p>
    <?php else: ?>
      <p class="admin-security-note admin-security-note-success">
        Encryption key is configured. 2FA setup is ready.
      </p>
    <?php endif; ?>

    <?php if ($twofaEnabled): ?>
      <p>Status: <strong class="status-text-success">Enabled</strong></p>
      <form method="post" class="admin-security-form-inline">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="disable_2fa">
        <button type="submit">Disable 2FA</button>
      </form>
    <?php else: ?>
      <p>Status: <strong>Disabled</strong></p>

      <?php if ($enrollSecret === ''): ?>
        <form method="post" class="admin-security-form-inline">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="begin_setup">
          <button type="submit">Start 2FA Setup</button>
        </form>
      <?php else: ?>
        <p><strong>Setup key:</strong> <code><?= e(totp_display_secret($enrollSecret)) ?></code></p>
        <p><strong>otpauth URI:</strong> <code class="admin-security-long-code"><?= e($otpauthUri) ?></code></p>
        <p class="admin-security-note">Add this account in your authenticator app, then enter the current 6-digit code.</p>

        <form method="post" class="admin-security-confirm-form">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="confirm_setup">

          <label>Authentication code<br>
            <input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required value="<?= e($code) ?>">
          </label>

          <button type="submit">Enable 2FA</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <section class="admin-security-panel" aria-labelledby="admin-security-alerts-title">
    <h2 id="admin-security-alerts-title">Recent Security Alerts</h2>
    <?php if (!$recentAlerts): ?>
      <p class="admin-security-note">No alerts recorded yet.</p>
    <?php else: ?>
      <div class="admin-security-table-wrap">
        <table class="admin-security-table" border="1" cellpadding="8" cellspacing="0">
          <thead>
            <tr>
              <th>When</th>
              <th>Level</th>
              <th>Action</th>
              <th>Subject</th>
              <th>Details</th>
              <th>IP</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recentAlerts as $alert): ?>
              <tr>
                <td><?= e((string)$alert['created_at']) ?></td>
                <td><?= e(strtoupper((string)$alert['level'])) ?></td>
                <td><?= e((string)$alert['action']) ?></td>
                <td><?= e((string)($alert['subject'] ?? '')) ?></td>
                <td><?= e((string)($alert['details'] ?? '')) ?></td>
                <td><?= e((string)($alert['ip_address'] ?? '')) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>
<?php render_footer(); ?>
