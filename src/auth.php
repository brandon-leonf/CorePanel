<?php
declare(strict_types=1);

require_once __DIR__ . '/error_handling.php';
app_configure_error_handling();

function session_cookie_options(): array {
  return [
    'lifetime' => 0,
    'path' => '/',
    'secure' => is_https_request(),
    'httponly' => true,
    'samesite' => 'Lax',
  ];
}

function env_flag(string $name, bool $default = false): bool {
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

function session_binding_enabled(): bool {
  return env_flag('COREPANEL_SESSION_BINDING', true);
}

function session_ip_binding_enabled(): bool {
  return env_flag('COREPANEL_SESSION_BIND_IP', true);
}

function session_idle_timeout_seconds(): int {
  return 60 * 60 * 8; // 8 hours
}

function session_absolute_timeout_seconds(): int {
  return 60 * 60 * 24 * 7; // 7 days
}

function configure_session_security(): void {
  ini_set('session.use_strict_mode', '1');
  ini_set('session.use_only_cookies', '1');
  ini_set('session.use_trans_sid', '0');
  ini_set('session.cookie_httponly', '1');
  ini_set('session.cookie_secure', is_https_request() ? '1' : '0');
  ini_set('session.cookie_samesite', 'Lax');
  ini_set('session.gc_maxlifetime', (string)(60 * 60 * 8));
}

function is_https_request(): bool {
  if (function_exists('helpers_request_is_https')) {
    return helpers_request_is_https();
  }

  if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
    return true;
  }

  if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
    return true;
  }

  if (auth_proxy_headers_trusted()) {
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

function auth_request_host(): string {
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

function auth_is_localhost_request(): bool {
  if (function_exists('helpers_is_localhost_request')) {
    return helpers_is_localhost_request();
  }

  $host = auth_request_host();
  return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

function auth_https_enforcement_enabled(): bool {
  if (function_exists('https_enforcement_enabled')) {
    return https_enforcement_enabled();
  }

  $default = !auth_is_localhost_request();
  return env_flag('COREPANEL_ENFORCE_HTTPS', $default);
}

function auth_ip_matches_cidr(string $ip, string $cidr): bool {
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
  return (ord($ipBin[$fullBytes]) & $mask) === (ord($networkBin[$fullBytes]) & $mask);
}

function auth_proxy_headers_trusted(): bool {
  if (function_exists('helpers_proxy_headers_trusted')) {
    return helpers_proxy_headers_trusted();
  }

  if (!env_flag('COREPANEL_TRUST_PROXY_HEADERS', false)) {
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
    if (auth_ip_matches_cidr($remoteAddr, $entry)) {
      return true;
    }
  }

  return false;
}

function auth_insecure_localhost_allowed(): bool {
  return env_flag('COREPANEL_ALLOW_INSECURE_LOCALHOST', true);
}

function auth_https_required_for_authenticated_routes(): bool {
  if (PHP_SAPI === 'cli') {
    return false;
  }

  if (auth_insecure_localhost_allowed() && auth_is_localhost_request()) {
    return false;
  }

  return true;
}

function auth_redirect_to_https_or_forbid(): never {
  $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
  $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
  if ($uri === '') {
    $uri = '/';
  }

  if (!headers_sent() && $host !== '' && preg_match('/\A[a-zA-Z0-9.\-:\[\]]+\z/', $host)) {
    header('Location: https://' . $host . $uri, true, 308);
    exit;
  }

  http_response_code(403);
  header('Content-Type: text/plain; charset=UTF-8');
  exit('HTTPS required.');
}

function enforce_https_for_authenticated_request(): void {
  if (!auth_https_required_for_authenticated_routes()) {
    return;
  }

  if (is_https_request()) {
    return;
  }

  auth_redirect_to_https_or_forbid();
}

function session_client_ip(): string {
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

function session_client_ip_prefix(): string {
  $ip = session_client_ip();

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

    $prefixBytes = substr($packed, 0, 8); // /64 prefix
    $hex = bin2hex($prefixBytes);
    $groups = str_split($hex, 4);
    return strtolower(implode(':', $groups)) . '::/64';
  }

  return '0.0.0.0/24';
}

function session_client_ua_hash(): string {
  $ua = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
  if ($ua === '') {
    $ua = 'unknown';
  }
  return hash('sha256', $ua);
}

function bind_session_to_client(): void {
  if (!session_binding_enabled()) {
    unset($_SESSION['_bind_ua_hash'], $_SESSION['_bind_ip_prefix']);
    return;
  }

  $_SESSION['_bind_ua_hash'] = session_client_ua_hash();
  if (session_ip_binding_enabled()) {
    $_SESSION['_bind_ip_prefix'] = session_client_ip_prefix();
  } else {
    unset($_SESSION['_bind_ip_prefix']);
  }
}

function is_session_bound_to_current_client(): bool {
  if (!session_binding_enabled()) {
    return true;
  }

  $currentUaHash = session_client_ua_hash();
  $storedUaHash = (string)($_SESSION['_bind_ua_hash'] ?? '');
  if ($storedUaHash === '') {
    $_SESSION['_bind_ua_hash'] = $currentUaHash;
  } elseif (!hash_equals($storedUaHash, $currentUaHash)) {
    return false;
  }

  if (session_ip_binding_enabled()) {
    $currentIpPrefix = session_client_ip_prefix();
    $storedIpPrefix = (string)($_SESSION['_bind_ip_prefix'] ?? '');
    if ($storedIpPrefix === '') {
      $_SESSION['_bind_ip_prefix'] = $currentIpPrefix;
    } elseif (!hash_equals($storedIpPrefix, $currentIpPrefix)) {
      return false;
    }
  } else {
    unset($_SESSION['_bind_ip_prefix']);
  }

  return true;
}

function start_session(): void {
  if (function_exists('enforce_https_if_configured')) {
    enforce_https_if_configured();
  } elseif (PHP_SAPI !== 'cli' && auth_https_enforcement_enabled() && !is_https_request()) {
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host !== '' && preg_match('/\A[a-zA-Z0-9.\-:\[\]]+\z/', $host)) {
      $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
      if ($uri === '') {
        $uri = '/';
      }
      header('Location: https://' . $host . $uri, true, 308);
      exit;
    }
  }

  if (session_status() === PHP_SESSION_ACTIVE) {
    return;
  }

  configure_session_security();
  session_name('corepanel_session');
  session_set_cookie_params(session_cookie_options());
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

function destroy_current_session(): void {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    start_session();
  }

  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    $cookieOptions = [
      'expires' => time() - 3600,
      'path' => $params['path'] ?? '/',
      'secure' => (bool)($params['secure'] ?? false),
      'httponly' => (bool)($params['httponly'] ?? true),
      'samesite' => $params['samesite'] ?? 'Lax',
    ];
    if (!empty($params['domain'])) {
      $cookieOptions['domain'] = (string)$params['domain'];
    }
    setcookie(session_name(), '', $cookieOptions);
  }

  session_destroy();
}

function complete_login_session(int $userId, string $role = 'user'): void {
  start_session();
  session_regenerate_id(true);

  $now = time();
  $_SESSION = [];
  $_SESSION['user_id'] = $userId;
  $_SESSION['_created_at'] = $now;
  $_SESSION['_login_at'] = $now;
  $_SESSION['_last_activity_at'] = $now;
  $_SESSION['_last_regenerated_at'] = $now;
  $_SESSION['_auth_role'] = $role;
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  bind_session_to_client();
}

function begin_pending_twofa_session(int $userId): void {
  start_session();
  session_regenerate_id(true);

  $now = time();
  $_SESSION = [];
  $_SESSION['pending_2fa_user_id'] = $userId;
  $_SESSION['_created_at'] = $now;
  $_SESSION['pending_2fa_started_at'] = $now;
  $_SESSION['_last_regenerated_at'] = $now;
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  bind_session_to_client();
}

function clear_pending_twofa_session(): void {
  unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_started_at']);
}

function send_private_no_store_headers(): void {
  if (headers_sent()) {
    return;
  }

  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');
}

function require_login(): void {
  enforce_https_for_authenticated_request();
  start_session();
  if (function_exists('send_security_headers')) {
    send_security_headers(false);
  }
  send_private_no_store_headers();

  if (!empty($_SESSION['user_id'])) {
    if (!is_session_bound_to_current_client()) {
      destroy_current_session();
      header('Location: /login.php');
      exit;
    }

    $now = time();
    $lastActivity = (int)($_SESSION['_last_activity_at'] ?? ($_SESSION['_login_at'] ?? $now));
    $loginAt = (int)($_SESSION['_login_at'] ?? ($_SESSION['_created_at'] ?? $now));

    $idleTimeoutSeconds = session_idle_timeout_seconds();
    $absoluteTimeoutSeconds = session_absolute_timeout_seconds();

    if (($now - $lastActivity) > $idleTimeoutSeconds || ($now - $loginAt) > $absoluteTimeoutSeconds) {
      destroy_current_session();
      header('Location: /login.php');
      exit;
    }

    $_SESSION['_last_activity_at'] = $now;
  }

  if (empty($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
  }
}

function ac_table_exists(PDO $pdo, string $tableName): bool {
  $stmt = $pdo->prepare(
    "SELECT 1
     FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = ?
     LIMIT 1"
  );
  $stmt->execute([$tableName]);
  return (bool)$stmt->fetchColumn();
}

function ac_column_exists(PDO $pdo, string $tableName, string $columnName): bool {
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

function ac_ensure_tenant_model(PDO $pdo): void {
  $pdo->exec(
    "CREATE TABLE IF NOT EXISTS tenants (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(190) NOT NULL UNIQUE,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB"
  );
  $seedTenantStmt = $pdo->prepare("INSERT IGNORE INTO tenants (id, name) VALUES (?, ?)");
  $seedTenantStmt->execute([1, 'Default Tenant']);

  if (!ac_column_exists($pdo, 'users', 'tenant_id')) {
    $pdo->exec("ALTER TABLE users ADD COLUMN tenant_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER role");
  }
  $pdo->exec("UPDATE users SET tenant_id = 1 WHERE tenant_id IS NULL OR tenant_id = 0");
  try {
    $pdo->exec("ALTER TABLE users ADD INDEX idx_users_tenant_id (tenant_id)");
  } catch (Throwable $e) {
    // index may already exist
  }
  if (!ac_column_exists($pdo, 'users', 'deleted_at')) {
    $pdo->exec("ALTER TABLE users ADD COLUMN deleted_at DATETIME NULL AFTER created_at");
  }
  try {
    $pdo->exec("ALTER TABLE users ADD INDEX idx_users_deleted_at (deleted_at)");
  } catch (Throwable $e) {
    // index may already exist
  }

  if (ac_table_exists($pdo, 'projects')) {
    if (!ac_column_exists($pdo, 'projects', 'tenant_id')) {
      $pdo->exec("ALTER TABLE projects ADD COLUMN tenant_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER user_id");
    }
    if (!ac_column_exists($pdo, 'projects', 'deleted_at')) {
      $pdo->exec("ALTER TABLE projects ADD COLUMN deleted_at DATETIME NULL AFTER created_at");
    }
    $pdo->exec(
      "UPDATE projects p
       JOIN users u ON u.id = p.user_id
       SET p.tenant_id = u.tenant_id
       WHERE p.tenant_id IS NULL OR p.tenant_id = 0"
    );
    try {
      $pdo->exec("ALTER TABLE projects ADD INDEX idx_projects_tenant_id (tenant_id)");
    } catch (Throwable $e) {
      // index may already exist
    }
    try {
      $pdo->exec("ALTER TABLE projects ADD INDEX idx_projects_deleted_at (deleted_at)");
    } catch (Throwable $e) {
      // index may already exist
    }
  }

  if (ac_table_exists($pdo, 'items')) {
    if (!ac_column_exists($pdo, 'items', 'tenant_id')) {
      $pdo->exec("ALTER TABLE items ADD COLUMN tenant_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER user_id");
    }
    $pdo->exec(
      "UPDATE items i
       JOIN users u ON u.id = i.user_id
       SET i.tenant_id = u.tenant_id
       WHERE i.tenant_id IS NULL OR i.tenant_id = 0"
    );
    try {
      $pdo->exec("ALTER TABLE items ADD INDEX idx_items_tenant_id (tenant_id)");
    } catch (Throwable $e) {
      // index may already exist
    }
  }

  if (ac_table_exists($pdo, 'project_tasks')) {
    if (!ac_column_exists($pdo, 'project_tasks', 'tenant_id')) {
      $pdo->exec("ALTER TABLE project_tasks ADD COLUMN tenant_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER project_id");
    }
    if (ac_table_exists($pdo, 'projects')) {
      $pdo->exec(
        "UPDATE project_tasks t
         JOIN projects p ON p.id = t.project_id
         SET t.tenant_id = p.tenant_id
         WHERE t.tenant_id IS NULL OR t.tenant_id = 0"
      );
    }
    try {
      $pdo->exec("ALTER TABLE project_tasks ADD INDEX idx_project_tasks_tenant_id (tenant_id)");
    } catch (Throwable $e) {
      // index may already exist
    }
  }

  if (ac_table_exists($pdo, 'project_images')) {
    if (!ac_column_exists($pdo, 'project_images', 'tenant_id')) {
      $pdo->exec("ALTER TABLE project_images ADD COLUMN tenant_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER project_id");
    }
    if (!ac_column_exists($pdo, 'project_images', 'deleted_at')) {
      $pdo->exec("ALTER TABLE project_images ADD COLUMN deleted_at DATETIME NULL AFTER created_at");
    }
    if (ac_table_exists($pdo, 'projects')) {
      $pdo->exec(
        "UPDATE project_images i
         JOIN projects p ON p.id = i.project_id
         SET i.tenant_id = p.tenant_id
         WHERE i.tenant_id IS NULL OR i.tenant_id = 0"
      );
    }
    try {
      $pdo->exec("ALTER TABLE project_images ADD INDEX idx_project_images_tenant_id (tenant_id)");
    } catch (Throwable $e) {
      // index may already exist
    }
    try {
      $pdo->exec("ALTER TABLE project_images ADD INDEX idx_project_images_deleted_at (deleted_at)");
    } catch (Throwable $e) {
      // index may already exist
    }
  }

  if (ac_table_exists($pdo, 'project_payments')) {
    if (!ac_column_exists($pdo, 'project_payments', 'deleted_at')) {
      $pdo->exec("ALTER TABLE project_payments ADD COLUMN deleted_at DATETIME NULL AFTER created_at");
    }
    try {
      $pdo->exec("ALTER TABLE project_payments ADD INDEX idx_project_payments_deleted_at (deleted_at)");
    } catch (Throwable $e) {
      // index may already exist
    }
  }
}

function ac_ensure_rbac_tables(PDO $pdo): void {
  $pdo->exec(
    "CREATE TABLE IF NOT EXISTS roles (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      role_key VARCHAR(80) NOT NULL UNIQUE,
      role_name VARCHAR(120) NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB"
  );

  $pdo->exec(
    "CREATE TABLE IF NOT EXISTS permissions (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      permission_key VARCHAR(120) NOT NULL UNIQUE,
      permission_description VARCHAR(255) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB"
  );

  $pdo->exec(
    "CREATE TABLE IF NOT EXISTS role_permissions (
      role_id INT UNSIGNED NOT NULL,
      permission_id INT UNSIGNED NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (role_id, permission_id),
      CONSTRAINT fk_role_permissions_role
        FOREIGN KEY (role_id) REFERENCES roles(id)
        ON DELETE CASCADE,
      CONSTRAINT fk_role_permissions_permission
        FOREIGN KEY (permission_id) REFERENCES permissions(id)
        ON DELETE CASCADE
    ) ENGINE=InnoDB"
  );

  $pdo->exec(
    "CREATE TABLE IF NOT EXISTS user_roles (
      user_id INT UNSIGNED NOT NULL,
      role_id INT UNSIGNED NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (user_id, role_id),
      CONSTRAINT fk_user_roles_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE,
      CONSTRAINT fk_user_roles_role
        FOREIGN KEY (role_id) REFERENCES roles(id)
        ON DELETE CASCADE
    ) ENGINE=InnoDB"
  );
}

function ac_seed_rbac_defaults(PDO $pdo): void {
  $roles = [
    ['admin', 'Administrator'],
    ['manager', 'Manager'],
    ['client', 'Client'],
  ];
  $insertRole = $pdo->prepare("INSERT IGNORE INTO roles (role_key, role_name) VALUES (?, ?)");
  foreach ($roles as $role) {
    $insertRole->execute([$role[0], $role[1]]);
  }

  $permissions = [
    'dashboard.admin.view' => 'Access admin dashboard',
    'users.view' => 'View tenant users',
    'users.create' => 'Create tenant users',
    'users.edit' => 'Edit tenant users',
    'users.delete' => 'Delete tenant users',
    'users.role.manage' => 'Promote or demote users',
    'security.manage' => 'Manage security settings',
    'projects.view.any' => 'View any tenant project',
    'projects.view.own' => 'View own projects',
    'projects.create' => 'Create projects',
    'projects.edit.any' => 'Edit any tenant project',
    'projects.edit.own' => 'Edit own projects',
    'projects.delete.any' => 'Delete any tenant project',
    'projects.delete.own' => 'Delete own projects',
    'projects.print.any' => 'Print any tenant project',
    'projects.print.own' => 'Print own projects',
    'project_tasks.edit.any' => 'Edit any tenant project task',
    'project_tasks.edit.own' => 'Edit own project task',
    'project_tasks.delete.any' => 'Delete any tenant project task',
    'project_tasks.delete.own' => 'Delete own project task',
    'project_images.manage.any' => 'Manage any tenant project images',
    'project_images.manage.own' => 'Manage own project images',
    'payments.create' => 'Create project payments',
    'client.dashboard.view' => 'Access client dashboard',
    'items.manage.own' => 'Manage own items',
  ];
  $insertPermission = $pdo->prepare(
    "INSERT IGNORE INTO permissions (permission_key, permission_description)
     VALUES (?, ?)"
  );
  foreach ($permissions as $key => $description) {
    $insertPermission->execute([$key, $description]);
  }

  $roleRowsStmt = $pdo->prepare("SELECT id, role_key FROM roles");
  $roleRowsStmt->execute();
  $roleRows = $roleRowsStmt->fetchAll() ?: [];
  $roleIds = [];
  foreach ($roleRows as $roleRow) {
    $roleIds[(string)$roleRow['role_key']] = (int)$roleRow['id'];
  }

  $permRowsStmt = $pdo->prepare("SELECT id, permission_key FROM permissions");
  $permRowsStmt->execute();
  $permRows = $permRowsStmt->fetchAll() ?: [];
  $permissionIds = [];
  foreach ($permRows as $permRow) {
    $permissionIds[(string)$permRow['permission_key']] = (int)$permRow['id'];
  }

  $rolePermissionMap = [
    'admin' => array_keys($permissions),
    'manager' => [
      'dashboard.admin.view',
      'users.view',
      'users.create',
      'users.edit',
      'projects.view.any',
      'projects.create',
      'projects.edit.any',
      'projects.delete.any',
      'projects.print.any',
      'project_tasks.edit.any',
      'project_tasks.delete.any',
      'project_images.manage.any',
      'payments.create',
    ],
    'client' => [
      'client.dashboard.view',
      'projects.view.own',
      'projects.print.own',
      'items.manage.own',
    ],
  ];

  $insertRolePermission = $pdo->prepare(
    "INSERT IGNORE INTO role_permissions (role_id, permission_id)
     VALUES (?, ?)"
  );
  foreach ($rolePermissionMap as $roleKey => $permissionKeys) {
    $roleId = (int)($roleIds[$roleKey] ?? 0);
    if ($roleId <= 0) {
      continue;
    }

    foreach ($permissionKeys as $permissionKey) {
      $permissionId = (int)($permissionIds[$permissionKey] ?? 0);
      if ($permissionId <= 0) {
        continue;
      }
      $insertRolePermission->execute([$roleId, $permissionId]);
    }
  }
}

function ensure_access_control_schema(PDO $pdo): bool {
  static $checked = false;
  static $ready = false;

  if ($checked) {
    return $ready;
  }

  $checked = true;

  try {
    ac_ensure_tenant_model($pdo);
    ac_ensure_rbac_tables($pdo);
    ac_seed_rbac_defaults($pdo);
    $ready = true;
  } catch (Throwable $e) {
    $ready = false;
  }

  return $ready;
}

function legacy_role_permissions(string $role): array {
  $normalized = strtolower(trim($role));

  if ($normalized === 'admin') {
    return ['*'];
  }

  return [
    'client.dashboard.view',
    'projects.view.own',
    'projects.print.own',
    'items.manage.own',
  ];
}

function ensure_user_primary_role_binding(PDO $pdo, int $userId, string $legacyRole): void {
  if (!ensure_access_control_schema($pdo)) {
    return;
  }

  $roleLookupStmt = $pdo->prepare("SELECT id FROM roles WHERE role_key = ? LIMIT 1");
  $assignStmt = $pdo->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)");
  $existingRoleStmt = $pdo->prepare(
    "SELECT r.role_key
     FROM user_roles ur
     JOIN roles r ON r.id = ur.role_id
     WHERE ur.user_id = ?"
  );
  $existingRoleStmt->execute([$userId]);
  $existingRoles = $existingRoleStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

  if (strtolower($legacyRole) === 'admin') {
    $roleLookupStmt->execute(['admin']);
    $adminRoleId = (int)$roleLookupStmt->fetchColumn();
    if ($adminRoleId > 0 && !in_array('admin', $existingRoles, true)) {
      $assignStmt->execute([$userId, $adminRoleId]);
    }
    return;
  }

  if ($existingRoles) {
    return;
  }

  $roleLookupStmt->execute(['client']);
  $clientRoleId = (int)$roleLookupStmt->fetchColumn();
  if ($clientRoleId > 0) {
    $assignStmt->execute([$userId, $clientRoleId]);
  }
}

