<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../../config/db.php';
require __DIR__ . '/../../../src/auth.php';
require __DIR__ . '/../../../src/helpers.php';
require __DIR__ . '/../../../src/layout.php';
require __DIR__ . '/../../../src/invoice.php';

require_admin($pdo);

start_session();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('Bad request'); }

$stmt = $pdo->prepare("SELECT id, name, email, role, phone, address, notes FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$user = $stmt->fetch();
if (!$user) { http_response_code(404); exit('User not found'); }

$errors = [];
$name = (string)$user['name'];
$email = (string)$user['email'];
$phone = (string)($user['phone'] ?? '');
$address = (string)($user['address'] ?? '');
$notes = (string)($user['notes'] ?? '');
$projectStatuses = ['draft', 'active', 'paused', 'completed'];
$projectErrors = [];
$projectTitle = trim((string)($_POST['project_title'] ?? ''));
$projectDescription = trim((string)($_POST['project_description'] ?? ''));
$projectStatus = (string)($_POST['project_status'] ?? 'draft');
$projects = [];
$projectsLoadError = '';
$me = current_user($pdo);
$adminId = (int)($me['id'] ?? 0);

if (!in_array($projectStatus, $projectStatuses, true)) {
  $projectStatus = 'draft';
}

if ($phone !== '') {
  $digits = preg_replace('/\D+/', '', $phone) ?? '';
  if (strlen($digits) === 10) {
    $phone = sprintf('(%s) %s %s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6, 4));
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token');
  }

  $action = (string)($_POST['action'] ?? 'update_user');

  if ($action === 'add_project') {
    $projectTitle = trim((string)($_POST['project_title'] ?? ''));
    $projectDescription = trim((string)($_POST['project_description'] ?? ''));
    $projectStatus = (string)($_POST['project_status'] ?? 'draft');

    if ($projectTitle === '') $projectErrors[] = 'Project title is required.';
    if (!in_array($projectStatus, $projectStatuses, true)) $projectErrors[] = 'Invalid project status.';
    if ($adminId <= 0) $projectErrors[] = 'Admin session is invalid. Please log in again.';

    if (!$projectErrors) {
      try {
        $projectNo = next_project_no($pdo);
        $ins = $pdo->prepare("
          INSERT INTO projects (project_no, user_id, title, description, status, created_by)
          VALUES (?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([
          $projectNo,
          $id,
          $projectTitle,
          $projectDescription === '' ? null : $projectDescription,
          $projectStatus,
          $adminId
        ]);

        $newId = (int)$pdo->lastInsertId();
        redirect('/admin/projects/edit.php?id=' . $newId);
      } catch (Throwable $e) {
        $projectErrors[] = 'Unable to add project right now. Confirm project tables and counters are initialized.';
      }
    }
  } else {
    $name = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));

    if ($phone !== '') {
      $digits = preg_replace('/\D+/', '', $phone) ?? '';
      if (strlen($digits) === 10) {
        $phone = sprintf('(%s) %s %s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6, 4));
      }
    }

    if ($name === '') $errors[] = 'Name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email required.';

    // Enforce unique email (excluding this user)
    if (!$errors) {
      $check = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
      $check->execute([$email, $id]);
      if ($check->fetch()) {
        $errors[] = 'Email is already in use.';
      }
    }

    if (!$errors) {
      $up = $pdo->prepare("
        UPDATE users
        SET name = ?, email = ?, phone = ?, address = ?, notes = ?
        WHERE id = ?
      ");
      $up->execute([
        $name,
        $email,
        $phone === '' ? null : $phone,
        $address === '' ? null : $address,
        $notes === '' ? null : $notes,
        $id
      ]);

      redirect('/admin/users/index.php');
    }
  }
}

try {
  $pstmt = $pdo->prepare("
    SELECT id, project_no, title, status, created_at
    FROM projects
    WHERE user_id = ?
    ORDER BY id DESC
  ");
  $pstmt->execute([$id]);
  $projects = $pstmt->fetchAll();
} catch (Throwable $e) {
  $projectsLoadError = 'Projects are not available yet. Confirm the projects tables are migrated.';
}

render_header('Edit User • CorePanel');
?>
<div class="container container-wide admin-user-edit-page">
  <h1>Edit User</h1>
  <p><a href="/admin/users/index.php">← Back</a></p>

  <div class="admin-user-edit-layout">
    <section class="admin-user-profile-panel">
      <h2>User Details</h2>

      <?php if ($errors): ?>
        <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
      <?php endif; ?>

      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="update_user">

        <label>Name<br>
          <input name="name" value="<?= e($name) ?>" required>
        </label>

        <label>Email<br>
          <input name="email" type="email" value="<?= e($email) ?>" required>
        </label>

        <label>Phone<br>
          <input
            id="phone"
            name="phone"
            value="<?= e($phone) ?>"
            inputmode="numeric"
            autocomplete="tel"
            placeholder="(000) 000 0000"
            maxlength="14"
          >
        </label>

        <label>Address<br>
          <input name="address" value="<?= e($address) ?>">
        </label>

        <label>Notes<br>
          <textarea name="notes" rows="5"><?= e($notes) ?></textarea>
        </label>

        <button type="submit">Save User</button>
      </form>
    </section>

    <section class="admin-user-projects-panel" aria-labelledby="user-projects-title">
      <h2 id="user-projects-title">Projects</h2>

      <?php if ($projectErrors): ?>
        <ul><?php foreach ($projectErrors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
      <?php endif; ?>

      <form method="post" class="admin-user-project-create-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="add_project">

        <label>Project Title<br>
          <input name="project_title" value="<?= e($projectTitle) ?>" required>
        </label>

        <label>Description<br>
          <textarea name="project_description" rows="4"><?= e($projectDescription) ?></textarea>
        </label>

        <label>Status<br>
          <select name="project_status">
            <?php foreach ($projectStatuses as $s): ?>
              <option value="<?= e($s) ?>" <?= $projectStatus === $s ? 'selected' : '' ?>><?= e($s) ?></option>
            <?php endforeach; ?>
          </select>
        </label>

        <button type="submit">Add Project</button>
      </form>

      <?php if ($projectsLoadError !== ''): ?>
        <p class="admin-user-projects-note"><?= e($projectsLoadError) ?></p>
      <?php else: ?>
        <div class="admin-user-projects-table-wrap">
          <table class="admin-user-projects-table" border="1" cellpadding="8" cellspacing="0">
            <thead>
              <tr>
                <th>Project #</th>
                <th>Title</th>
                <th>Status</th>
                <th>Created</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$projects): ?>
                <tr>
                  <td colspan="5">No projects for this user yet.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($projects as $p): ?>
                  <tr>
                    <td><?= e((string)$p['project_no']) ?></td>
                    <td><?= e((string)$p['title']) ?></td>
                    <td><?= e((string)$p['status']) ?></td>
                    <td><?= e((string)$p['created_at']) ?></td>
                    <td><a href="/admin/projects/edit.php?id=<?= (int)$p['id'] ?>">Edit</a></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  </div>
</div>
<script>
  (function () {
    const phoneInput = document.getElementById('phone');
    if (!phoneInput) return;

    function formatPhone(value) {
      const digits = value.replace(/\D/g, '').slice(0, 10);
      if (digits.length === 0) return '';
      if (digits.length <= 3) return digits.length === 3 ? `(${digits}) ` : `(${digits}`;
      if (digits.length <= 6) return `(${digits.slice(0, 3)}) ${digits.slice(3)}`;
      return `(${digits.slice(0, 3)}) ${digits.slice(3, 6)} ${digits.slice(6)}`;
    }

    phoneInput.value = formatPhone(phoneInput.value);
    phoneInput.addEventListener('input', () => {
      phoneInput.value = formatPhone(phoneInput.value);
    });
  })();
</script>
<?php render_footer(); ?>
