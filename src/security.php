<?php
declare(strict_types=1);

function password_hash_algorithm(): string|int {
  if (defined('PASSWORD_ARGON2ID')) {
    return PASSWORD_ARGON2ID;
  }
  return PASSWORD_BCRYPT;
}

function password_hash_algo_name(): string {
  $algo = password_hash_algorithm();
  if ($algo === PASSWORD_BCRYPT) {
    return 'bcrypt';
  }
  return 'argon2id';
}

function password_hash_secure_options(): array {
  $algo = password_hash_algorithm();

  if ($algo === PASSWORD_BCRYPT) {
    return ['cost' => 12];
  }

  return [
    'memory_cost' => 1 << 16,
    'time_cost' => 4,
    'threads' => 2,
  ];
}

function hash_password_secure(string $password): string {
  $hash = password_hash($password, password_hash_algorithm(), password_hash_secure_options());
  if (!is_string($hash) || $hash === '') {
    throw new RuntimeException('Failed to hash password.');
  }
  return $hash;
}

function password_needs_secure_rehash(string $passwordHash): bool {
  return password_needs_rehash($passwordHash, password_hash_algorithm(), password_hash_secure_options());
}

function password_min_length(): int {
  return 12;
}

function password_max_length(): int {
  return 128;
}

function common_password_map(): array {
  static $map = null;
  if ($map !== null) {
    return $map;
  }

  $common = [
    '123456', '123456789', '12345678', 'password', 'qwerty', '111111', '123123',
    'abc123', '1234567', 'password1', '12345', '1234', 'iloveyou', 'admin',
    'welcome', 'monkey', 'dragon', 'letmein', 'football', 'baseball', 'master',
    'sunshine', 'ashley', 'bailey', 'passw0rd', 'shadow', 'michael', 'superman',
    'qazwsx', 'trustno1', '654321', 'jordan', 'harley', 'hunter', 'buster',
    'soccer', 'jennifer', 'michelle', 'pepper', 'jessica', 'maggie', 'whatever',
    'access', 'freedom', 'secret', 'charlie', 'aa123456', 'donald', 'pokemon',
    'qwerty123', 'zaq12wsx', '1q2w3e4r', '1qaz2wsx', 'admin123', 'welcome1',
    'password123', 'changeme', 'test123', 'temp1234', 'temp12345', 'default',
    'administrator', '000000', '666666', '987654321', '112233', '121212',
    '7777777', '888888', '999999', '00000000', '11111111', '123321', '1111111',
    'aaaaaa', 'asdfgh', 'asdfghjkl', 'qwertyuiop', 'zxcvbnm', 'hello123',
    'welcome123', 'myspace1', 'mustang', 'cheese', 'batman', 'internet',
    'computer', 'starwars', 'whatever1', 'flower', 'loveme', 'killer', 'george',
    'summer', 'winter', 'spring', 'autumn', 'blink182', 'matrix', 'naruto',
    'pass1234', 'pass12345', '123abc', 'abc12345', 'google', 'facebook'
  ];

  $map = [];
  foreach ($common as $password) {
    $map[$password] = true;
  }

  return $map;
}

function is_common_password(string $password): bool {
  $normalized = strtolower(trim($password));
  if ($normalized === '') {
    return false;
  }

  $map = common_password_map();
  return isset($map[$normalized]);
}

function validate_password_policy(string $value, array &$errors, string $field = 'Password'): string {
  $minLen = password_min_length();
  $maxLen = password_max_length();

  if (strlen($value) < $minLen) {
    $errors[] = "{$field} must be at least {$minLen} characters.";
  }
  if (strlen($value) > $maxLen) {
    $errors[] = "{$field} must be {$maxLen} characters or fewer.";
  }
  if (is_common_password($value)) {
    $errors[] = "{$field} is too common. Choose a less predictable password.";
  }

  return $value;
}

function generate_temporary_password(int $length = 16): string {
  $length = max($length, 12);

  $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
  $lower = 'abcdefghijkmnopqrstuvwxyz';
  $digits = '23456789';
  $symbols = '!@#$%^*()-_=+';
  $all = $upper . $lower . $digits . $symbols;

  $passwordChars = [
    $upper[random_int(0, strlen($upper) - 1)],
    $lower[random_int(0, strlen($lower) - 1)],
    $digits[random_int(0, strlen($digits) - 1)],
    $symbols[random_int(0, strlen($symbols) - 1)],
  ];

  while (count($passwordChars) < $length) {
    $passwordChars[] = $all[random_int(0, strlen($all) - 1)];
  }

  for ($i = count($passwordChars) - 1; $i > 0; $i--) {
    $j = random_int(0, $i);
    $tmp = $passwordChars[$i];
    $passwordChars[$i] = $passwordChars[$j];
    $passwordChars[$j] = $tmp;
  }

  return implode('', $passwordChars);
}

