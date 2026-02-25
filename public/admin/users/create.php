<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../../config/db.php';
require __DIR__ . '/../../../src/helpers.php';
require __DIR__ . '/../../../src/auth.php';
require __DIR__ . '/../../../src/layout.php';
require __DIR__ . '/../../../src/admin_audit.php';

require_admin($pdo);
$me = current_user($pdo);

$errors = [];
$name = '';
$email = '';
$tempPass = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token');
  }

  $name = trim((string)($_POST['name'] ?? ''));
  $email = trim((string)($_POST['email'] ?? ''));

  if ($name === '') $errors[] = "Name is required.";
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Valid email required.";

  if (!$errors) {
    $exists = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $exists->execute([$email]);
    if ($exists->fetch()) {
      $errors[] = "Email already exists.";
    } else {
      $tempPass = bin2hex(random_bytes(4)); // 8 chars
      $hash = password_hash($tempPass, PASSWORD_DEFAULT);

      // role defaults to 'user' (client)
      $ins = $pdo->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, 'user')");
      $ins->execute([$name, $email, $hash]);

      if ($me) {
        admin_audit_log(
          $pdo,
          (int)$me['id'],
          'create_user',
          (int)$pdo->lastInsertId(),
          "Created user {$email} with role user"
        );
      }
    }
  }
}

render_header('New Client • CorePanel');
?>
<div class="container">
  <h1>New Client</h1>
  <p><a href="/admin/users/index.php">← Back</a></p>

  <?php if ($errors): ?>
    <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>

  <?php if ($tempPass): ?>
    <p><strong>Client created!</strong></p>
    <p>Temporary password (show once): <code><?= e($tempPass) ?></code></p>
    <p>Client can log in at <a href="/login.php">/login.php</a> and then use “Forgot password” to set their own password.</p>
  <?php else: ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <label>Name<br><input name="name" value="<?= e($name) ?>" required></label><br><br>
      <label>Email<br><input name="email" type="email" value="<?= e($email) ?>" required></label><br><br>
      <button type="submit">Create Client</button>
    </form>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
