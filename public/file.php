<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../config/db.php';
require __DIR__ . '/../src/helpers.php';
require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/upload.php';
require __DIR__ . '/../src/rate_limit.php';

send_security_headers(false);
require_login();

$me = current_user($pdo);
if (!$me) {
  http_response_code(403);
  exit('Forbidden');
}

$key = trim((string)($_GET['f'] ?? ''));
if (!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,189}\.(jpg|jpeg|png|webp|pdf)\z/i', $key)) {
  http_response_code(404);
  exit('Not found');
}
$download = ((string)($_GET['download'] ?? '') === '1');

$keyLower = strtolower($key);
$candidateReferences = [
  'private:' . $key,
  'private:' . $keyLower,
  '/uploads/' . $key,
  '/uploads/' . $keyLower,
];
$userId = (int)($me['id'] ?? 0);
$tenantId = actor_tenant_id($me);
$canViewTenantFiles = user_has_permission($me, 'projects.view.any') || user_has_permission($me, 'users.view');

$authorized = false;

if ($canViewTenantFiles) {
  $itemSql = 'SELECT id FROM items WHERE tenant_id = ? AND (image_path = ? OR image_path = ? OR image_path = ? OR image_path = ?) LIMIT 1';
  $stmt = $pdo->prepare(
    $itemSql
  );
  $stmt->execute([$tenantId, ...$candidateReferences]);
  $authorized = (bool)$stmt->fetch();

  if (!$authorized) {
    try {
      $projSql = 'SELECT pi.id
        FROM project_images pi
        JOIN projects p ON p.id = pi.project_id
        WHERE pi.tenant_id = ?
          AND p.tenant_id = ?
          AND (pi.image_path = ? OR pi.image_path = ? OR pi.image_path = ? OR pi.image_path = ?)
        LIMIT 1';
      $stmt = $pdo->prepare(
        $projSql
      );
      $stmt->execute([$tenantId, $tenantId, ...$candidateReferences]);
      $authorized = (bool)$stmt->fetch();
    } catch (Throwable $e) {
      $authorized = false;
    }
  }
} else {
  $itemSql = 'SELECT id
    FROM items
    WHERE user_id = ?
      AND tenant_id = ?
      AND (image_path = ? OR image_path = ? OR image_path = ? OR image_path = ?)
    LIMIT 1';
  $stmt = $pdo->prepare(
    $itemSql
  );
  $stmt->execute([$userId, $tenantId, ...$candidateReferences]);
  $authorized = (bool)$stmt->fetch();

  if (!$authorized) {
    try {
      $projSql = 'SELECT pi.id
        FROM project_images pi
        JOIN projects p ON p.id = pi.project_id
        WHERE pi.tenant_id = ?
          AND p.user_id = ?
          AND p.tenant_id = ?
          AND (pi.image_path = ? OR pi.image_path = ? OR pi.image_path = ? OR pi.image_path = ?)
        LIMIT 1';
      $stmt = $pdo->prepare(
        $projSql
      );
      $stmt->execute([$tenantId, $userId, $tenantId, ...$candidateReferences]);
      $authorized = (bool)$stmt->fetch();
    } catch (Throwable $e) {
      $authorized = false;
    }
  }
}

if (!$authorized) {
  http_response_code(403);
  exit('Forbidden');
}

$path = null;
foreach ($candidateReferences as $reference) {
  $candidatePath = upload_private_absolute_path_from_reference($reference);
  if ($candidatePath !== null && is_file($candidatePath)) {
    $path = $candidatePath;
    break;
  }
}
if ($path === null) {
  foreach ($candidateReferences as $reference) {
    $candidatePath = upload_legacy_public_absolute_path($reference);
    if ($candidatePath !== null && is_file($candidatePath)) {
      $path = $candidatePath;
      break;
    }
  }
}
if ($path === null || !is_file($path)) {
  http_response_code(404);
  exit('Not found');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = strtolower((string)$finfo->file($path));
$allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf', 'application/x-pdf'];
if (!in_array($mime, $allowedMimes, true)) {
  http_response_code(404);
  exit('Not found');
}

rl_record_download_activity($pdo, $me, 'private_file_download', 'file:' . $keyLower);

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
$disposition = $download ? 'attachment' : 'inline';
header('Content-Disposition: ' . $disposition . '; filename="' . basename($path) . '"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Download-Options: noopen');

$fp = fopen($path, 'rb');
if ($fp === false) {
  http_response_code(404);
  exit('Not found');
}

fpassthru($fp);
fclose($fp);
exit;
