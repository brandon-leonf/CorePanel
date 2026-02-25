<?php
declare(strict_types=1);

require __DIR__ . '/../src/auth.php';
start_session();

$_SESSION = [];
session_destroy();

header('Location: /login.php');
exit;