function sync_user_legacy_role_binding(PDO $pdo, int $userId, string $legacyRole): void {
  if (!ensure_access_control_schema($pdo)) {
    return;
  }

  $roleLookupStmt = $pdo->prepare("SELECT id FROM roles WHERE role_key = ? LIMIT 1");
  $assignStmt = $pdo->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)");
  $deleteStmt = $pdo->prepare("DELETE FROM user_roles WHERE user_id = ? AND role_id = ?");

  $roleLookupStmt->execute(['admin']);
  $adminRoleId = (int)$roleLookupStmt->fetchColumn();
  $roleLookupStmt->execute(['client']);
  $clientRoleId = (int)$roleLookupStmt->fetchColumn();

  if (strtolower($legacyRole) === 'admin') {
    if ($adminRoleId > 0) {
      $assignStmt->execute([$userId, $adminRoleId]);
    }
    return;
  }

  if ($clientRoleId > 0) {
    $assignStmt->execute([$userId, $clientRoleId]);
  }
  if ($adminRoleId > 0) {
    $deleteStmt->execute([$userId, $adminRoleId]);
  }
}

function actor_tenant_id(array $actor): int {
  $tenantId = (int)($actor['tenant_id'] ?? 0);
  return $tenantId > 0 ? $tenantId : 1;
}

