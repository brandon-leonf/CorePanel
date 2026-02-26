<?php
declare(strict_types=1);

function rl_env_value(string $name): ?string {
  $value = $_ENV[$name] ?? getenv($name);
  if ($value === false || $value === null) {
    return null;
  }

  $normalized = trim((string)$value);
  return $normalized === '' ? null : $normalized;
}

function rl_env_flag(string $name, bool $default = false): bool {
  $value = rl_env_value($name);
  if ($value === null) {
    return $default;
  }

  return !in_array(strtolower($value), ['0', 'false', 'off', 'no'], true);
}

function rl_env_int(string $name, int $default, int $min = 0, int $max = 2147483647): int {
  $value = rl_env_value($name);
  if ($value === null || !is_numeric($value)) {
    return $default;
  }

  $int = (int)$value;
  if ($int < $min) {
    return $min;
  }
  if ($int > $max) {
    return $max;
  }
  return $int;
}

function rl_client_ip(): string {
  $candidates = [
    (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''),
    (string)($_SERVER['HTTP_X_REAL_IP'] ?? ''),
    (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''),
    (string)($_SERVER['REMOTE_ADDR'] ?? ''),
  ];

  foreach ($candidates as $raw) {
    if ($raw === '') {
      continue;
    }

    $parts = explode(',', $raw);
    $candidate = trim((string)$parts[0]);
    if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP)) {
      return $candidate;
    }
  }

  return '0.0.0.0';
}

function rl_client_ip_prefix(string $ip): string {
  if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    $parts = explode('.', $ip);
    if (count($parts) === 4) {
      return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0/24';
    }
    return '0.0.0.0/24';
  }

  if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
    $packed = @inet_pton($ip);
    if ($packed === false || strlen($packed) !== 16) {
      return '0000:0000:0000:0000::/64';
    }

    $prefixBytes = substr($packed, 0, 8);
    $hex = bin2hex($prefixBytes);
    $groups = str_split($hex, 4);
    return strtolower(implode(':', $groups)) . '::/64';
  }

  return '0.0.0.0/24';
}

function rl_key_ip(string $ip): string {
  return 'ip:' . strtolower(trim($ip));
}

function rl_key_identity(string $type, string $value): string {
  $type = strtolower(trim($type));
  if ($type === '') {
    $type = 'key';
  }

  $value = strtolower(trim($value));
  if ($value === '') {
    $value = 'unknown';
  }

  if (strlen($value) > 200) {
    $value = substr($value, 0, 200);
  }

  return $type . ':' . $value;
}

function rl_policy(string $action): array {
  return match ($action) {
    'forgot_password' => [
      'window_seconds' => 3600,
      'delay_after' => 2,
      'max_delay_seconds' => 6,
      'lock_steps' => [5 => 300, 10 => 1800, 20 => 3600],
    ],
    'reset_password' => [
      'window_seconds' => 1800,
      'delay_after' => 2,
      'max_delay_seconds' => 8,
      'lock_steps' => [5 => 300, 8 => 1800, 12 => 3600],
    ],
    'search_items' => [
      'window_seconds' => 300,
      'delay_after' => 6,
      'max_delay_seconds' => 4,
      'lock_steps' => [20 => 120, 40 => 600, 80 => 1800],
    ],
    default => [
      'window_seconds' => 900,
      'delay_after' => 2,
      'max_delay_seconds' => 8,
      'lock_steps' => [5 => 300, 8 => 900, 12 => 3600],
    ],
  };
}

function rl_column_exists(PDO $pdo, string $tableName, string $columnName): bool {
  $stmt = $pdo->prepare(
    "SELECT 1
     FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = ?
       AND COLUMN_NAME = ?
     LIMIT 1"
  );
  $stmt->execute([$tableName, $columnName]);
  return (bool)$stmt->fetchColumn();
}

function rl_monitor_chain_secret(): string {
  static $cached = null;

  if (is_string($cached) && $cached !== '') {
    return $cached;
  }

  $key = rl_env_value('COREPANEL_LOG_CHAIN_KEY');
  if ($key === null) {
    $key = rl_env_value('COREPANEL_FIELD_KEY');
  }

  if ($key === null) {
    $key = hash('sha256', __FILE__ . '|fallback-monitor-chain-key');
    static $warned = false;
    if (!$warned) {
      error_log('[SECURITY WARN] COREPANEL_LOG_CHAIN_KEY is not configured; log chain protection is weakened.');
      $warned = true;
    }
  }

  $cached = $key;
  return $cached;
}

function rl_monitoring_log_file(): string {
  $customPath = rl_env_value('COREPANEL_SECURITY_LOG_FILE');
  if ($customPath !== null) {
    return $customPath;
  }

  return dirname(__DIR__) . '/storage/logs/security.log';
}

