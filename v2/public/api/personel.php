<?php
declare(strict_types=1);

/**
 * GET /api/personel?ay=YYYY-MM  → personel yüklü işveren maliyeti + kıdem özet (SALT-OKUMA).
 * personelYukluMaliyet() + kidemBirikim() + personelDagitim() sarar — yeni hesap yok.
 * $ay opsiyonel (kıdem birikimi referansı; default bugün).
 */

require __DIR__ . '/_boot.php';

use Uysa\Helpers;

$ay = (string) ($_GET['ay'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $ay)) {
    Helpers::json(['ok' => false, 'error' => 'Geçersiz ay (YYYY-MM)'], 400);
}

$rows = [];
$toplamYuklu = 0.0;
$toplamKidemBirikim = 0.0;
foreach ($repo->listPersonel() as $p) {
    $pid = (int) $p['id'];
    $maas = $repo->personelMaasAy($pid, $ay);
    $y = $repo->personelYukluMaliyet($pid, $ay);
    $k = $repo->kidemBirikim($pid, $ay);
    $toplamYuklu += (float) $y['yuklu_toplam'];
    $toplamKidemBirikim += (float) $k['birikim'];
    $rows[] = [
        'ad'    => $p['ad'],
        'gorev' => $p['gorev'],
        'calisma_gunu' => round((float) $maas['calisma_gunu'], 2),
        'eksik_gun' => round((float) $maas['eksik_gun'], 2),
        'hesaplanan_maas' => round((float) $maas['hesaplanan_maas'], 2),
        'maas_odendi' => (bool) $maas['maas_odendi'],
        'odeme_tarihi' => $maas['odeme_tarihi'],
        'brut'  => round((float) $y['brut'], 2),
        'sgk_isveren' => round((float) $y['sgk_isveren'], 2),
        'kidem_aylik' => round((float) $y['kidem_aylik'], 2),
        'diger' => round((float) $y['diger'], 2),
        'yuklu_toplam' => round((float) $y['yuklu_toplam'], 2),
        'kidem_ay_sayisi' => (int) $k['ay_sayisi'],
        'kidem_birikim'   => round((float) $k['birikim'], 2),
    ];
}

$dagitim = $repo->personelDagitim($ay);

Helpers::json([
    'ok' => true,
    'ay' => $ay,
    'toplam' => [
        'personel_sayisi'  => count($rows),
        'yuklu_maliyet'    => round($toplamYuklu, 2),
        'kidem_birikim'    => round($toplamKidemBirikim, 2),
        'dagitilmamis'     => round((float) $dagitim['dagitilmamis'], 2),
    ],
    'personel' => $rows,
]);
