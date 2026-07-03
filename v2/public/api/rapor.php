<?php
declare(strict_types=1);

/**
 * GET /api/rapor?ay=YYYY-MM  — ölü Railway fetch'in yenisi (OFUclaw + tools/uysa_erp_fetch.py).
 * Ay × müşteri: kişi, ciro, gün sayısı, kişi başı ortalama + finans net.
 */

require __DIR__ . '/_boot.php';

use Uysa\Helpers;

$ay = (string) ($_GET['ay'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $ay)) {
    Helpers::json(['ok' => false, 'error' => 'Geçersiz ay (YYYY-MM)'], 400);
}

$byCust = $repo->monthProductionByCustomer($ay);
$fin = $repo->monthFinanceTotals($ay);

$rows = [];
$toplamCiro = 0.0; $toplamKisi = 0;
foreach ($byCust as $r) {
    $kisi = (int) $r['persons'];
    $ciro = (float) $r['ciro'];
    $gun = (int) $r['gun'];
    $rows[] = [
        'musteri'      => $r['name'],
        'kisi'         => $kisi,
        'gun'          => $gun,
        'ciro'         => $ciro,
        'gun_ort_kisi' => $gun > 0 ? round($kisi / $gun, 1) : 0,
    ];
    $toplamCiro += $ciro;
    $toplamKisi += $kisi;
}

Helpers::json([
    'ok'     => true,
    'ay'     => $ay,
    'toplam' => ['kisi' => $toplamKisi, 'ciro' => $toplamCiro, 'musteri_sayisi' => count($rows)],
    'finans' => $fin,
    'musteriler' => $rows,
]);
