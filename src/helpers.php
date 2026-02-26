<?php
declare(strict_types=1);

require_once __DIR__ . '/error_handling.php';
app_configure_error_handling();

function e(string $v): string {
  return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function e_attr(string $v): string {
  return e($v);
}

function e_js(string $v): string {
  $encoded = json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
  return is_string($encoded) ? $encoded : '""';
}

function media_reference_key(string $value): ?string {
  $value = trim($value);
  if ($value === '') {
    return null;
  }

  if (str_starts_with($value, 'private:')) {
    $key = substr($value, strlen('private:'));
  } else {
    if (preg_match('#\Ahttps?://#i', $value) === 1) {
      $parsedPath = (string)(parse_url($value, PHP_URL_PATH) ?? '');
      if ($parsedPath !== '') {
        $value = $parsedPath;
      }
    }

    if (str_starts_with($value, '/uploads/')) {
      $key = substr($value, strlen('/uploads/'));
    } elseif (str_starts_with($value, 'uploads/')) {
      $key = substr($value, strlen('uploads/'));
    } else {
      return null;
    }
  }

  $key = trim($key);
  if ($key === '') {
    return null;
  }
  $key = rawurldecode($key);
  $key = str_replace('\\', '/', $key);
  if (str_contains($key, '/')) {
    $key = basename($key);
  }

  if (!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,189}\.(jpg|jpeg|png|webp|pdf)\z/i', $key)) {
    return null;
  }

  return $key;
}

function media_file_url(string $reference, bool $download = false): ?string {
  $key = media_reference_key($reference);
  if ($key === null) {
    return null;
  }

  $url = '/file.php?f=' . rawurlencode($key);
  if ($download) {
    $url .= '&download=1';
  }
  return $url;
}

function safe_output_url(string $url, string $fallback = '#'): string {
  $url = trim($url);
  if ($url === '') {
    return $fallback;
  }

  $fileUrl = media_file_url($url);
  if ($fileUrl !== null) {
    return $fileUrl;
  }

  if (preg_match('#^data:image/(?:png|gif|jpeg|jpg|webp|avif);base64,[a-z0-9+/=]+$#i', $url)) {
    return $url;
  }

  if (
    str_starts_with($url, '/') ||
    str_starts_with($url, './') ||
    str_starts_with($url, '../') ||
    str_starts_with($url, '?') ||
    str_starts_with($url, '#')
  ) {
    return $url;
  }

  $scheme = strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?? ''));
  if (in_array($scheme, ['http', 'https', 'mailto', 'tel'], true)) {
    return $url;
  }

  return $fallback;
}

function e_url_attr(string $url, string $fallback = '#'): string {
  return e_attr(safe_output_url($url, $fallback));
}

function app_env_flag(string $name, bool $default = false): bool {
  $value = $_ENV[$name] ?? getenv($name);
  if ($value === false || $value === null) {
    return $default;
  }

  $normalized = strtolower(trim((string)$value));
  if ($normalized === '') {
    return $default;
  }

  return !in_array($normalized, ['0', 'false', 'off', 'no'], true);
}

function helpers_request_host(): string {
  $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
  if ($host === '') {
    return '';
  }

  $host = strtolower($host);
  if (str_starts_with($host, '[')) {
    $end = strpos($host, ']');
    if ($end !== false) {
      return substr($host, 1, $end - 1);
    }
    return trim($host, '[]');
  }

  $parts = explode(':', $host, 2);
  return trim($parts[0]);
}

function helpers_is_localhost_request(): bool {
  $host = helpers_request_host();
  return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

function https_enforcement_enabled(): bool {
  $default = !helpers_is_localhost_request();
  return app_env_flag('COREPANEL_ENFORCE_HTTPS', $default);
}

function helpers_ip_matches_cidr(string $ip, string $cidr): bool {
  $cidr = trim($cidr);
  if ($cidr === '') {
    return false;
  }

  if (!str_contains($cidr, '/')) {
    return hash_equals(strtolower($cidr), strtolower($ip));
  }

  [$network, $prefix] = explode('/', $cidr, 2);
  $network = trim($network);
  $prefixLen = (int)trim($prefix);

  $ipBin = @inet_pton($ip);
  $networkBin = @inet_pton($network);
  if ($ipBin === false || $networkBin === false || strlen($ipBin) !== strlen($networkBin)) {
    return false;
  }

  $maxBits = strlen($ipBin) * 8;
  if ($prefixLen < 0 || $prefixLen > $maxBits) {
    return false;
  }

  $fullBytes = intdiv($prefixLen, 8);
  $remainderBits = $prefixLen % 8;

  if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($networkBin, 0, $fullBytes)) {
    return false;
  }

  if ($remainderBits === 0) {
    return true;
  }

  $mask = (0xFF << (8 - $remainderBits)) & 0xFF;
  $ipByte = ord($ipBin[$fullBytes]);
  $networkByte = ord($networkBin[$fullBytes]);
  return ($ipByte & $mask) === ($networkByte & $mask);
}

