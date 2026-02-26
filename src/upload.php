<?php
declare(strict_types=1);

function upload_env_flag(string $name, bool $default = false): bool {
  $value = $_ENV[$name] ?? getenv($name);
  if ($value === false || $value === null) {
    return $default;
  }

  $normalized = strtolower(trim((string)$value));
  if ($normalized === '') {
    return $default;
  }

  return !in_array($normalized, ['0', 'false', 'off', 'no'], true);
}

function upload_max_image_bytes(): int {
  $mb = (int)($_ENV['COREPANEL_UPLOAD_MAX_IMAGE_MB'] ?? getenv('COREPANEL_UPLOAD_MAX_IMAGE_MB') ?: 5);
  $mb = max(1, min($mb, 20));
  return $mb * 1024 * 1024;
}

function upload_max_pdf_bytes(): int {
  $mb = (int)($_ENV['COREPANEL_UPLOAD_MAX_PDF_MB'] ?? getenv('COREPANEL_UPLOAD_MAX_PDF_MB') ?: 10);
  $mb = max(1, min($mb, 50));
  return $mb * 1024 * 1024;
}

function upload_ini_size_to_bytes(string $value): int {
  $raw = trim($value);
  if ($raw === '') {
    return 0;
  }

  if (!preg_match('/\A([0-9]+)([kmg])?\z/i', $raw, $matches)) {
    return 0;
  }

  $bytes = (int)$matches[1];
  $unit = strtolower((string)($matches[2] ?? ''));
  if ($unit === 'k') {
    $bytes *= 1024;
  } elseif ($unit === 'm') {
    $bytes *= 1024 * 1024;
  } elseif ($unit === 'g') {
    $bytes *= 1024 * 1024 * 1024;
  }

  return max(0, $bytes);
}

function upload_human_bytes(int $bytes): string {
  if ($bytes <= 0) {
    return '0 B';
  }

  $units = ['B', 'KB', 'MB', 'GB'];
  $value = (float)$bytes;
  $idx = 0;
  while ($value >= 1024.0 && $idx < count($units) - 1) {
    $value /= 1024.0;
    $idx++;
  }

  $precision = $value >= 10 || $idx === 0 ? 0 : 1;
  return number_format($value, $precision) . ' ' . $units[$idx];
}

function upload_effective_server_limit_bytes(): int {
  $uploadMax = upload_ini_size_to_bytes((string)ini_get('upload_max_filesize'));
  $postMax = upload_ini_size_to_bytes((string)ini_get('post_max_size'));
  if ($uploadMax <= 0) {
    return $postMax;
  }
  if ($postMax <= 0) {
    return $uploadMax;
  }
  return min($uploadMax, $postMax);
}

function upload_error_message(int $errorCode, string $typeLabel = 'file'): string {
  $typeLabel = trim($typeLabel);
  if ($typeLabel === '') {
    $typeLabel = 'file';
  }

  if ($errorCode === UPLOAD_ERR_INI_SIZE || $errorCode === UPLOAD_ERR_FORM_SIZE) {
    $effectiveLimit = upload_effective_server_limit_bytes();
    if ($effectiveLimit > 0) {
      return ucfirst($typeLabel) . ' is too large for server upload limits (max '
        . upload_human_bytes($effectiveLimit) . ').';
    }
    return ucfirst($typeLabel) . ' is too large for server upload limits.';
  }
  if ($errorCode === UPLOAD_ERR_PARTIAL) {
    return ucfirst($typeLabel) . ' upload was interrupted. Please try again.';
  }
  if ($errorCode === UPLOAD_ERR_NO_FILE) {
    return 'No ' . $typeLabel . ' was uploaded.';
  }
  if ($errorCode === UPLOAD_ERR_NO_TMP_DIR) {
    return 'Upload failed: server temporary directory is missing.';
  }
  if ($errorCode === UPLOAD_ERR_CANT_WRITE) {
    return 'Upload failed: server could not write uploaded data.';
  }
  if ($errorCode === UPLOAD_ERR_EXTENSION) {
    return 'Upload blocked by a server extension.';
  }

  return 'Upload failed (error code ' . $errorCode . ').';
}

