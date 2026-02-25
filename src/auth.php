<?php
declare(strict_types=1);

function is_https_request(): bool {
  if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
    return true;
  }

  if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
    return true;
  }

  $forwardedProto = (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
  if ($forwardedProto !== '') {
    $firstProto = strtolower(trim(explode(',', $forwardedProto)[0]));
    if ($firstProto === 'https') {
      return true;
    }
  }

  return false;
}

function start_session(): void {
  if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
      'lifetime' => 0,
      'path' => '/',
      'secure' => is_https_request(),
      'httponly' => true,
      'samesite' => 'Lax',
    ]);
    session_start();
  }
}

function require_login(): void {
  start_session();
  if (empty($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
  }
}

function current_user(PDO $pdo): ?array {
  start_session();
  if (empty($_SESSION['user_id'])) return null;

  $stmt = $pdo->prepare("SELECT id, name, email, role FROM users WHERE id = ?");
  $stmt->execute([$_SESSION['user_id']]);
  $u = $stmt->fetch();
  return $u ?: null;
}

function require_admin(PDO $pdo): void {
  require_login();
  $u = current_user($pdo);
  if (!$u || ($u['role'] ?? 'user') !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
  }
}
