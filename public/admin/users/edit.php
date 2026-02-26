<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../../config/db.php';
require __DIR__ . '/../../../src/auth.php';
require __DIR__ . '/../../../src/helpers.php';
require __DIR__ . '/../../../src/layout.php';
require __DIR__ . '/../../../src/invoice.php';
require __DIR__ . '/../../../src/validation.php';
require __DIR__ . '/../../../src/security.php';

$me = require_permission($pdo, 'users.edit');
$tenantId = actor_tenant_id($me);
$projectNotesEnabled = ensure_project_notes_column($pdo);
$projectAddressEnabled = ensure_project_address_column($pdo);
security_prepare_sensitive_storage($pdo);

start_session();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('Bad request'); }

$user = require_user_in_tenant($pdo, $me, $id);

$stmt = $pdo->prepare(
  "SELECT id, name, email, role, phone, address, notes, tenant_id
   FROM users
   WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL
   LIMIT 1"
);
$stmt->execute([$id, $tenantId]);
$user = $stmt->fetch();
if (!$user) { http_response_code(404); exit('User not found'); }

$errors = [];
$name = (string)$user['name'];
$email = (string)$user['email'];
$phone = (string)(security_read_user_phone($user['phone'] ?? null) ?? '');
$address = (string)(security_read_user_address($user['address'] ?? null) ?? '');
$notes = (string)(security_read_user_notes($user['notes'] ?? null) ?? '');
$projectStatuses = project_statuses($pdo);
$projectErrors = [];
$projectTitle = normalize_single_line((string)($_POST['project_title'] ?? ''));
$projectDescription = normalize_multiline((string)($_POST['project_description'] ?? ''));
$projectNotes = normalize_multiline((string)($_POST['project_notes'] ?? ''));
$projectAddress = normalize_multiline((string)($_POST['project_address'] ?? ''));
$projectStatus = (string)($_POST['project_status'] ?? 'draft');
$projects = [];
$projectsLoadError = '';
$adminId = (int)($me['id'] ?? 0);
$canEditLinkedProjects = user_has_permission($me, 'projects.edit.any') || (user_has_permission($me, 'projects.edit.own') && $id === (int)$me['id']);

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
    if (!user_has_permission($me, 'projects.create')) {
      http_response_code(403);
      exit('Forbidden');
    }

    $projectTitle = normalize_single_line((string)($_POST['project_title'] ?? ''));
    $projectDescription = normalize_multiline((string)($_POST['project_description'] ?? ''));
    $projectNotes = normalize_multiline((string)($_POST['project_notes'] ?? ''));
    $projectAddress = normalize_multiline((string)($_POST['project_address'] ?? ''));
    $projectStatus = (string)($_POST['project_status'] ?? 'draft');

    validate_required_text($projectTitle, 'Project title', 190, $projectErrors);
    validate_optional_text($projectDescription, 'Project description', 10000, $projectErrors);
    if ($projectNotesEnabled) {
      validate_optional_text($projectNotes, 'Project notes', 5000, $projectErrors);
    }
    if ($projectAddressEnabled) {
      validate_optional_text($projectAddress, 'Project address', 2000, $projectErrors);
    }
    if (!in_array($projectStatus, $projectStatuses, true)) $projectErrors[] = 'Invalid project status.';
    if ($adminId <= 0) $projectErrors[] = 'Admin session is invalid. Please log in again.';

    if (!$projectErrors) {
      try {
        $projectNo = next_project_no($pdo);
        $projectNotesStored = security_store_project_notes($projectNotes === '' ? null : $projectNotes);
        $projectAddressStored = security_store_project_address($projectAddress === '' ? null : $projectAddress);
        if ($projectNotesEnabled && $projectAddressEnabled) {
          $ins = $pdo->prepare(
            "INSERT INTO projects
              (project_no, user_id, tenant_id, title, description, notes, project_address, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
          );
          $ins->execute([
            $projectNo,
            $id,
            $tenantId,
            $projectTitle,
            $projectDescription === '' ? null : $projectDescription,
            $projectNotesStored,
            $projectAddressStored,
            $projectStatus,
            $adminId,
          ]);
        } elseif ($projectNotesEnabled) {
          $ins = $pdo->prepare(
            "INSERT INTO projects
              (project_no, user_id, tenant_id, title, description, notes, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
          );
          $ins->execute([
            $projectNo,
            $id,
            $tenantId,
            $projectTitle,
            $projectDescription === '' ? null : $projectDescription,
            $projectNotesStored,
            $projectStatus,
            $adminId,
          ]);
        } elseif ($projectAddressEnabled) {
          $ins = $pdo->prepare(
            "INSERT INTO projects
              (project_no, user_id, tenant_id, title, description, project_address, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
          );
          $ins->execute([
            $projectNo,
            $id,
            $tenantId,
            $projectTitle,
            $projectDescription === '' ? null : $projectDescription,
            $projectAddressStored,
            $projectStatus,
            $adminId,
          ]);
        } else {
          $ins = $pdo->prepare(
            "INSERT INTO projects
              (project_no, user_id, tenant_id, title, description, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
          );
          $ins->execute([
            $projectNo,
            $id,
            $tenantId,
            $projectTitle,
            $projectDescription === '' ? null : $projectDescription,
            $projectStatus,
            $adminId,
          ]);
        }

        $newId = (int)$pdo->lastInsertId();
        redirect('/admin/projects/edit.php?id=' . $newId);
      } catch (Throwable $e) {
        $projectErrors[] = 'Unable to add project right now. Confirm project tables and counters are initialized.';
      }
    }
  } else {
    $name = normalize_single_line((string)($_POST['name'] ?? ''));
    $email = validate_email_input((string)($_POST['email'] ?? ''), $errors);
    $phone = validate_phone_optional((string)($_POST['phone'] ?? ''), $errors);
    $address = normalize_multiline((string)($_POST['address'] ?? ''));
    $notes = normalize_multiline((string)($_POST['notes'] ?? ''));

    validate_required_text($name, 'Name', 100, $errors);
    validate_optional_text($address, 'Address', 2000, $errors);
    validate_optional_text($notes, 'Notes', 5000, $errors);

    // Enforce unique email (excluding this user)
    if (!$errors) {
      $check = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
      $check->execute([$email, $id]);
      if ($check->fetch()) {
        $errors[] = 'Email is already in use.';
      }
    }

    if (!$errors) {
      $phoneStored = security_store_user_phone($phone === '' ? null : $phone);
      $addressStored = security_store_user_address($address === '' ? null : $address);
      $notesStored = security_store_user_notes($notes === '' ? null : $notes);

      $up = $pdo->prepare("
        UPDATE users
        SET name = ?, email = ?, phone = ?, address = ?, notes = ?
        WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL
      ");
      $up->execute([
        $name,
        $email,
        $phoneStored,
        $addressStored,
        $notesStored,
        $id,
        $tenantId
      ]);

      redirect('/admin/users/index.php');
    }
  }
}

try {
  $pstmt = $pdo->prepare("
    SELECT id, project_no, title, status, created_at
    FROM projects
    WHERE user_id = ? AND tenant_id = ? AND deleted_at IS NULL
    ORDER BY id DESC
  ");
  $pstmt->execute([$id, $tenantId]);
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
            data-phone-format="us"
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

      <?php if (user_has_permission($me, 'projects.create')): ?>
        <form method="post" class="admin-user-project-create-form">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="add_project">

          <label>Project Title<br>
            <input name="project_title" value="<?= e($projectTitle) ?>" required>
          </label>

          <label>Description<br>
            <textarea name="project_description" rows="4"><?= e($projectDescription) ?></textarea>
          </label>

          <?php if ($projectNotesEnabled): ?>
            <label>Notes<br>
              <textarea name="project_notes" rows="4"><?= e($projectNotes) ?></textarea>
            </label>
          <?php endif; ?>

          <?php if ($projectAddressEnabled): ?>
            <label>Project Address<br>
              <textarea name="project_address" rows="3"><?= e($projectAddress) ?></textarea>
            </label>
          <?php endif; ?>

          <label>Status<br>
            <select name="project_status">
              <?php foreach ($projectStatuses as $s): ?>
                <option value="<?= e($s) ?>" <?= $projectStatus === $s ? 'selected' : '' ?>><?= e($s) ?></option>
              <?php endforeach; ?>
            </select>
          </label>

          <button type="submit">Add Project</button>
        </form>
      <?php endif; ?>

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
                    <td><span class="<?= e(status_class((string)$p['status'])) ?>"><?= e((string)$p['status']) ?></span></td>
                    <td><?= e((string)$p['created_at']) ?></td>
                    <td>
                      <?php if ($canEditLinkedProjects): ?>
                        <a href="/admin/projects/edit.php?id=<?= (int)$p['id'] ?>">Edit</a>
                      <?php endif; ?>
                    </td>
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
<?php render_footer(); ?>
