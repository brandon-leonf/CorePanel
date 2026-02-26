<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../../config/db.php';
require __DIR__ . '/../../../src/auth.php';
require __DIR__ . '/../../../src/helpers.php';
require __DIR__ . '/../../../src/layout.php';
require __DIR__ . '/../../../src/upload.php';
require __DIR__ . '/../../../src/validation.php';
require __DIR__ . '/../../../src/security.php';

$me = require_any_permission($pdo, ['projects.edit.any', 'projects.edit.own']);
$tenantId = actor_tenant_id($me);
$projectNotesEnabled = ensure_project_notes_column($pdo);
$projectAddressEnabled = ensure_project_address_column($pdo);
security_prepare_sensitive_storage($pdo);
$projectStatuses = project_statuses($pdo);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('Bad request'); }
$projectScope = require_project_access($pdo, $me, $id, 'edit');
$ownsProject = (int)$projectScope['user_id'] === (int)$me['id'];
$canManageTasks = user_has_permission($me, 'project_tasks.edit.any') || ($ownsProject && user_has_permission($me, 'project_tasks.edit.own'));
$canDeleteTasks = user_has_permission($me, 'project_tasks.delete.any') || ($ownsProject && user_has_permission($me, 'project_tasks.delete.own'));
$canManageImages = user_has_permission($me, 'project_images.manage.any') || ($ownsProject && user_has_permission($me, 'project_images.manage.own'));

$pstmt = $pdo->prepare("
  SELECT p.*, u.name AS client_name, u.email AS client_email
  FROM projects p
  JOIN users u ON u.id = p.user_id
  WHERE p.id = ? AND p.tenant_id = ?
  LIMIT 1
");
$pstmt->execute([$id, $tenantId]);
$project = $pstmt->fetch();
if (!$project) { http_response_code(404); exit('Project not found'); }

$errors = [];
$title = (string)$project['title'];
$description = (string)($project['description'] ?? '');
$notes = (string)(security_read_project_notes($project['notes'] ?? null) ?? '');
$projectAddress = (string)(security_read_project_address($project['project_address'] ?? null) ?? '');
$status = (string)$project['status'];
$projectPdfMaxBytes = upload_max_pdf_bytes();
$projectServerUploadLimitBytes = upload_effective_server_limit_bytes();
if ($projectServerUploadLimitBytes > 0) {
  $projectPdfMaxBytes = min($projectPdfMaxBytes, $projectServerUploadLimitBytes);
}
$projectPdfMaxLabel = upload_human_bytes($projectPdfMaxBytes);
$projectImages = [];
$projectDocuments = [];
$projectImagesEnabled = true;
$projectImagesError = '';

try {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS project_images (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      project_id INT UNSIGNED NOT NULL,
      tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
      image_path VARCHAR(255) NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_project_images_project_id (project_id),
      INDEX idx_project_images_tenant_id (tenant_id),
      CONSTRAINT fk_project_images_project
        FOREIGN KEY (project_id) REFERENCES projects(id)
        ON DELETE CASCADE
    ) ENGINE=InnoDB
  ");
} catch (Throwable $e) {
  $projectImagesEnabled = false;
  $projectImagesError = 'Project images are not available yet.';
}

/** Update project meta */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_project') {
  if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token');
  }

  $title = normalize_single_line((string)($_POST['title'] ?? ''));
  $description = normalize_multiline((string)($_POST['description'] ?? ''));
  $notes = normalize_multiline((string)($_POST['notes'] ?? ''));
  $projectAddress = normalize_multiline((string)($_POST['project_address'] ?? ''));
  $status = (string)($_POST['status'] ?? 'active');

  validate_required_text($title, 'Title', 190, $errors);
  validate_optional_text($description, 'Description', 10000, $errors);
  if ($projectNotesEnabled) {
    validate_optional_text($notes, 'Notes', 5000, $errors);
  }
  if ($projectAddressEnabled) {
    validate_optional_text($projectAddress, 'Project address', 2000, $errors);
  }
  if (!in_array($status, $projectStatuses, true)) $errors[] = 'Invalid status.';

  if (!$errors) {
    $notesStored = security_store_project_notes($notes === '' ? null : $notes);
    $projectAddressStored = security_store_project_address($projectAddress === '' ? null : $projectAddress);

    if ($projectNotesEnabled && $projectAddressEnabled) {
      $up = $pdo->prepare(
        "UPDATE projects
         SET title = ?, description = ?, notes = ?, project_address = ?, status = ?
         WHERE id = ? AND tenant_id = ?"
      );
      $up->execute([
        $title,
        $description === '' ? null : $description,
        $notesStored,
        $projectAddressStored,
        $status,
        $id,
        $tenantId,
      ]);
    } elseif ($projectNotesEnabled) {
      $up = $pdo->prepare(
        "UPDATE projects
         SET title = ?, description = ?, notes = ?, status = ?
         WHERE id = ? AND tenant_id = ?"
      );
      $up->execute([
        $title,
        $description === '' ? null : $description,
        $notesStored,
        $status,
        $id,
        $tenantId,
      ]);
    } elseif ($projectAddressEnabled) {
      $up = $pdo->prepare(
        "UPDATE projects
         SET title = ?, description = ?, project_address = ?, status = ?
         WHERE id = ? AND tenant_id = ?"
      );
      $up->execute([
        $title,
        $description === '' ? null : $description,
        $projectAddressStored,
        $status,
        $id,
        $tenantId,
      ]);
    } else {
      $up = $pdo->prepare(
        "UPDATE projects
         SET title = ?, description = ?, status = ?
         WHERE id = ? AND tenant_id = ?"
      );
      $up->execute([
        $title,
        $description === '' ? null : $description,
        $status,
        $id,
        $tenantId,
      ]);
    }
    redirect('/admin/projects/edit.php?id=' . $id);
  }
}

