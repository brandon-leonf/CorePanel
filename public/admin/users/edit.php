<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../../config/db.php';
require __DIR__ . '/../../../src/auth.php';
require __DIR__ . '/../../../src/helpers.php';
require __DIR__ . '/../../../src/layout.php';

require_admin($pdo);

start_session();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('Bad request'); }

$stmt = $pdo->prepare("SELECT id, name, email, role, phone, address, notes FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$user = $stmt->fetch();
if (!$user) { http_response_code(404); exit('User not found'); }

$errors = [];
$name = (string)$user['name'];
$email = (string)$user['email'];
$phone = (string)($user['phone'] ?? '');
$address = (string)($user['address'] ?? '');
$notes = (string)($user['notes'] ?? '');

if ($phone !== '') {
  $digits = preg_replace('/\D+/', '', $phone) ?? '';
  if (strlen($digits) === 10) {
    $phone = sprintf('(%s) %s %s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6, 4));
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token');
  }

  $name = trim((string)($_POST['name'] ?? ''));
  $email = trim((string)($_POST['email'] ?? ''));
  $phone = trim((string)($_POST['phone'] ?? ''));
  $address = trim((string)($_POST['address'] ?? ''));
  $notes = trim((string)($_POST['notes'] ?? ''));

  if ($phone !== '') {
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (strlen($digits) === 10) {
      $phone = sprintf('(%s) %s %s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6, 4));
    }
  }

  if ($name === '') $errors[] = 'Name is required.';
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email required.';

  // Enforce unique email (excluding this user)
  if (!$errors) {
    $check = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
    $check->execute([$email, $id]);
    if ($check->fetch()) {
      $errors[] = 'Email is already in use.';
    }
  }

  if (!$errors) {
    $up = $pdo->prepare("
      UPDATE users
      SET name = ?, email = ?, phone = ?, address = ?, notes = ?
      WHERE id = ?
    ");
    $up->execute([
      $name,
      $email,
      $phone === '' ? null : $phone,
      $address === '' ? null : $address,
      $notes === '' ? null : $notes,
      $id
    ]);

    redirect('/admin/users/index.php');
  }
}

render_header('Edit User • CorePanel');
?>
<div class="container">
  <h1>Edit User</h1>
  <p><a href="/admin/users/index.php">← Back</a></p>

  <?php if ($errors): ?>
    <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

    <label>Name<br>
      <input name="name" value="<?= e($name) ?>" required>
    </label>

    <label>Email<br>
      <input name="email" type="email" value="<?= e($email) ?>" required>
    </label>

    <label>Phone<br>
      <input
        id="phone"
        name="phone"
        value="<?= e($phone) ?>"
        inputmode="numeric"
        autocomplete="tel"
        placeholder="(000) 000 0000"
        maxlength="14"
      >
    </label>

    <label>Address<br>
      <input name="address" value="<?= e($address) ?>">
    </label>

    <label>Notes<br>
      <textarea name="notes" rows="5"><?= e($notes) ?></textarea>
    </label>

    <button type="submit">Save</button>
  </form>
</div>
<script>
  (function () {
    const phoneInput = document.getElementById('phone');
    if (!phoneInput) return;

    function formatPhone(value) {
      const digits = value.replace(/\D/g, '').slice(0, 10);
      if (digits.length === 0) return '';
      if (digits.length <= 3) return digits.length === 3 ? `(${digits}) ` : `(${digits}`;
      if (digits.length <= 6) return `(${digits.slice(0, 3)}) ${digits.slice(3)}`;
      return `(${digits.slice(0, 3)}) ${digits.slice(3, 6)} ${digits.slice(6)}`;
    }

    phoneInput.value = formatPhone(phoneInput.value);
    phoneInput.addEventListener('input', () => {
      phoneInput.value = formatPhone(phoneInput.value);
    });
  })();
</script>
<?php render_footer(); ?>
