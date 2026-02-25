<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../config/db.php';
require __DIR__ . '/../src/helpers.php';
require __DIR__ . '/../src/auth.php';

start_session();

$errors = [];
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim((string)($_POST['email'] ?? ''));
  $pass  = (string)($_POST['password'] ?? '');

  $stmt = $pdo->prepare("SELECT id, password_hash FROM users WHERE email = ?");
  $stmt->execute([$email]);
  $u = $stmt->fetch();

  if (!$u || !password_verify($pass, $u['password_hash'])) {
    $errors[] = "Invalid email or password.";
  } else {
    $_SESSION['user_id'] = (int)$u['id'];
    redirect('/dashboard.php');
  }
}
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Login • CorePanel</title></head>
<body>
  <h1>Login</h1>

  <?php if ($errors): ?>
    <ul>
      <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <form method="post">
    <label>Email<br><input name="email" type="email" value="<?= e($email) ?>" required></label><br><br>
    <label>Password<br><input name="password" type="password" required></label><br><br>
    <button type="submit">Login</button>
  </form>

  <p>No account? <a href="/register.php">Register</a></p>
</body>
</html>