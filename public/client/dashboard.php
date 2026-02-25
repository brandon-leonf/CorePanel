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

  <p>
    <a href="/items/index.php">My Items</a> |
    <a href="/logout.php">Logout</a>
  </p>
</div>
<?php render_footer(); ?>