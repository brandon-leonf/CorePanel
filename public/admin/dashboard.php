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
<div class="container admin-dashboard-page">
  <h1>Admin Dashboard</h1>
  <p>Welcome, <?= e($me['name']) ?> (<?= e($me['email']) ?>)</p>

  <div class="inline-links">
    <a href="/admin/users/index.php">Manage Users</a>
    <span class="inline-sep">|</span>
    <a href="/items/index.php">My Items</a>
    <span class="inline-sep">|</span>
    <form method="post" action="/logout.php" class="inline-action-form">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <button type="submit" class="link-like-btn">Logout</button>
    </form>
  </div>

  <section class="admin-authz-panel" aria-labelledby="authz-rules-title">
    <h2 id="authz-rules-title">Authorization Rules</h2>
    <p><strong>Clients:</strong> Can only view and manage their own data.</p>
    <p><strong>Admins:</strong> Can access admin endpoints and view all users/data.</p>
    <p><strong>Enforced Server-Side:</strong> require_login(), require_admin(), and user-scoped queries.</p>
  </section>
</div>
<?php render_footer(); ?>
