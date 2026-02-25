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

// Prevent deleting yourself (safety)
$me = current_user($pdo);
if ($me && (int)$me['id'] === $id) {
  redirect('/admin/users/index.php');
}

// Fetch target user first (for safety checks + audit details)
$target = $pdo->prepare("SELECT id, name, email, role FROM users WHERE id = ?");
$target->execute([$id]);
$targetUser = $target->fetch();

if (!$targetUser) {
  redirect('/admin/users/index.php');
}

if (($targetUser['role'] ?? 'user') === 'admin') {
  $countAdmins = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
  if ($countAdmins <= 1) {
    http_response_code(400);
    exit("Cannot delete the last admin.");
  }
}

$pdo->beginTransaction();
try {
  // Explicit delete keeps behavior stable even without FK cascade in some envs.
  $pdo->prepare("DELETE FROM items WHERE user_id = ?")->execute([$id]);
  $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);

  if ($me) {
    admin_audit_log(
      $pdo,
      (int)$me['id'],
      'delete_user',
      $id,
      "Deleted user {$targetUser['email']} ({$targetUser['role']})"
    );
  }

  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  throw $e;
}

redirect('/admin/users/index.php');
