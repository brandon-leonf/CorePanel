<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../config/db.php';
require __DIR__ . '/../src/helpers.php';
require __DIR__ . '/../src/layout.php';
require __DIR__ . '/../src/password_reset.php';

$message = '';
$debugLink = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim((string)($_POST['email'] ?? ''));

  // Always respond generically (prevents email enumeration)
  $message = "If an account exists for that email, a reset link was generated.";

  if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $u = $stmt->fetch();

    if ($u) {
      $token = pr_create_reset($pdo, (int)$u['id'], 30);

      // For local dev: show the link instead of emailing it
      $debugLink = "/reset_password.php?token=" . urlencode($token);
      // Later: send email with full URL.
    }
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
    <label>Email<br>
      <input name="email" type="email" required>
    </label>
    <button type="submit">Send reset link</button>
  </form>

  <?php if ($debugLink): ?>
    <p><strong>Dev link:</strong> <a href="<?= e($debugLink) ?>"><?= e($debugLink) ?></a></p>
  <?php endif; ?>

  <p><a href="/login.php">Back to login</a></p>
</div>
<?php render_footer(); ?>