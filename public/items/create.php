<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../config/db.php';
require __DIR__ . '/../../src/helpers.php';
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/layout.php';
require __DIR__ . '/../../src/upload.php';

require_login();
$user = current_user($pdo);
$userId = (int)$user['id'];

$errors = [];
$title = '';
$description = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $title = trim((string)($_POST['title'] ?? ''));
  $description = trim((string)($_POST['description'] ?? ''));

  if ($title === '') $errors[] = 'Title is required.';

  $imagePath = null;

  if (!empty($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
    [$imagePath, $upErr] = upload_item_image($_FILES['image']);
    if ($upErr) $errors[] = $upErr;
  }

  if (!$errors) {
    $stmt = $pdo->prepare("
      INSERT INTO items (user_id, title, description, image_path)
      VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([
      $userId,
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
    <label>Title<br>
      <input name="title" value="<?= e($title) ?>" required>
    </label>

    <label>Description<br>
      <textarea name="description" rows="5"><?= e($description) ?></textarea>
    </label>

    <label>Image (optional)<br>
        <input type="file" name="image" accept="image/*">
    </label>

    <button type="submit">Create</button>
  </form>
</div>
<?php render_footer(); ?>