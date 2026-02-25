<?php
declare(strict_types=1);

function upload_item_image(array $file): array {
  if (!isset($file['error']) || is_array($file['error'])) {
    return [null, "Invalid upload."];
  }

  if ($file['error'] !== UPLOAD_ERR_OK) {
    return [null, "Upload failed (error code {$file['error']})."];
  }

  // 3MB limit
  if ($file['size'] > 3 * 1024 * 1024) {
    return [null, "Image too large (max 3MB)."];
  }

  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime = $finfo->file($file['tmp_name']);

  $allowed = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
  ];

  if (!isset($allowed[$mime])) {
    return [null, "Invalid image type. Use JPG, PNG, or WebP."];
  }

  $ext = $allowed[$mime];
  $name = bin2hex(random_bytes(16)) . '.' . $ext;

  $uploadDir = __DIR__ . '/../public/uploads';
  if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
  }

  $dest = $uploadDir . '/' . $name;

  if (!move_uploaded_file($file['tmp_name'], $dest)) {
    return [null, "Could not save uploaded file."];
  }

  // Return web path to store in DB
  return ["/uploads/{$name}", null];
}