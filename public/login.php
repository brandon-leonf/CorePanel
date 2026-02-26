<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../config/db.php';
require __DIR__ . '/../src/helpers.php';
require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/security.php';
require __DIR__ . '/../src/rate_limit.php';

start_session();
if (!empty($_SESSION['user_id'])) {
  destroy_current_session();
}
clear_pending_twofa_session();

$errors = [];
$email = '';
$twofaColumnsReady = ensure_user_twofa_columns($pdo);
ensure_access_control_schema($pdo);
$captchaRequired = false;
$captchaQuestion = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token');
  }

  $email = strtolower(trim((string)($_POST['email'] ?? '')));
  $pass  = (string)($_POST['password'] ?? '');

  $looksValid = $email !== '' &&
    strlen($email) <= 190 &&
    filter_var($email, FILTER_VALIDATE_EMAIL) &&
    strlen($pass) <= 128;

  $ip = rl_client_ip();
  $rateKeys = [
    rl_key_ip($ip),
    rl_key_identity('username', $email !== '' ? $email : 'unknown'),
  ];
  $rateState = rl_precheck($pdo, 'login', $rateKeys);
  $captchaRequired = rl_captcha_required('login', $rateState);
  if ($rateState['blocked']) {
    rl_log_blocked($pdo, 'login', $rateKeys, $email !== '' ? $email : null, (int)$rateState['retry_after']);
    $errors[] = rl_lock_message((int)$rateState['retry_after']);
  }

  $captchaPassed = true;
  if (!$rateState['blocked'] && $captchaRequired) {
    $captchaPassed = rl_captcha_verify('login', (string)($_POST['captcha_answer'] ?? ''));
    if (!$captchaPassed) {
      rl_register_attempt(
        $pdo,
        'login',
        $rateKeys,
        $email !== '' ? $email : null,
        'captcha_failed',
        true
      );
      $errors[] = "Invalid email or password.";
    }
  }

  $u = false;
  if ($looksValid && !$rateState['blocked'] && $captchaPassed) {
    if ($twofaColumnsReady) {
      $stmt = $pdo->prepare(
        "SELECT id, password_hash, role, totp_secret, twofa_enabled_at
         FROM users
         WHERE email = ?
           AND deleted_at IS NULL"
      );
    } else {
      $stmt = $pdo->prepare(
        "SELECT id, password_hash, role
         FROM users
         WHERE email = ?
           AND deleted_at IS NULL"
      );
    }
    $stmt->execute([$email]);
    $u = $stmt->fetch();
  }

  if (!$rateState['blocked'] && $captchaPassed && (!$u || !password_verify($pass, $u['password_hash']))) {
    rl_register_attempt(
      $pdo,
      'login',
      $rateKeys,
      $email !== '' ? $email : null,
      $looksValid ? 'invalid_credentials' : 'invalid_login_input',
      true
    );
    $errors[] = "Invalid email or password.";
  } elseif (!$rateState['blocked'] && $captchaPassed && $u) {
    try {
      if (password_needs_secure_rehash((string)$u['password_hash'])) {
        $newHash = hash_password_secure($pass);
        $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ? AND deleted_at IS NULL")
            ->execute([$newHash, (int)$u['id']]);
      }
    } catch (Throwable $e) {
      // Continue login even if transparent rehash fails.
    }

    $role = (string)($u['role'] ?? 'user');
    $totpSecret = totp_secret_resolve((string)($u['totp_secret'] ?? ''));
    $adminTwofaConfigured = $twofaColumnsReady
      && $role === 'admin'
      && !empty($u['twofa_enabled_at']);

    if ($adminTwofaConfigured && $totpSecret === null) {
      $errors[] = 'Admin 2FA is configured but unavailable. Contact support.';
    } elseif ($adminTwofaConfigured) {
      rl_clear_attempts($pdo, 'login', $rateKeys);
      begin_pending_twofa_session((int)$u['id']);
      redirect('/admin/two_factor_verify.php');
    } else {
      rl_clear_attempts($pdo, 'login', $rateKeys);
      complete_login_session((int)$u['id'], $role);
      $sessionUser = current_user($pdo);
      redirect(user_has_permission($sessionUser ?? [], 'dashboard.admin.view') ? '/admin/dashboard.php' : '/client/dashboard.php');
    }
  }

  if ($captchaRequired) {
    $captchaQuestion = rl_captcha_question('login');
  } else {
    rl_captcha_clear('login');
  }
}
?>

  <?php
  require __DIR__ . '/../src/layout.php';
  render_header('Login • CorePanel');
  ?>

  <div class="container">
    <h1>Login</h1>

    <?php if ($errors): ?>
      <ul>
        <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <label>Email<br><input name="email" type="email" value="<?= e($email) ?>" required></label><br><br>
      <label>Password<br><input name="password" type="password" required></label><br><br>
      <?php if ($captchaRequired && $captchaQuestion !== ''): ?>
        <label>Verification challenge: <?= e($captchaQuestion) ?><br>
          <input name="captcha_answer" inputmode="numeric" autocomplete="off" required>
        </label><br><br>
      <?php endif; ?>
      <button type="submit">Login</button>
    </form>

    <p>No account? <a href="/register.php">Register</a> | <a href="/forgot_password.php">Forgot Password</a></p>
  </div>


<?php render_footer(); ?>
