<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../config/db.php';
require __DIR__ . '/../src/helpers.php';
require __DIR__ . '/../src/layout.php';
require __DIR__ . '/../src/password_reset.php';
require __DIR__ . '/../src/security.php';
require __DIR__ . '/../src/rate_limit.php';

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$errors = [];
$success = false;
$genericResetError = 'Unable to reset password. Please request a new reset link.';
$captchaRequired = false;
$captchaQuestion = '';

$ip = rl_client_ip();
$tokenFingerprint = $token !== '' ? substr(hash('sha256', $token), 0, 24) : 'missing';
$rateKeys = [
  rl_key_ip($ip),
  rl_key_identity('reset_token', $tokenFingerprint),
];
$rateState = rl_precheck($pdo, 'reset_password', $rateKeys);
$rateBlocked = (bool)$rateState['blocked'];
$captchaRequired = rl_captcha_required('reset_password', $rateState);

if ($rateBlocked) {
  rl_log_blocked(
    $pdo,
    'reset_password',
    $rateKeys,
    $token !== '' ? $tokenFingerprint : null,
    (int)$rateState['retry_after']
  );
}

$reset = (!$rateBlocked && $token !== '') ? pr_find_valid_reset($pdo, $token) : null;

if (!$rateBlocked && $_SERVER['REQUEST_METHOD'] !== 'POST' && $token !== '' && !$reset) {
  rl_register_attempt(
    $pdo,
    'reset_password',
    $rateKeys,
    $tokenFingerprint,
    'invalid_or_expired_reset_token_get',
    true
  );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token');
  }

  if ($rateBlocked) {
    $errors[] = rl_lock_message((int)$rateState['retry_after']);
  } elseif (!$reset) {
    if ($token !== '') {
      rl_register_attempt(
        $pdo,
        'reset_password',
        $rateKeys,
        $tokenFingerprint,
        'invalid_or_expired_reset_token_post',
        true
      );
    }
    $errors[] = $genericResetError;
  } else {
    $captchaPassed = true;
    if ($captchaRequired) {
      $captchaPassed = rl_captcha_verify('reset_password', (string)($_POST['captcha_answer'] ?? ''));
      if (!$captchaPassed) {
        rl_register_attempt(
          $pdo,
          'reset_password',
          $rateKeys,
          $tokenFingerprint,
          'captcha_failed',
          true
        );
        $errors[] = $genericResetError;
      }
    }

    if ($captchaPassed) {
      $pass = (string)($_POST['password'] ?? '');
      $pass2 = (string)($_POST['password_confirm'] ?? '');

      validate_password_policy($pass, $errors);
      if ($pass !== $pass2) $errors[] = "Passwords do not match.";

      if (!$errors) {
        try {
          $hash = hash_password_secure($pass);
          $success = pr_redeem_and_update_password($pdo, $token, $hash);
          if (!$success) {
            rl_register_attempt(
              $pdo,
              'reset_password',
              $rateKeys,
              $tokenFingerprint,
              'reset_token_redeem_failed',
              true
            );
            $errors[] = $genericResetError;
          } else {
            rl_clear_attempts($pdo, 'reset_password', $rateKeys);
          }
        } catch (Throwable $e) {
          rl_register_attempt(
            $pdo,
            'reset_password',
            $rateKeys,
            $tokenFingerprint,
            'reset_password_update_error',
            true
          );
          $errors[] = "Unable to update password right now. Please request a new reset link.";
        }
      } else {
        rl_register_attempt(
          $pdo,
          'reset_password',
          $rateKeys,
          $tokenFingerprint,
          'reset_password_policy_validation_failed',
          true
        );
      }
    }
  }
}

if ($captchaRequired) {
  $captchaQuestion = rl_captcha_question('reset_password');
} else {
  rl_captcha_clear('reset_password');
}

render_header('Reset Password • CorePanel');
?>
<div class="container">
  <h1>Reset Password</h1>

  <?php if ($success): ?>
    <p>Password updated!</p>
    <p><a href="/login.php">Go to login</a></p>
  <?php else: ?>
    <?php if ($errors): ?>
      <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>

    <?php if (!$reset): ?>
      <p><?= e($genericResetError) ?></p>
      <p><a href="/forgot_password.php">Request a new link</a></p>
    <?php else: ?>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="token" value="<?= e($token) ?>">

        <label>New password<br>
          <input name="password" type="password" required>
        </label>

        <label>Confirm password<br>
          <input name="password_confirm" type="password" required>
        </label>

        <?php if ($captchaRequired && $captchaQuestion !== ''): ?>
          <label>Verification challenge: <?= e($captchaQuestion) ?><br>
            <input name="captcha_answer" inputmode="numeric" autocomplete="off" required>
          </label>
        <?php endif; ?>

        <button type="submit">Update password</button>
      </form>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
