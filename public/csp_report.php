<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../config/db.php';
require __DIR__ . '/../src/helpers.php';
require __DIR__ . '/../src/rate_limit.php';

send_security_headers(false);

if (!csp_report_endpoint_enabled()) {
  http_response_code(404);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(204);
  exit;
}

$raw = file_get_contents('php://input');
if (!is_string($raw) || trim($raw) === '') {
  http_response_code(204);
  exit;
}

$decoded = json_decode($raw, true);
if (!is_array($decoded)) {
  http_response_code(204);
  exit;
}

$report = $decoded['csp-report'] ?? $decoded;
$isList = is_array($report) && (
  count($report) === 0 ||
  array_keys($report) === range(0, count($report) - 1)
);
if ($isList && isset($report[0]) && is_array($report[0])) {
  $report = $report[0];
}
if (!is_array($report)) {
  http_response_code(204);
  exit;
}

$documentUri = (string)($report['document-uri'] ?? $report['documentURL'] ?? '');
$blockedUri = (string)($report['blocked-uri'] ?? $report['blockedURL'] ?? '');
$violatedDirective = (string)($report['violated-directive'] ?? $report['effective-directive'] ?? 'unknown');

$subject = $documentUri !== '' ? $documentUri : null;
$keyLabel = $blockedUri !== '' ? $blockedUri : null;

$detailPayload = [
  'violated_directive' => $violatedDirective,
  'blocked_uri' => $blockedUri,
  'document_uri' => $documentUri,
  'source_file' => (string)($report['source-file'] ?? ''),
  'line_number' => (string)($report['line-number'] ?? ''),
  'column_number' => (string)($report['column-number'] ?? ''),
  'disposition' => (string)($report['disposition'] ?? 'report'),
];

$details = (string)json_encode($detailPayload, JSON_UNESCAPED_SLASHES);
rl_log_event($pdo, 'csp_report', 'csp', $subject, $keyLabel, $details, 'warning');

http_response_code(204);
