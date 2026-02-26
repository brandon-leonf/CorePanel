#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${1:-http://localhost:8000}"
TEST_CLIENT_IP="${2:-${TEST_CLIENT_IP:-}}"

for bin in curl php; do
  if ! command -v "$bin" >/dev/null 2>&1; then
    echo "Missing required command: $bin"
    exit 1
  fi
done

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

CURL_IP_ARGS=()
if [[ -n "$TEST_CLIENT_IP" ]]; then
  CURL_IP_ARGS=(-H "X-Forwarded-For: $TEST_CLIENT_IP")
fi

extract_csrf() {
  tr '\n' ' ' | sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p'
}

json_get() {
  local key="$1"
  php -r '$d=json_decode(stream_get_contents(STDIN), true); if (!is_array($d) || !array_key_exists($argv[1], $d)) { exit(2); } $v=$d[$argv[1]]; if (is_bool($v)) { echo $v ? "true" : "false"; } else { echo (string)$v; }' "$key"
}

echo "[1/4] Preparing multi-tenant fixtures"
FIXTURE_JSON="$(cd "$ROOT_DIR" && php <<'PHP'
<?php
declare(strict_types=1);

ob_start();
$pdo = require __DIR__ . '/config/db.php';
require __DIR__ . '/src/auth.php';
$noise = ob_get_clean();
unset($noise);

ensure_access_control_schema($pdo);

// Ensure task/image tables exist with tenant scope.
$pdo->exec(
  "CREATE TABLE IF NOT EXISTS project_tasks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,
    tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
    task_title VARCHAR(190) NOT NULL,
    task_description TEXT NULL,
    rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status ENUM('todo','in_progress','done') NOT NULL DEFAULT 'todo',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_project_tasks_project_id (project_id),
    INDEX idx_project_tasks_tenant_id (tenant_id)
  ) ENGINE=InnoDB"
);
$pdo->exec(
  "CREATE TABLE IF NOT EXISTS project_images (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,
    tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
    image_path VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_project_images_project_id (project_id),
    INDEX idx_project_images_tenant_id (tenant_id)
  ) ENGINE=InnoDB"
);

$suffix = bin2hex(random_bytes(4));
$tenantAName = 'Tenant Isolation A ' . $suffix;
$tenantBName = 'Tenant Isolation B ' . $suffix;
$userAPass = 'TiUserA!' . $suffix;
$adminAPass = 'TiAdminA!' . $suffix;
$userBPass = 'TiUserB!' . $suffix;
$userAEmail = 'ti_user_a_' . $suffix . '@example.test';
$adminAEmail = 'ti_admin_a_' . $suffix . '@example.test';
$userBEmail = 'ti_user_b_' . $suffix . '@example.test';
$projectATitle = 'Tenant Isolation Project A ' . $suffix;
$projectBTitle = 'Tenant Isolation Project B ' . $suffix;
$taskBTitle = 'Tenant Isolation Task B ' . $suffix;

$pngData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO6rR9sAAAAASUVORK5CYII=');
if (!is_string($pngData) || $pngData === '') {
  throw new RuntimeException('Failed to decode PNG fixture');
}
$uploadDir = __DIR__ . '/storage/uploads/images';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
  throw new RuntimeException('Failed to create upload fixture directory');
}

