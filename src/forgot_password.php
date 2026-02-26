<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../config/db.php';
require __DIR__ . '/../src/helpers.php';
require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/layout.php';
require __DIR__ . '/../src/password_reset.php';
require __DIR__ . '/../src/validation.php';
require __DIR__ . '/../src/rate_limit.php';

$message = '';
$debugLink = null;
$captchaRequired = false;
$captchaQuestion = '';
ensure_access_control_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token');
  }

  $email = strtolower(trim((string)($_POST['email'] ?? '')));
  $ip = rl_client_ip();
  $rateKeys = [
    rl_key_ip($ip),
    rl_key_identity('username', $email !== '' ? $email : 'unknown'),
  ];

  // Always respond generically (prevents email enumeration)
  $message = "If an account exists for that email, a reset link was generated.";
  $rateState = rl_precheck($pdo, 'forgot_password', $rateKeys);
  $captchaRequired = rl_captcha_required('forgot_password', $rateState);
  if ($rateState['blocked']) {
    rl_log_blocked($pdo, 'forgot_password', $rateKeys, $email !== '' ? $email : null, (int)$rateState['retry_after']);
    $message .= ' ' . rl_lock_message((int)$rateState['retry_after']);
  }

  $captchaPassed = true;
  if (!$rateState['blocked'] && $captchaRequired) {
    $captchaPassed = rl_captcha_verify('forgot_password', (string)($_POST['captcha_answer'] ?? ''));
    if (!$captchaPassed) {
      $message .= ' Complete the verification challenge and try again.';
    }
  }

  $requestIssued = false;
  if (!$rateState['blocked'] && $captchaPassed && $email !== '' && strlen($email) <= 190 && filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([$email]);
    $u = $stmt->fetch();

    if ($u) {
      $token = pr_create_reset($pdo, (int)$u['id'], 15);
      $requestIssued = true;

      // For local dev only: show the link instead of emailing it.
      if (app_debug_enabled()) {
        $debugLink = "/reset_password.php?token=" . urlencode($token);
      }
      // In production: send email with full URL via mail provider.
    }
  }

  if (!$rateState['blocked']) {
    $reason = 'forgot_password_request_unmatched';
    $isFailure = true;
    if (!$captchaPassed) {
      $reason = 'captcha_failed';
    } elseif ($requestIssued) {
      $reason = 'reset_link_generated';
      $isFailure = false;
    }

    rl_register_attempt(
      $pdo,
      'forgot_password',
      $rateKeys,
      $email !== '' ? $email : null,
      $reason,
      $isFailure
    );
  }

  if ($captchaRequired) {
    $captchaQuestion = rl_captcha_question('forgot_password');
  } else {
    rl_captcha_clear('forgot_password');
  }
}

render_header('Forgot Password • CorePanel');
?>
<div class="container">
  <h1>Forgot Password</h1>

  <?php if ($message): ?>
    <p><?= e($message) ?></p>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <label>Email<br>
      <input name="email" type="email" required>
    </label>
    <?php if ($captchaRequired && $captchaQuestion !== ''): ?>
      <label>Verification challenge: <?= e($captchaQuestion) ?><br>
        <input name="captcha_answer" inputmode="numeric" autocomplete="off" required>
      </label>
    <?php endif; ?>
    <button type="submit">Send reset link</button>
  </form>

  <?php if ($debugLink): ?>
    <p><strong>Dev link:</strong> <a href="<?= e_url_attr($debugLink, '/forgot_password.php') ?>"><?= e($debugLink) ?></a></p>
  <?php endif; ?>

  <p><a href="/login.php">Back to login</a></p>
</div>
<?php render_footer(); ?>
