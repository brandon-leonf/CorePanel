<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../config/db.php';
require __DIR__ . '/../src/helpers.php';
require __DIR__ . '/../src/auth.php';

start_session();

$errors = [];
$name = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name  = trim((string)($_POST['name'] ?? ''));
  $email = trim((string)($_POST['email'] ?? ''));
  $pass  = (string)($_POST['password'] ?? '');

  if ($name === '') $errors[] = "Name is required.";
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Valid email is required.";
  if (strlen($pass) < 8) $errors[] = "Password must be at least 8 characters.";

  if (!$errors) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
      $errors[] = "Email is already registered.";
    } else {
      $hash = password_hash($pass, PASSWORD_DEFAULT);
      $ins = $pdo->prepare("INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)");
      $ins->execute([$name, $email, $hash]);

      $_SESSION['user_id'] = (int)$pdo->lastInsertId();
      redirect('/dashboard.php');
    }
  }
}
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Register • CorePanel</title></head>
<body>
  <h1>Register</h1>

  <?php if ($errors): ?>
    <ul>
      <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <form method="post">
    <label>Name<br><input name="name" value="<?= e($name) ?>" required></label><br><br>
    <label>Email<br><input name="email" type="email" value="<?= e($email) ?>" required></label><br><br>
    <label>Password<br><input name="password" type="password" required></label><br><br>
    <button type="submit">Create account</button>
  </form>

  <p>Already have an account? <a href="/login.php">Login</a></p>
</body>
</html>