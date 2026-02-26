<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../config/db.php';
require __DIR__ . '/../../src/helpers.php';
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/layout.php';
require __DIR__ . '/../../src/security.php';
require __DIR__ . '/../../src/totp.php';

start_session();
send_private_no_store_headers();
if (!is_session_bound_to_current_client()) {
  destroy_current_session();
  redirect('/login.php');
}

$pendingUserId = (int)($_SESSION['pending_2fa_user_id'] ?? 0);
$pendingStartedAt = (int)($_SESSION['pending_2fa_started_at'] ?? 0);
$timeoutSeconds = 300;
$errors = [];
$code = '';

if ($pendingUserId <= 0 || $pendingStartedAt <= 0 || (time() - $pendingStartedAt) > $timeoutSeconds) {
  clear_pending_twofa_session();
  redirect('/login.php');
}

if (!ensure_user_twofa_columns($pdo)) {
  clear_pending_twofa_session();
  redirect('/login.php');
}

$stmt = $pdo->prepare(
  "SELECT id, name, email, role, totp_secret, twofa_enabled_at
   FROM users
   WHERE id = ?
     AND deleted_at IS NULL
   LIMIT 1"
);
$stmt->execute([$pendingUserId]);
$user = $stmt->fetch();
$totpSecret = $user ? totp_secret_resolve((string)($user['totp_secret'] ?? '')) : null;

if (!$user || ($user['role'] ?? 'user') !== 'admin' || $totpSecret === null || empty($user['twofa_enabled_at'])) {
  clear_pending_twofa_session();
  redirect('/login.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token');
  }

  $action = (string)($_POST['action'] ?? 'verify');

  if ($action === 'cancel') {
    clear_pending_twofa_session();
    redirect('/login.php');
  }

  $code = preg_replace('/\D+/', '', (string)($_POST['code'] ?? '')) ?? '';
  if ($code === '') {
    $errors[] = 'Authentication code is required.';
  } elseif (!totp_verify_code((string)$totpSecret, $code, 1, 30, 6)) {
    $errors[] = 'Invalid authentication code.';
  } else {
    clear_pending_twofa_session();
    complete_login_session((int)$user['id'], (string)$user['role']);
    redirect('/admin/dashboard.php');
  }
}

render_header('Admin 2FA Verification • CorePanel');
?>
<div class="container">
  <h1>Admin 2FA Verification</h1>
  <p>Enter the 6-digit code from your authenticator app.</p>

  <?php if ($errors): ?>
    <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="verify">

    <label>Authentication code<br>
      <input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required value="<?= e($code) ?>">
    </label><br><br>

    <button type="submit">Verify</button>
  </form>

  <form method="post" style="margin-top:12px;">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="cancel">
    <button type="submit">Cancel</button>
  </form>
</div>
<?php render_footer(); ?>
