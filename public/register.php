<?php
declare(strict_types=1);

require __DIR__ . '/../src/helpers.php';
require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/layout.php';

start_session();
if (!empty($_SESSION['user_id'])) {
  destroy_current_session();
}

render_header('Registration Disabled • CorePanel');
?>
<div class="container">
  <h1>Registration Disabled</h1>
  <p>New user accounts can only be created by an admin from the admin panel.</p>
  <p>If you need access, contact your administrator to create your client account.</p>
  <p>
    <a href="/login.php">Go to Login</a>
  </p>
</div>
<?php render_footer(); ?>