/** Add task */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_task') {
  if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token');
  }
  if (!$canManageTasks) {
    http_response_code(403);
    exit('Forbidden');
  }

  $taskTitle = normalize_single_line((string)($_POST['task_title'] ?? ''));
  $taskDesc  = normalize_multiline((string)($_POST['task_description'] ?? ''));
  $rate = validate_decimal_input($_POST['rate'] ?? '', 'Rate', 0.0, 99999999.99, $errors);
  $qty  = validate_decimal_input($_POST['quantity'] ?? '', 'Quantity', 0.0, 99999999.99, $errors);

  validate_required_text($taskTitle, 'Task title', 190, $errors);
  validate_optional_text($taskDesc, 'Task description', 5000, $errors);

  $amount = ($rate !== null && $qty !== null) ? round($rate * $qty, 2) : 0.0;

  if (!$errors) {
    $ins = $pdo->prepare("
      INSERT INTO project_tasks (project_id, tenant_id, task_title, task_description, rate, quantity, amount)
      VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $ins->execute([
      $id,
      $tenantId,
      $taskTitle,
      $taskDesc === '' ? null : $taskDesc,
      $rate,
      $qty,
      $amount
    ]);
    redirect('/admin/projects/edit.php?id=' . $id);
  }
}

/** Add project images */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_project_images') {
  if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token');
  }
  if (!$canManageImages) {
    http_response_code(403);
    exit('Forbidden');
  }

  if (!$projectImagesEnabled) {
    $errors[] = 'Project images are not available yet.';
  } else {
    $files = $_FILES['project_images'] ?? null;
    if (!is_array($files) || !isset($files['error']) || !is_array($files['error'])) {
      $errors[] = 'Select at least one image to upload.';
    } else {
      $hasImage = false;
      $insImg = $pdo->prepare("
        INSERT INTO project_images (project_id, tenant_id, image_path)
        VALUES (?, ?, ?)
      ");

      foreach (array_keys($files['error']) as $idx) {
        $fileError = (int)($files['error'][$idx] ?? UPLOAD_ERR_NO_FILE);
        if ($fileError === UPLOAD_ERR_NO_FILE) {
          continue;
        }

        $hasImage = true;
        $file = [
          'name' => $files['name'][$idx] ?? '',
          'type' => $files['type'][$idx] ?? '',
          'tmp_name' => $files['tmp_name'][$idx] ?? '',
          'error' => $fileError,
          'size' => $files['size'][$idx] ?? 0,
        ];
        [$imagePath, $uploadErr] = upload_item_image($file);
        if ($uploadErr !== null) {
          $errors[] = 'Image upload failed: ' . $uploadErr;
          continue;
        }

        $insImg->execute([$id, $tenantId, $imagePath]);
      }

      if (!$hasImage) {
        $errors[] = 'Select at least one image to upload.';
      }
      if (!$errors) {
        redirect('/admin/projects/edit.php?id=' . $id);
      }
    }
  }
}

