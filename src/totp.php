<?php
declare(strict_types=1);

function totp_base32_encode(string $binary): string {
  if ($binary === '') {
    return '';
  }

  $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  $bits = '';
  $length = strlen($binary);

  for ($i = 0; $i < $length; $i++) {
    $bits .= str_pad(decbin(ord($binary[$i])), 8, '0', STR_PAD_LEFT);
  }

  $padding = strlen($bits) % 5;
  if ($padding > 0) {
    $bits .= str_repeat('0', 5 - $padding);
  }

  $output = '';
  $bitLength = strlen($bits);
  for ($i = 0; $i < $bitLength; $i += 5) {
    $chunk = substr($bits, $i, 5);
    $output .= $alphabet[bindec($chunk)];
  }

  return $output;
}

function totp_base32_decode(string $encoded): ?string {
  $clean = strtoupper(preg_replace('/[^A-Z2-7]/', '', $encoded) ?? '');
  if ($clean === '') {
    return null;
  }

  $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  $map = [];
  for ($i = 0; $i < strlen($alphabet); $i++) {
    $map[$alphabet[$i]] = $i;
  }

  $bits = '';
  $length = strlen($clean);
  for ($i = 0; $i < $length; $i++) {
    $char = $clean[$i];
    if (!isset($map[$char])) {
      return null;
    }
    $bits .= str_pad(decbin($map[$char]), 5, '0', STR_PAD_LEFT);
  }

  $bytes = '';
  $bitLength = strlen($bits);
  for ($i = 0; $i + 8 <= $bitLength; $i += 8) {
    $chunk = substr($bits, $i, 8);
    $bytes .= chr(bindec($chunk));
  }

  return $bytes === '' ? null : $bytes;
}

function totp_generate_secret(int $bytes = 20): string {
  $bytes = max(10, min($bytes, 64));
  return totp_base32_encode(random_bytes($bytes));
}

function totp_hotp_code(string $secret, int $counter, int $digits = 6): ?string {
  $key = totp_base32_decode($secret);
  if ($key === null) {
    return null;
  }

  $counterBytes = pack('N*', 0) . pack('N*', $counter);
  $hash = hash_hmac('sha1', $counterBytes, $key, true);
  $offset = ord(substr($hash, -1)) & 0x0F;
  $part = substr($hash, $offset, 4);
  if ($part === false || strlen($part) !== 4) {
    return null;
  }

  $value = unpack('N', $part)[1] & 0x7FFFFFFF;
  $mod = 10 ** $digits;
  $otp = (string)($value % $mod);

  return str_pad($otp, $digits, '0', STR_PAD_LEFT);
}

function totp_code_at(string $secret, int $timestamp, int $period = 30, int $digits = 6): ?string {
  $counter = (int)floor($timestamp / $period);
  return totp_hotp_code($secret, $counter, $digits);
}

function totp_verify_code(string $secret, string $code, int $window = 1, int $period = 30, int $digits = 6): bool {
  $normalized = preg_replace('/\D+/', '', $code) ?? '';
  if (strlen($normalized) !== $digits) {
    return false;
  }

  $now = time();
  for ($offset = -$window; $offset <= $window; $offset++) {
    $candidate = totp_code_at($secret, $now + ($offset * $period), $period, $digits);
    if ($candidate !== null && hash_equals($candidate, $normalized)) {
      return true;
    }
  }

  return false;
}

function totp_build_uri(string $issuer, string $accountLabel, string $secret): string {
  $issuerEnc = rawurlencode($issuer);
  $labelEnc = rawurlencode($issuer . ':' . $accountLabel);

  return "otpauth://totp/{$labelEnc}?secret={$secret}&issuer={$issuerEnc}&algorithm=SHA1&digits=6&period=30";
}

function totp_display_secret(string $secret): string {
  $clean = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret) ?? '');
  return trim(chunk_split($clean, 4, ' '));
}
