<?php
declare(strict_types=1);

function render_header(string $title): void {
?>
<!doctype html>
<html>
<head>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta charset="utf-8">
  <title><?= htmlspecialchars($title) ?></title>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
    <nav style="padding:16px; background:#111827; color:white;">
    <a href="/login.php" style="color:white; margin-right:12px;">Login</a>
    <a href="/register.php" style="color:white; margin-right:12px;">Register</a>
    <a href="/dashboard.php" style="color:white;">Dashboard</a>
    </nav>
<?php
}

function render_footer(): void {
?>
</body>
</html>
<?php
}