function helpers_proxy_headers_trusted(): bool {
  if (!app_env_flag('COREPANEL_TRUST_PROXY_HEADERS', false)) {
    return false;
  }

  $remoteAddr = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
  if ($remoteAddr === '' || filter_var($remoteAddr, FILTER_VALIDATE_IP) === false) {
    return false;
  }

  $configured = trim((string)($_ENV['COREPANEL_TRUSTED_PROXIES'] ?? getenv('COREPANEL_TRUSTED_PROXIES') ?? ''));
  if ($configured === '') {
    return false;
  }

  $entries = array_filter(array_map('trim', explode(',', $configured)), static fn(string $v): bool => $v !== '');
  foreach ($entries as $entry) {
    if (helpers_ip_matches_cidr($remoteAddr, $entry)) {
      return true;
    }
  }

  return false;
}

function helpers_request_is_https(): bool {
  if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
    return true;
  }

  if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
    return true;
  }

  if (helpers_proxy_headers_trusted()) {
    $forwardedProto = (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
    if ($forwardedProto !== '') {
      $firstProto = strtolower(trim(explode(',', $forwardedProto)[0]));
      if ($firstProto === 'https') {
        return true;
      }
    }

    $forwarded = (string)($_SERVER['HTTP_FORWARDED'] ?? '');
    if ($forwarded !== '' && preg_match('/proto=https/i', $forwarded) === 1) {
      return true;
    }
  }

  return false;
}

function enforce_https_if_configured(): void {
  if (PHP_SAPI === 'cli') {
    return;
  }

  if (!https_enforcement_enabled()) {
    return;
  }

  if (helpers_request_is_https()) {
    return;
  }

  if (headers_sent()) {
    return;
  }

  $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
  if ($host === '' || !preg_match('/\A[a-zA-Z0-9.\-:\[\]]+\z/', $host)) {
    return;
  }

  $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
  if ($uri === '') {
    $uri = '/';
  }

  header('Location: https://' . $host . $uri, true, 308);
  exit;
}

function hsts_header_value(): ?string {
  if (!helpers_request_is_https()) {
    return null;
  }

  if (!app_env_flag('COREPANEL_HSTS_ENABLED', false)) {
    return null;
  }

  $rawMaxAge = $_ENV['COREPANEL_HSTS_MAX_AGE'] ?? getenv('COREPANEL_HSTS_MAX_AGE');
  $maxAge = is_numeric($rawMaxAge) ? (int)$rawMaxAge : 31536000;
  $maxAge = max(0, min($maxAge, 63072000));

  $value = 'max-age=' . $maxAge;
  if (app_env_flag('COREPANEL_HSTS_INCLUDE_SUBDOMAINS', true)) {
    $value .= '; includeSubDomains';
  }
  if (app_env_flag('COREPANEL_HSTS_PRELOAD', false)) {
    $value .= '; preload';
  }

  return $value;
}

function csp_nonce(): string {
  static $nonce = null;

  if (is_string($nonce) && $nonce !== '') {
    return $nonce;
  }

  $nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
  return $nonce;
}

function csp_enforce_enabled(): bool {
  return app_env_flag('COREPANEL_CSP_ENFORCE', false);
}

function csp_report_endpoint_enabled(): bool {
  return app_env_flag('COREPANEL_CSP_REPORT_ENABLED', app_debug_enabled());
}

function csp_policy_value(): string {
  $nonce = csp_nonce();

  $directives = [
    "default-src 'self'",
    "base-uri 'self'",
    "form-action 'self'",
    "frame-ancestors 'none'",
    "object-src 'none'",
    "script-src 'self' 'nonce-{$nonce}'",
    "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net",
    "img-src 'self' data: https:",
    "font-src 'self' data: https://cdn.jsdelivr.net",
    "connect-src 'self'",
    "frame-src 'none'",
    "manifest-src 'self'",
  ];

  if (csp_report_endpoint_enabled()) {
    $directives[] = "report-uri /csp_report.php";
  }

  return implode('; ', $directives);
}

function send_security_headers(bool $includeCsp = true): void {
  enforce_https_if_configured();

  if (headers_sent()) {
    return;
  }

  header('X-Content-Type-Options: nosniff');
  header('Referrer-Policy: strict-origin-when-cross-origin');
  header('Permissions-Policy: geolocation=(), camera=(), microphone=(), payment=(), usb=(), accelerometer=(), gyroscope=()');
  header('X-Frame-Options: DENY');

  $hsts = hsts_header_value();
  if ($hsts !== null) {
    header('Strict-Transport-Security: ' . $hsts);
  }

  if ($includeCsp) {
    $cspHeader = csp_enforce_enabled()
      ? 'Content-Security-Policy'
      : 'Content-Security-Policy-Report-Only';
    header($cspHeader . ': ' . csp_policy_value());
  }
}