function rl_captcha_session_start(): void {
  if (session_status() === PHP_SESSION_ACTIVE) {
    return;
  }

  if (function_exists('start_session')) {
    start_session();
    return;
  }

  if (function_exists('csrf_session_start')) {
    csrf_session_start();
    return;
  }

  session_start();
}

function rl_captcha_enabled(): bool {
  return rl_env_flag('COREPANEL_ADAPTIVE_CAPTCHA_ENABLED', true);
}

function rl_captcha_action_key(string $action): string {
  $normalized = strtolower(trim($action));
  if ($normalized === '' || !preg_match('/\A[a-z0-9_]{1,32}\z/', $normalized)) {
    return 'default';
  }
  return $normalized;
}

function rl_captcha_threshold(string $action): int {
  $actionKey = rl_captcha_action_key($action);

  $specificEnv = match ($actionKey) {
    'login' => 'COREPANEL_CAPTCHA_LOGIN_THRESHOLD',
    'forgot_password' => 'COREPANEL_CAPTCHA_FORGOT_THRESHOLD',
    'reset_password' => 'COREPANEL_CAPTCHA_RESET_THRESHOLD',
    default => 'COREPANEL_CAPTCHA_DEFAULT_THRESHOLD',
  };

  $defaultThreshold = match ($actionKey) {
    'login' => 4,
    'forgot_password' => 4,
    'reset_password' => 3,
    default => 5,
  };

  return rl_env_int($specificEnv, $defaultThreshold, 1, 100);
}

function rl_captcha_ttl_seconds(): int {
  return rl_env_int('COREPANEL_CAPTCHA_TTL_SECONDS', 600, 60, 3600);
}

function rl_captcha_required(string $action, array $rateState): bool {
  if (!rl_captcha_enabled()) {
    return false;
  }

  if (!empty($rateState['blocked'])) {
    return true;
  }

  $attempts = (int)($rateState['max_attempts'] ?? 0);
  return $attempts >= rl_captcha_threshold($action);
}

function &rl_captcha_bucket_ref(): array {
  rl_captcha_session_start();

  if (!isset($_SESSION['_adaptive_captcha']) || !is_array($_SESSION['_adaptive_captcha'])) {
    $_SESSION['_adaptive_captcha'] = [];
  }

  return $_SESSION['_adaptive_captcha'];
}

function rl_captcha_answer_hash(string $answer): string {
  return hash_hmac('sha256', trim($answer), rl_monitor_chain_secret());
}

function rl_captcha_new_challenge(string $action): array {
  $first = random_int(2, 12);
  $second = random_int(1, 9);
  $useAddition = random_int(0, 1) === 1;

  if ($useAddition) {
    $question = 'What is ' . $first . ' + ' . $second . '?';
    $answer = (string)($first + $second);
  } else {
    if ($first < $second) {
      [$first, $second] = [$second, $first];
    }
    $question = 'What is ' . $first . ' - ' . $second . '?';
    $answer = (string)($first - $second);
  }

  return [
    'action' => rl_captcha_action_key($action),
    'question' => $question,
    'answer_hash' => rl_captcha_answer_hash($answer),
    'expires_at' => time() + rl_captcha_ttl_seconds(),
    'failed_attempts' => 0,
  ];
}

function rl_captcha_question(string $action): string {
  if (!rl_captcha_enabled()) {
    return '';
  }

  $actionKey = rl_captcha_action_key($action);
  $bucket = &rl_captcha_bucket_ref();
  $entry = $bucket[$actionKey] ?? null;

  if (
    is_array($entry)
    && !empty($entry['question'])
    && (int)($entry['expires_at'] ?? 0) > time()
  ) {
    return (string)$entry['question'];
  }

  $bucket[$actionKey] = rl_captcha_new_challenge($actionKey);
  return (string)$bucket[$actionKey]['question'];
}

function rl_captcha_clear(string $action): void {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    return;
  }

  $actionKey = rl_captcha_action_key($action);
  if (isset($_SESSION['_adaptive_captcha']) && is_array($_SESSION['_adaptive_captcha'])) {
    unset($_SESSION['_adaptive_captcha'][$actionKey]);
  }
}

function rl_captcha_verify(string $action, string $providedAnswer): bool {
  if (!rl_captcha_enabled()) {
    return true;
  }

  $actionKey = rl_captcha_action_key($action);
  $bucket = &rl_captcha_bucket_ref();
  $entry = $bucket[$actionKey] ?? null;
  if (!is_array($entry)) {
    $bucket[$actionKey] = rl_captcha_new_challenge($actionKey);
    return false;
  }

  $expiresAt = (int)($entry['expires_at'] ?? 0);
  if ($expiresAt <= time()) {
    $bucket[$actionKey] = rl_captcha_new_challenge($actionKey);
    return false;
  }

  $answer = trim($providedAnswer);
  if ($answer === '') {
    return false;
  }

  $expectedHash = (string)($entry['answer_hash'] ?? '');
  $providedHash = rl_captcha_answer_hash($answer);
  if ($expectedHash !== '' && hash_equals($expectedHash, $providedHash)) {
    unset($bucket[$actionKey]);
    return true;
  }

  $failedAttempts = (int)($entry['failed_attempts'] ?? 0) + 1;
  if ($failedAttempts >= 3) {
    $bucket[$actionKey] = rl_captcha_new_challenge($actionKey);
  } else {
    $entry['failed_attempts'] = $failedAttempts;
    $bucket[$actionKey] = $entry;
  }

  return false;
}

