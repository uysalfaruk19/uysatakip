<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
\Uysa\Auth::logout();
header('Location: login.php');
