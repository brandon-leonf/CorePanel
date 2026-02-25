<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../config/db.php';
require __DIR__ . '/../src/helpers.php';
require __DIR__ . '/../src/layout.php';

render_header('CorePanel');

$stmt = $pdo->query("SELECT NOW() AS now_time");
$row = $stmt->fetch();
?>

<div class="container">
  <h1>CorePanel</h1>
  <p>DB connected ✅ Server time: <?= e($row['now_time']) ?></p>
  <p><a href="/login.php">Login</a> | <a href="/register.php">Register</a></p>
</div>

<?php render_footer(); ?>