function rl_append_central_log(array $record): void {
  $path = rl_monitoring_log_file();
  $dir = dirname($path);

  if (!is_dir($dir)) {
    @mkdir($dir, 0700, true);
  }

  $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  if (!is_string($json) || $json === '') {
    return;
  }

  $line = $json . PHP_EOL;
  $fp = @fopen($path, 'ab');
  if ($fp === false) {
    error_log('[SECURITY WARN] Failed to open centralized security log file: ' . $path);
    return;
  }

  $locked = @flock($fp, LOCK_EX);
  if ($locked) {
    @fwrite($fp, $line);
    @fflush($fp);
    @flock($fp, LOCK_UN);
  }
  @fclose($fp);

  @chmod($path, 0600);
}

function rl_last_event_hash(PDO $pdo): string {
  $stmt = $pdo->prepare(
    "SELECT event_hash
     FROM security_event_logs
     WHERE event_hash IS NOT NULL AND event_hash <> ''
     ORDER BY id DESC
     LIMIT 1"
  );
  $stmt->execute();
  $value = $stmt->fetchColumn();
  return is_string($value) && $value !== '' ? $value : str_repeat('0', 64);
}

function rl_compose_event_hash(string $prevHash, array $payload): string {
  $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  if (!is_string($json)) {
    $json = '{}';
  }

  return hash_hmac('sha256', $prevHash . '|' . $json, rl_monitor_chain_secret());
}

function rl_ensure_tables(PDO $pdo): void {
  static $ensured = false;
  if ($ensured) {
    return;
  }

  $pdo->exec(
    "CREATE TABLE IF NOT EXISTS security_rate_limits (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      action VARCHAR(64) NOT NULL,
      key_hash CHAR(64) NOT NULL,
      key_label VARCHAR(255) NOT NULL,
      attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
      first_attempt_at DATETIME NULL,
      last_attempt_at DATETIME NULL,
      lock_until DATETIME NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_security_rate_key (action, key_hash),
      INDEX idx_security_rate_lock (lock_until),
      INDEX idx_security_rate_action_updated (action, updated_at)
    ) ENGINE=InnoDB"
  );

  $pdo->exec(
    "CREATE TABLE IF NOT EXISTS security_event_logs (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      event_type VARCHAR(64) NOT NULL,
      action VARCHAR(64) NOT NULL,
      subject VARCHAR(190) NULL,
      key_label VARCHAR(255) NULL,
      ip_address VARCHAR(45) NULL,
      details VARCHAR(255) NULL,
      level ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
      source VARCHAR(32) NOT NULL DEFAULT 'app',
      actor_user_id INT UNSIGNED NULL,
      tenant_id INT UNSIGNED NULL,
      prev_hash CHAR(64) NULL,
      event_hash CHAR(64) NULL,
      context_json TEXT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_security_event_action (action, created_at),
      INDEX idx_security_event_ip (ip_address, created_at),
      INDEX idx_security_event_subject (subject, created_at),
      INDEX idx_security_event_level_created (level, created_at),
      INDEX idx_security_event_type_created (event_type, created_at)
    ) ENGINE=InnoDB"
  );

  if (!rl_column_exists($pdo, 'security_event_logs', 'source')) {
    $pdo->exec("ALTER TABLE security_event_logs ADD COLUMN source VARCHAR(32) NOT NULL DEFAULT 'app' AFTER level");
  }
  if (!rl_column_exists($pdo, 'security_event_logs', 'actor_user_id')) {
    $pdo->exec("ALTER TABLE security_event_logs ADD COLUMN actor_user_id INT UNSIGNED NULL AFTER source");
  }
  if (!rl_column_exists($pdo, 'security_event_logs', 'tenant_id')) {
    $pdo->exec("ALTER TABLE security_event_logs ADD COLUMN tenant_id INT UNSIGNED NULL AFTER actor_user_id");
  }
  if (!rl_column_exists($pdo, 'security_event_logs', 'prev_hash')) {
    $pdo->exec("ALTER TABLE security_event_logs ADD COLUMN prev_hash CHAR(64) NULL AFTER tenant_id");
  }
  if (!rl_column_exists($pdo, 'security_event_logs', 'event_hash')) {
    $pdo->exec("ALTER TABLE security_event_logs ADD COLUMN event_hash CHAR(64) NULL AFTER prev_hash");
  }
  if (!rl_column_exists($pdo, 'security_event_logs', 'context_json')) {
    $pdo->exec("ALTER TABLE security_event_logs ADD COLUMN context_json TEXT NULL AFTER event_hash");
  }

  try {
    $pdo->exec("CREATE INDEX idx_security_event_level_created ON security_event_logs (level, created_at)");
  } catch (Throwable $e) {
    // Index may already exist.
  }

  try {
    $pdo->exec("CREATE INDEX idx_security_event_type_created ON security_event_logs (event_type, created_at)");
  } catch (Throwable $e) {
    // Index may already exist.
  }

  $ensured = true;

  if (random_int(1, 100) === 1) {
    $pdo->exec("DELETE FROM security_rate_limits WHERE updated_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    $pdo->exec("DELETE FROM security_event_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 180 DAY)");
  }
}

