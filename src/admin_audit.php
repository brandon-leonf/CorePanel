<?php
declare(strict_types=1);

function admin_audit_ensure_table(PDO $pdo): void {
  static $ensured = false;
  if ($ensured) {
    return;
  }

  $pdo->exec(
    "CREATE TABLE IF NOT EXISTS admin_user_audit_logs (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      actor_user_id INT UNSIGNED NOT NULL,
      target_user_id INT UNSIGNED NULL,
      action VARCHAR(64) NOT NULL,
      summary VARCHAR(255) NULL,
      ip_address VARCHAR(45) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_audit_created_at (created_at),
      INDEX idx_audit_actor (actor_user_id),
      INDEX idx_audit_target (target_user_id)
    ) ENGINE=InnoDB"
  );

  $ensured = true;
}

function admin_audit_log(
  PDO $pdo,
  int $actorUserId,
  string $action,
  ?int $targetUserId = null,
  ?string $summary = null
): void {
  admin_audit_ensure_table($pdo);

  $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
  $stmt = $pdo->prepare(
    "INSERT INTO admin_user_audit_logs
      (actor_user_id, target_user_id, action, summary, ip_address)
     VALUES (?, ?, ?, ?, ?)"
  );
  $stmt->execute([$actorUserId, $targetUserId, $action, $summary, $ip === '' ? null : $ip]);
}

function admin_audit_recent(PDO $pdo, int $limit = 25): array {
  admin_audit_ensure_table($pdo);

  $safeLimit = max(1, min(200, $limit));
  $sql = "
    SELECT
      l.id,
      l.action,
      l.summary,
      l.ip_address,
      l.created_at,
      actor.id AS actor_id,
      actor.name AS actor_name,
      actor.email AS actor_email,
      target.id AS target_id,
      target.name AS target_name,
      target.email AS target_email
    FROM admin_user_audit_logs l
    LEFT JOIN users actor ON actor.id = l.actor_user_id
    LEFT JOIN users target ON target.id = l.target_user_id
    ORDER BY l.id DESC
    LIMIT {$safeLimit}
  ";

  $rows = $pdo->query($sql);
  return $rows ? $rows->fetchAll() : [];
}
