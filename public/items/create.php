<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../config/db.php';
require __DIR__ . '/../../src/helpers.php';
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/layout.php';
require __DIR__ . '/../../src/upload.php';
require __DIR__ . '/../../src/validation.php';

require_login();
$user = current_user($pdo);
$userId = (int)$user['id'];
$tenantId = actor_tenant_id($user);

$errors = [];
$title = '';
$description = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token');
  }

  $title = normalize_single_line((string)($_POST['title'] ?? ''));
  $description = normalize_multiline((string)($_POST['description'] ?? ''));

  validate_required_text($title, 'Title', 160, $errors);
  validate_optional_text($description, 'Description', 10000, $errors);

  $imagePath = null;

  if (!empty($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
    [$imagePath, $upErr] = upload_item_image($_FILES['image']);
    if ($upErr) $errors[] = $upErr;
  }

  if (!$errors) {
    $stmt = $pdo->prepare("
      INSERT INTO items (user_id, tenant_id, title, description, image_path)
      VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([
      $userId,
      $tenantId,
      $title,
      $description === '' ? null : $description,
      $imagePath
    ]);

    redirect('/items/index.php');
  }
}

render_header('New Item • CorePanel');
?>
<div class="container">
  <h1>New Item</h1>
  <p><a href="/items/index.php">← Back</a></p>

  <?php if ($errors): ?>
    <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <label>Title<br>
      <input name="title" value="<?= e($title) ?>" required>
    </label>

    <label>Description<br>
      <textarea name="description" rows="5"><?= e($description) ?></textarea>
    </label>

    <label>Image (optional)<br>
        <input type="file" name="image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
    </label>

    <button type="submit">Create</button>
  </form>
</div>
<?php render_footer(); ?>