function rl_hash_key(string $keyLabel): string {
  return hash('sha256', $keyLabel);
}

function rl_datetime_to_ts(?string $value): int {
  if ($value === null || $value === '') {
    return 0;
  }
  $ts = strtotime($value);
  return $ts === false ? 0 : $ts;
}

function rl_default_state(): array {
  return [
    'attempt_count' => 0,
    'first_attempt_at' => null,
    'last_attempt_at' => null,
    'lock_until' => null,
  ];
}

function rl_get_counter(PDO $pdo, string $action, string $keyLabel): array {
  rl_ensure_tables($pdo);

  $stmt = $pdo->prepare(
    "SELECT attempt_count, first_attempt_at, last_attempt_at, lock_until
     FROM security_rate_limits
     WHERE action = ? AND key_hash = ?
     LIMIT 1"
  );
  $stmt->execute([$action, rl_hash_key($keyLabel)]);
  $row = $stmt->fetch();

  if (!$row) {
    return rl_default_state();
  }

  return [
    'attempt_count' => (int)($row['attempt_count'] ?? 0),
    'first_attempt_at' => $row['first_attempt_at'] ?? null,
    'last_attempt_at' => $row['last_attempt_at'] ?? null,
    'lock_until' => $row['lock_until'] ?? null,
  ];
}

function rl_normalized_attempt_count(array $counter, int $windowSeconds, int $nowTs): int {
  $lastAttemptTs = rl_datetime_to_ts((string)($counter['last_attempt_at'] ?? ''));
  if ($lastAttemptTs <= 0) {
    return 0;
  }

  if (($nowTs - $lastAttemptTs) > $windowSeconds) {
    return 0;
  }

  return max(0, (int)($counter['attempt_count'] ?? 0));
}

function rl_delay_seconds_for_attempts(int $attemptCount, int $delayAfter, int $maxDelay): int {
  if ($attemptCount < $delayAfter) {
    return 0;
  }

  $step = max(0, $attemptCount - $delayAfter);
  $delay = (int)pow(2, min($step, 3));
  return max(0, min($maxDelay, $delay));
}

function rl_lock_seconds_for_attempts(int $attemptCount, array $lockSteps): int {
  $lockSeconds = 0;
  foreach ($lockSteps as $threshold => $seconds) {
    if ($attemptCount >= (int)$threshold) {
      $lockSeconds = max($lockSeconds, (int)$seconds);
    }
  }
  return $lockSeconds;
}

