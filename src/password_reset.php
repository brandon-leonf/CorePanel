<?php
declare(strict_types=1);

function pr_create_token(): string {
  return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function pr_hash_token(string $token): string {
  return hash('sha256', $token);
}

function pr_create_reset(PDO $pdo, int $userId, int $minutes = 30): string {
  $token = pr_create_token();
  $tokenHash = pr_hash_token($token);

  // Optional: invalidate old unused tokens for this user
  $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")
      ->execute([$userId]);

  $stmt = $pdo->prepare(
    "INSERT INTO password_resets (user_id, token_hash, expires_at)
     VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))"
  );
  $stmt->execute([$userId, $tokenHash, $minutes]);

  return $token; // return RAW token ONLY to send to user (email/link)
}

function pr_find_valid_reset(PDO $pdo, string $token): ?array {
  $tokenHash = pr_hash_token($token);
  $stmt = $pdo->prepare(
    "SELECT pr.id, pr.user_id, pr.expires_at, pr.used_at
     FROM password_resets pr
     WHERE pr.token_hash = ?
       AND pr.used_at IS NULL
       AND pr.expires_at > NOW()
     LIMIT 1"
  );
  $stmt->execute([$tokenHash]);
  $row = $stmt->fetch();
  return $row ?: null;
}

function pr_mark_used(PDO $pdo, int $resetId): void {
  $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = ?")
      ->execute([$resetId]);
}