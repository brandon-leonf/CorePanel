<?php
declare(strict_types=1);

function health_env_value(string $name, ?string $default = null): ?string {
  $raw = $_ENV[$name] ?? getenv($name);
  if (!is_string($raw)) {
    return $default;
  }

  $value = trim($raw);
  if ($value === '') {
    return $default;
  }

  return $value;
}

function health_env_int(string $name, int $default): int {
  $raw = health_env_value($name);
  if ($raw === null) {
    return $default;
  }
  if (!preg_match('/\A-?[0-9]+\z/', $raw)) {
    return $default;
  }
  return (int)$raw;
}

function health_project_root(): string {
  return dirname(__DIR__);
}

function health_request_method(): string {
  return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
}

function health_extract_token(): ?string {
  $headerToken = trim((string)($_SERVER['HTTP_X_HEALTH_TOKEN'] ?? ''));
  if ($headerToken !== '') {
    return $headerToken;
  }

  $authHeader = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
  if ($authHeader !== '' && preg_match('/\ABearer\s+(.+)\z/i', $authHeader, $matches) === 1) {
    $bearer = trim((string)($matches[1] ?? ''));
    if ($bearer !== '') {
      return $bearer;
    }
  }

  $queryToken = trim((string)($_GET['token'] ?? ''));
  if ($queryToken !== '') {
    return $queryToken;
  }

  return null;
}

function health_is_admin_actor(array $actor): bool {
  if (user_has_permission($actor, 'dashboard.admin.view') || user_has_permission($actor, 'security.manage')) {
    return true;
  }

  return strtolower(trim((string)($actor['role'] ?? ''))) === 'admin';
}

function health_authorize(?PDO $pdo): array {
  $configuredToken = health_env_value('COREPANEL_HEALTH_TOKEN');
  $providedToken = health_extract_token();

  if ($configuredToken !== null && $providedToken !== null && hash_equals($configuredToken, $providedToken)) {
    return ['ok' => true, 'mode' => 'token'];
  }

  if ($pdo !== null) {
    $actor = current_user($pdo);
    if (is_array($actor) && health_is_admin_actor($actor)) {
      return [
        'ok' => true,
        'mode' => 'admin',
        'actor_id' => (int)($actor['id'] ?? 0),
      ];
    }
  }

  return ['ok' => false, 'code' => 403, 'message' => 'Forbidden'];
}

function health_connect_db(?string &$error): ?PDO {
  $error = null;
  $cfgPath = health_project_root() . '/config/db.local.php';
  if (!is_file($cfgPath)) {
    $error = 'Missing config/db.local.php';
    return null;
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
    $error = 'Invalid db.local.php format';
    return null;
  }

  $host = (string)($cfg['host'] ?? '127.0.0.1');
  $db = (string)($cfg['db'] ?? '');
  if ($db === '') {
    $error = 'Database name is not configured';
    return null;
  }
  $charset = (string)($cfg['charset'] ?? 'utf8mb4');
  $dsn = "mysql:host={$host};dbname={$db};charset={$charset}";
  if (isset($cfg['port']) && (int)$cfg['port'] > 0) {
    $dsn .= ';port=' . (int)$cfg['port'];
  }

  $options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ];

  try {
    return new PDO($dsn, (string)($cfg['user'] ?? ''), (string)($cfg['pass'] ?? ''), $options);
  } catch (Throwable $e) {
    $error = 'Database connection failed';
    return null;
  }
}

function health_check_db(?PDO $pdo, ?string $connectionError = null): array {
  $started = microtime(true);
  if ($pdo === null) {
    return [
      'ok' => false,
      'error' => $connectionError ?? 'Database connection failed',
      'latency_ms' => round((microtime(true) - $started) * 1000, 2),
    ];
  }

  try {
    $stmt = $pdo->query('SELECT 1 AS ok');
    $row = $stmt ? $stmt->fetch() : false;
    $dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    return [
      'ok' => (bool)$row,
      'database' => $dbName,
      'latency_ms' => round((microtime(true) - $started) * 1000, 2),
    ];
  } catch (Throwable $e) {
    return [
      'ok' => false,
      'error' => 'Database query failed',
      'latency_ms' => round((microtime(true) - $started) * 1000, 2),
    ];
  }
}

function health_check_disk(): array {
  $pathRaw = health_env_value('COREPANEL_HEALTH_DISK_PATH', health_project_root()) ?? health_project_root();
  $path = realpath($pathRaw);
  $pathToCheck = is_string($path) && $path !== '' ? $path : $pathRaw;
  $minMb = max(1, health_env_int('COREPANEL_HEALTH_MIN_DISK_FREE_MB', 512));
  $minBytes = $minMb * 1024 * 1024;

  $free = @disk_free_space($pathToCheck);
  $total = @disk_total_space($pathToCheck);
  if (!is_float($free) || !is_float($total) || $total <= 0) {
    return [
      'ok' => false,
      'path' => $pathToCheck,
      'error' => 'Disk stats unavailable',
    ];
  }

  $percentFree = round(($free / $total) * 100, 2);
  return [
    'ok' => $free >= $minBytes,
    'path' => $pathToCheck,
    'free_bytes' => (int)$free,
    'total_bytes' => (int)$total,
    'free_percent' => $percentFree,
    'threshold_bytes' => $minBytes,
  ];
}

