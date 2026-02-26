#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${1:-http://localhost:8000}"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORK_DIR="$(mktemp -d)"
TEST_CLIENT_IP="${TEST_CLIENT_IP:-198.51.100.$(( (RANDOM % 200) + 20 ))}"

FIXTURE_USER_ID=""
FIXTURE_SUFFIX=""
RATE_TEST_EMAIL=""

CURL_IP_ARGS=()
if [[ -n "$TEST_CLIENT_IP" ]]; then
  CURL_IP_ARGS=(-H "X-Forwarded-For: $TEST_CLIENT_IP")
fi

cleanup_basic_fixture_user() {
  if [[ -z "${FIXTURE_USER_ID:-}" ]]; then
    return
  fi

  (cd "$ROOT_DIR" && FIXTURE_USER_ID="$FIXTURE_USER_ID" php <<'PHP'
<?php
declare(strict_types=1);

$id = (int)(getenv('FIXTURE_USER_ID') ?: '0');
if ($id <= 0) {
  exit(0);
}

ob_start();
$pdo = require __DIR__ . '/config/db.php';
$noise = ob_get_clean();
unset($noise);

$pdo->beginTransaction();
try {
  $pdo->prepare("DELETE FROM items WHERE user_id = ?")->execute([$id]);
  $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?")->execute([$id]);
  $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
}
PHP
)
}

