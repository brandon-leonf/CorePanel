<?php
declare(strict_types=1);

$pdo = require __DIR__ . '/../../config/db.php';
require __DIR__ . '/../../src/helpers.php';
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/layout.php';
require __DIR__ . '/../../src/rate_limit.php';

require_login();
$user = current_user($pdo);
$userId = (int)$user['id'];
$tenantId = actor_tenant_id($user);

$perPage = 5; // change to 10 later if you want
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;
$q = trim((string)($_GET['q'] ?? ''));
$searchError = '';
$totalItems = 0;
$totalPages = 1;
$items = [];

$searchRateKeys = [
  rl_key_ip(rl_client_ip()),
  rl_key_identity('user', (string)$userId),
];

if ($q !== '') {
  $rateState = rl_precheck($pdo, 'search_items', $searchRateKeys);
  if ($rateState['blocked']) {
    rl_log_blocked($pdo, 'search_items', $searchRateKeys, (string)($user['email'] ?? ''), (int)$rateState['retry_after']);
    $searchError = rl_lock_message((int)$rateState['retry_after']);
  } else {
    rl_register_attempt($pdo, 'search_items', $searchRateKeys, (string)($user['email'] ?? ''), 'item_search_query', false);

    $like = '%' . $q . '%';

    $countStmt = $pdo->prepare(
      "SELECT COUNT(*)
       FROM items
       WHERE user_id = ?
         AND tenant_id = ?
         AND (title LIKE ? OR description LIKE ?)"
    );
    $countStmt->execute([$userId, $tenantId, $like, $like]);
    $totalItems = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalItems / $perPage));

    if ($page > $totalPages) {
      $page = $totalPages;
      $offset = ($page - 1) * $perPage;
    }

    $stmt = $pdo->prepare(
      "SELECT id, title, description, image_path, created_at
       FROM items
       WHERE user_id = ?
         AND tenant_id = ?
         AND (title LIKE ? OR description LIKE ?)
       ORDER BY created_at DESC
       LIMIT ? OFFSET ?"
    );
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $tenantId, PDO::PARAM_INT);
    $stmt->bindValue(3, $like, PDO::PARAM_STR);
    $stmt->bindValue(4, $like, PDO::PARAM_STR);
    $stmt->bindValue(5, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(6, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $items = $stmt->fetchAll();
  }
} else {
  $countStmt = $pdo->prepare("SELECT COUNT(*) FROM items WHERE user_id = ? AND tenant_id = ?");
  $countStmt->execute([$userId, $tenantId]);
  $totalItems = (int)$countStmt->fetchColumn();
  $totalPages = max(1, (int)ceil($totalItems / $perPage));

  if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
  }

  $stmt = $pdo->prepare(
    "SELECT id, title, description, image_path, created_at
     FROM items
     WHERE user_id = ?
       AND tenant_id = ?
     ORDER BY created_at DESC
     LIMIT ? OFFSET ?"
  );
  $stmt->bindValue(1, $userId, PDO::PARAM_INT);
  $stmt->bindValue(2, $tenantId, PDO::PARAM_INT);
  $stmt->bindValue(3, $perPage, PDO::PARAM_INT);
  $stmt->bindValue(4, $offset, PDO::PARAM_INT);
  $stmt->execute();
  $items = $stmt->fetchAll();
}

render_header('Items • CorePanel');
?>
<div class="container container-wide items-page">
  <h1>Items</h1>

  <p>
    <a href="/dashboard.php">← Dashboard</a> |
    <a href="/items/create.php">+ New Item</a>
  </p>

  <form method="get" style="margin: 12px 0;">
    <input name="q" placeholder="Search..." value="<?= e($q) ?>">
    <button type="submit">Search</button>
    <?php if ($q !== ''): ?> <a href="/items/index.php">Clear</a><?php endif; ?>
  </form>

  <?php if ($searchError !== ''): ?>
    <p><?= e($searchError) ?></p>
  <?php endif; ?>

  <?php if ($searchError !== ''): ?>
    <p>Search temporarily limited. Please try again shortly.</p>
  <?php elseif (!$items): ?>
    <p>No items yet.</p>
  <?php else: ?>
    <div class="items-table-wrap">
      <table class="items-table" border="1" cellpadding="8" cellspacing="0">
        <thead>
          <tr>
            <th>Title</th>
            <th>Description</th>
            <th>Image</th>
            <th>Created</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $it): ?>
            <tr>
              <td><?= e($it['title']) ?></td>
              <td><?= e(mb_strimwidth((string)($it['description'] ?? ''), 0, 80, '...')) ?></td>
              <td>
                <?php if (!empty($it['image_path'])): ?>
                    <img src="<?= e_url_attr((string)$it['image_path'], 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==') ?>" alt="" style="width:60px; height:60px; object-fit:cover; border-radius:8px;">
                <?php else: ?>
                    —
                <?php endif; ?>
              </td>
              <td><?= e((string)$it['created_at']) ?></td>
              <td class="item-actions-cell">
                <div class="item-actions-split" role="group" aria-label="Item actions">
                  <a class="item-action-btn item-action-edit" href="/items/edit.php?id=<?= (int)$it['id'] ?>">
                    <i class="bi bi-pencil-square" aria-hidden="true"></i>
                    <span>Edit</span>
                  </a>
                  <form method="post" action="/items/delete.php" class="item-action-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                    <button class="item-action-btn item-action-delete" type="submit" data-confirm="Delete this item?">
                      <i class="bi bi-trash3" aria-hidden="true"></i>
                      <span>Delete</span>
                    </button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

          <?php if ($totalPages > 1): ?>
      <div style="margin-top:20px;">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
          <?php if ($i === $page): ?>
            <strong><?= $i ?></strong>
          <?php else: ?>
            <a href="?page=<?= $i ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>"><?= $i ?></a>
          <?php endif; ?>
        <?php endfor; ?>
      </div>
    <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
