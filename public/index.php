<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../config/db.php';

$stmt = $pdo->query("SELECT NOW() AS now_time");
$row = $stmt->fetch();

?>
<!doctype html>
<html>
  <head><meta charset="utf-8"><title>CorePanel</title></head>
  <body>
    <h1>CorePanel</h1>
    <p>DB connected Server time: <?= htmlspecialchars($row['now_time']) ?></p>
  </body>
</html>