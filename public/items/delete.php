<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../config/db.php';
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/helpers.php';
require __DIR__ . '/../../src/upload.php';

require_login();
$user = current_user($pdo);
$userId = (int)$user['id'];
$tenantId = actor_tenant_id($user);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit;
}

if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
  http_response_code(403);
  exit('Invalid CSRF token');
}

$id = (int)($_POST['id'] ?? 0);
if ($id > 0) {
  $imgStmt = $pdo->prepare("SELECT image_path FROM items WHERE id = ? AND user_id = ? AND tenant_id = ? LIMIT 1");
  $imgStmt->execute([$id, $userId, $tenantId]);
  $imagePath = (string)($imgStmt->fetchColumn() ?: '');

  $del = $pdo->prepare("DELETE FROM items WHERE id = ? AND user_id = ? AND tenant_id = ?");
  $del->execute([$id, $userId, $tenantId]);

  if ($imagePath !== '') {
    upload_delete_reference_if_unreferenced($pdo, $imagePath);
  }
}

redirect('/items/index.php');
