<?php
declare(strict_types=1);

/**
 * GET /api/ozet?gun=YYYY-MM-DD   → o günün üretim toplamı + müşteri kırılımı + eksikler
 * GET /api/ozet?ay=YYYY-MM       → ayın üretim + finans özeti
 */

require __DIR__ . '/_boot.php';

use Uysa\Helpers;

$gun = (string) ($_GET['gun'] ?? '');
$ay  = (string) ($_GET['ay'] ?? '');

if ($gun !== '') {
    if (!Helpers::isDate($gun)) {
        Helpers::json(['ok' => false, 'error' => 'Geçersiz gün'], 400);
    }
    $meal = $_GET['meal'] ?? 'ogle';
    if (!in_array($meal, ['sabah', 'ogle', 'aksam', 'gece'], true)) {
        $meal = 'ogle';
    }
    $grid = $repo->dayGrid($gun, $meal);
    $tot = $repo->dayTotals($gun, $meal);
    $rows = [];
    $eksik = [];
    foreach ($grid as $r) {
        $rows[] = [
            'musteri' => $r['name'],
            'kisi'    => $r['persons'] !== null ? (int) $r['persons'] : null,
            'tutar'   => $r['amount'] !== null ? (float) $r['amount'] : null,
        ];
        if ($r['persons'] === null) {
            $eksik[] = $r['name'];
        }
    }
    Helpers::json([
        'ok'    => true,
        'gun'   => $gun,
        'ogun'  => $meal,
        'toplam' => ['kisi' => (int) $tot['persons'], 'tutar' => (float) $tot['amount']],
        'musteriler' => $rows,
        'eksik' => $eksik,
    ]);
}

if ($ay !== '') {
    if (!preg_match('/^\d{4}-\d{2}$/', $ay)) {
        Helpers::json(['ok' => false, 'error' => 'Geçersiz ay (YYYY-MM)'], 400);
    }
    $fin = $repo->monthFinanceTotals($ay);
    $byCust = $repo->monthProductionByCustomer($ay);
    $ciro = 0.0; $kisi = 0;
    foreach ($byCust as $r) {
        $ciro += (float) $r['ciro'];
        $kisi += (int) $r['persons'];
    }
    Helpers::json([
        'ok'    => true,
        'ay'    => $ay,
        'uretim' => ['kisi' => $kisi, 'ciro' => $ciro, 'musteri_sayisi' => count($byCust)],
        'finans' => $fin,
        'musteriler' => $byCust,
    ]);
}

Helpers::json(['ok' => false, 'error' => 'gun veya ay parametresi gerekli'], 400);
