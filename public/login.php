<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../config/db.php';
require __DIR__ . '/../src/helpers.php';
require __DIR__ . '/../src/auth.php';

start_session();

$errors = [];
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token');
  }

  $email = trim((string)($_POST['email'] ?? ''));
  $pass  = (string)($_POST['password'] ?? '');

  $stmt = $pdo->prepare("SELECT id, password_hash FROM users WHERE email = ?");
  $stmt->execute([$email]);
  $u = $stmt->fetch();

  if (!$u || !password_verify($pass, $u['password_hash'])) {
    $errors[] = "Invalid email or password.";
  } else {
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$u['id'];
    $stmtR = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmtR->execute([$_SESSION['user_id']]);
    $role = ($stmtR->fetch()['role'] ?? 'user');
    redirect($role === 'admin' ? '/admin/dashboard.php' : '/client/dashboard.php');
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
      <button type="submit">Login</button>
    </form>

    <p>No account? <a href="/register.php">Register</a> | <a href="/forgot_password.php">Forgot Password</a></p>
  </div>


<?php render_footer(); ?>
