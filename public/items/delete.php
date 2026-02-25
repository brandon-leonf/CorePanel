<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../config/db.php';
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/helpers.php';

require_login();
$user = current_user($pdo);
$userId = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Method not allowed');
}

$id = (int)($_POST['id'] ?? 0);
if ($id > 0) {
  $del = $pdo->prepare("DELETE FROM items WHERE id = ? AND user_id = ?");
  $del->execute([$id, $userId]);
}

redirect('/items/index.php');