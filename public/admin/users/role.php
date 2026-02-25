<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../../config/db.php';
require __DIR__ . '/../../../src/auth.php';
require __DIR__ . '/../../../src/helpers.php';

require_admin($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Method not allowed');
}

$id = (int)($_POST['id'] ?? 0);
$currentRole = (string)($_POST['role'] ?? 'user');

if ($id <= 0) redirect('/admin/users/index.php');

// Prevent changing your own role (safety)
$me = current_user($pdo);
if ($me && (int)$me['id'] === $id) {
  redirect('/admin/users/index.php');
}

$newRole = ($currentRole === 'admin') ? 'user' : 'admin';

$stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
$stmt->execute([$newRole, $id]);

redirect('/admin/users/index.php');