function user_has_role(array $actor, string $roleKey): bool {
  $roles = $actor['roles'] ?? [];
  if (!is_array($roles)) {
    return false;
  }
  return in_array($roleKey, $roles, true);
}

function user_has_permission(array $actor, string $permissionKey): bool {
  $permissions = $actor['permissions'] ?? [];
  if (!is_array($permissions)) {
    return false;
  }

  if (isset($permissions['*']) && $permissions['*'] === true) {
    return true;
  }

  return isset($permissions[$permissionKey]) && $permissions[$permissionKey] === true;
}

function require_permission(PDO $pdo, string $permissionKey): array {
  require_login();
  $actor = current_user($pdo);
  if (!$actor || !user_has_permission($actor, $permissionKey)) {
    http_response_code(403);
    exit('Forbidden');
  }

  return $actor;
}

function require_any_permission(PDO $pdo, array $permissionKeys): array {
  require_login();
  $actor = current_user($pdo);
  if (!$actor) {
    http_response_code(403);
    exit('Forbidden');
  }

  foreach ($permissionKeys as $permissionKey) {
    if (is_string($permissionKey) && user_has_permission($actor, $permissionKey)) {
      return $actor;
    }
  }

  http_response_code(403);
  exit('Forbidden');
}

function require_user_in_tenant(PDO $pdo, array $actor, int $targetUserId): array {
  $stmt = $pdo->prepare(
    "SELECT id, name, email, role, tenant_id
     FROM users
     WHERE id = ?
       AND deleted_at IS NULL
     LIMIT 1"
  );
  $stmt->execute([$targetUserId]);
  $target = $stmt->fetch();
  if (!$target) {
    http_response_code(404);
    exit('User not found');
  }

  if ((int)$target['tenant_id'] !== actor_tenant_id($actor)) {
    http_response_code(403);
    exit('Forbidden');
  }

  return $target;
}

