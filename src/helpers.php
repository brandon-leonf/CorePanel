<?php
declare(strict_types=1);

function e(string $v): string {
  return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): never {
  header("Location: {$path}");
  exit;
}

function csrf_session_start(): void {
  if (session_status() === PHP_SESSION_NONE) {
    session_start();
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
