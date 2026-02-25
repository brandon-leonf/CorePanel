<?php
declare(strict_types=1);

function e(string $v): string {
  return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): never {
  header("Location: {$path}");
  exit;
}