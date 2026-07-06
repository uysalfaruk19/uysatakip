<?php
declare(strict_types=1);

/**
 * GET /api/musteri?ad=cantas[&ay=YYYY-MM]  → fuzzy eşleşen müşteri + bu ay kişi/ciro/net + Paraşüt bakiye
 * GET /api/musteri (veya ?liste=1)         → aktif müşteri listesi (ad, kategori, birim fiyat, Paraşüt bakiye)
 * Fuzzy düşük güven / eşleşmeyen → 422 + "netleştir" (bot Ömer'e sorar).
 * Tüm rakamlar mevcut Repo metotlarından (customerNetKarlilik/customerMonthProduction) — yeni hesap yok.
 */

require __DIR__ . '/_boot.php';

use Uysa\Helpers;

$ad = (string) ($_GET['ad'] ?? '');
$ay = (string) ($_GET['ay'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $ay)) {
    Helpers::json(['ok' => false, 'error' => 'Geçersiz ay (YYYY-MM)'], 400);
}

// ── Liste modu ────────────────────────────────────────
if ($ad === '') {
    $rows = [];
    foreach ($repo->activeCustomers() as $c) {
        $full = $repo->customer((int) $c['id']);
        $rows[] = [
            'id'        => (int) $c['id'],
            'ad'        => $c['name'],
            'kategori'  => $c['category'],
            'birim_fiyat' => (float) $c['unit_price'],
            'parasut_bakiye' => $full && $full['parasut_bakiye'] !== null ? (float) $full['parasut_bakiye'] : null,
        ];
    }
    Helpers::json(['ok' => true, 'adet' => count($rows), 'musteriler' => $rows]);
}

// ── Tekil (fuzzy) ─────────────────────────────────────
$candidates = $repo->customerNameMap(true);
$m = Helpers::matchCustomer($ad, $candidates);
if ($m['id'] === null) {
    $resp = ['ok' => false, 'error' => 'Müşteri eşleşmedi', 'netlestir' => 'Tam müşteri adını yaz.'];
    if (!empty($m['aday'])) {
        $resp['en_yakin'] = $m['aday'];
        $resp['skor'] = round((float) $m['score'], 2);
        $resp['netlestir'] = "'" . $m['aday'] . "' mi demek istedin? Teyit et.";
    }
    Helpers::json($resp, 422);
}
if ($m['score'] < 1.0 && $m['reason'] !== 'tam') {
    Helpers::json([
        'ok' => false, 'error' => 'Düşük güvenli eşleşme — teyit gerekli',
        'en_yakin' => $m['name'], 'skor' => round((float) $m['score'], 2),
        'netlestir' => "'" . $m['name'] . "' mi demek istedin? Teyit et.",
    ], 422);
}

$cid = (int) $m['id'];
$c = $repo->customer($cid);
$nk = $repo->customerNetKarlilik($cid, $ay);
$prod = $repo->customerMonthProduction($cid, $ay);

Helpers::json([
    'ok'  => true,
    'ay'  => $ay,
    'musteri' => [
        'id'       => $cid,
        'ad'       => $c['name'],
        'kategori' => $c['category'],
        'birim_fiyat' => (float) $c['unit_price'],
        'eslesme'  => round((float) $m['score'], 2),
    ],
    'bu_ay' => [
        'kisi'  => (int) $prod['persons'],
        'gun'   => (int) $prod['cnt'],
        'ciro'  => round((float) $nk['ciro'], 2),
        'pay_gider'    => round((float) $nk['pay_gider'], 2),
        'pay_personel' => round((float) $nk['pay_personel'], 2),
        'net'   => round((float) $nk['net'], 2),
    ],
    'cari' => [
        'bakiye' => round($repo->customerBalance($cid), 2), // + = müşteri bize borçlu
        'parasut_bakiye' => $c['parasut_bakiye'] !== null ? (float) $c['parasut_bakiye'] : null,
        'parasut_sync_at' => $c['parasut_sync_at'] ?? null,
    ],
]);
