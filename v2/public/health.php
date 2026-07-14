<?php
// fable-010: app error.html'in erişilebilirlik yoklaması (DB'siz, en hafif yanıt).
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
echo 'ok';