/** Add project PDFs */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_project_documents') {
  if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token');
  }
  if (!$canManageImages) {
    http_response_code(403);
    exit('Forbidden');
  }

  if (!$projectImagesEnabled) {
    $errors[] = 'Project files are not available yet.';
  } else {
    $files = $_FILES['project_documents'] ?? null;
    if (!is_array($files) || !isset($files['error']) || !is_array($files['error'])) {
      $errors[] = 'Select at least one PDF to upload.';
    } else {
      $hasDocument = false;
      $insDoc = $pdo->prepare("
        INSERT INTO project_images (project_id, tenant_id, image_path)
        VALUES (?, ?, ?)
      ");

      foreach (array_keys($files['error']) as $idx) {
        $fileError = (int)($files['error'][$idx] ?? UPLOAD_ERR_NO_FILE);
        if ($fileError === UPLOAD_ERR_NO_FILE) {
          continue;
        }

        $hasDocument = true;
        $file = [
          'name' => $files['name'][$idx] ?? '',
          'type' => $files['type'][$idx] ?? '',
          'tmp_name' => $files['tmp_name'][$idx] ?? '',
          'error' => $fileError,
          'size' => $files['size'][$idx] ?? 0,
        ];
        [$docPath, $uploadErr] = upload_project_pdf($file);
        if ($uploadErr !== null) {
          $errors[] = 'PDF upload failed: ' . $uploadErr;
          continue;
        }

        $insDoc->execute([$id, $tenantId, $docPath]);
      }

      if (!$hasDocument) {
        $errors[] = 'Select at least one PDF to upload.';
      }
      if (!$errors) {
        redirect('/admin/projects/edit.php?id=' . $id);
      }
    }
  }
}

