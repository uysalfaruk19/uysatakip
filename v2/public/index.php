<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
\Uysa\Auth::startSession();
header('Location: ' . (\Uysa\Auth::user() ? 'bugun.php' : 'login.php'));