function rl_log_event(
  PDO $pdo,
  string $eventType,
  string $action,
  ?string $subject,
  ?string $keyLabel,
  string $details,
  string $level = 'info',
  array $meta = []
): void {
  rl_ensure_tables($pdo);

  $ip = substr(rl_client_ip(), 0, 45);
  $lvl = in_array($level, ['info', 'warning', 'critical'], true) ? $level : 'info';

  $normalizedSource = strtolower(trim((string)($meta['source'] ?? 'app')));
  if (!preg_match('/\A[a-z0-9_.:-]{1,32}\z/', $normalizedSource)) {
    $normalizedSource = 'app';
  }

  $actorUserId = isset($meta['actor_user_id']) ? (int)$meta['actor_user_id'] : 0;
  $tenantId = isset($meta['tenant_id']) ? (int)$meta['tenant_id'] : 0;

  $subjectValue = $subject !== null && $subject !== '' ? substr($subject, 0, 190) : null;
  $keyLabelValue = $keyLabel !== null && $keyLabel !== '' ? substr($keyLabel, 0, 255) : null;
  $detailsValue = $details !== '' ? substr($details, 0, 255) : null;

  $context = $meta['context'] ?? null;
  if (is_array($context) || is_object($context)) {
    $contextJson = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($contextJson)) {
      $contextJson = null;
    }
  } elseif (is_string($context) && trim($context) !== '') {
    $contextJson = substr($context, 0, 4000);
  } else {
    $contextJson = null;
  }

  $payload = [
    'event_type' => $eventType,
    'action' => $action,
    'subject' => $subjectValue,
    'key_label' => $keyLabelValue,
    'ip_address' => $ip,
    'details' => $detailsValue,
    'level' => $lvl,
    'source' => $normalizedSource,
    'actor_user_id' => $actorUserId > 0 ? $actorUserId : null,
    'tenant_id' => $tenantId > 0 ? $tenantId : null,
    'context_json' => $contextJson,
  ];

  $prevHash = rl_last_event_hash($pdo);
  $eventHash = rl_compose_event_hash($prevHash, $payload);

  $stmt = $pdo->prepare(
    "INSERT INTO security_event_logs
      (event_type, action, subject, key_label, ip_address, details, level, source, actor_user_id, tenant_id, prev_hash, event_hash, context_json)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
  );
  $stmt->execute([
    $eventType,
    $action,
    $subjectValue,
    $keyLabelValue,
    $ip,
    $detailsValue,
    $lvl,
    $normalizedSource,
    $actorUserId > 0 ? $actorUserId : null,
    $tenantId > 0 ? $tenantId : null,
    $prevHash,
    $eventHash,
    $contextJson,
  ]);

  $logRecord = [
    'ts' => gmdate('c'),
    'db_id' => (int)$pdo->lastInsertId(),
    'event_type' => $eventType,
    'action' => $action,
    'subject' => $subjectValue,
    'key_label' => $keyLabelValue,
    'ip_address' => $ip,
    'details' => $detailsValue,
    'level' => $lvl,
    'source' => $normalizedSource,
    'actor_user_id' => $actorUserId > 0 ? $actorUserId : null,
    'tenant_id' => $tenantId > 0 ? $tenantId : null,
    'prev_hash' => $prevHash,
    'event_hash' => $eventHash,
    'context' => $context,
  ];
  rl_append_central_log($logRecord);
}

function rl_emit_security_alert(
  PDO $pdo,
  string $action,
  ?string $subject,
  ?string $keyLabel,
  string $detail,
  string $level = 'warning',
  array $meta = []
): void {
  $safeLevel = in_array($level, ['warning', 'critical'], true) ? $level : 'warning';
  rl_log_event($pdo, 'security_alert', $action, $subject, $keyLabel, $detail, $safeLevel, $meta);

  error_log(
    '[SECURITY ALERT] action=' . $action
    . ' subject=' . ($subject ?? '')
    . ' key=' . ($keyLabel ?? '')
    . ' level=' . $safeLevel
    . ' ip=' . rl_client_ip()
    . ' detail=' . $detail
  );
}

function rl_recent_event_count(
  PDO $pdo,
  string $eventType,
  string $action,
  ?string $subject,
  int $windowSeconds
): int {
  rl_ensure_tables($pdo);

  $window = max(1, $windowSeconds);
  $since = date('Y-m-d H:i:s', time() - $window);

  if ($subject !== null && $subject !== '') {
    $stmt = $pdo->prepare(
      "SELECT COUNT(*)
       FROM security_event_logs
       WHERE event_type = ?
         AND action = ?
         AND subject = ?
         AND created_at >= ?"
    );
    $stmt->execute([$eventType, $action, $subject, $since]);
  } else {
    $stmt = $pdo->prepare(
      "SELECT COUNT(*)
       FROM security_event_logs
       WHERE event_type = ?
         AND action = ?
         AND created_at >= ?"
    );
    $stmt->execute([$eventType, $action, $since]);
  }

  return (int)$stmt->fetchColumn();
}

function rl_record_download_activity(PDO $pdo, array $actor, string $action, string $resourceRef): void {
  $normalizedAction = strtolower(trim($action));
  if ($normalizedAction === '') {
    $normalizedAction = 'download';
  }

  $actorUserId = (int)($actor['id'] ?? 0);
  $tenantId = (int)($actor['tenant_id'] ?? 0);
  $subject = $actorUserId > 0 ? 'user:' . $actorUserId : null;

  $details = 'resource=' . substr($resourceRef, 0, 180);
  rl_log_event(
    $pdo,
    'download_activity',
    $normalizedAction,
    $subject,
    $resourceRef,
    $details,
    'info',
    [
      'source' => 'download',
      'actor_user_id' => $actorUserId,
      'tenant_id' => $tenantId,
      'context' => [
        'resource' => $resourceRef,
      ],
    ]
  );

  $windowSeconds = rl_env_int('COREPANEL_DOWNLOAD_SPIKE_WINDOW_SECONDS', 600, 60, 86400);
  $threshold = rl_env_int('COREPANEL_DOWNLOAD_SPIKE_THRESHOLD', 25, 5, 5000);
  $count = rl_recent_event_count($pdo, 'download_activity', $normalizedAction, $subject, $windowSeconds);

  if ($count >= $threshold && ($count === $threshold || $count % $threshold === 0)) {
    $severity = $count >= ($threshold * 2) ? 'critical' : 'warning';
    $detail = 'action=' . $normalizedAction
      . '; events=' . $count
      . '; window_seconds=' . $windowSeconds
      . '; threshold=' . $threshold;
    rl_emit_security_alert(
      $pdo,
      'export_download_spike',
      $subject,
      $resourceRef,
      $detail,
      $severity,
      [
        'source' => 'monitoring',
        'actor_user_id' => $actorUserId,
        'tenant_id' => $tenantId,
        'context' => [
          'tracked_action' => $normalizedAction,
          'events' => $count,
          'window_seconds' => $windowSeconds,
          'threshold' => $threshold,
        ],
      ]
    );
  }
}