function security_env_value(string $name): ?string {
  $raw = $_ENV[$name] ?? getenv($name);
  if (!is_string($raw)) {
    return null;
  }

  $value = trim($raw);
  return $value === '' ? null : $value;
}

function security_key_material_from_raw(string $raw): ?string {
  $raw = trim($raw);
  if ($raw === '') {
    return null;
  }

  if (str_starts_with($raw, 'base64:')) {
    $decoded = base64_decode(substr($raw, 7), true);
    if (is_string($decoded) && strlen($decoded) >= 32) {
      return substr(hash('sha256', $decoded, true), 0, 32);
    }
    return null;
  }

  if (preg_match('/\A[0-9a-fA-F]{64}\z/', $raw)) {
    $key = hex2bin($raw);
    return is_string($key) ? $key : null;
  }

  if (strlen($raw) >= 32) {
    return substr(hash('sha256', $raw, true), 0, 32);
  }

  return null;
}

function security_parse_keyring_entries(string $raw): array {
  $keys = [];
  $parts = preg_split('/[,\n;]+/', $raw) ?: [];

  foreach ($parts as $entry) {
    $entry = trim($entry);
    if ($entry === '' || !str_contains($entry, '=')) {
      continue;
    }

    [$id, $value] = explode('=', $entry, 2);
    $id = trim($id);
    $value = trim($value);
    if (!preg_match('/\A[a-zA-Z0-9._-]{1,40}\z/', $id)) {
      continue;
    }

    $material = security_key_material_from_raw($value);
    if ($material !== null) {
      $keys[$id] = $material;
    }
  }

  return $keys;
}

function security_field_keyring(): array {
  static $cached = null;
  if (is_array($cached)) {
    return $cached;
  }

  $keys = [];
  $keyringRaw = security_env_value('COREPANEL_FIELD_KEYS');
  if ($keyringRaw !== null) {
    $keys = security_parse_keyring_entries($keyringRaw);
  }

  $singleKeyRaw = security_env_value('COREPANEL_FIELD_KEY');
  if ($singleKeyRaw !== null) {
    $singleMaterial = security_key_material_from_raw($singleKeyRaw);
    if ($singleMaterial !== null) {
      $defaultId = array_key_exists('legacy', $keys) ? 'legacy_single' : 'legacy';
      if (!$keys) {
        $activeIdRaw = security_env_value('COREPANEL_FIELD_KEY_ACTIVE_ID');
        if ($activeIdRaw !== null && preg_match('/\A[a-zA-Z0-9._-]{1,40}\z/', $activeIdRaw)) {
          $defaultId = $activeIdRaw;
        }
      }
      if (!array_key_exists($defaultId, $keys)) {
        $keys[$defaultId] = $singleMaterial;
      }
    }
  }

  $activeId = security_env_value('COREPANEL_FIELD_KEY_ACTIVE_ID');
  if ($activeId !== null && array_key_exists($activeId, $keys)) {
    $ordered = [$activeId => $keys[$activeId]];
    foreach ($keys as $id => $material) {
      if ($id === $activeId) {
        continue;
      }
      $ordered[$id] = $material;
    }
    $keys = $ordered;
  }

  $cached = $keys;
  return $cached;
}

function security_active_field_key_id(): ?string {
  $keys = security_field_keyring();
  if (!$keys) {
    return null;
  }

  $ids = array_keys($keys);
  return (string)$ids[0];
}

function security_field_key_material_by_id(string $keyId): ?string {
  $keys = security_field_keyring();
  return $keys[$keyId] ?? null;
}

function security_field_key_material(): ?string {
  $activeId = security_active_field_key_id();
  if ($activeId === null) {
    return null;
  }

  return security_field_key_material_by_id($activeId);
}

function security_sensitive_encryption_ready(): bool {
  return security_field_key_material() !== null
    && function_exists('openssl_encrypt')
    && function_exists('openssl_decrypt');
}

function security_context_key_material(string $context, ?string $master = null): ?string {
  if ($master === null) {
    $master = security_field_key_material();
  }
  if ($master === null) {
    return null;
  }

  $normalized = strtolower(trim($context));
  if ($normalized === '') {
    return null;
  }

  return hash_hmac('sha256', $normalized, $master, true);
}