function project_action_permission_pair(string $action): array {
  return match ($action) {
    'edit' => ['projects.edit.any', 'projects.edit.own'],
    'delete' => ['projects.delete.any', 'projects.delete.own'],
    'print' => ['projects.print.any', 'projects.print.own'],
    'task_edit' => ['project_tasks.edit.any', 'project_tasks.edit.own'],
    'task_delete' => ['project_tasks.delete.any', 'project_tasks.delete.own'],
    'images_manage' => ['project_images.manage.any', 'project_images.manage.own'],
    default => ['projects.view.any', 'projects.view.own'],
  };
}

function require_project_access(PDO $pdo, array $actor, int $projectId, string $action = 'view'): array {
  $stmt = $pdo->prepare(
    "SELECT id, user_id, tenant_id
     FROM projects
     WHERE id = ?
       AND deleted_at IS NULL
     LIMIT 1"
  );
  $stmt->execute([$projectId]);
  $project = $stmt->fetch();
  if (!$project) {
    http_response_code(404);
    exit('Project not found');
  }

  if ((int)$project['tenant_id'] !== actor_tenant_id($actor)) {
    http_response_code(403);
    exit('Forbidden');
  }

  [$anyPermission, $ownPermission] = project_action_permission_pair($action);
  if (user_has_permission($actor, $anyPermission)) {
    return $project;
  }

  if (user_has_permission($actor, $ownPermission) && (int)$project['user_id'] === (int)$actor['id']) {
    return $project;
  }

  http_response_code(403);
  exit('Forbidden');
}