function rl_record_admin_activity(
  PDO $pdo,
  int $actorUserId,
  string $adminAction,
  ?int $targetUserId = null,
  ?string $summary = null,
  ?int $tenantId = null
): void {
  if ($actorUserId <= 0) {
    return;
  }

  $normalizedAction = strtolower(trim($adminAction));
  if ($normalizedAction === '') {
    $normalizedAction = 'unknown_action';
  }

  $ip = rl_client_ip();
  $ipPrefix = rl_client_ip_prefix($ip);
  $subject = 'user:' . $actorUserId;
  $detail = $summary !== null && trim($summary) !== ''
    ? substr(trim($summary), 0, 255)
    : 'admin action recorded';

  rl_log_event(
    $pdo,
    'admin_activity',
    'admin_action',
    $subject,
    'action:' . $normalizedAction,
    $detail,
    'info',
    [
      'source' => 'admin',
      'actor_user_id' => $actorUserId,
      'tenant_id' => $tenantId !== null ? max(0, (int)$tenantId) : 0,
      'context' => [
        'admin_action' => $normalizedAction,
        'target_user_id' => $targetUserId,
        'ip_prefix' => $ipPrefix,
      ],
    ]
  );

  if (in_array($normalizedAction, ['promote_user', 'demote_user', 'grant_role', 'revoke_role'], true)) {
    $privilegeDetail = 'action=' . $normalizedAction
      . '; actor_user_id=' . $actorUserId
      . '; target_user_id=' . (int)($targetUserId ?? 0);
    rl_emit_security_alert(
      $pdo,
      'privilege_change',
      $subject,
      'action:' . $normalizedAction,
      $privilegeDetail,
      'warning',
      [
        'source' => 'admin',
        'actor_user_id' => $actorUserId,
        'tenant_id' => $tenantId !== null ? max(0, (int)$tenantId) : 0,
      ]
    );
  }

  $offHoursStart = rl_env_int('COREPANEL_ADMIN_HOURS_START', 6, 0, 23);
  $offHoursEnd = rl_env_int('COREPANEL_ADMIN_HOURS_END', 22, 1, 24);
  $hour = (int)date('G');
  $outsideHours = $offHoursStart < $offHoursEnd
    ? ($hour < $offHoursStart || $hour >= $offHoursEnd)
    : ($hour >= $offHoursEnd && $hour < $offHoursStart);

  if ($outsideHours) {
    $detailOffHours = 'admin_action=' . $normalizedAction
      . '; hour=' . $hour
      . '; allowed_window=' . $offHoursStart . '-' . $offHoursEnd;
    rl_emit_security_alert(
      $pdo,
      'admin_off_hours_action',
      $subject,
      $ipPrefix,
      $detailOffHours,
      'warning',
      [
        'source' => 'admin-monitor',
        'actor_user_id' => $actorUserId,
        'tenant_id' => $tenantId !== null ? max(0, (int)$tenantId) : 0,
      ]
    );
  }

  $ipLookbackDays = rl_env_int('COREPANEL_ADMIN_NEW_IP_LOOKBACK_DAYS', 30, 1, 365);
  $lookbackSince = date('Y-m-d H:i:s', time() - ($ipLookbackDays * 86400));

  $historyStmt = $pdo->prepare(
    "SELECT
       SUM(CASE WHEN ip_address = ? THEN 1 ELSE 0 END) AS same_ip_count,
       COUNT(*) AS total_count
     FROM security_event_logs
     WHERE event_type = 'admin_activity'
       AND action = 'admin_action'
       AND subject = ?
       AND created_at >= ?"
  );
  $historyStmt->execute([$ip, $subject, $lookbackSince]);
  $history = $historyStmt->fetch();

  $sameIpCount = (int)($history['same_ip_count'] ?? 0);
  $totalCount = (int)($history['total_count'] ?? 0);

  if ($totalCount >= 5 && $sameIpCount <= 1) {
    $detailNewIp = 'admin_action=' . $normalizedAction
      . '; ip=' . $ip
      . '; lookback_days=' . $ipLookbackDays;
    rl_emit_security_alert(
      $pdo,
      'admin_new_ip_pattern',
      $subject,
      $ip,
      $detailNewIp,
      'warning',
      [
        'source' => 'admin-monitor',
        'actor_user_id' => $actorUserId,
        'tenant_id' => $tenantId !== null ? max(0, (int)$tenantId) : 0,
        'context' => [
          'ip_prefix' => $ipPrefix,
          'lookback_days' => $ipLookbackDays,
          'same_ip_count' => $sameIpCount,
          'total_count' => $totalCount,
        ],
      ]
    );
  }

  $burstWindow = rl_env_int('COREPANEL_ADMIN_BURST_WINDOW_SECONDS', 600, 60, 86400);
  $burstThreshold = rl_env_int('COREPANEL_ADMIN_BURST_THRESHOLD', 20, 5, 5000);
  $recentCount = rl_recent_event_count($pdo, 'admin_activity', 'admin_action', $subject, $burstWindow);

  if ($recentCount >= $burstThreshold && ($recentCount === $burstThreshold || $recentCount % $burstThreshold === 0)) {
    $burstSeverity = $recentCount >= ($burstThreshold * 2) ? 'critical' : 'warning';
    $detailBurst = 'events=' . $recentCount
      . '; window_seconds=' . $burstWindow
      . '; threshold=' . $burstThreshold;
    rl_emit_security_alert(
      $pdo,
      'admin_action_spike',
      $subject,
      'action:' . $normalizedAction,
      $detailBurst,
      $burstSeverity,
      [
        'source' => 'admin-monitor',
        'actor_user_id' => $actorUserId,
        'tenant_id' => $tenantId !== null ? max(0, (int)$tenantId) : 0,
      ]
    );
  }
}

