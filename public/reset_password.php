<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../config/db.php';
require __DIR__ . '/../src/helpers.php';
require __DIR__ . '/../src/layout.php';
require __DIR__ . '/../src/password_reset.php';

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$errors = [];
$success = false;

$reset = $token ? pr_find_valid_reset($pdo, $token) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!$reset) {
    $errors[] = "This reset link is invalid or expired.";
  } else {
    $pass = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password_confirm'] ?? '');

    if (strlen($pass) < 8) $errors[] = "Password must be at least 8 characters.";
    if ($pass !== $pass2) $errors[] = "Passwords do not match.";

    if (!$errors) {
      $hash = password_hash($pass, PASSWORD_DEFAULT);

      $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
          ->execute([$hash, (int)$reset['user_id']]);

      pr_mark_used($pdo, (int)$reset['id']);
      $success = true;
    }
  }
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
      <p>This reset link is invalid or expired.</p>
      <p><a href="/forgot_password.php">Request a new link</a></p>
    <?php else: ?>
      <form method="post">
        <input type="hidden" name="token" value="<?= e($token) ?>">

        <label>New password<br>
          <input name="password" type="password" required>
        </label>

        <label>Confirm password<br>
          <input name="password_confirm" type="password" required>
        </label>

        <button type="submit">Update password</button>
      </form>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php render_footer(); ?>