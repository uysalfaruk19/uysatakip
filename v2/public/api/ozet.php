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
    // fable-023a: öğün verilmezse GÜNÜN TAMAMI (3 öğün) döner — panelin gösterdiği rakamla
    // birebir aynı olsun diye. Tek öğün isteyen çağıran ?meal=... ile eski davranışı alır.
    $mealParam = (string) ($_GET['meal'] ?? '');
    $tekOgun = in_array($mealParam, ['sabah', 'ogle', 'aksam', 'gece', 'kumanya'], true);

    $rows = [];
    $eksik = [];
    if ($tekOgun) {
        $tot = $repo->dayTotals($gun, $mealParam);
        $toplam = ['kisi' => (int) $tot['persons'], 'tutar' => (float) $tot['amount']];
        foreach ($repo->dayGrid($gun, $mealParam) as $r) {
            $rows[] = [
                'musteri' => $r['name'],
                'kisi'    => $r['persons'] !== null ? (int) $r['persons'] : null,
                'tutar'   => $r['amount'] !== null ? (float) $r['amount'] : null,
            ];
            if ($r['persons'] === null) {
                $eksik[] = $r['name'];
            }
        }
    } else {
        $kisi = 0; $tutar = 0.0;
        foreach ($repo->dayGridAllMeals($gun) as $r) {
            $var = (int) $r['toplam'] > 0;
            $rows[] = [
                'musteri' => $r['name'],
                'kisi'    => $var ? (int) $r['toplam'] : null,
                'tutar'   => $var ? (float) $r['tutar'] : null,
                'ogunler' => ['ogle' => (int) $r['ogle'], 'aksam' => (int) $r['aksam'], 'kumanya' => (int) $r['kumanya']],
            ];
            if (!$var) {
                $eksik[] = $r['name'];
            }
            $kisi += (int) $r['toplam'];
            $tutar += (float) $r['tutar'];
        }
        $toplam = ['kisi' => $kisi, 'tutar' => $tutar];
    }
    Helpers::json([
        'ok'    => true,
        'gun'   => $gun,
        'ogun'  => $tekOgun ? $mealParam : 'hepsi',
        'toplam' => $toplam,
        'musteriler' => $rows,
        'eksik' => $eksik,
    ]);
}

if ($ay !== '') {
    if (!preg_match('/^\d{4}-\d{2}$/', $ay)) {
        Helpers::json(['ok' => false, 'error' => 'Geçersiz ay (YYYY-MM)'], 400);
    }
    $fin = $repo->monthFinanceTotals($ay);
    $byCust = $repo->monthProductionByCustomer($ay, 'uretim'); // taşıma HARİÇ (kategori ayrımı)
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