function rl_emit_alert(
  PDO $pdo,
  string $action,
  string $keyLabel,
  ?string $subject,
  int $attemptCount,
  int $lockSeconds,
  string $reason
): void {
  $detail = "reason={$reason}; attempts={$attemptCount}; lock_seconds={$lockSeconds}";
  $level = $attemptCount >= 12 ? 'critical' : 'warning';
  rl_emit_security_alert(
    $pdo,
    $action,
    $subject,
    $keyLabel,
    $detail,
    $level,
    [
      'source' => 'rate_limit',
      'context' => [
        'reason' => $reason,
        'attempts' => $attemptCount,
        'lock_seconds' => $lockSeconds,
      ],
    ]
  );
}

function rl_precheck(PDO $pdo, string $action, array $keyLabels): array {
  rl_ensure_tables($pdo);
  $policy = rl_policy($action);
  $nowTs = time();

  $maxAttempts = 0;
  $retryAfter = 0;

  foreach ($keyLabels as $rawKeyLabel) {
    $keyLabel = trim((string)$rawKeyLabel);
    if ($keyLabel === '') {
      continue;
    }

    $counter = rl_get_counter($pdo, $action, $keyLabel);
    $attempts = rl_normalized_attempt_count($counter, (int)$policy['window_seconds'], $nowTs);
    $maxAttempts = max($maxAttempts, $attempts);

    $lockUntilTs = rl_datetime_to_ts((string)($counter['lock_until'] ?? ''));
    if ($lockUntilTs > $nowTs) {
      $retryAfter = max($retryAfter, $lockUntilTs - $nowTs);
    }
  }

  if ($retryAfter > 0) {
    return [
      'blocked' => true,
      'retry_after' => $retryAfter,
      'applied_delay_seconds' => 0,
      'max_attempts' => $maxAttempts,
    ];
  }

  $delaySeconds = rl_delay_seconds_for_attempts(
    $maxAttempts,
    (int)$policy['delay_after'],
    (int)$policy['max_delay_seconds']
  );

  if ($delaySeconds > 0) {
    usleep($delaySeconds * 1000000);
  }

  return [
    'blocked' => false,
    'retry_after' => 0,
    'applied_delay_seconds' => $delaySeconds,
    'max_attempts' => $maxAttempts,
  ];
}

