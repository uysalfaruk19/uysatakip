<?php
declare(strict_types=1);

/**
 * GET /api/musteri/cari?ay=YYYY-MM — SADECE oturumdaki müşterinin ekstresi + bakiye.
 * IDOR: $cid oturumdan; başka müşterinin carisi ASLA dönmez.
 */

require __DIR__ . '/_boot_m.php';

use Uysa\Helpers;

$ay = (string) ($_GET['ay'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $ay)) {
    $ay = date('Y-m');
}

$rows = $repo->customerLedger($cid, $ay);
$balance = $repo->customerBalance($cid);

Helpers::json([
    'ok'      => true,
    'ay'      => $ay,
    'bakiye'  => $balance,
    'ekstre'  => $rows,
]);
