<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../../config/db.php';
require __DIR__ . '/../../../src/helpers.php';
require __DIR__ . '/../../../src/auth.php';
require __DIR__ . '/../../../src/layout.php';
require __DIR__ . '/../../../src/admin_audit.php';
require __DIR__ . '/../../../src/validation.php';
require __DIR__ . '/../../../src/security.php';

$me = require_permission($pdo, 'users.create');
$tenantId = actor_tenant_id($me);

$errors = [];
$name = '';
$email = '';
$tempPass = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token');
  }

  $name = normalize_single_line((string)($_POST['name'] ?? ''));
  $email = validate_email_input((string)($_POST['email'] ?? ''), $errors);

  validate_required_text($name, 'Name', 100, $errors);

  if (!$errors) {
    $exists = $pdo->prepare("SELECT id, deleted_at FROM users WHERE email = ? LIMIT 1");
    $exists->execute([$email]);
    $existingUser = $exists->fetch();
    if ($existingUser) {
      if (trim((string)($existingUser['deleted_at'] ?? '')) !== '') {
        $errors[] = 'Email belongs to a deleted client. Restore that client from Manage Users.';
      } else {
        $errors[] = 'Email already exists.';
      }
    } else {
      try {
        $tempPass = generate_temporary_password(16);
        $hash = hash_password_secure($tempPass);

        // role defaults to 'user' (client), tenant-scoped
        $ins = $pdo->prepare(
          "INSERT INTO users (name, email, password_hash, role, tenant_id)
           VALUES (?, ?, ?, 'user', ?)"
        );
        $ins->execute([$name, $email, $hash, $tenantId]);
        $newUserId = (int)$pdo->lastInsertId();
        sync_user_legacy_role_binding($pdo, $newUserId, 'user');

        if ($me) {
          admin_audit_log(
            $pdo,
            (int)$me['id'],
            'create_user',
            $newUserId,
            "Created user {$email} with role user",
            $tenantId
          );
        }
      } catch (Throwable $e) {
        $errors[] = "Unable to create client right now.";
        $tempPass = null;
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
