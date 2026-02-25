<?php
declare(strict_types=1);

$cfgPath = __DIR__ . '/db.local.php';
if (!file_exists($cfgPath)) {
  http_response_code(500);
  exit("Missing config/db.local.php (create it from the template).");
}

$cfg = require $cfgPath;

$dsn = "mysql:host={$cfg['host']};dbname={$cfg['db']};charset={$cfg['charset']}";
$options = [
  PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
  return new PDO($dsn, $cfg['user'], $cfg['pass'], $options);
} catch (PDOException $e) {
    http_response_code(500);
    exit("DB connection failed: " . $e->getMessage());
  }