<?php
declare(strict_types=1);

function normalize_single_line(string $value): string {
  $value = trim($value);
  $collapsed = preg_replace('/\s+/u', ' ', $value);
  return $collapsed === null ? $value : $collapsed;
}

function normalize_multiline(string $value): string {
  $normalized = str_replace("\r\n", "\n", $value);
  return trim($normalized);
}

function validate_required_text(string $value, string $field, int $maxLen, array &$errors): string {
  if ($value === '') {
    $errors[] = "{$field} is required.";
    return $value;
  }
  if (mb_strlen($value) > $maxLen) {
    $errors[] = "{$field} must be {$maxLen} characters or fewer.";
  }
  return $value;
}

function validate_optional_text(string $value, string $field, int $maxLen, array &$errors): string {
  if ($value !== '' && mb_strlen($value) > $maxLen) {
    $errors[] = "{$field} must be {$maxLen} characters or fewer.";
  }
  return $value;
}

function validate_email_input(string $value, array &$errors, string $field = 'Email', int $maxLen = 190): string {
  $value = strtolower(trim($value));

  if ($value === '') {
    $errors[] = "{$field} is required.";
    return $value;
  }
  if (strlen($value) > $maxLen) {
    $errors[] = "{$field} must be {$maxLen} characters or fewer.";
  }
  if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
    $errors[] = "Valid {$field} required.";
  }

  return $value;
}

function validate_phone_optional(string $value, array &$errors, string $field = 'Phone'): string {
  $value = trim($value);
  if ($value === '') {
    return '';
  }

  $digits = preg_replace('/\D+/', '', $value) ?? '';
  if (strlen($digits) !== 10) {
    $errors[] = "{$field} must contain exactly 10 digits.";
    return $value;
  }

  return sprintf('(%s) %s %s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6, 4));
}

function validate_decimal_input(
  mixed $value,
  string $field,
  float $min,
  float $max,
  array &$errors
): ?float {
  $raw = trim((string)$value);
  if ($raw === '') {
    $errors[] = "{$field} is required.";
    return null;
  }

  if (!preg_match('/^-?\d+(?:\.\d{1,2})?$/', $raw)) {
    $errors[] = "{$field} must be a valid number (up to 2 decimal places).";
    return null;
  }

  $num = (float)$raw;
  if ($num < $min || $num > $max) {
    $errors[] = "{$field} must be between {$min} and {$max}.";
    return null;
  }

  return $num;
}

function validate_password(string $value, array &$errors, int $minLen = 8, int $maxLen = 128): string {
  if (strlen($value) < $minLen) {
    $errors[] = "Password must be at least {$minLen} characters.";
  }
  if (strlen($value) > $maxLen) {
    $errors[] = "Password must be {$maxLen} characters or fewer.";
  }
  return $value;
}
