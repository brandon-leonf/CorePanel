<?php
declare(strict_types=1);

function start_session(): void {
  if (session_status() === PHP_SESSION_NONE) {
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