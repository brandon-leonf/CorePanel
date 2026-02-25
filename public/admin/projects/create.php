<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../../config/db.php';
require __DIR__ . '/../../../src/auth.php';
require __DIR__ . '/../../../src/helpers.php';
require __DIR__ . '/../../../src/layout.php';
require __DIR__ . '/../../../src/invoice.php';

require_admin($pdo);

$me = current_user($pdo);
$adminId = (int)$me['id'];

$usersStmt = $pdo->query("SELECT id, name, email FROM users WHERE role='user' ORDER BY name ASC");
$clients = $usersStmt->fetchAll();

$errors = [];
$userId = (int)($_POST['user_id'] ?? 0);
$title = (string)($_POST['title'] ?? '');
$description = (string)($_POST['description'] ?? '');
$status = (string)($_POST['status'] ?? 'active');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token');
  }

  $userId = (int)($_POST['user_id'] ?? 0);
  $title = trim((string)($_POST['title'] ?? ''));
  $description = trim((string)($_POST['description'] ?? ''));
  $status = (string)($_POST['status'] ?? 'active');

  if ($userId <= 0) $errors[] = 'Client is required.';
  if ($title === '') $errors[] = 'Title is required.';
  if (!in_array($status, ['draft','active','paused','completed'], true)) $errors[] = 'Invalid status.';

  if (!$errors) {
    $projectNo = next_project_no($pdo);

    $ins = $pdo->prepare("
      INSERT INTO projects (project_no, user_id, title, description, status, created_by)
      VALUES (?, ?, ?, ?, ?, ?)
    ");
    $ins->execute([
      $projectNo,
      $userId,
      $title,
      $description === '' ? null : $description,
      $status,
      $adminId
    ]);

    $newId = (int)$pdo->lastInsertId();
    redirect('/admin/projects/edit.php?id=' . $newId);
  }
}

render_header('New Project • Admin • CorePanel');
?>
<div class="container">
  <h1>New Project</h1>
  <p><a href="/admin/projects/index.php">← Back</a></p>

  <?php if ($errors): ?>
    <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

    <label>Client<br>
      <select name="user_id" required>
        <option value="">Select client…</option>
        <?php foreach ($clients as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $userId === (int)$c['id'] ? 'selected' : '' ?>>
            <?= e($c['name']) ?> (<?= e($c['email']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <br><br>

    <label>Title<br>
      <input name="title" value="<?= e($title) ?>" required>
    </label>
    <br><br>

    <label>Description<br>
      <textarea name="description" rows="4"><?= e($description) ?></textarea>
    </label>
    <br><br>

    <label>Status<br>
      <select name="status">
        <?php foreach (['draft','active','paused','completed'] as $s): ?>
          <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e($s) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <br><br>

    <button type="submit">Create Project</button>
  </form>
</div>
<?php render_footer(); ?>