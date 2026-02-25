<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../config/db.php';

$email = $argv[1] ?? '';
if ($email === '') {
  fwrite(STDERR, "Usage: php scripts/make_admin.php you@email.com\n");
  exit(1);
}

$stmt = $pdo->prepare("UPDATE users SET role='admin' WHERE email=?");
$stmt->execute([$email]);

echo "Updated rows: " . $stmt->rowCount() . PHP_EOL;