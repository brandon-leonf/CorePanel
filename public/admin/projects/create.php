<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../../config/db.php';
require __DIR__ . '/../../../src/auth.php';
require __DIR__ . '/../../../src/helpers.php';
require __DIR__ . '/../../../src/layout.php';
require __DIR__ . '/../../../src/invoice.php';
require __DIR__ . '/../../../src/validation.php';
require __DIR__ . '/../../../src/security.php';

$me = require_permission($pdo, 'projects.create');
$projectNotesEnabled = ensure_project_notes_column($pdo);
$projectAddressEnabled = ensure_project_address_column($pdo);
$projectDueDateEnabled = ensure_project_due_date_column($pdo);
security_prepare_sensitive_storage($pdo);
$projectStatuses = project_statuses($pdo);

$adminId = (int)$me['id'];
$tenantId = actor_tenant_id($me);

$usersStmt = $pdo->prepare(
  "SELECT id, name, email
   FROM users
   WHERE role = ?
     AND tenant_id = ?
     AND deleted_at IS NULL
   ORDER BY name ASC"
);
$usersStmt->execute(['user', $tenantId]);
$clients = $usersStmt->fetchAll();

$errors = [];
$userId = (int)($_POST['user_id'] ?? 0);
$title = normalize_single_line((string)($_POST['title'] ?? ''));
$description = normalize_multiline((string)($_POST['description'] ?? ''));
$notes = normalize_multiline((string)($_POST['notes'] ?? ''));
$projectAddress = normalize_multiline((string)($_POST['project_address'] ?? ''));
$dueDateInput = trim((string)($_POST['due_date'] ?? ''));
$status = (string)($_POST['status'] ?? 'active');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token');
  }

  $userId = (int)($_POST['user_id'] ?? 0);
  $title = normalize_single_line((string)($_POST['title'] ?? ''));
  $description = normalize_multiline((string)($_POST['description'] ?? ''));
  $notes = normalize_multiline((string)($_POST['notes'] ?? ''));
  $projectAddress = normalize_multiline((string)($_POST['project_address'] ?? ''));
  $dueDateInput = trim((string)($_POST['due_date'] ?? ''));
  $status = (string)($_POST['status'] ?? 'active');

  if ($userId <= 0) $errors[] = 'Client is required.';
  if ($userId > 0) {
    $clientCheckStmt = $pdo->prepare(
      "SELECT id
       FROM users
       WHERE id = ?
         AND tenant_id = ?
         AND role = 'user'
         AND deleted_at IS NULL
       LIMIT 1"
    );
    $clientCheckStmt->execute([$userId, $tenantId]);
    if (!$clientCheckStmt->fetch()) {
      $errors[] = 'Selected client is not in your tenant.';
    }
  }
  validate_required_text($title, 'Title', 190, $errors);
  validate_optional_text($description, 'Description', 10000, $errors);
  if ($projectNotesEnabled) {
    validate_optional_text($notes, 'Notes', 5000, $errors);
  }
  if ($projectAddressEnabled) {
    validate_optional_text($projectAddress, 'Project address', 2000, $errors);
  }
  $dueDateValue = null;
  if ($projectDueDateEnabled && $dueDateInput !== '') {
    $dueDate = DateTimeImmutable::createFromFormat('Y-m-d', $dueDateInput);
    $dueErrors = DateTimeImmutable::getLastErrors();
    $dueWarnings = is_array($dueErrors) ? (int)($dueErrors['warning_count'] ?? 0) : 0;
    $dueErrorCount = is_array($dueErrors) ? (int)($dueErrors['error_count'] ?? 0) : 0;
    if (
      !($dueDate instanceof DateTimeImmutable) ||
      $dueWarnings > 0 ||
      $dueErrorCount > 0
    ) {
      $errors[] = 'Due date must be a valid date.';
    } else {
      $dueDateValue = $dueDate->format('Y-m-d');
    }
  }
  if (!in_array($status, $projectStatuses, true)) $errors[] = 'Invalid status.';

  if (!$errors) {
    $projectNo = next_project_no($pdo);
    $notesStored = security_store_project_notes($notes === '' ? null : $notes);
    $projectAddressStored = security_store_project_address($projectAddress === '' ? null : $projectAddress);
    if ($projectNotesEnabled && $projectAddressEnabled && $projectDueDateEnabled) {
      $ins = $pdo->prepare(
        "INSERT INTO projects
          (project_no, user_id, tenant_id, title, description, notes, project_address, due_date, status, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
      );
      $ins->execute([
        $projectNo,
        $userId,
        $tenantId,
        $title,
        $description === '' ? null : $description,
        $notesStored,
        $projectAddressStored,
        $dueDateValue,
        $status,
        $adminId,
      ]);
    } elseif ($projectNotesEnabled && $projectAddressEnabled) {
      $ins = $pdo->prepare(
        "INSERT INTO projects
          (project_no, user_id, tenant_id, title, description, notes, project_address, status, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
      );
      $ins->execute([
        $projectNo,
        $userId,
        $tenantId,
        $title,
        $description === '' ? null : $description,
        $notesStored,
        $projectAddressStored,
        $status,
        $adminId,
      ]);
    } elseif ($projectNotesEnabled && $projectDueDateEnabled) {
      $ins = $pdo->prepare(
        "INSERT INTO projects
          (project_no, user_id, tenant_id, title, description, notes, due_date, status, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
      );
      $ins->execute([
        $projectNo,
        $userId,
        $tenantId,
        $title,
        $description === '' ? null : $description,
        $notesStored,
        $dueDateValue,
        $status,
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
        $userId,
        $tenantId,
        $title,
        $description === '' ? null : $description,
        $notesStored,
        $status,
        $adminId,
      ]);
    } elseif ($projectAddressEnabled && $projectDueDateEnabled) {
      $ins = $pdo->prepare(
        "INSERT INTO projects
          (project_no, user_id, tenant_id, title, description, project_address, due_date, status, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
      );
      $ins->execute([
        $projectNo,
        $userId,
        $tenantId,
        $title,
        $description === '' ? null : $description,
        $projectAddressStored,
        $dueDateValue,
        $status,
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
        $userId,
        $tenantId,
        $title,
        $description === '' ? null : $description,
        $projectAddressStored,
        $status,
        $adminId,
      ]);
    } elseif ($projectDueDateEnabled) {
      $ins = $pdo->prepare(
        "INSERT INTO projects
          (project_no, user_id, tenant_id, title, description, due_date, status, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
      );
      $ins->execute([
        $projectNo,
        $userId,
        $tenantId,
        $title,
        $description === '' ? null : $description,
        $dueDateValue,
        $status,
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
        $userId,
        $tenantId,
        $title,
        $description === '' ? null : $description,
        $status,
        $adminId,
      ]);
    }

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

    <?php if ($projectNotesEnabled): ?>
      <label>Notes<br>
        <textarea name="notes" rows="4"><?= e($notes) ?></textarea>
      </label>
      <br><br>
    <?php endif; ?>

    <?php if ($projectAddressEnabled): ?>
      <label>Project Address<br>
        <textarea name="project_address" rows="3"><?= e($projectAddress) ?></textarea>
      </label>
      <br><br>
    <?php endif; ?>

    <?php if ($projectDueDateEnabled): ?>
      <label>Due Date<br>
        <input type="date" name="due_date" value="<?= e($dueDateInput) ?>">
      </label>
      <br><br>
    <?php endif; ?>

    <label>Status<br>
      <select name="status">
        <?php foreach ($projectStatuses as $s): ?>
          <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e($s) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <br><br>

    <button type="submit">Create Project</button>
  </form>
</div>
<?php render_footer(); ?>
