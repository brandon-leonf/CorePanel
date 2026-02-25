<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../config/db.php';
require __DIR__ . '/../src/helpers.php';
require __DIR__ . '/../src/auth.php';

require_login();
$user = current_user($pdo);
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Dashboard • CorePanel</title></head>
<body>
  <h1>Dashboard</h1>

  <p>Welcome, <?= e($user['name'] ?? 'User') ?> (<?= e($user['email'] ?? '') ?>)</p>
  <p>Role: <?= e($user['role'] ?? 'user') ?></p>

  <p>
    <a href="/items/index.php">Manage Items</a> |
    <a href="/logout.php">Logout</a>
  </p>
</body>
</html>