<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../../config/db.php';
require __DIR__ . '/../../../src/auth.php';
require __DIR__ . '/../../../src/helpers.php';
require __DIR__ . '/../../../src/admin_audit.php';

require_admin($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit;
}

if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
  http_response_code(403);
  exit('Invalid CSRF token');
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) redirect('/admin/users/index.php');

$me = current_user($pdo);
if ($me && (int)$me['id'] === $id) {
  http_response_code(400);
  exit("You can't change your own role.");
}

/** Always read the current role from DB (never trust POST) */
$roleStmt = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
$roleStmt->execute([$id]);
$currentRoleDb = $roleStmt->fetchColumn();

if (!$currentRoleDb) {
  http_response_code(404);
  exit('User not found');
}

if ($currentRoleDb === 'admin') {
  $countAdmins = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
  if ($countAdmins <= 1) {
    http_response_code(400);
    exit("Cannot demote the last admin.");
  }
  $newRole = 'user';
} else {
  $newRole = 'admin';
}

$stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
$stmt->execute([$newRole, $id]);

if ($me) {
  $action = $newRole === 'admin' ? 'promote_user' : 'demote_user';
  admin_audit_log(
    $pdo,
    (int)$me['id'],
    $action,
    $id,
    "Changed role to {$newRole}"
  );
}

redirect('/admin/users/index.php');