function current_user(PDO $pdo): ?array {
  start_session();
  if (empty($_SESSION['user_id'])) return null;
  if (!is_session_bound_to_current_client()) {
    destroy_current_session();
    return null;
  }

  $accessReady = ensure_access_control_schema($pdo);
  $selectSql = $accessReady
    ? "SELECT id, name, email, role, tenant_id FROM users WHERE id = ? AND deleted_at IS NULL"
    : "SELECT id, name, email, role, 1 AS tenant_id FROM users WHERE id = ?";
  $stmt = $pdo->prepare($selectSql);
  $stmt->execute([$_SESSION['user_id']]);
  $u = $stmt->fetch();
  if (!$u) {
    return null;
  }

  $role = (string)($u['role'] ?? 'user');
  $sessionRole = (string)($_SESSION['_auth_role'] ?? '');
  if ($sessionRole === '') {
    $_SESSION['_auth_role'] = $role;
  } elseif ($sessionRole !== $role) {
    session_regenerate_id(true);
    $_SESSION['_last_regenerated_at'] = time();
    $_SESSION['_auth_role'] = $role;
  }

  $userId = (int)($u['id'] ?? 0);
  ensure_user_primary_role_binding($pdo, $userId, $role);

  $roles = [];
  $permissions = [];

  if ($accessReady) {
    $rpStmt = $pdo->prepare(
      "SELECT r.role_key, p.permission_key
       FROM user_roles ur
       JOIN roles r ON r.id = ur.role_id
       LEFT JOIN role_permissions rp ON rp.role_id = r.id
       LEFT JOIN permissions p ON p.id = rp.permission_id
       WHERE ur.user_id = ?"
    );
    $rpStmt->execute([$userId]);
    $rpRows = $rpStmt->fetchAll() ?: [];

    foreach ($rpRows as $rpRow) {
      $roleKey = trim((string)($rpRow['role_key'] ?? ''));
      if ($roleKey !== '' && !in_array($roleKey, $roles, true)) {
        $roles[] = $roleKey;
      }

      $permissionKey = trim((string)($rpRow['permission_key'] ?? ''));
      if ($permissionKey !== '') {
        $permissions[$permissionKey] = true;
      }
    }
  }

  if (!$roles) {
    $roles[] = $role === 'admin' ? 'admin' : 'client';
  }
  if (!$permissions) {
    foreach (legacy_role_permissions($role) as $permissionKey) {
      $permissions[$permissionKey] = true;
    }
  }

  $u['tenant_id'] = actor_tenant_id($u);
  $u['roles'] = $roles;
  $u['permissions'] = $permissions;

  return $u;
}

function require_admin(PDO $pdo): void {
  $actor = require_any_permission($pdo, ['dashboard.admin.view', 'users.view', 'projects.view.any']);
  if (!user_has_role($actor, 'admin') && (($actor['role'] ?? 'user') !== 'admin')) {
    http_response_code(403);
    exit('Forbidden');
  }
}
