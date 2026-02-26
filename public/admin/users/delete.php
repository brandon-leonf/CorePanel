<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../../config/db.php';
require __DIR__ . '/../../../src/auth.php';
require __DIR__ . '/../../../src/helpers.php';
require __DIR__ . '/../../../src/admin_audit.php';
require __DIR__ . '/../../../src/upload.php';

$me = require_permission($pdo, 'users.delete');
$tenantId = actor_tenant_id($me);

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
if ($me && (int)$me['id'] === $id) {
  redirect('/admin/users/index.php');
}

// Fetch target user first (for safety checks + audit details + tenant scope)
$targetUser = require_user_in_tenant($pdo, $me, $id);

if (($targetUser['role'] ?? 'user') === 'admin') {
  $countAdminsStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM users
     WHERE role = ? AND tenant_id = ?"
  );
  $countAdminsStmt->execute(['admin', $tenantId]);
  $countAdmins = (int)$countAdminsStmt->fetchColumn();
  if ($countAdmins <= 1) {
    http_response_code(400);
    exit("Cannot delete the last admin.");
  }
}

$itemImageStmt = $pdo->prepare("
  SELECT image_path
  FROM items
  WHERE user_id = ?
    AND tenant_id = ?
    AND image_path IS NOT NULL
    AND image_path <> ''
");
$itemImageStmt->execute([$id, $tenantId]);
$itemImagePaths = array_values(array_unique(array_map(
  static fn($v): string => (string)$v,
  $itemImageStmt->fetchAll(PDO::FETCH_COLUMN) ?: []
)));

$pdo->beginTransaction();
try {
  // Explicit delete keeps behavior stable even without FK cascade in some envs.
  $pdo->prepare("DELETE FROM items WHERE user_id = ? AND tenant_id = ?")->execute([$id, $tenantId]);
  $pdo->prepare("DELETE FROM users WHERE id = ? AND tenant_id = ?")->execute([$id, $tenantId]);

  if ($me) {
    admin_audit_log(
      $pdo,
      (int)$me['id'],
      'delete_user',
      $id,
      "Deleted user {$targetUser['email']} ({$targetUser['role']})",
      $tenantId
    );
  }

  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  throw $e;
}

foreach ($itemImagePaths as $imagePath) {
  upload_delete_reference_if_unreferenced($pdo, $imagePath);
}

redirect('/admin/users/index.php');