function security_encrypt_string_with_context(string $plaintext, string $context): ?string {
  $keyId = security_active_field_key_id();
  if ($keyId === null || !function_exists('openssl_encrypt')) {
    return null;
  }

  $master = security_field_key_material_by_id($keyId);
  $key = security_context_key_material($context, $master);
  if ($key === null) {
    return null;
  }

  $iv = random_bytes(12);
  $tag = '';
  $aad = 'corepanel:' . strtolower(trim($context)) . ':v2:' . $keyId;
  $ciphertext = openssl_encrypt(
    $plaintext,
    'aes-256-gcm',
    $key,
    OPENSSL_RAW_DATA,
    $iv,
    $tag,
    $aad,
    16
  );

  if (!is_string($ciphertext) || $ciphertext === '' || !is_string($tag) || strlen($tag) !== 16) {
    return null;
  }

  return 'encv2:' . $keyId . ':' . base64_encode($iv . $tag . $ciphertext);
}

function security_decrypt_string_with_context(string $value, string $context): ?string {
  if (str_starts_with($value, 'enc:')) {
    return security_decrypt_string($value);
  }

  if (str_starts_with($value, 'enc2:')) {
    return security_decrypt_string($value);
  }

  if (str_starts_with($value, 'encv2:')) {
    if (preg_match('/\Aencv2:([a-zA-Z0-9._-]{1,40}):(.+)\z/s', $value, $matches) !== 1) {
      return null;
    }

    $keyId = (string)$matches[1];
    $payloadRaw = (string)$matches[2];

    $master = security_field_key_material_by_id($keyId);
    $key = security_context_key_material($context, $master);
    if ($key === null || !function_exists('openssl_decrypt')) {
      return null;
    }

    $payload = base64_decode($payloadRaw, true);
    if (!is_string($payload) || strlen($payload) < 29) {
      return null;
    }

    $iv = substr($payload, 0, 12);
    $tag = substr($payload, 12, 16);
    $ciphertext = substr($payload, 28);
    if (strlen($iv) !== 12 || strlen($tag) !== 16 || $ciphertext === '') {
      return null;
    }

    $aad = 'corepanel:' . strtolower(trim($context)) . ':v2:' . $keyId;
    $plaintext = openssl_decrypt(
      $ciphertext,
      'aes-256-gcm',
      $key,
      OPENSSL_RAW_DATA,
      $iv,
      $tag,
      $aad
    );

    return is_string($plaintext) ? $plaintext : null;
  }

  if (!str_starts_with($value, 'encv1:')) {
    return $value;
  }

  if (!function_exists('openssl_decrypt')) {
    return null;
  }

  $payload = base64_decode(substr($value, 6), true);
  if (!is_string($payload) || strlen($payload) < 29) {
    return null;
  }

  $iv = substr($payload, 0, 12);
  $tag = substr($payload, 12, 16);
  $ciphertext = substr($payload, 28);
  if (strlen($iv) !== 12 || strlen($tag) !== 16 || $ciphertext === '') {
    return null;
  }

  $aad = 'corepanel:' . strtolower(trim($context)) . ':v1';
  $keys = security_field_keyring();
  foreach ($keys as $master) {
    $key = security_context_key_material($context, $master);
    if ($key === null) {
      continue;
    }

    $plaintext = openssl_decrypt(
      $ciphertext,
      'aes-256-gcm',
      $key,
      OPENSSL_RAW_DATA,
      $iv,
      $tag,
      $aad
    );
    if (is_string($plaintext)) {
      return $plaintext;
    }
  }

  return null;
}

function security_store_sensitive_text(?string $value, string $context): ?string {
  if ($value === null) {
    return null;
  }

  if ($value === '') {
    return null;
  }

  if (
    str_starts_with($value, 'encv2:')
    || str_starts_with($value, 'encv1:')
    || str_starts_with($value, 'enc2:')
    || str_starts_with($value, 'enc:')
  ) {
    return $value;
  }

  if (!security_sensitive_encryption_ready()) {
    return $value;
  }

  $encrypted = security_encrypt_string_with_context($value, $context);
  return is_string($encrypted) ? $encrypted : $value;
}

