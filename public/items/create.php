<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../config/db.php';
require __DIR__ . '/../../src/helpers.php';
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/layout.php';

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

  if (!$errors) {
    $stmt = $pdo->prepare("INSERT INTO items (user_id, title, description) VALUES (?, ?, ?)");
    $stmt->execute([$userId, $title, $description === '' ? null : $description]);
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

  <form method="post">
    <label>Title<br>
      <input name="title" value="<?= e($title) ?>" required>
    </label>

    <label>Description<br>
      <textarea name="description" rows="5"><?= e($description) ?></textarea>
    </label>

    <button type="submit">Create</button>
  </form>
</div>
<?php render_footer(); ?>