function upload_allowed_image_mimes(): array {
  return [
    'image/jpeg' => ['jpg', 'jpeg'],
    'image/png' => ['png'],
    'image/webp' => ['webp'],
  ];
}

function upload_private_storage_dir(): string {
  $configured = trim((string)($_ENV['COREPANEL_UPLOAD_DIR'] ?? getenv('COREPANEL_UPLOAD_DIR') ?: ''));
  if ($configured !== '') {
    if (str_starts_with($configured, '/')) {
      return rtrim($configured, '/');
    }
    return rtrim(__DIR__ . '/../' . ltrim($configured, '/'), '/');
  }

  return __DIR__ . '/../storage/uploads/images';
}

function upload_ensure_private_storage_dir(): bool {
  $dir = upload_private_storage_dir();
  if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
    return false;
  }

  if (!is_writable($dir)) {
    return false;
  }

  return true;
}

function upload_normalize_mime(string $mime): string {
  $mime = strtolower(trim($mime));
  return match ($mime) {
    'image/jpg', 'image/pjpeg' => 'image/jpeg',
    'image/x-png' => 'image/png',
    default => $mime,
  };
}

function upload_extension_from_filename(string $filename): string {
  return strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
}

function upload_safe_filename_key(string $filename): bool {
  return preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,189}\.(jpg|jpeg|png|webp|pdf)\z/i', $filename) === 1;
}

function upload_magic_mime(string $filePath): ?string {
  $bytes = @file_get_contents($filePath, false, null, 0, 12);
  if (!is_string($bytes) || strlen($bytes) < 12) {
    return null;
  }

  if (strncmp($bytes, "\xFF\xD8\xFF", 3) === 0) {
    return 'image/jpeg';
  }

  if (strncmp($bytes, "\x89PNG\x0D\x0A\x1A\x0A", 8) === 0) {
    return 'image/png';
  }

  if (strncmp($bytes, 'RIFF', 4) === 0 && substr($bytes, 8, 4) === 'WEBP') {
    return 'image/webp';
  }

  return null;
}

function upload_imagetype_mime(string $filePath): ?string {
  if (!function_exists('exif_imagetype')) {
    return null;
  }

  $type = @exif_imagetype($filePath);
  return match ($type) {
    IMAGETYPE_JPEG => 'image/jpeg',
    IMAGETYPE_PNG => 'image/png',
    IMAGETYPE_WEBP => 'image/webp',
    default => null,
  };
}

function upload_detect_valid_image_mime(string $filePath): ?string {
  $magicMime = upload_magic_mime($filePath);
  if ($magicMime === null) {
    return null;
  }

  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $finfoMime = upload_normalize_mime((string)$finfo->file($filePath));
  if ($finfoMime !== $magicMime) {
    return null;
  }

  $typeMime = upload_imagetype_mime($filePath);
  if ($typeMime !== null && $typeMime !== $magicMime) {
    return null;
  }

  return $magicMime;
}

function upload_random_filename(string $ext): string {
  return bin2hex(random_bytes(16)) . '.' . $ext;
}

function upload_private_reference_from_filename(string $filename): string {
  return 'private:' . $filename;
}

function upload_private_filename_from_reference(string $reference): ?string {
  $reference = trim($reference);
  if (!str_starts_with($reference, 'private:')) {
    return null;
  }

  $filename = substr($reference, 8);
  if (!preg_match('/\A[a-f0-9]{32}\.(jpg|jpeg|png|webp|pdf)\z/i', $filename)) {
    return null;
  }

  return strtolower($filename);
}

function upload_private_absolute_path_from_reference(string $reference): ?string {
  $filename = upload_private_filename_from_reference($reference);
  if ($filename === null) {
    return null;
  }

  return upload_private_storage_dir() . '/' . $filename;
}