cleanup_tenant_isolation_fixtures() {
  (cd "$ROOT_DIR" && php <<'PHP'
<?php
declare(strict_types=1);

ob_start();
$pdo = require __DIR__ . '/config/db.php';
$noise = ob_get_clean();
unset($noise);

$pdo->beginTransaction();
try {
  $tenantStmt = $pdo->prepare("SELECT id FROM tenants WHERE name LIKE 'Tenant Isolation %'");
  $tenantStmt->execute();
  $tenantIds = array_map('intval', $tenantStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
  if (!$tenantIds) {
    $pdo->commit();
    exit(0);
  }

  $tenantIn = implode(',', array_fill(0, count($tenantIds), '?'));
  $projectStmt = $pdo->prepare("SELECT id FROM projects WHERE tenant_id IN ($tenantIn)");
  $projectStmt->execute($tenantIds);
  $projectIds = array_map('intval', $projectStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

  $imagePaths = [];
  if ($projectIds) {
    $projectIn = implode(',', array_fill(0, count($projectIds), '?'));
    $imgPathStmt = $pdo->prepare("SELECT image_path FROM project_images WHERE project_id IN ($projectIn)");
    $imgPathStmt->execute($projectIds);
    $imagePaths = $imgPathStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $pdo->prepare("DELETE FROM project_images WHERE project_id IN ($projectIn)")->execute($projectIds);
    $pdo->prepare("DELETE FROM project_tasks WHERE project_id IN ($projectIn)")->execute($projectIds);
    $pdo->prepare("DELETE FROM projects WHERE id IN ($projectIn)")->execute($projectIds);
  }

  $userStmt = $pdo->prepare("SELECT id FROM users WHERE tenant_id IN ($tenantIn)");
  $userStmt->execute($tenantIds);
  $userIds = array_map('intval', $userStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
  if ($userIds) {
    $userIn = implode(',', array_fill(0, count($userIds), '?'));
    $pdo->prepare("DELETE FROM user_roles WHERE user_id IN ($userIn)")->execute($userIds);
    $pdo->prepare("DELETE FROM users WHERE id IN ($userIn)")->execute($userIds);
  }

  $pdo->prepare("DELETE FROM tenants WHERE id IN ($tenantIn)")->execute($tenantIds);
  $pdo->commit();

  foreach ($imagePaths as $ref) {
    $ref = (string)$ref;
    if (!str_starts_with($ref, 'private:')) {
      continue;
    }
    $key = substr($ref, 8);
    if (preg_match('/\A[a-f0-9]{32}\.(?:jpg|jpeg|png|webp)\z/i', $key) !== 1) {
      continue;
    }
    $path = __DIR__ . '/storage/uploads/images/' . strtolower($key);
    if (is_file($path)) {
      @unlink($path);
    }
  }
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
}
PHP
)
}

cleanup() {
  cleanup_basic_fixture_user || true
  cleanup_tenant_isolation_fixtures || true
  rm -rf "$WORK_DIR" || true
}
trap cleanup EXIT

extract_csrf() {
  tr '\n' ' ' | sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p'
}

json_get() {
  local key="$1"
  php -r '$d=json_decode(stream_get_contents(STDIN), true); if (!is_array($d) || !array_key_exists($argv[1], $d)) { exit(2); } $v=$d[$argv[1]]; if (is_bool($v)) { echo $v ? "true" : "false"; } else { echo (string)$v; }' "$key"
}

login_with_credentials() {
  local cookie_file="$1"
  local email="$2"
  local password="$3"

  local login_html csrf
  login_html="$(curl -sS "${CURL_IP_ARGS[@]}" -c "$cookie_file" -b "$cookie_file" "$BASE_URL/login.php")"
  csrf="$(printf '%s' "$login_html" | extract_csrf)"
  if [[ -z "$csrf" ]]; then
    echo "Could not parse login CSRF token for $email"
    return 1
  fi

  curl -sS "${CURL_IP_ARGS[@]}" -L -c "$cookie_file" -b "$cookie_file" \
    -X POST "$BASE_URL/login.php" \
    --data-urlencode "csrf_token=$csrf" \
    --data-urlencode "email=$email" \
    --data-urlencode "password=$password" >/dev/null
}

echo "[1/6] Preparing regression fixture user"
FIXTURE_JSON="$(cd "$ROOT_DIR" && php <<'PHP'
<?php
declare(strict_types=1);

ob_start();
$pdo = require __DIR__ . '/config/db.php';
require __DIR__ . '/src/auth.php';
$noise = ob_get_clean();
unset($noise);

ensure_access_control_schema($pdo);

$suffix = bin2hex(random_bytes(4));
$email = 'sec_reg_user_' . $suffix . '@example.test';
$password = 'SecReg!' . $suffix;

$stmt = $pdo->prepare(
  "INSERT INTO users (name, email, password_hash, role, tenant_id)
   VALUES (?, ?, ?, 'user', 1)"
);
$stmt->execute(['Security Regression User ' . $suffix, $email, password_hash($password, PASSWORD_BCRYPT)]);

echo json_encode([
  'user_id' => (int)$pdo->lastInsertId(),
  'suffix' => $suffix,
  'email' => $email,
  'password' => $password,
], JSON_UNESCAPED_SLASHES);
PHP
)"

if [[ -z "$FIXTURE_JSON" ]]; then
  echo "Fixture setup failed: empty output."
  exit 1
fi

FIXTURE_USER_ID="$(printf '%s' "$FIXTURE_JSON" | json_get user_id)"
FIXTURE_SUFFIX="$(printf '%s' "$FIXTURE_JSON" | json_get suffix)"
FIXTURE_EMAIL="$(printf '%s' "$FIXTURE_JSON" | json_get email)"
FIXTURE_PASSWORD="$(printf '%s' "$FIXTURE_JSON" | json_get password)"

USER_COOKIE="$WORK_DIR/user.cookie"
RBAC_BODY="$WORK_DIR/rbac_forbidden.html"
CSRF_BODY="$WORK_DIR/csrf_missing.html"
UPLOAD_BODY="$WORK_DIR/upload_invalid.html"
RATE_COOKIE="$WORK_DIR/rate.cookie"

echo "[2/6] CSRF missing token must fail"
login_with_credentials "$USER_COOKIE" "$FIXTURE_EMAIL" "$FIXTURE_PASSWORD"
CSRF_STATUS="$(curl -sS "${CURL_IP_ARGS[@]}" -o "$CSRF_BODY" -w '%{http_code}' -c "$USER_COOKIE" -b "$USER_COOKIE" \
  -X POST "$BASE_URL/items/create.php" \
  --data-urlencode "title=CSRF Regression Test" \
  --data-urlencode "description=Missing csrf token test")"
if [[ "$CSRF_STATUS" != "403" ]] || ! rg -n "Invalid CSRF token" "$CSRF_BODY" >/dev/null; then
  echo "FAIL: CSRF missing-token check did not fail as expected (status=$CSRF_STATUS)."
  exit 1
fi

echo "[3/6] RBAC forbidden action must be denied"
RBAC_STATUS="$(curl -sS "${CURL_IP_ARGS[@]}" -o "$RBAC_BODY" -w '%{http_code}' -c "$USER_COOKIE" -b "$USER_COOKIE" "$BASE_URL/admin/users/index.php")"
if [[ "$RBAC_STATUS" != "403" ]]; then
  echo "FAIL: RBAC check expected 403 for non-admin user on /admin/users/index.php (got $RBAC_STATUS)."
  exit 1
fi

echo "[4/6] IDOR project/file checks must fail (uses tenant isolation script)"
"$ROOT_DIR/scripts/security/test_tenant_isolation.sh" "$BASE_URL" "$TEST_CLIENT_IP" >/dev/null

echo "[5/6] Upload must reject bad MIME/magic bytes"
ITEMS_CREATE_HTML="$(curl -sS "${CURL_IP_ARGS[@]}" -c "$USER_COOKIE" -b "$USER_COOKIE" "$BASE_URL/items/create.php")"
ITEMS_CSRF="$(printf '%s' "$ITEMS_CREATE_HTML" | extract_csrf)"
if [[ -z "$ITEMS_CSRF" ]]; then
  echo "FAIL: Could not parse CSRF token for upload test."
  exit 1
fi

printf 'this is not a real image' > "$WORK_DIR/bad.png"
UPLOAD_STATUS="$(curl -sS "${CURL_IP_ARGS[@]}" -o "$UPLOAD_BODY" -w '%{http_code}' -c "$USER_COOKIE" -b "$USER_COOKIE" \
  -X POST "$BASE_URL/items/create.php" \
  -F "csrf_token=$ITEMS_CSRF" \
  -F "title=Bad Upload Regression" \
  -F "description=invalid mime and magic bytes test" \
  -F "image=@$WORK_DIR/bad.png;type=image/png")"

if [[ "$UPLOAD_STATUS" != "200" ]]; then
  echo "FAIL: Upload rejection test expected HTTP 200 with validation errors (got $UPLOAD_STATUS)."
  exit 1
fi
if ! rg -n "Invalid image content\\. Use JPG, PNG, or WebP\\.|Invalid image type|File extension does not match" "$UPLOAD_BODY" >/dev/null; then
  echo "FAIL: Upload rejection message not found; bad file may have been accepted unexpectedly."
  exit 1
fi

echo "[6/6] Rate limiting must trigger on repeated failed login"
RATE_TEST_EMAIL="rate_limit_probe_${FIXTURE_SUFFIX}@example.test"
RATE_LIMIT_TRIGGERED=0
for attempt in 1 2 3 4 5 6 7 8; do
  LOGIN_HTML="$(curl -sS "${CURL_IP_ARGS[@]}" -c "$RATE_COOKIE" -b "$RATE_COOKIE" "$BASE_URL/login.php")"
  LOGIN_CSRF="$(printf '%s' "$LOGIN_HTML" | extract_csrf)"
  if [[ -z "$LOGIN_CSRF" ]]; then
    echo "FAIL: Could not parse login CSRF token during rate-limit probe."
    exit 1
  fi

  RESP="$(curl -sS "${CURL_IP_ARGS[@]}" -c "$RATE_COOKIE" -b "$RATE_COOKIE" \
    -X POST "$BASE_URL/login.php" \
    --data-urlencode "csrf_token=$LOGIN_CSRF" \
    --data-urlencode "email=$RATE_TEST_EMAIL" \
    --data-urlencode "password=WrongPassword!${attempt}")"

  if printf '%s' "$RESP" | rg -n "Too many attempts\\. Try again in" >/dev/null; then
    RATE_LIMIT_TRIGGERED=1
    break
  fi
done
if [[ "$RATE_LIMIT_TRIGGERED" -ne 1 ]]; then
  echo "FAIL: Rate limiting lock message did not trigger after repeated failed logins."
  exit 1
fi

echo "PASS: Security regression checks passed."
echo "Verified: CSRF missing token, RBAC deny, rate limiting, IDOR project/file, upload MIME/magic validation."
