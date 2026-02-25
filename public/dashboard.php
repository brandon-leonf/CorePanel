<?php
$pdo = require __DIR__ . '/../config/db.php';
require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/helpers.php';

require_login();
$u = current_user($pdo);
redirect(($u['role'] ?? 'user') === 'admin' ? '/admin/dashboard.php' : '/client/dashboard.php');