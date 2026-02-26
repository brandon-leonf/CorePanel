<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/error_handling.php';
app_configure_error_handling();

$cfgPath = __DIR__ . '/db.local.php';
if (!file_exists($cfgPath)) {
  http_response_code(500);
  if (app_debug_enabled()) {
    exit("Missing config/db.local.php (create it from the template).");
  }
  exit('Server configuration error.');
}

$obLevel = ob_get_level();
ob_start();
try {
  $cfg = require $cfgPath;
} finally {
  while (ob_get_level() > $obLevel) {
    ob_end_clean();
  }
}

if (!is_array($cfg)) {
  http_response_code(500);
  if (app_debug_enabled()) {
    exit('Invalid config/db.local.php format (expected array).');
  }
  exit('Server configuration error.');
}

$host = (string)($cfg['host'] ?? '127.0.0.1');
$db = (string)($cfg['db'] ?? '');
$charset = (string)($cfg['charset'] ?? 'utf8mb4');
$dsn = "mysql:host={$host};dbname={$db};charset={$charset}";
if (isset($cfg['port']) && (int)$cfg['port'] > 0) {
  $dsn .= ';port=' . (int)$cfg['port'];
}
$options = [
  PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
  return new PDO($dsn, $cfg['user'], $cfg['pass'], $options);
} catch (PDOException $e) {
    error_log('[DB CONNECT ERROR] ' . $e->getMessage());
    http_response_code(500);
    if (app_debug_enabled()) {
    exit("DB connection failed: " . $e->getMessage());
    }
    exit('Service temporarily unavailable.');
  }
