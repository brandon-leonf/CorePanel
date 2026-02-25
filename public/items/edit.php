<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../config/db.php';
require __DIR__ . '/../../src/helpers.php';
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/layout.php';

require_login();
$user = current_user($pdo);
$userId = (int)$user['id'];

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('Bad request'); }

$stmt = $pdo->prepare("SELECT id, title, description FROM items WHERE id = ? AND user_id = ?");
$stmt->execute([$id, $userId]);
$item = $stmt->fetch();

if (!$item) { http_response_code(404); exit('Item not found'); }

$errors = [];
$title = (string)$item['title'];
$description = (string)($item['description'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $title = trim((string)($_POST['title'] ?? ''));
  $description = trim((string)($_POST['description'] ?? ''));

  if ($title === '') $errors[] = 'Title is required.';

  if (!$errors) {
    $up = $pdo->prepare("UPDATE items SET title = ?, description = ? WHERE id = ? AND user_id = ?");
    $up->execute([$title, $description === '' ? null : $description, $id, $userId]);
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

  <form method="post">
    <label>Title<br>
      <input name="title" value="<?= e($title) ?>" required>
    </label>

    <label>Description<br>
      <textarea name="description" rows="5"><?= e($description) ?></textarea>
    </label>

    <button type="submit">Save</button>
  </form>
</div>
<?php render_footer(); ?>