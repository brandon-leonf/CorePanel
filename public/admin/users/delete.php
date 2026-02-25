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
if ($id <= 0) redirect('/admin/users/index.php');

// Prevent deleting yourself (safety)
$me = current_user($pdo);
if ($me && (int)$me['id'] === $id) {
  redirect('/admin/users/index.php');
}

// If you have foreign key ON DELETE CASCADE from items.user_id -> users.id,
// deleting user will delete their items automatically.
// If not, delete items first:
$pdo->prepare("DELETE FROM items WHERE user_id = ?")->execute([$id]);

$pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);

redirect('/admin/users/index.php');