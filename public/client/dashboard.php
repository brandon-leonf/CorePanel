<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../config/db.php';
require __DIR__ . '/../../src/helpers.php';
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/layout.php';

require_login();
$me = current_user($pdo);

if (($me['role'] ?? 'user') === 'admin') {
  redirect('/admin/dashboard.php');
}

render_header('Client Dashboard • CorePanel');
?>
<div class="container">
  <h1>Client Dashboard</h1>
  <p>Welcome, <?= e($me['name']) ?> (<?= e($me['email']) ?>)</p>

  <div class="inline-links">
    <a href="/items/index.php">My Items</a>
    <span class="inline-sep">|</span>
    <form method="post" action="/logout.php" class="inline-action-form">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <button type="submit" class="link-like-btn">Logout</button>
    </form>
  </div>
</div>
<?php render_footer(); ?>
