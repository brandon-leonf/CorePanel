<?php
declare(strict_types=1);

$requestPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$requestPath = is_string($requestPath) ? $requestPath : '/';
if (!in_array($requestPath, ['/', '/index.php'], true)) {
  http_response_code(404);
  header('Content-Type: text/plain; charset=UTF-8');
  exit('Not found');
}

$pdo = require __DIR__ . '/../config/db.php';
require __DIR__ . '/../src/helpers.php';
require __DIR__ . '/../src/layout.php';

render_header('CorePanel');

$stmt = $pdo->prepare("SELECT NOW() AS now_time");
$stmt->execute();
$row = $stmt->fetch();
?>

<div class="container">
  <h1>CorePanel</h1>
  <p>DB connected ✅ Server time: <?= e($row['now_time']) ?></p>
  <p><a href="/login.php">Login</a> | <a href="/register.php">Register</a></p>
</div>

<?php render_footer(); ?>
