<?php
declare(strict_types=1);

function app_debug_enabled(): bool {
  $value = $_ENV['COREPANEL_DEBUG'] ?? getenv('COREPANEL_DEBUG');
  if ($value === false || $value === null) {
    $value = $_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG');
  }

  if ($value === false || $value === null) {
    return false;
  }

  $normalized = strtolower(trim((string)$value));
  if ($normalized === '') {
    return false;
  }

  return in_array($normalized, ['1', 'true', 'on', 'yes'], true);
}

function app_configure_error_handling(): void {
  static $configured = false;
  if ($configured) {
    return;
  }
  $configured = true;

  $debug = app_debug_enabled();

  error_reporting(E_ALL);
  ini_set('display_errors', $debug ? '1' : '0');
  ini_set('display_startup_errors', $debug ? '1' : '0');
  ini_set('log_errors', '1');

  $customLog = $_ENV['COREPANEL_ERROR_LOG'] ?? getenv('COREPANEL_ERROR_LOG');
  if (is_string($customLog) && trim($customLog) !== '') {
    ini_set('error_log', trim($customLog));
  }

  if ($debug) {
    return;
  }

  set_exception_handler(static function (Throwable $e): void {
    error_log(
      '[UNCAUGHT EXCEPTION] '
      . get_class($e)
      . ': ' . $e->getMessage()
      . ' in ' . $e->getFile()
      . ':' . $e->getLine()
    );

    if (!headers_sent()) {
      http_response_code(500);
      header('Content-Type: text/plain; charset=UTF-8');
    }

    echo 'An unexpected error occurred.';
  });

  register_shutdown_function(static function (): void {
    $error = error_get_last();
    if (!is_array($error)) {
      return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int)($error['type'] ?? 0), $fatalTypes, true)) {
      return;
    }

    error_log(
      '[FATAL ERROR] '
      . (string)($error['message'] ?? '')
      . ' in ' . (string)($error['file'] ?? 'unknown')
      . ':' . (string)($error['line'] ?? '0')
    );

    if (!headers_sent()) {
      http_response_code(500);
      header('Content-Type: text/plain; charset=UTF-8');
    }

    echo 'An unexpected error occurred.';
  });
}
