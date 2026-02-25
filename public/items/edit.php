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

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('Bad request'); }

$stmt = $pdo->prepare("SELECT id, title, description, image_path FROM items WHERE id = ? AND user_id = ?");
$stmt->execute([$id, $userId]);
$item = $stmt->fetch();

if (!$item) { http_response_code(404); exit('Item not found'); }

$errors = [];
$title = (string)$item['title'];
$description = (string)($item['description'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
      http_response_code(403);
      exit('Invalid CSRF token');
    }

    $title = trim((string)($_POST['title'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
  
    if ($title === '') $errors[] = 'Title is required.';
  
    // Keep current image by default
    $newImagePath = $item['image_path'] ?? null;
  
    // If a new image was uploaded, replace it
    if (!empty($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
      [$uploadedPath, $upErr] = upload_item_image($_FILES['image']);
      if ($upErr) {
        $errors[] = $upErr;
      } else {
        $newImagePath = $uploadedPath;
      }
    }
  
    if (!$errors) {
      $up = $pdo->prepare("
        UPDATE items
        SET title = ?, description = ?, image_path = ?
        WHERE id = ? AND user_id = ?
      ");
      $up->execute([
        $title,
        $description === '' ? null : $description,
        $newImagePath,
        $id,
        $userId
      ]);
  
      redirect('/items/index.php');
    }
  }


render_header('Edit Item • CorePanel');
?>
<div class="container">
  <h1>Edit Item</h1>
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

    <?php if (!empty($item['image_path'])): ?>
    <p>Current image:<br>
        <img src="<?= e($item['image_path']) ?>" alt="" style="max-width:180px; height:auto;">
    </p>
    <?php endif; ?>

    <label>Replace image (optional)<br>
        <?php if (!empty($item['image_path'])): ?>
            <p>Current image:<br>
                <img src="<?= e($item['image_path']) ?>" style="max-width:180px; height:auto;">
            </p>
        <?php endif; ?>
        <input type="file" name="image" accept="image/*">
    </label>
    <button type="submit">Save</button>
  </form>
</div>
<?php render_footer(); ?>