function upload_legacy_public_absolute_path(string $reference): ?string {
  $reference = trim($reference);
  if (preg_match('#\Ahttps?://#i', $reference) === 1) {
    $reference = (string)(parse_url($reference, PHP_URL_PATH) ?? '');
  }

  if (str_starts_with($reference, 'uploads/')) {
    $reference = '/' . $reference;
  }

  if (!str_starts_with($reference, '/uploads/')) {
    return null;
  }

  $filename = substr($reference, strlen('/uploads/'));
  $filename = rawurldecode(str_replace('\\', '/', $filename));
  if (str_contains($filename, '/')) {
    $filename = basename($filename);
  }
  if (!upload_safe_filename_key($filename)) {
    return null;
  }

  return __DIR__ . '/../public/uploads/' . $filename;
}

function upload_reference_filename(string $reference): ?string {
  $private = upload_private_filename_from_reference($reference);
  if ($private !== null) {
    return $private;
  }

  $reference = trim($reference);
  if (preg_match('#\Ahttps?://#i', $reference) === 1) {
    $reference = (string)(parse_url($reference, PHP_URL_PATH) ?? '');
  }

  if (str_starts_with($reference, 'uploads/')) {
    $reference = '/' . $reference;
  }
  if (!str_starts_with($reference, '/uploads/')) {
    return null;
  }
  $filename = substr($reference, strlen('/uploads/'));
  $filename = rawurldecode(str_replace('\\', '/', $filename));
  if (str_contains($filename, '/')) {
    $filename = basename($filename);
  }
  if (!upload_safe_filename_key($filename)) {
    return null;
  }

  return $filename;
}

function upload_reference_extension(string $reference): string {
  $filename = upload_reference_filename($reference);
  if ($filename === null) {
    return '';
  }
  return strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
}

function upload_reference_is_image(string $reference): bool {
  return in_array(upload_reference_extension($reference), ['jpg', 'jpeg', 'png', 'webp'], true);
}

function upload_reference_is_pdf(string $reference): bool {
  return upload_reference_extension($reference) === 'pdf';
}

function upload_authorized_extension_for_mime(string $mime): string {
  return match ($mime) {
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    default => '',
  };
}

function upload_apply_jpeg_orientation($imageResource, string $sourcePath) {
  if (!function_exists('exif_read_data')) {
    return $imageResource;
  }

  $exif = @exif_read_data($sourcePath);
  $orientation = (int)($exif['Orientation'] ?? 1);
  if ($orientation <= 1) {
    return $imageResource;
  }

  $rotated = $imageResource;
  if ($orientation === 3) {
    $rotated = imagerotate($imageResource, 180, 0);
  } elseif ($orientation === 6) {
    $rotated = imagerotate($imageResource, -90, 0);
  } elseif ($orientation === 8) {
    $rotated = imagerotate($imageResource, 90, 0);
  }

  if ($rotated !== false && $rotated !== $imageResource) {
    imagedestroy($imageResource);
    return $rotated;
  }

  return $imageResource;
}

function upload_reencode_image_without_metadata(string $sourcePath, string $mime, string $destPath): bool {
  if (!extension_loaded('gd')) {
    return false;
  }

  $image = null;
  if ($mime === 'image/jpeg' && function_exists('imagecreatefromjpeg')) {
    $image = @imagecreatefromjpeg($sourcePath);
    if ($image !== false && $image !== null) {
      $image = upload_apply_jpeg_orientation($image, $sourcePath);
    }
  } elseif ($mime === 'image/png' && function_exists('imagecreatefrompng')) {
    $image = @imagecreatefrompng($sourcePath);
  } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
    $image = @imagecreatefromwebp($sourcePath);
  }

  if ($image === false || $image === null) {
    return false;
  }

  $ok = false;
  if ($mime === 'image/jpeg' && function_exists('imagejpeg')) {
    $ok = imagejpeg($image, $destPath, 86);
  } elseif ($mime === 'image/png' && function_exists('imagepng')) {
    imagealphablending($image, false);
    imagesavealpha($image, true);
    $ok = imagepng($image, $destPath, 6);
  } elseif ($mime === 'image/webp' && function_exists('imagewebp')) {
    $ok = imagewebp($image, $destPath, 84);
  }

  imagedestroy($image);
  return $ok;
}

