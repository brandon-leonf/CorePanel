<?php
declare(strict_types=1);

function next_project_no(PDO $pdo): string {
  $pdo->beginTransaction();

  try {
    // Lock the counter row
    $stmt = $pdo->prepare("SELECT next_num FROM counters WHERE name = 'project_inv' FOR UPDATE");
    $stmt->execute();
    $num = (int)$stmt->fetchColumn();

    if ($num <= 0) {
      throw new RuntimeException("Counter not initialized.");
    }

    // Increment counter
    $upd = $pdo->prepare("UPDATE counters SET next_num = next_num + 1 WHERE name = 'project_inv'");
    $upd->execute();

    $pdo->commit();

    // Format INV + 4 digits
    return 'INV' . str_pad((string)$num, 4, '0', STR_PAD_LEFT);
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
}