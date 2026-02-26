<?php
declare(strict_types=1);

require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/helpers.php';
start_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit;
}

if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
  http_response_code(403);
  exit('Invalid CSRF token');
}

destroy_current_session();

header('Location: /login.php');
exit;