$pdo->beginTransaction();
try {
  $insTenant = $pdo->prepare("INSERT INTO tenants (name) VALUES (?)");
  $insTenant->execute([$tenantAName]);
  $tenantAId = (int)$pdo->lastInsertId();
  $insTenant->execute([$tenantBName]);
  $tenantBId = (int)$pdo->lastInsertId();

  $insUser = $pdo->prepare(
    "INSERT INTO users (name, email, password_hash, role, tenant_id)
     VALUES (?, ?, ?, ?, ?)"
  );
  $insUser->execute(['Tenant User A', $userAEmail, password_hash($userAPass, PASSWORD_BCRYPT), 'user', $tenantAId]);
  $userAId = (int)$pdo->lastInsertId();
  $insUser->execute(['Tenant Admin A', $adminAEmail, password_hash($adminAPass, PASSWORD_BCRYPT), 'admin', $tenantAId]);
  $adminAId = (int)$pdo->lastInsertId();
  $insUser->execute(['Tenant User B', $userBEmail, password_hash($userBPass, PASSWORD_BCRYPT), 'user', $tenantBId]);
  $userBId = (int)$pdo->lastInsertId();

  $insProject = $pdo->prepare(
    "INSERT INTO projects (project_no, user_id, tenant_id, title, description, status, created_by)
     VALUES (?, ?, ?, ?, ?, 'active', ?)"
  );
  $projectANo = 'TA' . substr($suffix, 0, 4) . 'A';
  $projectBNo = 'TB' . substr($suffix, 0, 4) . 'B';
  $insProject->execute([$projectANo, $userAId, $tenantAId, $projectATitle, 'Fixture A', $adminAId]);
  $projectAId = (int)$pdo->lastInsertId();
  $insProject->execute([$projectBNo, $userBId, $tenantBId, $projectBTitle, 'Fixture B', $userBId]);
  $projectBId = (int)$pdo->lastInsertId();

  $insTask = $pdo->prepare(
    "INSERT INTO project_tasks (project_id, tenant_id, task_title, task_description, rate, quantity, amount, status)
     VALUES (?, ?, ?, ?, 10.00, 1.00, 10.00, 'todo')"
  );
  $insTask->execute([$projectBId, $tenantBId, $taskBTitle, 'Fixture task']);
  $taskBId = (int)$pdo->lastInsertId();

  $fileAKey = bin2hex(random_bytes(16)) . '.png';
  $fileBKey = bin2hex(random_bytes(16)) . '.png';
  file_put_contents($uploadDir . '/' . $fileAKey, $pngData);
  file_put_contents($uploadDir . '/' . $fileBKey, $pngData);

  $insImage = $pdo->prepare(
    "INSERT INTO project_images (project_id, tenant_id, image_path)
     VALUES (?, ?, ?)"
  );
  $insImage->execute([$projectAId, $tenantAId, 'private:' . $fileAKey]);
  $insImage->execute([$projectBId, $tenantBId, 'private:' . $fileBKey]);

  $pdo->commit();

  echo json_encode([
    'suffix' => $suffix,
    'user_a_email' => $userAEmail,
    'user_a_password' => $userAPass,
    'admin_a_email' => $adminAEmail,
    'admin_a_password' => $adminAPass,
    'user_b_email' => $userBEmail,
    'project_a_id' => $projectAId,
    'project_b_id' => $projectBId,
    'task_b_id' => $taskBId,
    'file_a_key' => $fileAKey,
    'file_b_key' => $fileBKey,
    'project_a_title' => $projectATitle,
    'project_b_title' => $projectBTitle,
  ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  fwrite(STDERR, "Fixture setup failed: " . $e->getMessage() . PHP_EOL);
  exit(1);
}
PHP
)"

if [[ -z "$FIXTURE_JSON" ]]; then
  echo "Fixture setup returned empty output."
  exit 1
fi

USER_A_EMAIL="$(printf '%s' "$FIXTURE_JSON" | json_get user_a_email)"
USER_A_PASSWORD="$(printf '%s' "$FIXTURE_JSON" | json_get user_a_password)"
ADMIN_A_EMAIL="$(printf '%s' "$FIXTURE_JSON" | json_get admin_a_email)"
ADMIN_A_PASSWORD="$(printf '%s' "$FIXTURE_JSON" | json_get admin_a_password)"
PROJECT_A_ID="$(printf '%s' "$FIXTURE_JSON" | json_get project_a_id)"
PROJECT_B_ID="$(printf '%s' "$FIXTURE_JSON" | json_get project_b_id)"
TASK_B_ID="$(printf '%s' "$FIXTURE_JSON" | json_get task_b_id)"
FILE_A_KEY="$(printf '%s' "$FIXTURE_JSON" | json_get file_a_key)"
FILE_B_KEY="$(printf '%s' "$FIXTURE_JSON" | json_get file_b_key)"
PROJECT_A_TITLE="$(printf '%s' "$FIXTURE_JSON" | json_get project_a_title)"

USER_COOKIE="$WORK_DIR/user.cookie"
ADMIN_COOKIE="$WORK_DIR/admin.cookie"

echo "[2/4] Logging in as tenant user and checking URL tampering"
LOGIN_PAGE_HTML="$(curl -sS "${CURL_IP_ARGS[@]}" -c "$USER_COOKIE" -b "$USER_COOKIE" "$BASE_URL/login.php")"
USER_CSRF="$(printf '%s' "$LOGIN_PAGE_HTML" | extract_csrf)"
if [[ -z "$USER_CSRF" ]]; then
  echo "Failed to parse user login CSRF token."
  exit 1
fi
curl -sS "${CURL_IP_ARGS[@]}" -L -c "$USER_COOKIE" -b "$USER_COOKIE" \
  -X POST "$BASE_URL/login.php" \
  --data-urlencode "csrf_token=$USER_CSRF" \
  --data-urlencode "email=$USER_A_EMAIL" \
  --data-urlencode "password=$USER_A_PASSWORD" >/dev/null

USER_OWN_BODY="$WORK_DIR/user_own_project.html"
USER_OWN_STATUS="$(curl -sS "${CURL_IP_ARGS[@]}" -o "$USER_OWN_BODY" -w '%{http_code}' -c "$USER_COOKIE" -b "$USER_COOKIE" "$BASE_URL/client/projects/view.php?id=$PROJECT_A_ID")"
if [[ "$USER_OWN_STATUS" != "200" ]] || ! grep -Fq "$PROJECT_A_TITLE" "$USER_OWN_BODY"; then
  echo "FAIL: tenant user could not access own project (status=$USER_OWN_STATUS)."
  exit 1
fi

USER_CROSS_PROJECT_STATUS="$(curl -sS "${CURL_IP_ARGS[@]}" -o /dev/null -w '%{http_code}' -c "$USER_COOKIE" -b "$USER_COOKIE" "$BASE_URL/client/projects/view.php?id=$PROJECT_B_ID")"
if [[ "$USER_CROSS_PROJECT_STATUS" == "200" ]]; then
  echo "FAIL: tenant user accessed cross-tenant project by ID tampering."
  exit 1
fi

USER_OWN_FILE_STATUS="$(curl -sS "${CURL_IP_ARGS[@]}" -o /dev/null -w '%{http_code}' -c "$USER_COOKIE" -b "$USER_COOKIE" "$BASE_URL/file.php?f=$FILE_A_KEY")"
if [[ "$USER_OWN_FILE_STATUS" != "200" ]]; then
  echo "FAIL: tenant user could not access own file (status=$USER_OWN_FILE_STATUS)."
  exit 1
fi

USER_CROSS_FILE_STATUS="$(curl -sS "${CURL_IP_ARGS[@]}" -o /dev/null -w '%{http_code}' -c "$USER_COOKIE" -b "$USER_COOKIE" "$BASE_URL/file.php?f=$FILE_B_KEY")"
if [[ "$USER_CROSS_FILE_STATUS" == "200" ]]; then
  echo "FAIL: tenant user accessed cross-tenant file by key tampering."
  exit 1
fi

echo "[3/4] Logging in as tenant admin and checking body tampering"
ADMIN_LOGIN_HTML="$(curl -sS "${CURL_IP_ARGS[@]}" -c "$ADMIN_COOKIE" -b "$ADMIN_COOKIE" "$BASE_URL/login.php")"
ADMIN_LOGIN_CSRF="$(printf '%s' "$ADMIN_LOGIN_HTML" | extract_csrf)"
if [[ -z "$ADMIN_LOGIN_CSRF" ]]; then
  echo "Failed to parse admin login CSRF token."
  exit 1
fi
curl -sS "${CURL_IP_ARGS[@]}" -L -c "$ADMIN_COOKIE" -b "$ADMIN_COOKIE" \
  -X POST "$BASE_URL/login.php" \
  --data-urlencode "csrf_token=$ADMIN_LOGIN_CSRF" \
  --data-urlencode "email=$ADMIN_A_EMAIL" \
  --data-urlencode "password=$ADMIN_A_PASSWORD" >/dev/null

ADMIN_CROSS_EDIT_STATUS="$(curl -sS "${CURL_IP_ARGS[@]}" -o /dev/null -w '%{http_code}' -c "$ADMIN_COOKIE" -b "$ADMIN_COOKIE" "$BASE_URL/admin/projects/edit.php?id=$PROJECT_B_ID")"
if [[ "$ADMIN_CROSS_EDIT_STATUS" == "200" ]]; then
  echo "FAIL: tenant admin accessed cross-tenant project edit page by URL tampering."
  exit 1
fi

ADMIN_OWN_EDIT_HTML="$(curl -sS "${CURL_IP_ARGS[@]}" -c "$ADMIN_COOKIE" -b "$ADMIN_COOKIE" "$BASE_URL/admin/projects/edit.php?id=$PROJECT_A_ID")"
ADMIN_POST_CSRF="$(printf '%s' "$ADMIN_OWN_EDIT_HTML" | extract_csrf)"
if [[ -z "$ADMIN_POST_CSRF" ]]; then
  echo "Failed to parse admin CSRF token from project edit page."
  exit 1
fi

ADMIN_TAMPER_DELETE_STATUS="$(curl -sS "${CURL_IP_ARGS[@]}" -o /dev/null -w '%{http_code}' -c "$ADMIN_COOKIE" -b "$ADMIN_COOKIE" \
  -X POST "$BASE_URL/admin/projects/task_delete.php" \
  --data-urlencode "csrf_token=$ADMIN_POST_CSRF" \
  --data-urlencode "project_id=$PROJECT_B_ID" \
  --data-urlencode "task_id=$TASK_B_ID")"
if [[ "$ADMIN_TAMPER_DELETE_STATUS" == "200" || "$ADMIN_TAMPER_DELETE_STATUS" == "302" ]]; then
  echo "FAIL: tenant admin executed cross-tenant task delete by body tampering."
  exit 1
fi

echo "[4/4] Static scope check for project/file queries"
if rg -n \
  "FROM project_tasks WHERE project_id = \\?|FROM project_images WHERE project_id = \\?|UPDATE project_tasks\\s+SET .*WHERE id = \\? AND project_id = \\?|DELETE FROM project_images\\s+WHERE id = \\? AND project_id = \\?" \
  "$ROOT_DIR/public" >/dev/null; then
  echo "FAIL: Found legacy unscoped project task/image query pattern."
  exit 1
fi

echo "PASS: Multi-tenant isolation checks passed (URL tampering, body tampering, file scope, and query scope)."
echo "Fixture suffix: $(printf '%s' "$FIXTURE_JSON" | json_get suffix)"
echo "Note: this script inserts fixture records for traceability; clean up by suffix if needed."