function security_read_sensitive_text(mixed $stored, string $context): ?string {
  if (!is_string($stored)) {
    return null;
  }

  if ($stored === '') {
    return null;
  }

  $plaintext = security_decrypt_string_with_context($stored, $context);
  if (!is_string($plaintext) || $plaintext === '') {
    if (
      str_starts_with($stored, 'encv2:')
      || str_starts_with($stored, 'encv1:')
      || str_starts_with($stored, 'enc2:')
      || str_starts_with($stored, 'enc:')
    ) {
      return $stored;
    }
    return null;
  }

  return $plaintext;
}

function security_store_user_phone(?string $value): ?string {
  return security_store_sensitive_text($value, 'users.phone');
}

function security_store_user_address(?string $value): ?string {
  return security_store_sensitive_text($value, 'users.address');
}

function security_store_user_notes(?string $value): ?string {
  return security_store_sensitive_text($value, 'users.notes');
}

function security_store_project_notes(?string $value): ?string {
  return security_store_sensitive_text($value, 'projects.notes');
}

function security_store_project_address(?string $value): ?string {
  return security_store_sensitive_text($value, 'projects.project_address');
}

function security_read_user_phone(mixed $value): ?string {
  return security_read_sensitive_text($value, 'users.phone');
}

function security_read_user_address(mixed $value): ?string {
  return security_read_sensitive_text($value, 'users.address');
}

function security_read_user_notes(mixed $value): ?string {
  return security_read_sensitive_text($value, 'users.notes');
}

function security_read_project_notes(mixed $value): ?string {
  return security_read_sensitive_text($value, 'projects.notes');
}

function security_read_project_address(mixed $value): ?string {
  return security_read_sensitive_text($value, 'projects.project_address');
}

function security_table_exists(PDO $pdo, string $tableName): bool {
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

function security_column_type(PDO $pdo, string $tableName, string $columnName): ?string {
  $stmt = $pdo->prepare(
    "SELECT COLUMN_TYPE
     FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = ?
       AND COLUMN_NAME = ?
     LIMIT 1"
  );
  $stmt->execute([$tableName, $columnName]);
  $type = $stmt->fetchColumn();

  if ($type === false || $type === null) {
    return null;
  }

  return strtolower((string)$type);
}

function security_ensure_text_column(PDO $pdo, string $tableName, string $columnName): void {
  $type = security_column_type($pdo, $tableName, $columnName);
  if ($type === null) {
    return;
  }

  if (str_contains($type, 'text')) {
    return;
  }

  $pdo->exec("ALTER TABLE `{$tableName}` MODIFY COLUMN `{$columnName}` TEXT NULL");
}

function security_prepare_sensitive_storage(PDO $pdo): void {
  static $checked = false;
  if ($checked) {
    return;
  }
  $checked = true;

  try {
    if (security_table_exists($pdo, 'users')) {
      foreach (['phone', 'address', 'notes'] as $columnName) {
        security_ensure_text_column($pdo, 'users', $columnName);
      }
    }

    if (security_table_exists($pdo, 'projects')) {
      foreach (['notes', 'project_address'] as $columnName) {
        security_ensure_text_column($pdo, 'projects', $columnName);
      }
    }
  } catch (Throwable $e) {
    // Non-fatal: keep app available even if migration privileges are restricted.
  }
}

function security_encrypt_string(string $plaintext): ?string {
  $keyId = security_active_field_key_id();
  if ($keyId === null || !function_exists('openssl_encrypt')) {
    return null;
  }
  $key = security_field_key_material_by_id($keyId);
  if ($key === null) {
    return null;
  }

  $iv = random_bytes(12);
  $tag = '';
  $aad = 'corepanel:generic:v2:' . $keyId;
  $ciphertext = openssl_encrypt(
    $plaintext,
    'aes-256-gcm',
    $key,
    OPENSSL_RAW_DATA,
    $iv,
    $tag,
    $aad,
    16
  );

  if (!is_string($ciphertext) || $ciphertext === '' || !is_string($tag) || strlen($tag) !== 16) {
    return null;
  }

  return 'enc2:' . $keyId . ':' . base64_encode($iv . $tag . $ciphertext);
}

function security_decrypt_string(string $value): ?string {
  if (str_starts_with($value, 'enc2:')) {
    if (preg_match('/\Aenc2:([a-zA-Z0-9._-]{1,40}):(.+)\z/s', $value, $matches) !== 1) {
      return null;
    }

    $keyId = (string)$matches[1];
    $payloadRaw = (string)$matches[2];
    $key = security_field_key_material_by_id($keyId);
    if ($key === null || !function_exists('openssl_decrypt')) {
      return null;
    }

    $payload = base64_decode($payloadRaw, true);
    if (!is_string($payload) || strlen($payload) < 29) {
      return null;
    }

    $iv = substr($payload, 0, 12);
    $tag = substr($payload, 12, 16);
    $ciphertext = substr($payload, 28);
    if (strlen($iv) !== 12 || strlen($tag) !== 16 || $ciphertext === '') {
      return null;
    }

    $aad = 'corepanel:generic:v2:' . $keyId;
    $plaintext = openssl_decrypt(
      $ciphertext,
      'aes-256-gcm',
      $key,
      OPENSSL_RAW_DATA,
      $iv,
      $tag,
      $aad
    );

    return is_string($plaintext) ? $plaintext : null;
  }

  if (!str_starts_with($value, 'enc:')) {
    return $value;
  }

  if (!function_exists('openssl_decrypt')) {
    return null;
  }

  $payload = base64_decode(substr($value, 4), true);
  if (!is_string($payload) || strlen($payload) < 29) {
    return null;
  }

  $iv = substr($payload, 0, 12);
  $tag = substr($payload, 12, 16);
  $ciphertext = substr($payload, 28);

  if (strlen($iv) !== 12 || strlen($tag) !== 16 || $ciphertext === '') {
    return null;
  }

  foreach (security_field_keyring() as $key) {
    $plaintext = openssl_decrypt(
      $ciphertext,
      'aes-256-gcm',
      $key,
      OPENSSL_RAW_DATA,
      $iv,
      $tag,
      ''
    );
    if (is_string($plaintext)) {
      return $plaintext;
    }
  }

  return null;
}

function totp_secret_normalize(string $secret): ?string {
  $normalized = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret) ?? '');
  if ($normalized === '' || strlen($normalized) < 16 || strlen($normalized) > 128) {
    return null;
  }

  return $normalized;
}

