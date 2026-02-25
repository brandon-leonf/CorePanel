<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../config/db.php';
require __DIR__ . '/../../src/helpers.php';
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/layout.php';

require_admin($pdo);
$me = current_user($pdo);

render_header('Admin Dashboard • CorePanel');
?>
<div class="container">
  <h1>Admin Dashboard</h1>
  <p>Welcome, <?= e($me['name']) ?> (<?= e($me['email']) ?>)</p>

  <p>
    <a href="/admin/users/index.php">Manage Users</a> |
    <a href="/items/index.php">My Items</a> |
    <a href="/logout.php">Logout</a>
  </p>
</div>
<?php render_footer(); ?>