/** Delete project image */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_project_image') {
  if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token');
  }
  if (!$canManageImages) {
    http_response_code(403);
    exit('Forbidden');
  }

  if (!$projectImagesEnabled) {
    $errors[] = 'Project images are not available yet.';
  } else {
    $imageId = (int)($_POST['image_id'] ?? 0);
    if ($imageId <= 0) {
      $errors[] = 'Invalid image.';
    } else {
      $imgStmt = $pdo->prepare("
        SELECT i.image_path
        FROM project_images i
        JOIN projects p ON p.id = i.project_id
        WHERE i.id = ?
          AND i.project_id = ?
          AND i.tenant_id = ?
          AND p.tenant_id = ?
        LIMIT 1
      ");
      $imgStmt->execute([$imageId, $id, $tenantId, $tenantId]);
      $img = $imgStmt->fetch();

      if (!$img) {
        $errors[] = 'Image not found.';
      } else {
        $imagePath = (string)($img['image_path'] ?? '');
        $delImg = $pdo->prepare("
          DELETE i
          FROM project_images i
          JOIN projects p ON p.id = i.project_id
          WHERE i.id = ?
            AND i.project_id = ?
            AND i.tenant_id = ?
            AND p.tenant_id = ?
        ");
        $delImg->execute([$imageId, $id, $tenantId, $tenantId]);
        upload_delete_reference_if_unreferenced($pdo, $imagePath);

        redirect('/admin/projects/edit.php?id=' . $id);
      }
    }
  }
}

$tstmt = $pdo->prepare(
  "SELECT t.*
   FROM project_tasks t
   JOIN projects p ON p.id = t.project_id
   WHERE t.project_id = ?
     AND t.tenant_id = ?
     AND p.tenant_id = ?
   ORDER BY t.id DESC"
);
$tstmt->execute([$id, $tenantId, $tenantId]);
$tasks = $tstmt->fetchAll();

if ($projectImagesEnabled) {
  try {
    $imgListStmt = $pdo->prepare("
      SELECT i.id, i.image_path, i.created_at
      FROM project_images i
      JOIN projects p ON p.id = i.project_id
      WHERE i.project_id = ?
        AND i.tenant_id = ?
        AND p.tenant_id = ?
      ORDER BY i.id DESC
    ");
    $imgListStmt->execute([$id, $tenantId, $tenantId]);
    $projectMedia = $imgListStmt->fetchAll();
    foreach ($projectMedia as $media) {
      $reference = (string)($media['image_path'] ?? '');
      if (upload_reference_is_pdf($reference)) {
        $projectDocuments[] = $media;
      } elseif (upload_reference_is_image($reference)) {
        $projectImages[] = $media;
      }
    }
  } catch (Throwable $e) {
    $projectImagesError = 'Project images are not available yet.';
  }
}

$total = 0.00;
foreach ($tasks as $t) $total += (float)$t['amount'];

render_header('Edit Project • Admin • CorePanel');
?>
<div class="container container-wide admin-project-edit-page">
  <div class="admin-project-edit-header">
    <p><a href="/admin/projects/index.php">← Projects</a></p>
    <h1><?= e($project['project_no']) ?> — <?= e($project['title']) ?></h1>
    <p class="admin-project-edit-client">Client: <?= e($project['client_name']) ?> (<?= e($project['client_email']) ?>)</p>
  </div>

  <?php if ($errors): ?>
    <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>

  <div class="admin-project-edit-grid">
    <section class="admin-project-edit-panel" aria-labelledby="project-details-title">
      <h2 id="project-details-title">Project Details</h2>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="update_project">

        <label>Title<br>
          <input name="title" value="<?= e($title) ?>" required>
        </label>

        <label>Description<br>
          <textarea name="description" rows="4"><?= e($description) ?></textarea>
        </label>

        <?php if ($projectNotesEnabled): ?>
          <label>Notes<br>
            <textarea name="notes" rows="4"><?= e($notes) ?></textarea>
          </label>
        <?php endif; ?>

        <?php if ($projectAddressEnabled): ?>
          <label>Project Address<br>
            <textarea name="project_address" rows="3"><?= e($projectAddress) ?></textarea>
          </label>
        <?php endif; ?>

        <label>Status<br>
          <select name="status">
            <?php foreach ($projectStatuses as $s): ?>
              <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e($s) ?></option>
            <?php endforeach; ?>
          </select>
        </label>

        <button type="submit">Save Project</button>
      </form>
    </section>

    <?php if ($canManageTasks): ?>
      <section class="admin-project-edit-panel" aria-labelledby="add-task-title">
        <h2 id="add-task-title">Add Task</h2>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="add_task">

          <label>Task Title<br>
            <input name="task_title" required>
          </label>

          <label>Task Description<br>
            <textarea name="task_description" rows="3"></textarea>
          </label>

          <div class="admin-project-edit-task-row">
            <label>Rate<br>
              <input name="rate" type="number" step="0.01" value="0.00" required>
            </label>

            <label>Quantity<br>
              <input name="quantity" type="number" step="0.01" value="1.00" required>
            </label>
          </div>

          <button type="submit">Add Task</button>
        </form>
      </section>
    <?php endif; ?>
  </div>

  <?php if ($canManageImages): ?>
    <section class="admin-project-edit-images-section" aria-labelledby="project-images-title">
      <h2 id="project-images-title">Project Images</h2>

    <?php if ($projectImagesError !== ''): ?>
      <p class="admin-project-edit-images-note"><?= e($projectImagesError) ?></p>
    <?php else: ?>
      <form method="post" enctype="multipart/form-data" class="admin-project-edit-images-upload-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="add_project_images">

        <label>Upload Images<br>
          <input type="file" name="project_images[]" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" multiple>
        </label>

        <button type="submit">Upload Images</button>
      </form>

      <form method="post" enctype="multipart/form-data" class="admin-project-edit-images-upload-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="add_project_documents">

        <label>Upload PDF Files<br>
          <input type="file" name="project_documents[]" accept=".pdf,application/pdf" multiple>
        </label>
        <p class="admin-project-edit-images-note">Max PDF size: <?= e($projectPdfMaxLabel) ?></p>

        <button type="submit">Upload PDFs</button>
      </form>

      <?php if (!$projectImages): ?>
        <p class="admin-project-edit-images-note">No images uploaded yet.</p>
      <?php else: ?>
        <div class="admin-project-edit-images-grid">
          <?php foreach ($projectImages as $img): ?>
            <figure class="admin-project-edit-image-card">
              <?php $viewUrl = media_file_url((string)$img['image_path']) ?? '#'; ?>
              <?php $downloadUrl = media_file_url((string)$img['image_path'], true) ?? '#'; ?>
              <a href="<?= e_url_attr($viewUrl) ?>" target="_blank" rel="noopener">
                <img src="<?= e_url_attr($viewUrl, 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==') ?>" alt="Project image">
              </a>
              <div class="admin-project-edit-media-actions">
                <a class="admin-project-edit-media-link" href="<?= e_url_attr($viewUrl) ?>" target="_blank" rel="noopener">Open</a>
                <a class="admin-project-edit-media-link" href="<?= e_url_attr($downloadUrl) ?>">Download</a>
              </div>
              <form method="post" class="admin-project-edit-image-delete-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete_project_image">
                <input type="hidden" name="image_id" value="<?= (int)$img['id'] ?>">
                <button
                  type="submit"
                  class="admin-project-edit-image-delete-btn"
                  data-confirm="Delete this image?"
                >
                  Delete Image
                </button>
              </form>
            </figure>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <h3 class="admin-project-edit-documents-title">Project PDFs</h3>
      <?php if (!$projectDocuments): ?>
        <p class="admin-project-edit-images-note">No PDF files uploaded yet.</p>
      <?php else: ?>
        <div class="admin-project-edit-documents-list">
          <?php foreach ($projectDocuments as $doc): ?>
            <?php $docViewUrl = media_file_url((string)$doc['image_path']) ?? '#'; ?>
            <?php $docDownloadUrl = media_file_url((string)$doc['image_path'], true) ?? '#'; ?>
            <?php $docName = upload_reference_filename((string)$doc['image_path']) ?? ('Document #' . (int)$doc['id']); ?>
            <div class="admin-project-edit-document-item">
              <div class="admin-project-edit-document-meta">
                <strong><?= e($docName) ?></strong>
                <small><?= e((string)$doc['created_at']) ?></small>
              </div>
              <div class="admin-project-edit-document-actions">
                <a class="admin-project-edit-media-link" href="<?= e_url_attr($docViewUrl) ?>" target="_blank" rel="noopener">View</a>
                <a class="admin-project-edit-media-link" href="<?= e_url_attr($docDownloadUrl) ?>">Download</a>
                <form method="post" class="admin-project-edit-image-delete-form">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="delete_project_image">
                  <input type="hidden" name="image_id" value="<?= (int)$doc['id'] ?>">
                  <button
                    type="submit"
                    class="admin-project-edit-document-delete-btn"
                    data-confirm="Delete this file?"
                  >
                    Delete
                  </button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
    </section>
  <?php endif; ?>

  <section class="admin-project-edit-tasks-section" aria-labelledby="tasks-title">
    <h2 id="tasks-title">Tasks</h2>
    <div class="admin-project-edit-tasks-wrap">
      <table class="admin-project-edit-tasks-table" border="1" cellpadding="8" cellspacing="0">
        <thead>
          <tr>
            <th>Title</th>
            <th>Rate</th>
            <th>Qty</th>
            <th>Amount</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$tasks): ?>
            <tr>
              <td colspan="6">No tasks yet.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($tasks as $t): ?>
              <tr>
                <td>
                  <strong><?= e($t['task_title']) ?></strong><br>
                  <small><?= e((string)($t['task_description'] ?? '')) ?></small>
                </td>
                <td><?= number_format((float)$t['rate'], 2) ?></td>
                <td><?= number_format((float)$t['quantity'], 2) ?></td>
                <td><?= number_format((float)$t['amount'], 2) ?></td>
                <td><span class="<?= e(status_class((string)$t['status'])) ?>"><?= e((string)$t['status']) ?></span></td>
                <td class="admin-project-edit-task-actions-cell">
                  <div class="admin-project-edit-task-actions">
                    <?php if ($canManageTasks): ?>
                      <a class="admin-project-edit-task-action-link" href="/admin/projects/task_edit.php?project_id=<?= (int)$id ?>&task_id=<?= (int)$t['id'] ?>">Edit</a>
                    <?php endif; ?>
                    <?php if ($canDeleteTasks): ?>
                      <form method="post" action="/admin/projects/task_delete.php" class="admin-project-edit-task-delete-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="project_id" value="<?= (int)$id ?>">
                        <input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>">
                        <button
                          type="submit"
                          class="admin-project-edit-task-delete-btn"
                          data-confirm="Delete this task?"
                        >
                          Delete
                        </button>
                      </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <p class="admin-project-edit-total"><strong>Total:</strong> $<?= number_format((float)$total, 2) ?></p>
  </section>
</div>
<?php render_footer(); ?>
