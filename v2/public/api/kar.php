<?php
declare(strict_types=1);

/**
 * GET /api/kar?ay=YYYY-MM  → Kâr Analizi (üretim/taşıma grup net + toplam net + marj).
 * karAnalizi()'yi sarar — kar-analizi.php ekranıyla BİREBİR (yeni hesap yok).
 */

require __DIR__ . '/_boot.php';

use Uysa\Helpers;

$ay = (string) ($_GET['ay'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $ay)) {
    Helpers::json(['ok' => false, 'error' => 'Geçersiz ay (YYYY-MM)'], 400);
}

$ka = $repo->karAnalizi($ay);

// Müşteri kırılımı (bot isterse "en kârlı/zararlı müşteri" için) — ekranla aynı satırlar.
$uretimRows = [];
foreach ($ka['uretim']['rows'] as $r) {
    $uretimRows[] = [
        'musteri' => $r['name'],
        'gelir'   => round((float) $r['gelir'], 2),
        'gider'   => round((float) $r['gider'], 2),
        'personel' => round((float) $r['personel'], 2),
        'net'     => round((float) $r['net'], 2),
        'marj'    => round((float) $r['marj'], 4),
    ];
}
$tasimaRows = [];
foreach ($ka['tasima']['rows'] as $r) {
    $tasimaRows[] = [
        'musteri' => $r['name'],
        'satis'   => round((float) $r['satis'], 2),
        'net'     => round((float) $r['net'], 2),
        'marj'    => round((float) $r['marj'], 4),
    ];
}

Helpers::json([
    'ok'  => true,
    'ay'  => $ay,
    'toplam' => [
        'gelir' => round((float) $ka['toplam_gelir'], 2),
        'net'   => round((float) $ka['toplam_net'], 2),
        'marj'  => round((float) $ka['toplam_marj'], 4),
    ],
    'uretim' => [
        'gelir'    => round((float) $ka['uretim']['gelir'], 2),
        'gider'    => round((float) $ka['uretim']['gider'], 2),
        'personel' => round((float) $ka['uretim']['personel'], 2),
        'net'      => round((float) $ka['uretim']['net'], 2),
        'marj'     => round((float) $ka['uretim']['marj'], 4),
        'musteriler' => $uretimRows,
    ],
    'tasima' => [
        'satis'    => round((float) $ka['tasima']['satis'], 2),
        'alis'     => round((float) $ka['tasima']['alis'], 2),
        'sabit'    => round((float) $ka['tasima']['sabit'], 2),
        'gider'    => round((float) $ka['tasima']['gider'], 2),
        'personel' => round((float) $ka['tasima']['personel'], 2),
        'net'      => round((float) $ka['tasima']['net'], 2),
        'marj'     => round((float) $ka['tasima']['marj'], 4),
        'musteriler' => $tasimaRows,
    ],
    'dagitilmamis' => round((float) $ka['dagitilmamis'], 2),
]);