function redirect(string $path): never {
  header("Location: {$path}");
  exit;
}

function csrf_session_start(): void {
  if (session_status() === PHP_SESSION_ACTIVE) {
    return;
  }

  if (function_exists('start_session')) {
    start_session();
    return;
  }

  // Fallback for scripts that use CSRF helpers without loading auth.php
  $isHttps = false;
  if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
    $isHttps = true;
  } elseif (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
    $isHttps = true;
  } else {
    $forwardedProto = (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
    if ($forwardedProto !== '') {
      $firstProto = strtolower(trim(explode(',', $forwardedProto)[0]));
      if ($firstProto === 'https') {
        $isHttps = true;
      }
    }
  }

  ini_set('session.use_strict_mode', '1');
  ini_set('session.use_only_cookies', '1');
  ini_set('session.use_trans_sid', '0');
  ini_set('session.cookie_httponly', '1');
  ini_set('session.cookie_secure', $isHttps ? '1' : '0');
  ini_set('session.cookie_samesite', 'Lax');
  ini_set('session.gc_maxlifetime', (string)(60 * 60 * 8));

  session_name('corepanel_session');
  session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
  session_start();

  $now = time();
  if (!isset($_SESSION['_created_at'])) {
    $_SESSION['_created_at'] = $now;
  }
  if (!isset($_SESSION['_last_regenerated_at'])) {
    $_SESSION['_last_regenerated_at'] = $now;
  }
  if (($now - (int)$_SESSION['_last_regenerated_at']) >= 900) {
    session_regenerate_id(true);
    $_SESSION['_last_regenerated_at'] = $now;
  }
}

function csrf_token(): string {
  csrf_session_start();
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf_token'];
}

function csrf_verify(string $token): bool {
  csrf_session_start();
  return isset($_SESSION['csrf_token']) &&
         hash_equals($_SESSION['csrf_token'], $token);
}

function status_class(string $status): string {
  $normalized = strtolower(trim($status));

  if ($normalized === 'active') {
    return 'status-text status-text-active';
  }
  if ($normalized === 'completed' || $normalized === 'done') {
    return 'status-text status-text-success';
  }
  if ($normalized === 'decliend' || $normalized === 'declined') {
    return 'status-text status-text-danger';
  }

  return 'status-text';
}

function db_column_type(PDO $pdo, string $table, string $column): ?string {
  $stmt = $pdo->prepare(
    "SELECT COLUMN_TYPE
     FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = ?
       AND COLUMN_NAME = ?
     LIMIT 1"
  );
  $stmt->execute([$table, $column]);
  $value = $stmt->fetchColumn();
  if ($value === false || $value === null) {
    return null;
  }
  return (string)$value;
}

function db_has_column(PDO $pdo, string $table, string $column): bool {
  return db_column_type($pdo, $table, $column) !== null;
}

function ensure_project_notes_column(PDO $pdo): bool {
  static $checked = false;
  static $available = false;

  if ($checked) {
    return $available;
  }

  $checked = true;

  try {
    if (db_has_column($pdo, 'projects', 'notes')) {
      $available = true;
      return true;
    }

    $pdo->exec("ALTER TABLE projects ADD COLUMN notes TEXT NULL AFTER description");
    $available = true;
    return true;
  } catch (Throwable $e) {
    $available = false;
    return false;
  }
}

function ensure_project_address_column(PDO $pdo): bool {
  static $checked = false;
  static $available = false;

  if ($checked) {
    return $available;
  }

  $checked = true;

  try {
    if (db_has_column($pdo, 'projects', 'project_address')) {
      $available = true;
      return true;
    }

    $pdo->exec("ALTER TABLE projects ADD COLUMN project_address TEXT NULL AFTER notes");
    $available = true;
    return true;
  } catch (Throwable $e) {
    $available = false;
    return false;
  }
}

function ensure_project_decliend_status(PDO $pdo): bool {
  static $checked = false;
  static $available = false;

  if ($checked) {
    return $available;
  }

  $checked = true;

  try {
    $statusCol = db_column_type($pdo, 'projects', 'status') ?? '';
    if ($statusCol !== '' && str_contains($statusCol, "'decliend'")) {
      $available = true;
      return true;
    }

    $pdo->exec("
      ALTER TABLE projects
      MODIFY COLUMN status ENUM('draft','active','paused','completed','decliend')
      NOT NULL DEFAULT 'active'
    ");
    $available = true;
    return true;
  } catch (Throwable $e) {
    $available = false;
    return false;
  }
}

function project_statuses(PDO $pdo): array {
  $statuses = ['draft', 'active', 'paused', 'completed'];
  if (ensure_project_decliend_status($pdo)) {
    $statuses[] = 'decliend';
  }
  return $statuses;
}
