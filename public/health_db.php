<?php
declare(strict_types=1);

require __DIR__ . '/../src/helpers.php';
require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/security.php';
require __DIR__ . '/../src/health.php';

$method = health_request_method();
if (!in_array($method, ['GET', 'HEAD'], true)) {
  header('Allow: GET, HEAD');
  health_send_json(405, ['status' => 'fail', 'error' => 'Method not allowed']);
}

$dbConnectionError = null;
$pdo = health_connect_db($dbConnectionError);
$auth = health_authorize($pdo);
if (!($auth['ok'] ?? false)) {
  health_send_json((int)($auth['code'] ?? 403), [
    'status' => 'fail',
    'error' => (string)($auth['message'] ?? 'Forbidden'),
  ]);
}

$dbCheck = health_check_db($pdo, $dbConnectionError);
$status = ($dbCheck['ok'] ?? false) ? 'ok' : 'fail';
$httpStatus = $status === 'ok' ? 200 : 503;
health_send_json($httpStatus, [
  'status' => $status,
  'timestamp' => gmdate('c'),
  'authorized_via' => (string)($auth['mode'] ?? 'unknown'),
  'checks' => [
    'db' => $dbCheck,
  ],
]);