function upload_find_clamscan_binary(): ?string {
  foreach (['/opt/homebrew/bin/clamscan', '/usr/local/bin/clamscan', '/usr/bin/clamscan'] as $path) {
    if (is_file($path) && is_executable($path)) {
      return $path;
    }
  }

  if (function_exists('exec')) {
    $out = [];
    $code = 0;
    @exec('command -v clamscan 2>/dev/null', $out, $code);
    if ($code === 0 && !empty($out[0])) {
      $bin = trim((string)$out[0]);
      if ($bin !== '') {
        return $bin;
      }
    }
  }

  return null;
}

function upload_optional_virus_scan_error(string $filePath): ?string {
  if (!upload_env_flag('COREPANEL_UPLOAD_VIRUS_SCAN', false)) {
    return null;
  }

  if (!function_exists('exec')) {
    return 'Virus scanner unavailable on this server.';
  }

  $binary = upload_find_clamscan_binary();
  if ($binary === null) {
    return 'Virus scanner unavailable on this server.';
  }

  $output = [];
  $exitCode = 0;
  @exec(escapeshellarg($binary) . ' --no-summary --infected ' . escapeshellarg($filePath) . ' 2>&1', $output, $exitCode);

  if ($exitCode === 0) {
    return null;
  }
  if ($exitCode === 1) {
    return 'Malware detected in uploaded file.';
  }

  return 'Virus scan failed.';
}

function upload_detect_valid_pdf_mime(string $filePath): ?string {
  $bytes = @file_get_contents($filePath, false, null, 0, 5);
  if (!is_string($bytes) || $bytes !== '%PDF-') {
    return null;
  }

  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $finfoMime = upload_normalize_mime((string)$finfo->file($filePath));
  $allowed = ['application/pdf', 'application/x-pdf'];
  if (!in_array($finfoMime, $allowed, true)) {
    return null;
  }

  return 'application/pdf';
}

function upload_item_image(array $file): array {
  if (!isset($file['error']) || is_array($file['error'])) {
    return [null, 'Invalid upload.'];
  }

  $errorCode = (int)$file['error'];
  if ($errorCode !== UPLOAD_ERR_OK) {
    return [null, upload_error_message($errorCode, 'image')];
  }

  $tmpName = (string)($file['tmp_name'] ?? '');
  if ($tmpName === '' || !is_uploaded_file($tmpName)) {
    return [null, 'Invalid uploaded file source.'];
  }

  $size = (int)($file['size'] ?? 0);
  if ($size <= 0) {
    return [null, 'Uploaded file is empty.'];
  }

  $maxBytes = upload_max_image_bytes();
  if ($size > $maxBytes) {
    $maxMb = (int)floor($maxBytes / (1024 * 1024));
    return [null, "Image too large (max {$maxMb}MB)."];
  }

  $mime = upload_detect_valid_image_mime($tmpName);
  if ($mime === null) {
    return [null, 'Invalid image content. Use JPG, PNG, or WebP.'];
  }

  $allowed = upload_allowed_image_mimes();
  if (!isset($allowed[$mime])) {
    return [null, 'Invalid image type. Use JPG, PNG, or WebP.'];
  }

  $inputExt = upload_extension_from_filename((string)($file['name'] ?? ''));
  if ($inputExt === '' || !in_array($inputExt, $allowed[$mime], true)) {
    return [null, 'File extension does not match the uploaded image type.'];
  }

  if (!upload_ensure_private_storage_dir()) {
    return [null, 'Upload storage is not available.'];
  }

  $storageDir = upload_private_storage_dir();
  $tmpStoredPath = $storageDir . '/tmp-' . bin2hex(random_bytes(12));
  if (!move_uploaded_file($tmpName, $tmpStoredPath)) {
    return [null, 'Could not save uploaded file.'];
  }

  $scanErr = upload_optional_virus_scan_error($tmpStoredPath);
  if ($scanErr !== null) {
    @unlink($tmpStoredPath);
    return [null, $scanErr];
  }

  $finalExt = upload_authorized_extension_for_mime($mime);
  if ($finalExt === '') {
    @unlink($tmpStoredPath);
    return [null, 'Invalid image type.'];
  }

  $finalName = upload_random_filename($finalExt);
  $finalPath = $storageDir . '/' . $finalName;

  $reencoded = upload_reencode_image_without_metadata($tmpStoredPath, $mime, $finalPath);
  @unlink($tmpStoredPath);

  if (!$reencoded || !is_file($finalPath)) {
    @unlink($finalPath);
    return [null, 'Could not process uploaded image.'];
  }

  @chmod($finalPath, 0640);

  return [upload_private_reference_from_filename($finalName), null];
}