function health_check_encryption(): array {
  $ready = security_sensitive_encryption_ready();
  return [
    'ok' => $ready,
    'active_key_id' => security_active_field_key_id(),
  ];
}

function health_check_queue(?PDO $pdo): array {
  $mode = strtolower((string)(health_env_value('COREPANEL_HEALTH_QUEUE_MODE', 'none') ?? 'none'));
  if (in_array($mode, ['none', 'off', 'disabled'], true)) {
    return [
      'ok' => true,
      'mode' => 'none',
      'detail' => 'Queue check disabled',
    ];
  }

  if ($mode === 'db') {
    if ($pdo === null) {
      return [
        'ok' => false,
        'mode' => 'db',
        'error' => 'DB unavailable for queue check',
      ];
    }

    $table = (string)(health_env_value('COREPANEL_HEALTH_QUEUE_TABLE', 'job_queue') ?? 'job_queue');
    if (preg_match('/\A[a-zA-Z0-9_]{1,64}\z/', $table) !== 1) {
      return [
        'ok' => false,
        'mode' => 'db',
        'error' => 'Invalid queue table name',
      ];
    }

    try {
      $existsStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?"
      );
      $existsStmt->execute([$table]);
      $exists = (int)$existsStmt->fetchColumn() > 0;
      if (!$exists) {
        return [
          'ok' => false,
          'mode' => 'db',
          'error' => 'Queue table not found',
          'table' => $table,
        ];
      }

      $countStmt = $pdo->query('SELECT COUNT(*) FROM `' . $table . '`');
      $queued = (int)($countStmt ? $countStmt->fetchColumn() : 0);
      return [
        'ok' => true,
        'mode' => 'db',
        'table' => $table,
        'queued_jobs' => $queued,
      ];
    } catch (Throwable $e) {
      return [
        'ok' => false,
        'mode' => 'db',
        'error' => 'Queue DB check failed',
      ];
    }
  }

  if ($mode === 'filesystem') {
    $queueDir = (string)(health_env_value('COREPANEL_HEALTH_QUEUE_DIR', health_project_root() . '/storage/queue') ?? (health_project_root() . '/storage/queue'));
    $dirPath = realpath($queueDir) ?: $queueDir;
    if (!is_dir($dirPath) || !is_readable($dirPath) || !is_writable($dirPath)) {
      return [
        'ok' => false,
        'mode' => 'filesystem',
        'path' => $dirPath,
        'error' => 'Queue directory missing or not writable',
      ];
    }

    $heartbeatFileRaw = health_env_value('COREPANEL_HEALTH_QUEUE_HEARTBEAT_FILE');
    if ($heartbeatFileRaw !== null) {
      $heartbeatPath = $heartbeatFileRaw;
      if (!str_starts_with($heartbeatPath, '/')) {
        $heartbeatPath = rtrim($dirPath, '/') . '/' . ltrim($heartbeatPath, '/');
      }

      if (!is_file($heartbeatPath)) {
        return [
          'ok' => false,
          'mode' => 'filesystem',
          'path' => $dirPath,
          'error' => 'Queue heartbeat file missing',
        ];
      }

      $maxAge = max(10, health_env_int('COREPANEL_HEALTH_QUEUE_HEARTBEAT_MAX_AGE_SECONDS', 600));
      $modifiedAt = (int)@filemtime($heartbeatPath);
      if ($modifiedAt <= 0 || (time() - $modifiedAt) > $maxAge) {
        return [
          'ok' => false,
          'mode' => 'filesystem',
          'path' => $dirPath,
          'error' => 'Queue heartbeat is stale',
          'max_age_seconds' => $maxAge,
        ];
      }
    }

    return [
      'ok' => true,
      'mode' => 'filesystem',
      'path' => $dirPath,
    ];
  }

  return [
    'ok' => false,
    'mode' => $mode,
    'error' => 'Unsupported queue mode',
  ];
}

function health_overall_status(array $checks): string {
  foreach ($checks as $check) {
    if (!is_array($check) || !($check['ok'] ?? false)) {
      return 'fail';
    }
  }
  return 'ok';
}

function health_send_json(int $statusCode, array $payload): never {
  if (function_exists('send_security_headers')) {
    send_security_headers(false);
  }
  if (function_exists('send_private_no_store_headers')) {
    send_private_no_store_headers();
  }

  http_response_code($statusCode);
  header('Content-Type: application/json; charset=UTF-8');

  if (health_request_method() === 'HEAD') {
    exit;
  }

  $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
  if (!is_string($json)) {
    $json = '{"status":"fail","error":"json_encode_failed"}';
  }
  echo $json;
  exit;
}