function rl_register_attempt(
  PDO $pdo,
  string $action,
  array $keyLabels,
  ?string $subject,
  string $reason,
  bool $isFailure = true
): array {
  rl_ensure_tables($pdo);
  $policy = rl_policy($action);
  $nowTs = time();
  $nowDb = date('Y-m-d H:i:s', $nowTs);

  $maxLockSeconds = 0;
  $maxAttempts = 0;

  foreach ($keyLabels as $rawKeyLabel) {
    $keyLabel = trim((string)$rawKeyLabel);
    if ($keyLabel === '') {
      continue;
    }

    $keyHash = rl_hash_key($keyLabel);
    $counter = rl_get_counter($pdo, $action, $keyLabel);

    $attemptBase = rl_normalized_attempt_count($counter, (int)$policy['window_seconds'], $nowTs);
    $newAttempts = $attemptBase + 1;
    $maxAttempts = max($maxAttempts, $newAttempts);

    $lockSeconds = rl_lock_seconds_for_attempts($newAttempts, (array)$policy['lock_steps']);
    $maxLockSeconds = max($maxLockSeconds, $lockSeconds);

    $existingLockUntilTs = rl_datetime_to_ts((string)($counter['lock_until'] ?? ''));
    $newLockUntilTs = $lockSeconds > 0 ? ($nowTs + $lockSeconds) : 0;
    $finalLockTs = max($existingLockUntilTs, $newLockUntilTs);
    $finalLockDb = $finalLockTs > 0 ? date('Y-m-d H:i:s', $finalLockTs) : null;

    $firstAttemptDb = $attemptBase === 0
      ? $nowDb
      : (string)($counter['first_attempt_at'] ?? $nowDb);

    $existsStmt = $pdo->prepare(
      "SELECT id FROM security_rate_limits WHERE action = ? AND key_hash = ? LIMIT 1"
    );
    $existsStmt->execute([$action, $keyHash]);
    $existing = $existsStmt->fetch();

    if ($existing) {
      $upd = $pdo->prepare(
        "UPDATE security_rate_limits
         SET key_label = ?,
             attempt_count = ?,
             first_attempt_at = ?,
             last_attempt_at = ?,
             lock_until = ?
         WHERE action = ? AND key_hash = ?"
      );
      $upd->execute([
        $keyLabel,
        $newAttempts,
        $firstAttemptDb,
        $nowDb,
        $finalLockDb,
        $action,
        $keyHash,
      ]);
    } else {
      $ins = $pdo->prepare(
        "INSERT INTO security_rate_limits
          (action, key_hash, key_label, attempt_count, first_attempt_at, last_attempt_at, lock_until)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
      );
      $ins->execute([
        $action,
        $keyHash,
        $keyLabel,
        $newAttempts,
        $firstAttemptDb,
        $nowDb,
        $finalLockDb,
      ]);
    }

    if ($lockSeconds > 0) {
      $thresholds = array_map('intval', array_keys((array)$policy['lock_steps']));
      if (in_array($newAttempts, $thresholds, true)) {
        rl_emit_alert($pdo, $action, $keyLabel, $subject, $newAttempts, $lockSeconds, $reason);
      }
    }
  }

  if ($isFailure) {
    $details = "reason={$reason}; attempts={$maxAttempts}; lock_seconds={$maxLockSeconds}";
    rl_log_event(
      $pdo,
      'rate_limit_failure',
      $action,
      $subject,
      null,
      $details,
      'warning',
      [
        'source' => 'rate_limit',
        'context' => [
          'reason' => $reason,
          'attempts' => $maxAttempts,
          'lock_seconds' => $maxLockSeconds,
        ],
      ]
    );
  }

  return [
    'lock_seconds' => $maxLockSeconds,
    'attempts' => $maxAttempts,
  ];
}

function rl_clear_attempts(PDO $pdo, string $action, array $keyLabels): void {
  rl_ensure_tables($pdo);

  foreach ($keyLabels as $rawKeyLabel) {
    $keyLabel = trim((string)$rawKeyLabel);
    if ($keyLabel === '') {
      continue;
    }

    $stmt = $pdo->prepare(
      "UPDATE security_rate_limits
       SET attempt_count = 0,
           first_attempt_at = NULL,
           last_attempt_at = NULL,
           lock_until = NULL
       WHERE action = ? AND key_hash = ?"
    );
    $stmt->execute([$action, rl_hash_key($keyLabel)]);
  }
}

function rl_log_blocked(
  PDO $pdo,
  string $action,
  array $keyLabels,
  ?string $subject,
  int $retryAfterSeconds
): void {
  $keyLabel = $keyLabels ? implode(',', array_slice($keyLabels, 0, 2)) : null;
  $details = 'blocked=true; retry_after_seconds=' . max(0, $retryAfterSeconds);
  rl_log_event(
    $pdo,
    'rate_limit_blocked',
    $action,
    $subject,
    $keyLabel,
    $details,
    'warning',
    [
      'source' => 'rate_limit',
      'context' => [
        'retry_after_seconds' => max(0, $retryAfterSeconds),
      ],
    ]
  );
}

function rl_lock_message(int $retryAfterSeconds): string {
  $retryAfterSeconds = max(1, $retryAfterSeconds);
  if ($retryAfterSeconds >= 60) {
    $minutes = (int)ceil($retryAfterSeconds / 60);
    return "Too many attempts. Try again in {$minutes} minute(s).";
  }
  return "Too many attempts. Try again in {$retryAfterSeconds} second(s).";
}