function upload_project_pdf(array $file): array {
  if (!isset($file['error']) || is_array($file['error'])) {
    return [null, 'Invalid upload.'];
  }

  $errorCode = (int)$file['error'];
  if ($errorCode !== UPLOAD_ERR_OK) {
    return [null, upload_error_message($errorCode, 'PDF')];
  }

  $tmpName = (string)($file['tmp_name'] ?? '');
  if ($tmpName === '' || !is_uploaded_file($tmpName)) {
    return [null, 'Invalid uploaded file source.'];
  }

  $size = (int)($file['size'] ?? 0);
  if ($size <= 0) {
    return [null, 'Uploaded file is empty.'];
  }

  $maxBytes = upload_max_pdf_bytes();
  if ($size > $maxBytes) {
    $maxMb = (int)floor($maxBytes / (1024 * 1024));
    return [null, "PDF too large (max {$maxMb}MB)."];
  }

  $inputExt = upload_extension_from_filename((string)($file['name'] ?? ''));
  if ($inputExt !== 'pdf') {
    return [null, 'Only PDF files are allowed.'];
  }

  if (upload_detect_valid_pdf_mime($tmpName) === null) {
    return [null, 'Invalid PDF content.'];
  }

  if (!upload_ensure_private_storage_dir()) {
    return [null, 'Upload storage is not available.'];
  }

  $storageDir = upload_private_storage_dir();
  $tmpStoredPath = $storageDir . '/tmp-' . bin2hex(random_bytes(12));
  if (!move_uploaded_file($tmpName, $tmpStoredPath)) {
    return [null, 'Could not save uploaded file.'];
  }

  $scanErr = upload_optional_virus_scan_error($tmpStoredPath);
  if ($scanErr !== null) {
    @unlink($tmpStoredPath);
    return [null, $scanErr];
  }

  $finalName = upload_random_filename('pdf');
  $finalPath = $storageDir . '/' . $finalName;
  if (!rename($tmpStoredPath, $finalPath)) {
    @unlink($tmpStoredPath);
    return [null, 'Could not finalize uploaded PDF.'];
  }

  @chmod($finalPath, 0640);
  return [upload_private_reference_from_filename($finalName), null];
}

function upload_reference_usage_count(PDO $pdo, string $reference): int {
  $total = 0;

  $stmt = $pdo->prepare('SELECT COUNT(*) FROM items WHERE image_path = ?');
  $stmt->execute([$reference]);
  $total += (int)$stmt->fetchColumn();

  try {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM project_images WHERE image_path = ?');
    $stmt->execute([$reference]);
    $total += (int)$stmt->fetchColumn();
  } catch (Throwable $e) {
    // project_images may not exist on all deployments
  }

  return $total;
}

function upload_delete_reference_if_unreferenced(PDO $pdo, ?string $reference): void {
  $reference = trim((string)$reference);
  if ($reference === '') {
    return;
  }

  if (upload_reference_usage_count($pdo, $reference) > 0) {
    return;
  }

  $privatePath = upload_private_absolute_path_from_reference($reference);
  if ($privatePath !== null) {
    if (is_file($privatePath)) {
      @unlink($privatePath);
    }
    return;
  }

  $legacyPath = upload_legacy_public_absolute_path($reference);
  if ($legacyPath !== null && is_file($legacyPath)) {
    @unlink($legacyPath);
  }
}