function totp_secret_store_value(string $secret): ?string {
  $normalized = totp_secret_normalize($secret);
  if ($normalized === null) {
    return null;
  }

  if (!security_sensitive_encryption_ready()) {
    return $normalized;
  }

  return security_encrypt_string($normalized);
}

function totp_secret_resolve(string $stored): ?string {
  $stored = trim($stored);
  if ($stored === '') {
    return null;
  }

  $plaintext = security_decrypt_string($stored);
  if (!is_string($plaintext) || $plaintext === '') {
    return null;
  }

  return totp_secret_normalize($plaintext);
}

function ensure_user_twofa_columns(PDO $pdo): bool {
  static $checked = false;
  static $available = false;

  if ($checked) {
    return $available;
  }

  $checked = true;

  try {
    $colStmt = $pdo->prepare(
      "SELECT COLUMN_NAME, COLUMN_TYPE
       FROM INFORMATION_SCHEMA.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = 'users'
         AND COLUMN_NAME IN ('totp_secret', 'twofa_enabled_at')"
    );
    $colStmt->execute();
    $columns = $colStmt->fetchAll() ?: [];
    $columnTypes = [];
    foreach ($columns as $column) {
      $name = (string)($column['COLUMN_NAME'] ?? '');
      if ($name === '') {
        continue;
      }
      $columnTypes[$name] = strtolower((string)($column['COLUMN_TYPE'] ?? ''));
    }

    $hasSecret = isset($columnTypes['totp_secret']);
    $hasEnabledAt = isset($columnTypes['twofa_enabled_at']);

    if (!$hasSecret && !$hasEnabledAt) {
      $pdo->exec(
        "ALTER TABLE users
         ADD COLUMN totp_secret TEXT NULL AFTER password_hash,
         ADD COLUMN twofa_enabled_at DATETIME NULL AFTER totp_secret"
      );
    } elseif (!$hasSecret) {
      $pdo->exec("ALTER TABLE users ADD COLUMN totp_secret TEXT NULL AFTER password_hash");
    } elseif (!$hasEnabledAt) {
      $pdo->exec("ALTER TABLE users ADD COLUMN twofa_enabled_at DATETIME NULL AFTER totp_secret");
    }

    if ($hasSecret && !str_contains((string)$columnTypes['totp_secret'], 'text')) {
      $pdo->exec("ALTER TABLE users MODIFY COLUMN totp_secret TEXT NULL");
    }

    $available = true;
    return true;
  } catch (Throwable $e) {
    $available = false;
    return false;
  }
}

function admin_twofa_enabled(array $user): bool {
  $secret = totp_secret_resolve((string)($user['totp_secret'] ?? ''));

  return (($user['role'] ?? 'user') === 'admin')
    && $secret !== null
    && !empty($user['twofa_enabled_at']);
}
